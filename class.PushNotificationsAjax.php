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

    // -------------------------------------------------------
    // Auto-Update Endpoints (admin only)
    // -------------------------------------------------------

    /**
     * GET /push-notifications/update/check
     * Check GitHub for a newer plugin version.
     */
    function checkUpdate() {
        $this->adminOnly();

        require_once dirname(__FILE__) . '/class.PushUpdater.php';
        $updater = new PushUpdater();

        // Allow ?channel=beta override for one-off checks
        $channel = (isset($_GET['channel']) && in_array($_GET['channel'], PushUpdater::CHANNELS, true))
            ? $_GET['channel'] : null;
        $result = $updater->checkForUpdate($channel);
        $result['channels'] = PushUpdater::CHANNELS;
        $result['current_channel'] = $updater->getChannel();

        Http::response(200, $this->json_encode($result));
    }

    /**
     * POST /push-notifications/update/channel
     * Switch the update channel.
     */
    function setChannel() {
        $this->adminOnly();

        $body = file_get_contents('php://input');
        $data = json_decode($body, true);
        $channel = (is_array($data) && !empty($data['channel']))
            ? $data['channel'] : '';

        require_once dirname(__FILE__) . '/class.PushUpdater.php';
        $updater = new PushUpdater();
        if (!$updater->setChannel($channel)) {
            Http::response(400, $this->json_encode(array(
                'success' => false,
                'error'   => 'Invalid channel. Allowed: ' . implode(', ', PushUpdater::CHANNELS),
            )));
            return;
        }

        // Clear cached update check so next check uses new channel
        $config = PushNotificationsPlugin::getActiveConfig();
        if ($config) {
            $config->set('update_available', '');
            $config->set('last_update_check', 0);
        }

        Http::response(200, $this->json_encode(array(
            'success' => true,
            'channel' => $channel,
        )));
    }

    /**
     * POST /push-notifications/update/apply
     * Download and install the latest release.
     */
    function applyUpdate() {
        $this->adminOnly();

        // Extend execution limits for download + extract
        @set_time_limit(300);
        @ini_set('memory_limit', '256M');

        require_once dirname(__FILE__) . '/class.PushUpdater.php';
        $updater = new PushUpdater();
        $result = $updater->performUpdate();

        // Clear update_available flag on success
        if ($result['success']) {
            $config = PushNotificationsPlugin::getActiveConfig();
            if ($config)
                $config->set('update_available', '');
        }

        Http::response(200, $this->json_encode($result));
    }

    /**
     * POST /push-notifications/update/rollback
     * Rollback to the most recent backup.
     */
    function rollbackUpdate() {
        $this->adminOnly();

        require_once dirname(__FILE__) . '/class.PushUpdater.php';
        $updater = new PushUpdater();

        $body = file_get_contents('php://input');
        $data = json_decode($body, true);
        $backupPath = (is_array($data) && !empty($data['backup_path']))
            ? $data['backup_path']
            : null;

        // Validate backup path is within our backups directory (prevent path traversal)
        if ($backupPath) {
            $realBackup = realpath($backupPath);
            $realBase = realpath(dirname(__FILE__) . '/backups');
            if (!$realBackup || !$realBase || strpos($realBackup, $realBase) !== 0) {
                Http::response(403, $this->json_encode(array(
                    'success' => false,
                    'error'   => 'Invalid backup path',
                )));
                return;
            }
        }

        $result = $updater->rollback($backupPath);
        Http::response(200, $this->json_encode($result));
    }

    /**
     * GET /push-notifications/update/backups
     * List available backups.
     */
    function listBackups() {
        $this->adminOnly();

        require_once dirname(__FILE__) . '/class.PushUpdater.php';
        $updater = new PushUpdater();

        Http::response(200, $this->json_encode(array(
            'backups' => $updater->listBackups(),
        )));
    }

    /**
     * GET /push-notifications/update-manager
     * Serve the full-page Update Manager UI (like Joomla's extension updater).
     */
    function serveUpdateManager() {
        $this->adminOnly();

        require_once dirname(__FILE__) . '/class.PushUpdater.php';
        $updater = new PushUpdater();
        $current = $updater->getCurrentVersion();

        global $ost;
        $csrfToken = $ost ? $ost->getCSRFToken() : '';
        $base = ROOT_PATH . 'scp/ajax.php/push-notifications';

        // Flush output buffers
        while (ob_get_level() > 0)
            ob_end_clean();

        header('Content-Type: text/html; charset=UTF-8');

        echo '<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="csrf_token" content="' . htmlspecialchars($csrfToken) . '">
<title>Push Notifications &mdash; Update Manager</title>
<style>
:root {
    --bg: #f4f6f9; --card-bg: #fff; --text: #1a1a2e; --text-muted: #6b7280;
    --border: #e5e7eb; --primary: #1a73e8; --primary-hover: #1557b0;
    --success: #34a853; --success-bg: #ecfdf5; --success-border: #a7f3d0;
    --warning: #f59e0b; --warning-bg: #fffbeb; --warning-border: #fde68a;
    --danger: #ea4335; --danger-bg: #fef2f2; --danger-border: #fecaca;
    --info-bg: #eff6ff; --info-border: #bfdbfe;
    --radius: 10px; --shadow: 0 1px 3px rgba(0,0,0,.08);
}
@media (prefers-color-scheme: dark) {
    :root {
        --bg: #0f172a; --card-bg: #1e293b; --text: #e2e8f0; --text-muted: #94a3b8;
        --border: #334155; --primary: #3b82f6; --primary-hover: #2563eb;
        --success: #22c55e; --success-bg: #052e16; --success-border: #166534;
        --warning: #eab308; --warning-bg: #422006; --warning-border: #854d0e;
        --danger: #ef4444; --danger-bg: #450a0a; --danger-border: #991b1b;
        --info-bg: #172554; --info-border: #1e40af;
        --shadow: 0 1px 3px rgba(0,0,0,.3);
    }
}
* { margin: 0; padding: 0; box-sizing: border-box; }
body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
    background: var(--bg); color: var(--text); line-height: 1.5; }
.um-container { max-width: 900px; margin: 0 auto; padding: 24px 16px; }
.um-header { display: flex; align-items: center; justify-content: space-between;
    margin-bottom: 24px; flex-wrap: wrap; gap: 12px; }
.um-header h1 { font-size: 22px; font-weight: 700; }
.um-header .um-version { font-size: 13px; color: var(--text-muted); }
.um-back { text-decoration: none; color: var(--primary); font-size: 13px;
    display: inline-flex; align-items: center; gap: 4px; }
.um-back:hover { text-decoration: underline; }

.um-card { background: var(--card-bg); border: 1px solid var(--border);
    border-radius: var(--radius); box-shadow: var(--shadow); margin-bottom: 20px;
    overflow: hidden; }
.um-card-header { padding: 16px 20px; border-bottom: 1px solid var(--border);
    font-weight: 600; font-size: 15px; display: flex; align-items: center;
    justify-content: space-between; }
.um-card-body { padding: 20px; }

.um-status-box { padding: 14px 18px; border-radius: 8px; margin-bottom: 16px;
    display: flex; align-items: center; gap: 12px; font-size: 14px; }
.um-status-uptodate { background: var(--success-bg); border: 1px solid var(--success-border); color: var(--success); }
.um-status-available { background: var(--info-bg); border: 1px solid var(--info-border); color: var(--primary); }
.um-status-error { background: var(--danger-bg); border: 1px solid var(--danger-border); color: var(--danger); }
.um-status-checking { background: var(--info-bg); border: 1px solid var(--info-border); color: var(--text-muted); }

.um-update-details { margin-top: 16px; }
.um-update-details table { width: 100%; border-collapse: collapse; font-size: 14px; }
.um-update-details td { padding: 8px 12px; border-bottom: 1px solid var(--border); }
.um-update-details td:first-child { font-weight: 600; width: 140px; color: var(--text-muted); }
.um-release-notes { margin-top: 12px; padding: 14px; background: var(--bg);
    border-radius: 6px; font-size: 13px; max-height: 200px; overflow-y: auto;
    white-space: pre-wrap; word-break: break-word; }

.um-btn { display: inline-flex; align-items: center; gap: 6px; padding: 9px 20px;
    border-radius: 6px; font-size: 13px; font-weight: 600; cursor: pointer;
    border: none; transition: all .15s; }
.um-btn:disabled { opacity: .5; cursor: not-allowed; }
.um-btn-primary { background: var(--primary); color: #fff; }
.um-btn-primary:hover:not(:disabled) { background: var(--primary-hover); }
.um-btn-success { background: var(--success); color: #fff; }
.um-btn-danger { background: var(--danger); color: #fff; }
.um-btn-danger:hover:not(:disabled) { background: #c62828; }
.um-btn-outline { background: transparent; color: var(--text); border: 1px solid var(--border); }
.um-btn-outline:hover:not(:disabled) { background: var(--bg); }
.um-btn-sm { padding: 5px 12px; font-size: 12px; }

.um-actions { display: flex; gap: 10px; margin-top: 16px; flex-wrap: wrap; }

.um-progress { display: none; margin-top: 16px; }
.um-progress-bar { height: 6px; background: var(--border); border-radius: 3px; overflow: hidden; }
.um-progress-fill { height: 100%; background: var(--primary); border-radius: 3px;
    transition: width .4s; width: 0; }
.um-progress-text { font-size: 12px; color: var(--text-muted); margin-top: 6px; }

.um-log { margin-top: 12px; padding: 12px; background: #111827; color: #d1d5db;
    border-radius: 6px; font-family: "SF Mono", Consolas, monospace; font-size: 12px;
    max-height: 200px; overflow-y: auto; display: none; line-height: 1.8; }
.um-log .log-ok { color: #34d399; }
.um-log .log-err { color: #f87171; }
.um-log .log-info { color: #60a5fa; }

.um-backup-list { list-style: none; }
.um-backup-list li { display: flex; align-items: center; justify-content: space-between;
    padding: 10px 0; border-bottom: 1px solid var(--border); font-size: 13px; }
.um-backup-list li:last-child { border-bottom: none; }
.um-backup-info { display: flex; flex-direction: column; gap: 2px; }
.um-backup-name { font-weight: 600; }
.um-backup-meta { color: var(--text-muted); font-size: 12px; }
.um-empty { color: var(--text-muted); font-size: 14px; text-align: center; padding: 30px 0; }

.um-spinner { display: inline-block; width: 16px; height: 16px; border: 2px solid transparent;
    border-top-color: currentColor; border-radius: 50%; animation: um-spin .6s linear infinite; }
@keyframes um-spin { to { transform: rotate(360deg); } }

.um-channel-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 10px; }
.um-channel-opt { cursor: pointer; }
.um-channel-opt input { display: none; }
.um-channel-card { padding: 14px; border: 2px solid var(--border); border-radius: 8px;
    text-align: center; transition: all .15s; }
.um-channel-opt input:checked + .um-channel-card {
    border-color: var(--primary); background: var(--info-bg); }
.um-channel-card:hover { border-color: var(--primary); }
.um-channel-name { font-weight: 700; font-size: 14px; margin-bottom: 4px; }
.um-channel-desc { font-size: 11px; color: var(--text-muted); }
.um-channel-badge { display: inline-block; padding: 2px 8px; border-radius: 10px;
    font-size: 11px; font-weight: 600; margin-left: 6px; }
.um-badge-stable { background: var(--success-bg); color: var(--success); border: 1px solid var(--success-border); }
.um-badge-rc { background: var(--info-bg); color: var(--primary); border: 1px solid var(--info-border); }
.um-badge-beta { background: var(--warning-bg); color: var(--warning); border: 1px solid var(--warning-border); }
.um-badge-dev { background: var(--danger-bg); color: var(--danger); border: 1px solid var(--danger-border); }

@media (max-width: 600px) {
    .um-container { padding: 16px 10px; }
    .um-header { flex-direction: column; align-items: flex-start; }
    .um-update-details td:first-child { width: 100px; }
    .um-channel-grid { grid-template-columns: repeat(2, 1fr); }
}
</style>
</head>
<body>
<div class="um-container">
    <div class="um-header">
        <div>
            <a href="' . ROOT_PATH . 'scp/plugins.php" class="um-back">&larr; Back to Plugins</a>
            <h1>Push Notifications &mdash; Update Manager</h1>
            <div class="um-version">Installed: v<span id="um-current">' . htmlspecialchars($current) . '</span> &middot; Schema: v<span id="um-schema">...</span></div>
        </div>
    </div>

    <!-- Release Channel Card -->
    <div class="um-card">
        <div class="um-card-header">
            <span>Release Channel</span>
        </div>
        <div class="um-card-body">
            <div class="um-channel-grid" id="um-channels">
                <label class="um-channel-opt">
                    <input type="radio" name="channel" value="stable">
                    <div class="um-channel-card">
                        <div class="um-channel-name">Stable</div>
                        <div class="um-channel-desc">Production-ready releases only</div>
                    </div>
                </label>
                <label class="um-channel-opt">
                    <input type="radio" name="channel" value="rc">
                    <div class="um-channel-card">
                        <div class="um-channel-name">Release Candidate</div>
                        <div class="um-channel-desc">Final testing before stable</div>
                    </div>
                </label>
                <label class="um-channel-opt">
                    <input type="radio" name="channel" value="beta">
                    <div class="um-channel-card">
                        <div class="um-channel-name">Beta</div>
                        <div class="um-channel-desc">New features, may have bugs</div>
                    </div>
                </label>
                <label class="um-channel-opt">
                    <input type="radio" name="channel" value="dev">
                    <div class="um-channel-card">
                        <div class="um-channel-name">Dev</div>
                        <div class="um-channel-desc">Latest development builds</div>
                    </div>
                </label>
            </div>
        </div>
    </div>

    <!-- Update Check Card -->
    <div class="um-card">
        <div class="um-card-header">
            <span>Update Status</span>
            <button class="um-btn um-btn-outline um-btn-sm" onclick="checkForUpdate()">
                Check Now
            </button>
        </div>
        <div class="um-card-body">
            <div id="um-status" class="um-status-box um-status-checking">
                <span class="um-spinner"></span>
                <span>Checking for updates...</span>
            </div>
            <div id="um-details" class="um-update-details" style="display:none;"></div>

            <div id="um-progress" class="um-progress">
                <div class="um-progress-bar"><div id="um-progress-fill" class="um-progress-fill"></div></div>
                <div id="um-progress-text" class="um-progress-text">Preparing update...</div>
            </div>
            <div id="um-log" class="um-log"></div>

            <div id="um-actions" class="um-actions" style="display:none;"></div>
        </div>
    </div>

    <!-- Backups Card -->
    <div class="um-card">
        <div class="um-card-header">
            <span>Backups</span>
            <button class="um-btn um-btn-outline um-btn-sm" onclick="loadBackups()">Refresh</button>
        </div>
        <div class="um-card-body">
            <div id="um-backups-content">
                <div class="um-empty">Loading backups...</div>
            </div>
        </div>
    </div>
</div>

<script>
var BASE = ' . json_encode($base) . ';
var CSRF = ' . json_encode($csrfToken) . ';

function ajax(method, url, body, callback) {
    var xhr = new XMLHttpRequest();
    xhr.open(method, url, true);
    xhr.setRequestHeader("X-CSRFToken", CSRF);
    if (body) xhr.setRequestHeader("Content-Type", "application/json");
    xhr.onload = function() {
        try { callback(JSON.parse(xhr.responseText), null); }
        catch(e) { callback(null, xhr.responseText || "Parse error"); }
    };
    xhr.onerror = function() { callback(null, "Network error"); };
    xhr.send(body ? JSON.stringify(body) : null);
}

function $(id) { return document.getElementById(id); }

function setStatus(cls, html) {
    var el = $("um-status");
    el.className = "um-status-box " + cls;
    el.innerHTML = html;
}

function log(msg, type) {
    var logEl = $("um-log");
    logEl.style.display = "block";
    var span = document.createElement("div");
    span.className = type ? "log-" + type : "";
    span.textContent = msg;
    logEl.appendChild(span);
    logEl.scrollTop = logEl.scrollHeight;
}

function progress(pct, text) {
    $("um-progress").style.display = "block";
    $("um-progress-fill").style.width = pct + "%";
    $("um-progress-text").textContent = text;
}

var lastCheck = null;
var currentChannel = "stable";

function channelBadge(ch) {
    var labels = {stable:"Stable",rc:"RC",beta:"Beta",dev:"Dev"};
    return "<span class=\"um-channel-badge um-badge-" + ch + "\">" + (labels[ch]||ch) + "</span>";
}

function initChannels() {
    var radios = document.querySelectorAll("#um-channels input[name=channel]");
    radios.forEach(function(r) {
        r.addEventListener("change", function() {
            if (this.value === currentChannel) return;
            switchChannel(this.value);
        });
    });
}

function selectChannel(ch) {
    currentChannel = ch;
    var radios = document.querySelectorAll("#um-channels input[name=channel]");
    radios.forEach(function(r) { r.checked = (r.value === ch); });
}

function switchChannel(ch) {
    ajax("POST", BASE + "/update/channel", {channel: ch}, function(r, err) {
        if (err || !r || !r.success) {
            selectChannel(currentChannel);
            return;
        }
        currentChannel = ch;
        checkForUpdate();
    });
}

function checkForUpdate() {
    setStatus("um-status-checking", "<span class=\"um-spinner\"></span> <span>Checking for updates...</span>");
    $("um-details").style.display = "none";
    $("um-actions").style.display = "none";
    $("um-log").style.display = "none";
    $("um-log").innerHTML = "";
    $("um-progress").style.display = "none";

    ajax("GET", BASE + "/update/check", null, function(r, err) {
        if (err) {
            setStatus("um-status-error", "&#10060; Failed to check: " + err);
            return;
        }
        lastCheck = r;
        $("um-current").textContent = r.current_version || "?";

        // Update channel selector to match server state
        if (r.current_channel) selectChannel(r.current_channel);

        var chBadge = channelBadge(r.channel || currentChannel);

        if (r.error) {
            setStatus("um-status-error", "&#10060; " + r.error + " " + chBadge);
            $("um-actions").style.display = "flex";
            $("um-actions").innerHTML = "<button class=\"um-btn um-btn-outline\" onclick=\"checkForUpdate()\">Retry</button>";
            return;
        }

        if (r.available) {
            var preBadge = r.prerelease ? " <span class=\"um-channel-badge um-badge-" +
                (r.channel || "beta") + "\">" + (r.channel || "pre-release").toUpperCase() + "</span>" : "";
            setStatus("um-status-available",
                "&#128640; <strong>v" + r.latest_version + "</strong>" + preBadge + " is available!");

            var details = "<table>";
            details += "<tr><td>New Version</td><td><strong>v" + r.latest_version + "</strong>" + preBadge + "</td></tr>";
            details += "<tr><td>Channel</td><td>" + chBadge + "</td></tr>";
            if (r.release_name)
                details += "<tr><td>Release</td><td>" + escHtml(r.release_name) + "</td></tr>";
            if (r.published_at)
                details += "<tr><td>Published</td><td>" + new Date(r.published_at).toLocaleDateString() + "</td></tr>";
            details += "<tr><td>Installed</td><td>v" + r.current_version + "</td></tr>";
            details += "</table>";
            if (r.release_notes)
                details += "<div class=\"um-release-notes\">" + escHtml(r.release_notes) + "</div>";
            $("um-details").innerHTML = details;
            $("um-details").style.display = "block";

            $("um-actions").style.display = "flex";
            $("um-actions").innerHTML =
                "<button class=\"um-btn um-btn-primary\" id=\"um-update-btn\" onclick=\"applyUpdate()\">" +
                "&#128229; Update to v" + r.latest_version + "</button>" +
                (r.html_url ? "<a href=\"" + escHtml(r.html_url) + "\" target=\"_blank\" class=\"um-btn um-btn-outline\">View on GitHub</a>" : "");
        } else {
            setStatus("um-status-uptodate", "&#9989; Latest on " + chBadge + " (v" + r.current_version + ")");
        }
    });
}

function applyUpdate() {
    if (!lastCheck || !lastCheck.available) return;
    if (!confirm("This will:\n1. Backup current files + database\n2. Download v" + lastCheck.latest_version + " from GitHub\n3. Replace plugin files\n4. Run database migrations\n\nProceed?"))
        return;

    var btn = $("um-update-btn");
    if (btn) { btn.disabled = true; btn.innerHTML = "<span class=\"um-spinner\"></span> Updating..."; }

    $("um-log").style.display = "block";
    $("um-log").innerHTML = "";

    log("Starting update to v" + lastCheck.latest_version + "...", "info");
    progress(10, "Creating backup...");
    log("Creating backup of files and database...", "info");

    setTimeout(function() {
        progress(30, "Downloading release...");
        log("Downloading release from GitHub...", "info");
    }, 500);

    setTimeout(function() {
        progress(60, "Installing...");
    }, 1200);

    ajax("POST", BASE + "/update/apply", {}, function(r, err) {
        if (err) {
            progress(100, "Failed!");
            log("ERROR: " + err, "err");
            setStatus("um-status-error", "&#10060; Update failed: " + err);
            if (btn) { btn.disabled = false; btn.innerHTML = "Retry"; }
            return;
        }

        if (r.success) {
            progress(100, "Complete!");
            log("Backup created successfully", "ok");
            log("Files downloaded and extracted", "ok");
            log("Migrations run: " + (r.migrations_run || 0), "ok");
            log("Updated from v" + r.previous_version + " to v" + r.new_version, "ok");
            setStatus("um-status-uptodate", "&#9989; Successfully updated to <strong>v" + r.new_version + "</strong>!");
            $("um-current").textContent = r.new_version;
            $("um-actions").innerHTML =
                "<button class=\"um-btn um-btn-success\" disabled>&#9989; Updated!</button>" +
                "<button class=\"um-btn um-btn-outline\" onclick=\"location.reload()\">Refresh Page</button>";
            loadBackups();
        } else {
            progress(100, "Failed!");
            log("ERROR: " + (r.error || "Unknown error"), "err");
            setStatus("um-status-error", "&#10060; " + (r.error || "Update failed"));
            if (btn) { btn.disabled = false; btn.innerHTML = "Retry"; }
        }
    });
}

function loadBackups() {
    $("um-backups-content").innerHTML = "<div class=\"um-empty\">Loading...</div>";
    ajax("GET", BASE + "/update/backups", null, function(r, err) {
        if (err || !r) {
            $("um-backups-content").innerHTML = "<div class=\"um-empty\">Failed to load backups</div>";
            return;
        }
        var backups = r.backups || [];
        if (!backups.length) {
            $("um-backups-content").innerHTML = "<div class=\"um-empty\">No backups yet. Backups are created automatically before each update.</div>";
            return;
        }

        var html = "<ul class=\"um-backup-list\">";
        backups.forEach(function(b) {
            html += "<li><div class=\"um-backup-info\">" +
                "<span class=\"um-backup-name\">v" + escHtml(b.version) + "</span>" +
                "<span class=\"um-backup-meta\">" + escHtml(b.date) + " &middot; " +
                (b.tables ? b.tables.length + " tables" : "") + "</span>" +
                "</div>" +
                "<button class=\"um-btn um-btn-danger um-btn-sm\" onclick=\"rollback(\'" + escHtml(b.path) + "\')\">" +
                "Restore</button></li>";
        });
        html += "</ul>";
        $("um-backups-content").innerHTML = html;
    });
}

function rollback(path) {
    if (!confirm("This will restore the plugin to the backed-up version.\\nCurrent files and database tables will be replaced.\\n\\nProceed?"))
        return;

    setStatus("um-status-checking", "<span class=\"um-spinner\"></span> <span>Rolling back...</span>");
    log("Rolling back to backup...", "info");

    ajax("POST", BASE + "/update/rollback", {backup_path: path}, function(r, err) {
        if (err) {
            setStatus("um-status-error", "&#10060; Rollback failed: " + err);
            log("Rollback error: " + err, "err");
            return;
        }
        if (r.success) {
            setStatus("um-status-uptodate", "&#9989; Restored to <strong>v" + (r.restored_version || "?") + "</strong>");
            log("Rollback successful! Restored to v" + (r.restored_version || "?"), "ok");
            $("um-current").textContent = r.restored_version || "?";
            $("um-actions").innerHTML = "<button class=\"um-btn um-btn-outline\" onclick=\"location.reload()\">Refresh Page</button>";
            loadBackups();
        } else {
            setStatus("um-status-error", "&#10060; " + (r.error || "Rollback failed"));
            log("Rollback failed: " + (r.error || "Unknown"), "err");
        }
    });
}

function escHtml(s) {
    if (!s) return "";
    return String(s).replace(/&/g,"&amp;").replace(/</g,"&lt;").replace(/>/g,"&gt;").replace(/"/g,"&quot;");
}

// Init
initChannels();
checkForUpdate();
loadBackups();
</script>
</body>
</html>';
        exit;
    }

    function staffOnly() {
        global $thisstaff;
        if (!$thisstaff || !$thisstaff->isValid())
            Http::response(403, __('Access Denied'));
    }

    function adminOnly() {
        global $thisstaff;
        if (!$thisstaff || !$thisstaff->isValid())
            Http::response(403, __('Access Denied'));
        if (!$thisstaff->isAdmin())
            Http::response(403, __('Admin access required'));
    }
}
