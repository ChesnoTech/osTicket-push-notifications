<?php
/**
 * Push Notifications Plugin - Notification Dispatcher
 *
 * Mirrors the email alert recipient logic from class.ticket.php and sends
 * Web Push notifications to the same recipients, respecting admin config
 * toggles and staff alert preferences.
 *
 * @author  ChesnoTech
 * @version 1.0.0
 */

class PushDispatcher {

    /**
     * Signal handler: ticket.created
     * Mirrors onNewTicket() alert logic from class.ticket.php:1641
     */
    static function onTicketCreated($ticket) {
        if (!$ticket || !($ticket instanceof Ticket))
            return;

        $config = self::getPluginConfig();
        if (!$config || !$config->get('push_enabled') || !$config->get('alert_new_ticket'))
            return;

        global $cfg;
        if (!$cfg || !$cfg->alertONNewTicket())
            return;

        $dept = $ticket->getDept();
        if (!$dept || !$dept->getNumMembersForAlerts())
            return;

        $recipients = array();

        // Department members (only if ticket is NOT assigned)
        $manager = $dept->getManager();
        if ($cfg->alertDeptMembersONNewTicket() && !$ticket->isAssigned()) {
            if ($members = $dept->getMembersForAlerts()) {
                foreach ($members as $M) {
                    if ($M != $manager)
                        $recipients[] = $M;
                }
            }
        }

        // Department manager
        if ($cfg->alertDeptManagerONNewTicket() && $manager)
            $recipients[] = $manager;

        // Account manager
        if ($cfg->alertAcctManagerONNewTicket()
            && ($owner = $ticket->getOwner())
            && ($org = $owner->getOrganization())
            && ($acctManager = $org->getAccountManager())
        ) {
            if ($acctManager instanceof Team)
                $recipients = array_merge($recipients, $acctManager->getMembersForAlerts());
            else
                $recipients[] = $acctManager;
        }

        $deptId = $dept ? $dept->getId() : 0;
        self::dispatchToRecipients($recipients, array(
            'event'         => 'new_ticket',
            'title'         => sprintf('New Ticket #%s', $ticket->getNumber()),
            'body'          => $ticket->getSubject(),
            'ticket_id'     => $ticket->getId(),
            'ticket_number' => $ticket->getNumber(),
        ), 0, $deptId);
    }

    /**
     * Signal handler: object.created
     * Mirrors postMessage() alert logic from class.ticket.php:3210
     * Fires for type='message' (new customer reply)
     */
    static function onObjectCreated($ticket, &$data) {
        if (!$ticket || !($ticket instanceof Ticket))
            return;
        if (!isset($data['type']) || $data['type'] !== 'message')
            return;

        $config = self::getPluginConfig();
        if (!$config || !$config->get('push_enabled') || !$config->get('alert_new_message'))
            return;

        global $cfg;
        if (!$cfg || !$cfg->alertONNewMessage())
            return;

        $dept = $ticket->getDept();
        if (!$dept)
            return;

        $recipients = array();

        // Last respondent
        if ($cfg->alertLastRespondentONNewMessage() && ($lr = $ticket->getLastRespondent()))
            $recipients[] = $lr;

        // Assigned staff or team
        if ($cfg->alertAssignedONNewMessage() && $ticket->isAssigned()) {
            if ($staff = $ticket->getStaff())
                $recipients[] = $staff;
            elseif ($team = $ticket->getTeam())
                $recipients = array_merge($recipients, $team->getMembersForAlerts());
        }

        // Department manager
        if ($cfg->alertDeptManagerONNewMessage()
            && $dept
            && ($manager = $dept->getManager())
        ) {
            $recipients[] = $manager;
        }

        // Account manager
        if ($cfg->alertAcctManagerONNewMessage()
            && ($owner = $ticket->getOwner())
            && ($org = $owner->getOrganization())
            && ($acctManager = $org->getAccountManager())
        ) {
            if ($acctManager instanceof Team)
                $recipients = array_merge($recipients, $acctManager->getMembersForAlerts());
            else
                $recipients[] = $acctManager;
        }

        // Exclude the poster
        $excludeStaffId = 0;
        if (isset($data['uid']) && $data['uid'])
            $excludeStaffId = $data['uid'];

        $deptId = $dept ? $dept->getId() : 0;
        self::dispatchToRecipients($recipients, array(
            'event'         => 'new_message',
            'title'         => sprintf('New Reply on #%s', $ticket->getNumber()),
            'body'          => $ticket->getSubject(),
            'ticket_id'     => $ticket->getId(),
            'ticket_number' => $ticket->getNumber(),
        ), $excludeStaffId, $deptId);
    }

    /**
     * Signal handler: object.edited
     * Handles assignment events (type='assigned') from class.ticket.php:2812,2902
     */
    static function onObjectEdited($ticket, &$data) {
        if (!$ticket || !($ticket instanceof Ticket))
            return;
        if (!isset($data['type']) || $data['type'] !== 'assigned')
            return;

        $config = self::getPluginConfig();
        if (!$config || !$config->get('push_enabled') || !$config->get('alert_assignment'))
            return;

        global $cfg;
        if (!$cfg || !$cfg->alertONAssignment())
            return;

        $dept = $ticket->getDept();
        if (!$dept || !$dept->getNumMembersForAlerts())
            return;

        $recipients = array();

        // Assigned to staff
        if (isset($data['staff']) || isset($data['claim'])) {
            if ($cfg->alertStaffONAssignment() && ($staff = $ticket->getStaff()))
                $recipients[] = $staff;
        }

        // Assigned to team
        if (isset($data['team'])) {
            $team = $ticket->getTeam();
            if ($team && $team->alertsEnabled()) {
                if ($cfg->alertTeamMembersONAssignment()
                    && ($members = $team->getMembersForAlerts())
                ) {
                    $recipients = array_merge($recipients, $members);
                } elseif ($cfg->alertTeamLeadONAssignment()
                    && ($lead = $team->getTeamLead())
                ) {
                    $recipients[] = $lead;
                }
            }
        }

        // Exclude the assigner (current staff)
        global $thisstaff;
        $excludeStaffId = $thisstaff ? $thisstaff->getId() : 0;

        $deptId = $dept ? $dept->getId() : 0;
        self::dispatchToRecipients($recipients, array(
            'event'         => 'assignment',
            'title'         => sprintf('Ticket #%s Assigned to You', $ticket->getNumber()),
            'body'          => $ticket->getSubject(),
            'ticket_id'     => $ticket->getId(),
            'ticket_number' => $ticket->getNumber(),
        ), $excludeStaffId, $deptId);
    }

    /**
     * Signal handler: model.updated
     * Detects ticket transfers by checking if dept_id changed.
     * Fired from class.ticket.php via save() → class.orm.php:684
     */
    static function onModelUpdated($model, $data = null) {
        if (!$model || !($model instanceof Ticket))
            return;

        // $data contains the dirty fields (changed values)
        if (!is_array($data) || !isset($data['dept_id']))
            return;

        $config = self::getPluginConfig();
        if (!$config || !$config->get('push_enabled') || !$config->get('alert_transfer'))
            return;

        global $cfg;
        if (!$cfg || !$cfg->alertONTransfer())
            return;

        $dept = $model->getDept(); // New (target) department
        if (!$dept || !$dept->getNumMembersForAlerts())
            return;

        $recipients = array();

        // Assigned staff or team in the new department
        if ($model->isAssigned() && $cfg->alertAssignedONTransfer()) {
            if ($model->getStaffId())
                $recipients[] = $model->getStaff();
            elseif ($model->getTeamId()
                && ($team = $model->getTeam())
                && ($members = $team->getMembersForAlerts())
            ) {
                $recipients = array_merge($recipients, $members);
            }
        } elseif ($cfg->alertDeptMembersONTransfer() && !$model->isAssigned()) {
            foreach ($dept->getMembersForAlerts() as $M)
                $recipients[] = $M;
        }

        // Department manager of the new department
        if ($cfg->alertDeptManagerONTransfer()
            && $dept
            && ($manager = $dept->getManager())
        ) {
            $recipients[] = $manager;
        }

        // Exclude the staff who initiated the transfer
        global $thisstaff;
        $excludeStaffId = $thisstaff ? $thisstaff->getId() : 0;

        $deptId = $dept ? $dept->getId() : 0;
        self::dispatchToRecipients($recipients, array(
            'event'         => 'transfer',
            'title'         => sprintf('Ticket #%s Transferred', $model->getNumber()),
            'body'          => $model->getSubject(),
            'ticket_id'     => $model->getId(),
            'ticket_number' => $model->getNumber(),
        ), $excludeStaffId, $deptId);
    }

    /**
     * Signal handler: cron
     * Batch-processes overdue ticket events since last check.
     */
    static function onCron($null = null, &$data = null) {
        $config = self::getPluginConfig();
        if (!$config || !$config->get('push_enabled') || !$config->get('alert_overdue'))
            return;

        global $cfg;
        if (!$cfg || !$cfg->alertONOverdueTicket())
            return;

        $lastCheck = $config->get('last_cron_check');
        if (!$lastCheck)
            $lastCheck = date('Y-m-d H:i:s', strtotime('-5 minutes'));

        $prefix = TABLE_PREFIX;

        // Query thread_event for 'overdue' events since last check
        $res = db_query(sprintf(
            "SELECT DISTINCT t.object_id AS ticket_id
             FROM `{$prefix}thread_event` e
             JOIN `{$prefix}thread` t ON (e.thread_id = t.id AND t.object_type = 'T')
             WHERE e.name = 'overdue'
               AND e.timestamp > %s
             ORDER BY e.timestamp ASC",
            db_input($lastCheck)));

        if ($res) {
            while ($row = db_fetch_array($res)) {
                $ticket = Ticket::lookup($row['ticket_id']);
                if (!$ticket)
                    continue;
                self::processOverdue($ticket);
            }
        }

        // Update last cron check timestamp
        $config->set('last_cron_check', date('Y-m-d H:i:s'));

        // Periodic subscription cleanup (~10% of cron runs)
        if (mt_rand(1, 10) === 5)
            self::cleanupSubscriptions();
    }

    /**
     * Process overdue alert for a single ticket.
     * Mirrors onOverdue() from class.ticket.php:2112
     */
    private static function processOverdue($ticket) {
        global $cfg;

        $dept = $ticket->getDept();
        if (!$dept || !$dept->getNumMembersForAlerts())
            return;

        $recipients = array();

        // Assigned staff or team
        if ($ticket->isAssigned() && $cfg->alertAssignedONOverdueTicket()) {
            if ($ticket->getStaffId())
                $recipients[] = $ticket->getStaff();
            elseif ($ticket->getTeamId()
                && ($team = $ticket->getTeam())
                && ($members = $team->getMembersForAlerts())
            ) {
                $recipients = array_merge($recipients, $members);
            }
        } elseif ($cfg->alertDeptMembersONOverdueTicket() && !$ticket->isAssigned()) {
            foreach ($dept->getMembersForAlerts() as $M)
                $recipients[] = $M;
        }

        // Department manager
        if ($cfg->alertDeptManagerONOverdueTicket()
            && $dept
            && ($manager = $dept->getManager())
        ) {
            $recipients[] = $manager;
        }

        $deptId = $dept ? $dept->getId() : 0;
        self::dispatchToRecipients($recipients, array(
            'event'         => 'overdue',
            'title'         => sprintf('Ticket #%s is Overdue', $ticket->getNumber()),
            'body'          => $ticket->getSubject(),
            'ticket_id'     => $ticket->getId(),
            'ticket_number' => $ticket->getNumber(),
        ), 0, $deptId);
    }

    /**
     * Core dispatch: filter recipients by preferences, look up subscriptions, send pushes.
     *
     * @param array  $recipients      Staff objects
     * @param array  $payload         Notification payload with 'event', 'title', 'body', etc.
     * @param int    $excludeStaffId  Staff ID to exclude (e.g., the actor)
     * @param int    $deptId          Department ID for dept-based filtering (0 = no filter)
     */
    private static function dispatchToRecipients($recipients, $payload, $excludeStaffId = 0, $deptId = 0) {
        if (empty($recipients))
            return;

        $seen = array();
        $staffIds = array();

        foreach ($recipients as $staff) {
            if (!is_object($staff) || !$staff->isAvailable())
                continue;
            if ($excludeStaffId && $staff->getId() == $excludeStaffId)
                continue;
            $id = $staff->getId();
            if (isset($seen[$id]))
                continue;
            $seen[$id] = true;
            $staffIds[] = (int) $id;
        }

        if (empty($staffIds))
            return;

        // Load preferences for all candidate staff members
        $prefix = TABLE_PREFIX;
        $idList = implode(',', $staffIds);
        $prefs = self::loadPreferences($staffIds);

        // Map event name to preference column
        $eventColumn = self::eventToColumn($payload['event']);

        // Filter staff by their preferences
        $allowedStaffIds = array();
        foreach ($staffIds as $sid) {
            if (!self::staffAllowed($sid, $eventColumn, $deptId, $prefs))
                continue;
            $allowedStaffIds[] = $sid;
        }

        if (empty($allowedStaffIds))
            return;

        // Look up push subscriptions for allowed staff members
        $allowedList = implode(',', $allowedStaffIds);
        $res = db_query(
            "SELECT id, staff_id, endpoint, p256dh_key, auth_key, encoding
             FROM `{$prefix}push_subscription`
             WHERE staff_id IN ({$allowedList})");

        if (!$res)
            return;

        $subscriptions = array();
        while ($row = db_fetch_array($res))
            $subscriptions[] = $row;

        if (empty($subscriptions))
            return;

        // Build the notification JSON payload
        $config = self::getPluginConfig();
        $iconUrl = ($config && $config->get('notification_icon'))
            ? $config->get('notification_icon')
            : '/scp/images/ost-logo.png';

        $jsonPayload = json_encode(array(
            'title' => $payload['title'],
            'body'  => $payload['body'],
            'icon'  => $iconUrl,
            'badge' => $iconUrl,
            'tag'   => $payload['event'] . '-' . $payload['ticket_id'],
            'data'  => array(
                'url'          => '/scp/tickets.php?id=' . $payload['ticket_id'],
                'ticketNumber' => $payload['ticket_number'],
            ),
        ));

        // Get the WebPush sender
        $webpush = self::getWebPush();
        if (!$webpush)
            return;

        $expiredIds = array();
        foreach ($subscriptions as $sub) {
            $result = $webpush->send(
                $sub['endpoint'],
                $sub['p256dh_key'],
                $sub['auth_key'],
                $jsonPayload,
                $sub['encoding'] ?: 'aes128gcm'
            );

            // Mark expired subscriptions for deletion
            if ($result === 'gone')
                $expiredIds[] = (int) $sub['id'];
        }

        // Remove expired subscriptions
        if (!empty($expiredIds)) {
            $expiredList = implode(',', $expiredIds);
            db_query("DELETE FROM `{$prefix}push_subscription` WHERE id IN ({$expiredList})");
        }
    }

    /**
     * Load push preferences for a list of staff IDs.
     * Returns array keyed by staff_id.
     */
    private static function loadPreferences($staffIds) {
        $prefix = TABLE_PREFIX;
        $idList = implode(',', array_map('intval', $staffIds));
        $prefs = array();

        $res = db_query(
            "SELECT * FROM `{$prefix}push_preferences`
             WHERE staff_id IN ({$idList})");

        if ($res) {
            while ($row = db_fetch_array($res))
                $prefs[(int) $row['staff_id']] = $row;
        }

        return $prefs;
    }

    /**
     * Map event name to preference column name.
     */
    private static function eventToColumn($event) {
        $map = array(
            'new_ticket'  => 'event_new_ticket',
            'new_message' => 'event_new_message',
            'assignment'  => 'event_assignment',
            'transfer'    => 'event_transfer',
            'overdue'     => 'event_overdue',
        );
        return isset($map[$event]) ? $map[$event] : '';
    }

    /**
     * Check if a staff member should receive this push based on their preferences.
     *
     * @param int    $staffId     Staff ID
     * @param string $eventColumn Preference column for this event type
     * @param int    $deptId      Department ID of the ticket (0 = skip dept check)
     * @param array  $prefs       Preferences array keyed by staff_id
     * @return bool
     */
    private static function staffAllowed($staffId, $eventColumn, $deptId, $prefs) {
        // No preferences row = all defaults (everything enabled, no quiet hours)
        if (!isset($prefs[$staffId]))
            return true;

        $p = $prefs[$staffId];

        // Check event toggle
        if ($eventColumn && isset($p[$eventColumn]) && !$p[$eventColumn])
            return false;

        // Check department filter (empty = all departments allowed)
        if ($deptId && !empty($p['dept_ids'])) {
            $deptIds = json_decode($p['dept_ids'], true);
            if (is_array($deptIds) && !empty($deptIds)) {
                $found = false;
                foreach ($deptIds as $did) {
                    if ((int) $did === (int) $deptId) {
                        $found = true;
                        break;
                    }
                }
                if (!$found)
                    return false;
            }
        }

        // Check quiet hours
        if (!empty($p['quiet_start']) && !empty($p['quiet_end'])) {
            $now = date('H:i');
            $start = $p['quiet_start'];
            $end = $p['quiet_end'];

            if ($start <= $end) {
                // Same-day range: e.g. 09:00-17:00
                if ($now >= $start && $now < $end)
                    return false;
            } else {
                // Overnight range: e.g. 22:00-08:00
                if ($now >= $start || $now < $end)
                    return false;
            }
        }

        return true;
    }

    /**
     * Send a test notification to a specific staff member.
     */
    static function sendTestNotification($staffId) {
        $prefix = TABLE_PREFIX;
        $res = db_query(sprintf(
            "SELECT id, endpoint, p256dh_key, auth_key, encoding
             FROM `{$prefix}push_subscription`
             WHERE staff_id = %d",
            db_input($staffId)));

        if (!$res)
            return array('success' => false, 'error' => 'Database error');

        $subscriptions = array();
        while ($row = db_fetch_array($res))
            $subscriptions[] = $row;

        if (empty($subscriptions))
            return array('success' => false, 'error' => 'No push subscriptions found. Click the bell icon to subscribe first.');

        $webpush = self::getWebPush();
        if (!$webpush)
            return array('success' => false, 'error' => 'VAPID keys not configured');

        $config = self::getPluginConfig();
        $iconUrl = ($config && $config->get('notification_icon'))
            ? $config->get('notification_icon')
            : '/scp/images/ost-logo.png';

        $payload = json_encode(array(
            'title' => 'osTicket Push Test',
            'body'  => 'Push notifications are working!',
            'icon'  => $iconUrl,
            'badge' => $iconUrl,
            'tag'   => 'test-' . time(),
            'data'  => array(
                'url' => '/scp/',
            ),
        ));

        $sent = 0;
        $failed = 0;
        $errors = array();
        foreach ($subscriptions as $sub) {
            $result = $webpush->send(
                $sub['endpoint'],
                $sub['p256dh_key'],
                $sub['auth_key'],
                $payload,
                $sub['encoding'] ?: 'aes128gcm'
            );
            if ($result === true) {
                $sent++;
            } else {
                $failed++;
                $errors[] = $result;
                if ($result === 'gone') {
                    db_query(sprintf(
                        "DELETE FROM `{$prefix}push_subscription` WHERE id = %d",
                        db_input($sub['id'])));
                }
            }
        }

        return array(
            'success' => $sent > 0,
            'sent' => $sent,
            'failed' => $failed,
            'errors' => $errors,
        );
    }

    /**
     * Clean up subscriptions for inactive/deleted staff.
     */
    private static function cleanupSubscriptions() {
        $prefix = TABLE_PREFIX;
        db_query(
            "DELETE ps FROM `{$prefix}push_subscription` ps
             LEFT JOIN `{$prefix}staff` s ON (ps.staff_id = s.staff_id)
             WHERE s.staff_id IS NULL OR s.isactive = 0");
    }

    /**
     * Get the WebPush sender instance.
     */
    private static function getWebPush() {
        static $instance = null;
        if ($instance !== null)
            return $instance ?: null;

        $config = self::getPluginConfig();
        if (!$config) {
            $instance = false;
            return null;
        }

        $pub  = $config->get('vapid_public_key');
        $priv = $config->get('vapid_private_key');
        $subj = $config->get('vapid_subject');

        if (!$pub || !$priv || !$subj) {
            $instance = false;
            return null;
        }

        require_once dirname(__FILE__) . '/class.WebPush.php';
        $instance = new WebPush($pub, $priv, $subj);
        return $instance;
    }

    /**
     * Get the plugin configuration.
     */
    static function getPluginConfig() {
        require_once dirname(__FILE__) . '/class.PushNotificationsPlugin.php';
        return PushNotificationsPlugin::getActiveConfig();
    }
}
