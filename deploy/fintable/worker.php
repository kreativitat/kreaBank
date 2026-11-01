<?php
/* Copyright (C) 2026 Kreativität Works <mail@kreativitat.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit(1);
}
if (($argv[1] ?? '') === '--health') {
    $heartbeat = '/tmp/fintable-worker-ok';
    exit(is_file($heartbeat) && time() - filemtime($heartbeat) < 180 ? 0 : 1);
}
define('NOLOGIN', 1);
define('NOSESSION', 1);
define('NOREQUIREMENU', 1);
define('NOREQUIREHTML', 1);
define('NOREQUIREAJAX', 1);
require '/var/www/html/master.inc.php';
try {
    require_once DOL_DOCUMENT_ROOT . '/custom/kreabank/class/KreaBankFintableSync.class.php';
    $sync = new KreaBankFintableSync($db);
    if ($sync->setting('KREABANK_FINTABLE_ENABLED') !== '1'
        || strpos($sync->setting('KREABANK_FINTABLE_TOKEN'), 'dolcrypt:') !== 0) {
        throw new RuntimeException('The encrypted Fintable collector is not enabled');
    }
    $result = $db->query('SELECT rowid,classesname,jobtype,status,module_name,params FROM ' . MAIN_DB_PREFIX
        . "cronjob WHERE entity=0 AND objectname='KreaBankFintableSync' AND methodename='run'");
    if (!$result || $db->num_rows($result) !== 1) {
        throw new RuntimeException('Exactly one global Fintable job is required');
    }
    $job = $db->fetch_object($result);
    if ($job->classesname !== '/kreabank/class/KreaBankFintableSync.class.php'
        || $job->jobtype !== 'method' || $job->module_name !== 'kreabank' || (int) $job->status !== 1
        || trim((string) $job->params) !== '') {
        throw new RuntimeException('Fintable job identity or enabled state changed');
    }
    $key = getDolGlobalString('CRON_KEY');
    if ($key === '') {
        throw new RuntimeException('The native scheduler security key is unavailable');
    }
    // The key stays in PHP memory. Only this job ID reaches the native scheduler.
    $login = (string) (getenv('FINTABLE_RUNNER_LOGIN') ?: 'DeGema_Bot');
    $argv = array(DOL_DOCUMENT_ROOT . '/scripts/cron/cron_run_jobs.php', $key, $login, (string) $job->rowid);
    require DOL_DOCUMENT_ROOT . '/scripts/cron/cron_run_jobs.php';
} catch (Throwable $e) {
    fwrite(STDERR, 'Fintable worker: ' . $e->getMessage() . "\n");
    exit(1);
}
