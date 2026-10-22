#!/usr/bin/env php
<?php
// Modified by Scheduled Sending contributors, 2026-10-23. GPL-3.0; see LICENSE and CREDITS.md.
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}
define('INSTALL_PATH', dirname(__DIR__, 3) . '/');
require INSTALL_PATH . 'program/include/iniset.php';
// iniset.php imposes a web-request limit. systemd bounds this CLI process instead.
set_time_limit(0);
try {
    $rcmail = rcmail::get_instance(0);
    require_once dirname(__DIR__) . '/scheduled_sending.php';
    $plugin = new scheduled_sending($rcmail->plugins);
    $result = $plugin->run_cli_worker();
    echo json_encode($result, JSON_THROW_ON_ERROR) . "\n";
    exit(!empty($result['failed']) || !empty($result['sent_copy_errors']) ? 1 : 0);
} catch (Throwable $error) {
    fwrite(STDERR, "scheduled_sending worker: " . $error->getMessage() . "\n");
    exit(1);
}
