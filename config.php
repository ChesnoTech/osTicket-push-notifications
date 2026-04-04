<?php
/**
 * Push Notifications Plugin - Configuration
 *
 * @author  ChesnoTech
 * @version 1.0.0
 */

require_once INCLUDE_DIR . 'class.forms.php';

class PushNotificationsConfig extends PluginConfig {

    function getOptions() {
        return array(
            'push_enabled' => new BooleanField(array(
                'label' => __('Enable Push Notifications'),
                'default' => true,
                'configuration' => array(
                    'desc' => __('Master switch for Web Push notifications'),
                ),
            )),
            'vapid_subject' => new TextboxField(array(
                'label' => __('VAPID Subject'),
                'required' => true,
                'default' => '',
                'hint' => __('Contact URL or email for VAPID identification (e.g. mailto:admin@example.com)'),
                'configuration' => array('size' => 60, 'length' => 255),
            )),
            'vapid_public_key' => new TextboxField(array(
                'label' => __('VAPID Public Key'),
                'required' => false,
                'default' => '',
                'hint' => __('Auto-generated. Share this with clients for push subscription.'),
                'configuration' => array('size' => 90, 'length' => 255),
            )),
            'vapid_private_key' => new TextboxField(array(
                'label' => __('VAPID Private Key'),
                'required' => false,
                'default' => '',
                'hint' => __('Auto-generated. Keep this secret.'),
                'configuration' => array('size' => 60, 'length' => 255),
            )),
            'generate_keys' => new BooleanField(array(
                'label' => __('Generate New VAPID Keys'),
                'default' => false,
                'configuration' => array(
                    'desc' => __('Check this box and save to generate a new VAPID keypair. WARNING: Existing subscriptions will stop working.'),
                ),
            )),
            'alert_new_ticket' => new BooleanField(array(
                'label' => __('New Ticket Alert'),
                'default' => true,
                'configuration' => array(
                    'desc' => __('Send push notification when a new ticket is created'),
                ),
            )),
            'alert_new_message' => new BooleanField(array(
                'label' => __('New Message Alert'),
                'default' => true,
                'configuration' => array(
                    'desc' => __('Send push notification when a new message/reply is posted'),
                ),
            )),
            'alert_assignment' => new BooleanField(array(
                'label' => __('Assignment Alert'),
                'default' => true,
                'configuration' => array(
                    'desc' => __('Send push notification when a ticket is assigned'),
                ),
            )),
            'alert_transfer' => new BooleanField(array(
                'label' => __('Transfer Alert'),
                'default' => true,
                'configuration' => array(
                    'desc' => __('Send push notification when a ticket is transferred'),
                ),
            )),
            'alert_overdue' => new BooleanField(array(
                'label' => __('Overdue Alert'),
                'default' => true,
                'configuration' => array(
                    'desc' => __('Send push notification when a ticket becomes overdue'),
                ),
            )),
            'notification_icon' => new TextboxField(array(
                'label' => __('Notification Icon URL'),
                'required' => false,
                'default' => '',
                'hint' => __('URL for the notification icon/logo (e.g. /logo.png or https://example.com/logo.png). Leave empty for osTicket default logo.'),
                'configuration' => array('size' => 80, 'length' => 500),
            )),
            'update_section' => new SectionBreakField(array(
                'label' => __('Auto-Update'),
                'hint' => sprintf(
                    '<a href="%sscp/ajax.php/push-notifications/update-manager" target="_blank" '
                    . 'style="color:#1a73e8;font-weight:600;text-decoration:none;">'
                    . '&#128640; Open Update Manager</a> &mdash; '
                    . 'Check for updates, install new versions, manage backups &amp; rollbacks.',
                    ROOT_PATH
                ),
            )),
            'update_channel' => new ChoiceField(array(
                'label' => __('Update Channel'),
                'default' => 'stable',
                'choices' => array(
                    'stable' => __('Stable') . ' — ' . __('Production-ready releases only'),
                    'rc'     => __('Release Candidate') . ' — ' . __('Final testing before stable'),
                    'beta'   => __('Beta') . ' — ' . __('New features, may have bugs'),
                    'dev'    => __('Dev') . ' — ' . __('Latest development builds'),
                ),
                'hint' => __('Which release channel to check for updates. Stable is recommended for production.'),
            )),
            // Auto-update: these are stored directly via $config->set() — not shown in UI:
            // last_cron_check, db_schema_version, last_update_check, update_available
        );
    }

    function pre_save(&$config, &$errors) {

        // Validate VAPID subject
        $subject = trim($config['vapid_subject'] ?? '');
        if ($subject && !preg_match('/^(mailto:|https:\/\/)/', $subject)) {
            $errors['err'] = __('VAPID Subject must start with mailto: or https://');
            return false;
        }

        // Generate VAPID keys if requested or if none exist
        $generateKeys = !empty($config['generate_keys']);
        $hasKeys = !empty($config['vapid_public_key']) && !empty($config['vapid_private_key']);

        if ($generateKeys || !$hasKeys) {
            $keys = $this->generateVapidKeys();
            if (!$keys) {
                $errors['err'] = __('Failed to generate VAPID keys. Ensure PHP openssl extension supports EC P-256.');
                return false;
            }
            $config['vapid_public_key'] = $keys['public'];
            $config['vapid_private_key'] = $keys['private'];
        }

        // Always reset the generate flag
        $config['generate_keys'] = 0;

        // Create the push subscription table if it doesn't exist
        $this->ensureTable();

        return true;
    }

    /**
     * Generate VAPID keypair using openssl EC P-256 curve.
     * Returns array('public' => base64url, 'private' => base64url) or false.
     */
    private function generateVapidKeys() {
        $key = @openssl_pkey_new(array(
            'curve_name' => 'prime256v1',
            'private_key_type' => OPENSSL_KEYTYPE_EC,
        ));
        if (!$key)
            return false;

        $details = openssl_pkey_get_details($key);
        if (!$details || !isset($details['ec']))
            return false;

        // Public key: uncompressed point = 0x04 || x (32 bytes) || y (32 bytes)
        $x = str_pad($details['ec']['x'], 32, "\0", STR_PAD_LEFT);
        $y = str_pad($details['ec']['y'], 32, "\0", STR_PAD_LEFT);
        $publicKey = "\x04" . $x . $y;

        // Private key: scalar d (32 bytes)
        $privateKey = str_pad($details['ec']['d'], 32, "\0", STR_PAD_LEFT);

        return array(
            'public'  => self::base64UrlEncode($publicKey),
            'private' => self::base64UrlEncode($privateKey),
        );
    }

    /**
     * Create the push subscription and preferences tables if they don't exist.
     */
    private function ensureTable() {
        $prefix = TABLE_PREFIX;
        $sql = "CREATE TABLE IF NOT EXISTS `{$prefix}push_subscription` (
            `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
            `staff_id` int(11) unsigned NOT NULL,
            `endpoint` varchar(500) NOT NULL,
            `p256dh_key` varchar(100) NOT NULL,
            `auth_key` varchar(50) NOT NULL,
            `user_agent` varchar(255) DEFAULT NULL,
            `encoding` varchar(30) NOT NULL DEFAULT 'aes128gcm',
            `created` datetime NOT NULL,
            `updated` datetime NOT NULL,
            PRIMARY KEY (`id`),
            KEY `staff_id` (`staff_id`),
            UNIQUE KEY `endpoint` (`endpoint`(191))
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
        db_query($sql);

        $sql = "CREATE TABLE IF NOT EXISTS `{$prefix}push_preferences` (
            `staff_id` int(11) unsigned NOT NULL,
            `event_new_ticket` tinyint(1) NOT NULL DEFAULT 1,
            `event_new_message` tinyint(1) NOT NULL DEFAULT 1,
            `event_assignment` tinyint(1) NOT NULL DEFAULT 1,
            `event_transfer` tinyint(1) NOT NULL DEFAULT 1,
            `event_overdue` tinyint(1) NOT NULL DEFAULT 1,
            `quiet_start` varchar(5) DEFAULT NULL,
            `quiet_end` varchar(5) DEFAULT NULL,
            `dept_ids` text DEFAULT NULL,
            `updated` datetime NOT NULL,
            PRIMARY KEY (`staff_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
        db_query($sql);
    }

    static function base64UrlEncode($data) {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}
