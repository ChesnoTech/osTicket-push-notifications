<?php
/**
 * Push Notifications Plugin - AJAX Controller
 *
 * @author  ChesnoTech
 * @version 1.0.0
 */

require_once INCLUDE_DIR . 'class.ajax.php';

class PushNotificationsAjax extends AjaxController {

    /**
     * GET /push-notifications/status
     * Returns subscription status for the current staff member.
     */
    function getStatus() {
        $this->staffOnly();

        global $thisstaff;
        $staffId = $thisstaff->getId();

        $prefix = TABLE_PREFIX;
        $res = db_query(sprintf(
            "SELECT COUNT(*) FROM `{$prefix}push_subscription` WHERE staff_id = %d",
            db_input($staffId)));
        $row = db_fetch_row($res);
        $count = (int) $row[0];

        Http::response(200, $this->json_encode(array(
            'subscribed' => $count > 0,
            'count' => $count,
        )));
    }

    /**
     * POST /push-notifications/subscribe
     * Store a push subscription for the current staff member.
     */
    function subscribe() {
        $this->staffOnly();

        global $thisstaff;
        $staffId = $thisstaff->getId();

        $body = file_get_contents('php://input');
        $data = json_decode($body, true);
        if (!$data
            || empty($data['endpoint'])
            || empty($data['keys']['p256dh'])
            || empty($data['keys']['auth'])
        ) {
            Http::response(400, $this->json_encode(array(
                'error' => 'Missing required fields: endpoint, keys.p256dh, keys.auth'
            )));
            return;
        }

        $endpoint = $data['endpoint'];
        $p256dh = $data['keys']['p256dh'];
        $auth = $data['keys']['auth'];
        $encoding = $data['encoding'] ?? 'aes128gcm';
        $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
        $now = date('Y-m-d H:i:s');
        $prefix = TABLE_PREFIX;

        // Delete any existing subscription with this endpoint (could be from another staff)
        db_query(sprintf(
            "DELETE FROM `{$prefix}push_subscription` WHERE endpoint = %s",
            db_input($endpoint)));

        // Insert the new subscription
        // db_input() auto-quotes strings; use %s without extra quotes
        db_query(sprintf(
            "INSERT INTO `{$prefix}push_subscription`
                (staff_id, endpoint, p256dh_key, auth_key, user_agent, encoding, created, updated)
             VALUES (%d, %s, %s, %s, %s, %s, %s, %s)",
            db_input($staffId),
            db_input($endpoint),
            db_input($p256dh),
            db_input($auth),
            db_input($userAgent),
            db_input($encoding),
            db_input($now),
            db_input($now)));

        Http::response(200, $this->json_encode(array(
            'success' => true,
            'message' => 'Subscription saved',
        )));
    }

    /**
     * POST /push-notifications/unsubscribe
     * Remove a push subscription for the current staff member.
     */
    function unsubscribe() {
        $this->staffOnly();

        global $thisstaff;
        $staffId = $thisstaff->getId();

        $body = file_get_contents('php://input');
        $data = json_decode($body, true);
        if (!$data || empty($data['endpoint'])) {
            Http::response(400, $this->json_encode(array(
                'error' => 'Missing required field: endpoint'
            )));
            return;
        }

        $prefix = TABLE_PREFIX;
        db_query(sprintf(
            "DELETE FROM `{$prefix}push_subscription`
             WHERE staff_id = %d AND endpoint = %s",
            db_input($staffId),
            db_input($data['endpoint'])));

        Http::response(200, $this->json_encode(array(
            'success' => true,
            'message' => 'Subscription removed',
        )));
    }

    /**
     * GET /push-notifications/sw.js
     * Serve the service worker file with appropriate headers.
     */
    function serveServiceWorker() {
        $file = dirname(__FILE__) . '/assets/sw.js';
        if (!file_exists($file))
            Http::response(404, 'Not found');

        // Flush output buffers
        while (ob_get_level() > 0)
            ob_end_clean();

        // Allow the service worker to control the /scp/ scope
        header('Service-Worker-Allowed: /scp/');
        header('Content-Type: application/javascript; charset=UTF-8');
        header('Cache-Control: no-cache');
        header('Content-Length: ' . filesize($file));
        readfile($file);
        exit;
    }

    /**
     * GET /push-notifications/test
     * Send a test push notification to the current staff member.
     */
    function sendTest() {
        $this->staffOnly();

        global $thisstaff;
        $staffId = $thisstaff->getId();

        require_once dirname(__FILE__) . '/class.PushDispatcher.php';
        $result = PushDispatcher::sendTestNotification($staffId);

        Http::response(200, $this->json_encode($result));
    }

    /**
     * GET /push-notifications/preferences
     * Return agent's push preferences + available departments.
     */
    function getPreferences() {
        $this->staffOnly();

        global $thisstaff;
        $staffId = $thisstaff->getId();
        $prefix = TABLE_PREFIX;

        // Get existing preferences (or defaults)
        $res = db_query(sprintf(
            "SELECT * FROM `{$prefix}push_preferences` WHERE staff_id = %d",
            db_input($staffId)));

        if ($res && ($row = db_fetch_array($res))) {
            $prefs = array(
                'event_new_ticket'  => (int) $row['event_new_ticket'],
                'event_new_message' => (int) $row['event_new_message'],
                'event_assignment'  => (int) $row['event_assignment'],
                'event_transfer'    => (int) $row['event_transfer'],
                'event_overdue'     => (int) $row['event_overdue'],
                'quiet_start'       => $row['quiet_start'] ?: '',
                'quiet_end'         => $row['quiet_end'] ?: '',
                'dept_ids'          => $row['dept_ids'] ? json_decode($row['dept_ids'], true) : array(),
            );
        } else {
            // Defaults: all events on, no quiet hours, all departments
            $prefs = array(
                'event_new_ticket'  => 1,
                'event_new_message' => 1,
                'event_assignment'  => 1,
                'event_transfer'    => 1,
                'event_overdue'     => 1,
                'quiet_start'       => '',
                'quiet_end'         => '',
                'dept_ids'          => array(),
            );
        }

        // Get agent's accessible departments
        $depts = array();
        // Primary department
        $primaryDeptId = $thisstaff->getDeptId();
        if ($primaryDeptId) {
            $dept = Dept::lookup($primaryDeptId);
            if ($dept) {
                $depts[] = array(
                    'id'   => (int) $dept->getId(),
                    'name' => $dept->getName(),
                );
            }
        }
        // Extended departments
        if (method_exists($thisstaff, 'getDepts')) {
            $extDeptIds = $thisstaff->getDepts();
            if (is_array($extDeptIds)) {
                foreach ($extDeptIds as $deptId) {
                    if ($deptId == $primaryDeptId)
                        continue;
                    $dept = Dept::lookup($deptId);
                    if ($dept) {
                        $depts[] = array(
                            'id'   => (int) $dept->getId(),
                            'name' => $dept->getName(),
                        );
                    }
                }
            }
        }

        Http::response(200, $this->json_encode(array(
            'preferences' => $prefs,
            'departments' => $depts,
        )));
    }

    /**
     * POST /push-notifications/preferences
     * Save agent's push preferences.
     */
    function savePreferences() {
        $this->staffOnly();

        global $thisstaff;
        $staffId = $thisstaff->getId();

        $body = file_get_contents('php://input');
        $data = json_decode($body, true);
        if (!is_array($data)) {
            Http::response(400, $this->json_encode(array(
                'error' => 'Invalid JSON body'
            )));
            return;
        }

        $eventFields = array(
            'event_new_ticket', 'event_new_message', 'event_assignment',
            'event_transfer', 'event_overdue',
        );

        $values = array();
        foreach ($eventFields as $f) {
            $values[$f] = isset($data[$f]) ? (empty($data[$f]) ? 0 : 1) : 1;
        }

        // Quiet hours: validate HH:MM format
        $quietStart = '';
        $quietEnd = '';
        if (!empty($data['quiet_start']) && preg_match('/^\d{2}:\d{2}$/', $data['quiet_start']))
            $quietStart = $data['quiet_start'];
        if (!empty($data['quiet_end']) && preg_match('/^\d{2}:\d{2}$/', $data['quiet_end']))
            $quietEnd = $data['quiet_end'];

        // Dept IDs: validate as array of ints
        $deptIds = array();
        if (isset($data['dept_ids']) && is_array($data['dept_ids'])) {
            foreach ($data['dept_ids'] as $id) {
                $deptIds[] = (int) $id;
            }
        }

        $prefix = TABLE_PREFIX;
        $now = date('Y-m-d H:i:s');
        $deptJson = json_encode($deptIds);

        // Upsert using REPLACE INTO
        db_query(sprintf(
            "REPLACE INTO `{$prefix}push_preferences`
                (staff_id, event_new_ticket, event_new_message, event_assignment,
                 event_transfer, event_overdue, quiet_start, quiet_end, dept_ids, updated)
             VALUES (%d, %d, %d, %d, %d, %d, %s, %s, %s, %s)",
            db_input($staffId),
            $values['event_new_ticket'],
            $values['event_new_message'],
            $values['event_assignment'],
            $values['event_transfer'],
            $values['event_overdue'],
            db_input($quietStart),
            db_input($quietEnd),
            db_input($deptJson),
            db_input($now)));

        Http::response(200, $this->json_encode(array(
            'success' => true,
            'message' => 'Preferences saved',
        )));
    }

    /**
     * GET /push-notifications/assets/js
     */
    function serveJs() {
        $this->serveFile(dirname(__FILE__) . '/assets/push-notifications.js',
            'application/javascript; charset=UTF-8');
    }

    /**
     * GET /push-notifications/assets/css
     */
    function serveCss() {
        $this->serveFile(dirname(__FILE__) . '/assets/push-notifications.css',
            'text/css; charset=UTF-8');
    }

    private function serveFile($file, $contentType, $maxAge = 86400) {
        if (!file_exists($file))
            Http::response(404, 'Not found');

        $etag = '"pn-' . md5($file) . '-' . filemtime($file) . '"';

        if (isset($_SERVER['HTTP_IF_NONE_MATCH'])
                && trim($_SERVER['HTTP_IF_NONE_MATCH']) === $etag) {
            Http::response(304, '');
        }

        // Flush any output buffers (plugin ob_start callbacks)
        while (ob_get_level() > 0)
            ob_end_clean();

        header('Content-Type: ' . $contentType);
        header('Cache-Control: public, max-age=' . $maxAge);
        header('ETag: ' . $etag);
        header('Content-Length: ' . filesize($file));
        readfile($file);
        exit;
    }

    function staffOnly() {
        global $thisstaff;
        if (!$thisstaff || !$thisstaff->isValid())
            Http::response(403, __('Access Denied'));
    }
}
