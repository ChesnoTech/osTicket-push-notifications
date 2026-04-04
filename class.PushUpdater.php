<?php
/**
 * Push Notifications Plugin - Auto-Updater
 *
 * Checks GitHub for new releases, backs up files + DB tables,
 * downloads and applies updates, runs database migrations.
 *
 * Supports release channels: stable, rc, beta, dev.
 * Version format: MAJOR.MINOR.PATCH[-channel.N]
 *   stable:  1.2.0
 *   rc:      1.2.0-rc.1
 *   beta:    1.2.0-beta.1
 *   dev:     1.2.0-dev.1
 *
 * Branch mapping:
 *   stable  → GitHub releases marked as latest (prerelease=false)
 *   rc      → GitHub releases tagged *-rc.* (prerelease=true)
 *   beta    → GitHub releases tagged *-beta.* (prerelease=true)
 *   dev     → GitHub releases tagged *-dev.* (prerelease=true)
 *
 * @author  ChesnoTech
 * @version 2.0.0
 */

class PushUpdater {

    const GITHUB_REPO      = 'ChesnoTech/osTicket-push-notifications';
    const GITHUB_API_BASE  = 'https://api.github.com/repos/ChesnoTech/osTicket-push-notifications';
    const CHECK_INTERVAL   = 43200; // 12 hours

    /** Allowed channels ordered by stability */
    const CHANNELS = array('stable', 'rc', 'beta', 'dev');

    private $pluginDir;
    private $backupDir;

    function __construct() {
        $this->pluginDir = dirname(__FILE__) . '/';
        $this->backupDir = $this->pluginDir . 'backups/';
    }

    /**
     * Get the configured update channel from plugin config.
     * Defaults to 'stable'.
     */
    function getChannel() {
        $config = PushNotificationsPlugin::getActiveConfig();
        $ch = $config ? $config->get('update_channel') : '';
        return in_array($ch, self::CHANNELS) ? $ch : 'stable';
    }

    /**
     * Set the update channel in plugin config.
     */
    function setChannel($channel) {
        if (!in_array($channel, self::CHANNELS))
            return false;
        $config = PushNotificationsPlugin::getActiveConfig();
        if ($config) {
            $config->set('update_channel', $channel);
            return true;
        }
        return false;
    }

    /**
     * Check GitHub for the latest release matching the configured channel.
     */
    function checkForUpdate($channel = null) {
        $current = $this->getCurrentVersion();
        if (!$channel)
            $channel = $this->getChannel();

        $release = $this->fetchLatestForChannel($channel);
        if (!$release)
            return array(
                'available'       => false,
                'current_version' => $current,
                'channel'         => $channel,
                'error'           => 'Could not reach GitHub API',
            );

        $latest = ltrim($release['tag_name'] ?? '', 'vV');
        if (!$latest)
            return array(
                'available'       => false,
                'current_version' => $current,
                'channel'         => $channel,
                'error'           => 'No releases found for channel: ' . $channel,
            );

        $hasUpdate = version_compare($latest, $current, '>');

        return array(
            'available'       => $hasUpdate,
            'current_version' => $current,
            'latest_version'  => $latest,
            'channel'         => $channel,
            'prerelease'      => !empty($release['prerelease']),
            'release_name'    => $release['name'] ?? '',
            'release_notes'   => $release['body'] ?? '',
            'published_at'    => $release['published_at'] ?? '',
            'download_url'    => $release['zipball_url'] ?? '',
            'html_url'        => $release['html_url'] ?? '',
        );
    }

    /**
     * Perform the full update process:
     * 1. Check for update
     * 2. Backup current files + DB tables
     * 3. Download new release
     * 4. Extract and replace files
     * 5. Run database migrations
     */
    function performUpdate() {
        $check = $this->checkForUpdate();
        if (!$check['available'])
            return array('success' => false, 'error' => 'No update available');

        $version = $check['latest_version'];
        $downloadUrl = $check['download_url'];

        if (!$downloadUrl)
            return array('success' => false, 'error' => 'No download URL in release');

        // Step 1: Create backup
        $backupResult = $this->createBackup();
        if (!$backupResult['success'])
            return array('success' => false, 'error' => 'Backup failed: ' . $backupResult['error']);

        $backupPath = $backupResult['path'];

        // Step 2: Download release
        $zipPath = $this->downloadRelease($downloadUrl);
        if (!$zipPath)
            return $this->failWithRollback($backupPath, 'Failed to download release from GitHub');

        // Step 3: Extract and replace files
        $extractResult = $this->extractAndReplace($zipPath);
        @unlink($zipPath); // Clean up downloaded zip

        if (!$extractResult['success'])
            return $this->failWithRollback($backupPath, 'Extract failed: ' . $extractResult['error']);

        // Step 4: Run database migrations
        $migrateResult = $this->runMigrations();
        if (!$migrateResult['success'])
            return $this->failWithRollback($backupPath, 'Migration failed: ' . $migrateResult['error']);

        // Step 5: Update schema version in config
        $this->updateSchemaVersion();

        // Step 6: Prune old backups (keep last 5)
        $this->pruneBackups(5);

        return array(
            'success'          => true,
            'previous_version' => $check['current_version'],
            'new_version'      => $version,
            'backup_path'      => $backupPath,
            'migrations_run'   => $migrateResult['count'] ?? 0,
        );
    }

    /**
     * Create a backup of current plugin files and database tables.
     */
    function createBackup() {
        $timestamp = date('Ymd_His');
        $version = $this->getCurrentVersion();
        $backupName = "backup_{$version}_{$timestamp}";
        $backupPath = $this->backupDir . $backupName . '/';

        // Ensure backup directory exists with .htaccess protection
        if (!is_dir($this->backupDir)) {
            if (!@mkdir($this->backupDir, 0755, true))
                return array('success' => false, 'error' => 'Cannot create backup directory');
            @file_put_contents($this->backupDir . '.htaccess', "Deny from all\n");
        }

        if (!@mkdir($backupPath, 0755, true))
            return array('success' => false, 'error' => 'Cannot create backup folder');

        // Backup plugin files
        $filesBackup = $backupPath . 'files/';
        @mkdir($filesBackup, 0755, true);
        $this->copyDirectory($this->pluginDir, $filesBackup, array('backups'));

        // Backup database tables
        $dbBackup = $this->backupDatabase($backupPath);
        if (!$dbBackup['success'])
            return array('success' => false, 'error' => 'DB backup failed: ' . $dbBackup['error']);

        // Write backup manifest
        $manifest = array(
            'version'    => $version,
            'timestamp'  => $timestamp,
            'date'       => date('Y-m-d H:i:s'),
            'tables'     => $dbBackup['tables'],
            'file_count' => $dbBackup['file_count'] ?? 0,
        );
        @file_put_contents($backupPath . 'manifest.json', json_encode($manifest, JSON_PRETTY_PRINT));

        return array(
            'success' => true,
            'path'    => $backupPath,
            'name'    => $backupName,
        );
    }

    /**
     * Backup the plugin's database tables to SQL files.
     */
    private function backupDatabase($backupPath) {
        $prefix = TABLE_PREFIX;
        $tables = array(
            $prefix . 'push_subscription',
            $prefix . 'push_preferences',
        );

        $backedUp = array();
        foreach ($tables as $table) {
            // Check if table exists
            $check = db_query("SHOW TABLES LIKE '{$table}'");
            if (!$check || !db_fetch_row($check))
                continue;

            $sql = "-- Backup of {$table}\n";
            $sql .= "-- Date: " . date('Y-m-d H:i:s') . "\n\n";

            // Get CREATE TABLE statement
            $createRes = db_query("SHOW CREATE TABLE `{$table}`");
            if ($createRes && ($createRow = db_fetch_row($createRes))) {
                $sql .= $createRow[1] . ";\n\n";
            }

            // Dump data
            $dataRes = db_query("SELECT * FROM `{$table}`");
            if ($dataRes) {
                while ($row = db_fetch_array($dataRes)) {
                    $values = array();
                    foreach ($row as $val) {
                        if ($val === null)
                            $values[] = 'NULL';
                        else
                            $values[] = db_input($val);
                    }
                    $sql .= "INSERT INTO `{$table}` VALUES (" . implode(', ', $values) . ");\n";
                }
            }

            $filename = str_replace($prefix, '', $table) . '.sql';
            @file_put_contents($backupPath . $filename, $sql);
            $backedUp[] = $table;
        }

        return array('success' => true, 'tables' => $backedUp);
    }

    /**
     * Download a release zip from GitHub.
     * Returns path to downloaded zip or false.
     */
    private function downloadRelease($url) {
        $tmpFile = tempnam(sys_get_temp_dir(), 'ost_push_update_');
        if (!$tmpFile)
            return false;

        $tmpFile .= '.zip';

        $ch = curl_init();
        curl_setopt_array($ch, array(
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 5,
            CURLOPT_TIMEOUT        => 120,
            CURLOPT_USERAGENT      => 'osTicket-PushNotifications/' . $this->getCurrentVersion(),
            CURLOPT_SSL_VERIFYPEER => true,
        ));

        $data = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200 || !$data) {
            @unlink($tmpFile);
            return false;
        }

        if (@file_put_contents($tmpFile, $data) === false) {
            @unlink($tmpFile);
            return false;
        }

        // Validate it's actually a zip
        if (!class_exists('ZipArchive')) {
            @unlink($tmpFile);
            return false;
        }

        $zip = new ZipArchive();
        if ($zip->open($tmpFile) !== true) {
            @unlink($tmpFile);
            return false;
        }
        $zip->close();

        return $tmpFile;
    }

    /**
     * Extract downloaded zip and replace plugin files.
     * Preserves: backups/, config stored in DB (not files).
     */
    private function extractAndReplace($zipPath) {
        if (!class_exists('ZipArchive'))
            return array('success' => false, 'error' => 'ZipArchive extension not available');

        $zip = new ZipArchive();
        if ($zip->open($zipPath) !== true)
            return array('success' => false, 'error' => 'Cannot open zip file');

        // GitHub zipball has a top-level directory like "ChesnoTech-ost-push-notifications-abc1234/"
        // Find it by looking at the first entry
        $topDir = '';
        if ($zip->numFiles > 0) {
            $firstName = $zip->getNameIndex(0);
            if (strpos($firstName, '/') !== false) {
                $topDir = substr($firstName, 0, strpos($firstName, '/') + 1);
            }
        }

        // Extract to a temp directory first
        $extractDir = sys_get_temp_dir() . '/ost_push_extract_' . uniqid();
        if (!@mkdir($extractDir, 0755, true)) {
            $zip->close();
            return array('success' => false, 'error' => 'Cannot create temp extract directory');
        }

        $zip->extractTo($extractDir);
        $zip->close();

        // Find the actual plugin files (look for plugin.php)
        $sourceDir = $extractDir . '/' . $topDir;
        if (!file_exists($sourceDir . 'plugin.php')) {
            // Maybe files are nested deeper — search for plugin.php
            $found = $this->findFileRecursive($extractDir, 'plugin.php', 3);
            if ($found) {
                $sourceDir = dirname($found) . '/';
            } else {
                $this->removeDirectory($extractDir);
                return array('success' => false, 'error' => 'plugin.php not found in release');
            }
        }

        // Validate plugin.php is ours
        $manifest = @include $sourceDir . 'plugin.php';
        if (!is_array($manifest) || ($manifest['id'] ?? '') !== 'osticket:push-notifications') {
            $this->removeDirectory($extractDir);
            return array('success' => false, 'error' => 'Invalid plugin manifest in release');
        }

        // Remove old files (except backups/ directory)
        $this->cleanPluginDir();

        // Copy new files
        $this->copyDirectory($sourceDir, $this->pluginDir, array('backups'));

        // Clean up temp extract
        $this->removeDirectory($extractDir);

        return array('success' => true);
    }

    /**
     * Run any pending database migrations.
     */
    function runMigrations() {
        $migrationsDir = $this->pluginDir . 'migrations/';
        if (!is_dir($migrationsDir))
            return array('success' => true, 'count' => 0);

        $currentSchema = $this->getSchemaVersion();

        // Collect migration files (format: NNN_name.php)
        $files = glob($migrationsDir . '*.php');
        if (!$files)
            return array('success' => true, 'count' => 0);

        sort($files);
        $count = 0;

        foreach ($files as $file) {
            $basename = basename($file, '.php');
            $parts = explode('_', $basename, 2);
            $migrationId = (int) $parts[0];

            if ($migrationId <= $currentSchema)
                continue;

            // Each migration returns a callable or has an 'up' function
            $migration = include $file;
            if (is_callable($migration)) {
                try {
                    $result = $migration();
                    if ($result === false) {
                        return array(
                            'success' => false,
                            'error'   => "Migration {$basename} failed",
                            'count'   => $count,
                        );
                    }
                } catch (\Throwable $e) {
                    return array(
                        'success' => false,
                        'error'   => "Migration {$basename}: " . $e->getMessage(),
                        'count'   => $count,
                    );
                }
            }

            $this->setSchemaVersion($migrationId);
            $count++;
        }

        return array('success' => true, 'count' => $count);
    }

    /**
     * Rollback to a backup.
     */
    function rollback($backupPath = null) {
        if (!$backupPath) {
            // Find the latest backup
            $backupPath = $this->getLatestBackupPath();
            if (!$backupPath)
                return array('success' => false, 'error' => 'No backup found');
        }

        if (!is_dir($backupPath))
            return array('success' => false, 'error' => 'Backup directory not found');

        $manifest = @json_decode(@file_get_contents($backupPath . 'manifest.json'), true);
        if (!$manifest)
            return array('success' => false, 'error' => 'Invalid backup manifest');

        // Restore files
        $filesDir = $backupPath . 'files/';
        if (is_dir($filesDir)) {
            $this->cleanPluginDir();
            $this->copyDirectory($filesDir, $this->pluginDir, array('backups'));
        }

        // Restore database tables inside a transaction
        $prefix = TABLE_PREFIX;
        $sqlFiles = glob($backupPath . '*.sql');
        if ($sqlFiles) {
            db_query('BEGIN');
            foreach ($sqlFiles as $sqlFile) {
                $tableName = $prefix . basename($sqlFile, '.sql');
                db_query("DROP TABLE IF EXISTS `{$tableName}`");
                $sql = file_get_contents($sqlFile);
                $statements = array_filter(array_map('trim', explode(";\n", $sql)));
                foreach ($statements as $stmt) {
                    if ($stmt && strpos($stmt, '--') !== 0)
                        db_query($stmt);
                }
            }
            db_query('COMMIT');
        }

        return array(
            'success'          => true,
            'restored_version' => $manifest['version'] ?? 'unknown',
        );
    }

    /**
     * Get the current plugin version from plugin.php manifest.
     */
    function getCurrentVersion() {
        $manifest = @include($this->pluginDir . 'plugin.php');
        return (is_array($manifest) && isset($manifest['version']))
            ? $manifest['version']
            : '0.0.0';
    }

    /**
     * Fetch the latest release matching a channel.
     *
     * Channel logic:
     *   stable → latest non-prerelease (GitHub "latest" endpoint)
     *   rc     → newest prerelease tagged *-rc.*
     *   beta   → newest prerelease tagged *-beta.* or *-rc.*
     *   dev    → newest release of any kind (including dev/beta/rc/stable)
     */
    private function fetchLatestForChannel($channel) {
        if ($channel === 'stable') {
            // GitHub /releases/latest returns the newest non-prerelease
            return $this->githubGet('/releases/latest');
        }

        // For pre-release channels, scan recent releases
        $releases = $this->githubGet('/releases?per_page=30');
        if (!is_array($releases) || empty($releases))
            return null;

        // Channel hierarchy: dev sees everything, beta sees beta+rc+stable, rc sees rc+stable
        $allowedSuffixes = $this->channelSuffixes($channel);

        foreach ($releases as $rel) {
            if (!is_array($rel) || empty($rel['tag_name']))
                continue;

            // Draft releases are never shown
            if (!empty($rel['draft']))
                continue;

            $tag = ltrim($rel['tag_name'], 'vV');

            // Stable release (no suffix) is always acceptable
            if (!$rel['prerelease'])
                return $rel;

            // Check if tag suffix matches allowed channels
            foreach ($allowedSuffixes as $suffix) {
                if (stripos($tag, '-' . $suffix . '.') !== false)
                    return $rel;
            }
        }

        return null;
    }

    /**
     * Return which pre-release suffixes a channel can see.
     */
    private function channelSuffixes($channel) {
        switch ($channel) {
            case 'dev':  return array('dev', 'beta', 'rc');
            case 'beta': return array('beta', 'rc');
            case 'rc':   return array('rc');
            default:     return array();
        }
    }

    /**
     * Make a GitHub API GET request.
     */
    private function githubGet($path) {
        $url = self::GITHUB_API_BASE . $path;
        $ch = curl_init();
        curl_setopt_array($ch, array(
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_USERAGENT      => 'osTicket-PushNotifications/' . $this->getCurrentVersion(),
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_HTTPHEADER     => array(
                'Accept: application/vnd.github.v3+json',
            ),
        ));

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200 || !$response)
            return null;

        $data = json_decode($response, true);
        if (!is_array($data))
            return null;

        return $data;
    }

    /**
     * Get the current database schema version.
     */
    private function getSchemaVersion() {
        $config = PushNotificationsPlugin::getActiveConfig();
        if (!$config)
            return 0;
        return (int) $config->get('db_schema_version');
    }

    /**
     * Set the database schema version.
     */
    private function setSchemaVersion($version) {
        $config = PushNotificationsPlugin::getActiveConfig();
        if ($config)
            $config->set('db_schema_version', (int) $version);
    }

    /**
     * Update schema version to match the latest migration file.
     */
    private function updateSchemaVersion() {
        $migrationsDir = $this->pluginDir . 'migrations/';
        if (!is_dir($migrationsDir))
            return;
        $files = glob($migrationsDir . '*.php');
        if (!$files)
            return;
        sort($files);
        $last = basename(end($files), '.php');
        $parts = explode('_', $last, 2);
        $this->setSchemaVersion((int) $parts[0]);
    }

    /**
     * Clean the plugin directory, preserving backups/ and any hidden files.
     */
    private function cleanPluginDir() {
        $preserve = array('backups', '.', '..');
        $items = @scandir($this->pluginDir);
        if (!$items)
            return;

        foreach ($items as $item) {
            if (in_array($item, $preserve))
                continue;
            $path = $this->pluginDir . $item;
            if (is_dir($path))
                $this->removeDirectory($path);
            else
                @unlink($path);
        }
    }

    /**
     * Copy a directory recursively, excluding specified subdirectory names.
     */
    private function copyDirectory($src, $dst, $exclude = array()) {
        if (!is_dir($dst))
            @mkdir($dst, 0755, true);

        $items = @scandir($src);
        if (!$items)
            return;

        $count = 0;
        foreach ($items as $item) {
            if ($item === '.' || $item === '..')
                continue;
            if (in_array($item, $exclude))
                continue;

            $srcPath = $src . $item;
            $dstPath = $dst . $item;

            if (is_dir($srcPath)) {
                $this->copyDirectory($srcPath . '/', $dstPath . '/', $exclude);
            } else {
                @copy($srcPath, $dstPath);
                $count++;
            }
        }
        return $count;
    }

    /**
     * Remove a directory recursively.
     */
    private function removeDirectory($dir) {
        if (!is_dir($dir))
            return;

        $items = @scandir($dir);
        if ($items) {
            foreach ($items as $item) {
                if ($item === '.' || $item === '..')
                    continue;
                $path = $dir . '/' . $item;
                if (is_dir($path))
                    $this->removeDirectory($path);
                else
                    @unlink($path);
            }
        }
        @rmdir($dir);
    }

    /**
     * Find a file recursively up to a certain depth.
     */
    private function findFileRecursive($dir, $filename, $maxDepth = 3) {
        if ($maxDepth <= 0)
            return false;

        $path = rtrim($dir, '/') . '/' . $filename;
        if (file_exists($path))
            return $path;

        $items = @scandir($dir);
        if (!$items)
            return false;

        foreach ($items as $item) {
            if ($item === '.' || $item === '..')
                continue;
            $subDir = rtrim($dir, '/') . '/' . $item;
            if (is_dir($subDir)) {
                $found = $this->findFileRecursive($subDir, $filename, $maxDepth - 1);
                if ($found)
                    return $found;
            }
        }

        return false;
    }

    /**
     * Get the path to the most recent backup.
     */
    private function getLatestBackupPath() {
        if (!is_dir($this->backupDir))
            return null;

        $dirs = glob($this->backupDir . 'backup_*', GLOB_ONLYDIR);
        if (!$dirs)
            return null;

        sort($dirs);
        return end($dirs) . '/';
    }

    /**
     * Remove old backups, keeping the N most recent.
     */
    function pruneBackups($keep = 5) {
        if (!is_dir($this->backupDir))
            return;

        $dirs = glob($this->backupDir . 'backup_*', GLOB_ONLYDIR);
        if (!$dirs || count($dirs) <= $keep)
            return;

        sort($dirs);
        $toRemove = array_slice($dirs, 0, count($dirs) - $keep);
        foreach ($toRemove as $dir) {
            $this->removeDirectory($dir);
        }
    }

    /**
     * Get list of available backups with metadata.
     */
    function listBackups() {
        if (!is_dir($this->backupDir))
            return array();

        $dirs = glob($this->backupDir . 'backup_*', GLOB_ONLYDIR);
        if (!$dirs)
            return array();

        sort($dirs);
        $backups = array();
        foreach ($dirs as $dir) {
            $manifest = @json_decode(@file_get_contents($dir . '/manifest.json'), true);
            $backups[] = array(
                'path'    => $dir . '/',
                'name'    => basename($dir),
                'version' => $manifest['version'] ?? 'unknown',
                'date'    => $manifest['date'] ?? '',
                'tables'  => $manifest['tables'] ?? array(),
            );
        }

        return array_reverse($backups); // newest first
    }

    /**
     * Fail an update and attempt rollback.
     */
    private function failWithRollback($backupPath, $error) {
        $rollback = $this->rollback($backupPath);
        $rolled = $rollback['success'] ? ' (rolled back successfully)' : ' (rollback also failed!)';
        return array('success' => false, 'error' => $error . $rolled);
    }
}
