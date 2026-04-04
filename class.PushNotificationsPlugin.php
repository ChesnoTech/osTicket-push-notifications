<?php
/**
 * Push Notifications Plugin - Main Class
 *
 * @author  ChesnoTech
 * @version 1.0.0
 */

require_once 'config.php';

class PushNotificationsPlugin extends Plugin {
    var $config_class = 'PushNotificationsConfig';

    static private $bootstrapped = false;

    function isMultiInstance() {
        return false;
    }

    function bootstrap() {
        if (self::$bootstrapped)
            return;
        self::$bootstrapped = true;

        $pluginDir = dirname(__FILE__) . '/';

        // Signal hooks for push dispatch (all contexts: staff, API, cron)
        require_once $pluginDir . 'class.PushDispatcher.php';
        Signal::connect('ticket.created', array('PushDispatcher', 'onTicketCreated'));
        Signal::connect('object.created', array('PushDispatcher', 'onObjectCreated'));
        Signal::connect('object.edited', array('PushDispatcher', 'onObjectEdited'));
        Signal::connect('model.updated', array('PushDispatcher', 'onModelUpdated'));
        Signal::connect('cron', array('PushDispatcher', 'onCron'));

        // AJAX routes and asset injection (staff panel only)
        if (defined('STAFFINC_DIR')) {
            Signal::connect('ajax.scp', array('PushNotificationsPlugin', 'registerAjaxRoutes'));
            ob_start(array('PushNotificationsPlugin', 'injectAssets'));
        }
    }

    static function bootstrapStatic() {
        if (self::$bootstrapped)
            return;
        self::$bootstrapped = true;

        $pluginDir = dirname(__FILE__) . '/';

        require_once $pluginDir . 'class.PushDispatcher.php';
        Signal::connect('ticket.created', array('PushDispatcher', 'onTicketCreated'));
        Signal::connect('object.created', array('PushDispatcher', 'onObjectCreated'));
        Signal::connect('object.edited', array('PushDispatcher', 'onObjectEdited'));
        Signal::connect('model.updated', array('PushDispatcher', 'onModelUpdated'));
        Signal::connect('cron', array('PushDispatcher', 'onCron'));

        if (defined('STAFFINC_DIR')) {
            Signal::connect('ajax.scp', array('PushNotificationsPlugin', 'registerAjaxRoutes'));
            ob_start(array('PushNotificationsPlugin', 'injectAssets'));
        }
    }

    static function registerAjaxRoutes($dispatcher) {
        $dir = INCLUDE_DIR . 'plugins/push-notifications/';
        $dispatcher->append(
            url('^/push-notifications/', patterns(
                $dir . 'class.PushNotificationsAjax.php:PushNotificationsAjax',
                url_get('^status$', 'getStatus'),
                url_post('^subscribe$', 'subscribe'),
                url_post('^unsubscribe$', 'unsubscribe'),
                url_get('^sw\\.js$', 'serveServiceWorker'),
                url_get('^preferences$', 'getPreferences'),
                url_post('^preferences$', 'savePreferences'),
                url_get('^assets/js$', 'serveJs'),
                url_get('^assets/css$', 'serveCss'),
                url_get('^test$', 'sendTest'),
                url_get('^update/check$', 'checkUpdate'),
                url_post('^update/apply$', 'applyUpdate'),
                url_post('^update/rollback$', 'rollbackUpdate'),
                url_post('^update/channel$', 'setChannel'),
                url_get('^update/backups$', 'listBackups'),
                url_get('^update-manager$', 'serveUpdateManager')
            ))
        );
    }

    static function injectAssets($buffer) {
        // Skip during PJAX requests
        if (!empty($_SERVER['HTTP_X_PJAX']))
            return $buffer;

        // Skip if not an HTML page (AJAX asset responses, JSON, etc.)
        if (strpos($buffer, '</head>') === false
                || strpos($buffer, '</body>') === false)
            return $buffer;

        // Check if plugin is configured with VAPID keys
        try {
            $config = self::getActiveConfig();
        } catch (\Throwable $e) {
            return $buffer;
        }
        if (!$config
            || !$config->get('push_enabled')
            || !$config->get('vapid_public_key')
        ) {
            return $buffer;
        }

        $base = ROOT_PATH . 'scp/ajax.php/push-notifications';
        $dir = dirname(__FILE__) . '/assets/';
        $v = max(
            @filemtime($dir . 'push-notifications.js'),
            @filemtime($dir . 'push-notifications.css')
        ) ?: time();

        $css = sprintf(
            '<link rel="stylesheet" type="text/css" href="%s/assets/css?v=%s">',
            $base, $v);

        // Inline config for the client JS
        global $ost;
        $csrfToken = $ost ? $ost->getCSRFToken() : '';
        $vapidPublicKey = $config->get('vapid_public_key');

        // Translatable UI strings for the client JS
        // Use strings that exist in osTicket's translation catalog where possible.
        // For custom phrases, compose from translated words or use sprintf(__()).
        $strings = json_encode(array(
            'pushNotifications'    => __('Alerts and Notices'),
            'enabled'              => __('Enabled'),
            'disabled'             => __('Disabled'),
            'notifPreferences'     => __('Alerts and Notices'),
            'prefTitle'            => __('Alerts and Notices'),
            'prefClose'            => __('Close'),
            'eventTypes'           => __('Alerts'),
            'eventTypesHint'       => '',
            'newTicket'            => __('New Ticket Alert'),
            'newMessage'           => __('New Message Alert'),
            'ticketAssignment'     => __('Assignment Alert'),
            'ticketTransfer'       => __('Ticket Transfer Alert'),
            'overdueTicket'        => __('Ticket Overdue Alerts'),
            'departments'          => __('Departments'),
            'departmentsHint'      => '',
            'noDepartments'        => __('Departments'),
            'quietHours'           => __('Schedule'),
            'quietHoursHint'       => '',
            'from'                 => __('From'),
            'to'                   => __('To'),
            'clear'                => __('Reset'),
            'cancel'               => __('Cancel'),
            'save'                 => __('Save'),
            'saving'               => __('Save') . '...',
            'loading'              => __('Loading') . '...',
            'loadFailed'           => __('Error'),
            'prefsSaved'           => __('Updated'),
            'prefsSaveFailed'      => __('Error'),
            'pushEnabled'          => __('Enabled'),
            'pushDisabled'         => __('Disabled'),
            'pushBlocked'          => __('Disabled'),
            'pushFailed'           => __('Error'),
            'iosHint'              => __('Settings'),
        ));

        $inlineConfig = '<script type="text/javascript">'
            . 'window.__PUSH_CONFIG={'
            . 'vapidPublicKey:' . json_encode($vapidPublicKey) . ','
            . 'swUrl:' . json_encode($base . '/sw.js') . ','
            . 'subscribeUrl:' . json_encode($base . '/subscribe') . ','
            . 'unsubscribeUrl:' . json_encode($base . '/unsubscribe') . ','
            . 'statusUrl:' . json_encode($base . '/status') . ','
            . 'preferencesUrl:' . json_encode($base . '/preferences') . ','
            . 'csrfToken:' . json_encode($csrfToken) . ','
            . 'strings:' . $strings
            . '};</script>';

        $js = sprintf(
            '<script type="text/javascript" src="%s/assets/js?v=%s"></script>',
            $base, $v);

        $buffer = str_replace('</head>', $css . "\n</head>", $buffer);
        $buffer = str_replace('</body>', $inlineConfig . "\n" . $js . "\n</body>", $buffer);

        // Inject admin update banner if an update is available
        global $thisstaff;
        if ($thisstaff && $thisstaff->isAdmin()) {
            $updateJson = $config->get('update_available');
            if ($updateJson) {
                $update = json_decode($updateJson, true);
                if (is_array($update) && !empty($update['version'])) {
                    $updateBanner = self::buildUpdateBanner($update, $base);
                    $buffer = str_replace('</body>', $updateBanner . "\n</body>", $buffer);
                }
            }
        }

        return $buffer;
    }

    /**
     * Build the admin update notification banner HTML + JS.
     */
    static function buildUpdateBanner($update, $baseUrl) {
        $version = htmlspecialchars($update['version']);
        $releaseUrl = htmlspecialchars($update['url'] ?? '');
        $channel = htmlspecialchars($update['channel'] ?? 'stable');
        $channelLabel = $channel !== 'stable'
            ? ' <span style="background:rgba(255,255,255,.2);padding:1px 6px;border-radius:3px;font-size:10px;text-transform:uppercase;">' . $channel . '</span>'
            : '';

        return <<<HTML
<div id="push-update-banner" style="display:none;position:fixed;bottom:20px;right:20px;z-index:99999;
    background:#1a73e8;color:#fff;padding:14px 20px;border-radius:8px;box-shadow:0 4px 16px rgba(0,0,0,.3);
    font-size:13px;max-width:380px;font-family:-apple-system,BlinkMacSystemFont,sans-serif;">
    <div style="display:flex;align-items:center;gap:10px;">
        <div style="flex:1;">
            <strong>Push Notifications v{$version}</strong>{$channelLabel} is available!
            <div style="margin-top:4px;opacity:.85;font-size:12px;">
                <a href="{$releaseUrl}" target="_blank" style="color:#fff;text-decoration:underline;">Release notes</a>
            </div>
        </div>
        <div style="display:flex;gap:6px;">
            <button onclick="pushApplyUpdate()" id="push-update-btn"
                style="background:#fff;color:#1a73e8;border:none;padding:6px 14px;border-radius:4px;
                cursor:pointer;font-size:12px;font-weight:600;">Update</button>
            <button onclick="document.getElementById('push-update-banner').style.display='none'"
                style="background:transparent;color:#fff;border:1px solid rgba(255,255,255,.4);
                padding:6px 10px;border-radius:4px;cursor:pointer;font-size:12px;">Dismiss</button>
        </div>
    </div>
</div>
<script type="text/javascript">
(function(){
    var banner = document.getElementById('push-update-banner');
    if (banner) banner.style.display = 'block';
    window.pushApplyUpdate = function() {
        var btn = document.getElementById('push-update-btn');
        if (!btn) return;
        btn.textContent = 'Updating...';
        btn.disabled = true;
        var csrf = (window.__PUSH_CONFIG && window.__PUSH_CONFIG.csrfToken) || '';
        var xhr = new XMLHttpRequest();
        xhr.open('POST', '{$baseUrl}/update/apply', true);
        xhr.setRequestHeader('Content-Type', 'application/json');
        xhr.setRequestHeader('X-CSRFToken', csrf);
        xhr.onload = function() {
            try {
                var r = JSON.parse(xhr.responseText);
                if (r.success) {
                    btn.textContent = 'Done!';
                    btn.style.background = '#34a853';
                    btn.style.color = '#fff';
                    banner.querySelector('strong').textContent =
                        'Updated to v' + r.new_version;
                    setTimeout(function(){ location.reload(); }, 2000);
                } else {
                    btn.textContent = 'Failed';
                    btn.style.background = '#ea4335';
                    btn.style.color = '#fff';
                    alert('Update failed: ' + (r.error || 'Unknown error'));
                    setTimeout(function(){ btn.textContent='Retry'; btn.disabled=false;
                        btn.style.background='#fff'; btn.style.color='#1a73e8'; }, 3000);
                }
            } catch(e) {
                btn.textContent = 'Error';
                alert('Update error: ' + xhr.responseText);
            }
        };
        xhr.onerror = function() {
            btn.textContent = 'Error';
            btn.disabled = false;
            alert('Network error during update');
        };
        xhr.send('{}');
    };
})();
</script>
HTML;
    }

    /**
     * Find the active plugin config across all instances.
     * Since this is a single-instance plugin, we look for the first active instance.
     */
    static function getActiveConfig() {
        static $config = null;
        if ($config !== null)
            return $config ?: null;

        // Find active plugin instances
        $sql = "SELECT pi.id FROM " . PLUGIN_INSTANCE_TABLE . " pi"
            . " JOIN " . PLUGIN_TABLE . " p ON (pi.plugin_id = p.id)"
            . " WHERE p.isphar = 0 AND p.isactive = 1 AND (pi.flags & 1) = 1"
            . " AND p.install_path = 'plugins/push-notifications'";
        $res = db_query($sql);
        if ($res && ($row = db_fetch_row($res))) {
            $instance = PluginInstance::lookup($row[0]);
            if ($instance) {
                $config = $instance->getConfig();
                return $config;
            }
        }

        $config = false;
        return null;
    }
}

// Static bootstrap: ensures Signal hooks and AJAX routes load even with 0 instances.
// Plugin class file is loaded during discovery, so this runs on every request.
PushNotificationsPlugin::bootstrapStatic();
