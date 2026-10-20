<?php

/**
 * Rebuild the document search index (doc_search_index).
 *
 * Usage:
 *   php server/console/reindex.php
 *
 * It also ensures the doc_search_index table exists (idempotent upgrade).
 */

if (PHP_SAPI !== 'cli') {
    exit("CLI only.\n");
}

$serverDir = __DIR__ . '/..';

require $serverDir . '/vendor/autoload.php';

use App\Common\Database\Database;
use App\Common\Database\Upgrade;
use App\Model\DocSearchIndex;

$rootPath = dirname($serverDir);
if (is_file($rootPath . '/.env')) {
    Dotenv\Dotenv::createImmutable($rootPath)->load();
}

$timezone = getenv('APP_TIMEZONE') ?: 'Asia/Shanghai';
@date_default_timezone_set($timezone);

$appPath = $serverDir . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR;
$runtimePath = $appPath . 'Runtime' . DIRECTORY_SEPARATOR;
if (!is_dir($runtimePath)) {
    @mkdir($runtimePath, 0755, true);
}
$logPath = $runtimePath . 'Logs' . DIRECTORY_SEPARATOR;
if (!is_dir($logPath)) {
    @mkdir($logPath, 0755, true);
}
if (!defined('LOG_PATH')) {
    define('LOG_PATH', $logPath);
}

try {
    Database::getInstance();
    if (!Upgrade::checkAndUpgrade()) {
        fwrite(STDERR, "Upgrade check failed (maybe table exists already). Continue...\n");
    }
} catch (\Throwable $e) {
    fwrite(STDERR, 'Database init failed: ' . $e->getMessage() . "\n");
    exit(1);
}

echo "Rebuilding search index...\n";
$result = DocSearchIndex::rebuildAll();
echo "Done: total={$result['total']} indexed={$result['indexed']} failed={$result['failed']}\n";
exit($result['failed'] > 0 ? 1 : 0);
