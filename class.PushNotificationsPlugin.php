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
                url_get('^test$', 'sendTest')
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

        $inlineConfig = sprintf(
            '<script type="text/javascript">'
            . 'window.__PUSH_CONFIG={'
            . 'vapidPublicKey:"%s",'
            . 'swUrl:"%s/sw.js",'
            . 'subscribeUrl:"%s/subscribe",'
            . 'unsubscribeUrl:"%s/unsubscribe",'
            . 'statusUrl:"%s/status",'
            . 'preferencesUrl:"%s/preferences",'
            . 'csrfToken:"%s"'
            . '};</script>',
            addslashes($vapidPublicKey),
            $base, $base, $base, $base, $base,
            addslashes($csrfToken));

        $js = sprintf(
            '<script type="text/javascript" src="%s/assets/js?v=%s"></script>',
            $base, $v);

        $buffer = str_replace('</head>', $css . "\n</head>", $buffer);
        $buffer = str_replace('</body>', $inlineConfig . "\n" . $js . "\n</body>", $buffer);

        return $buffer;
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
