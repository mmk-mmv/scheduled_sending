#!/usr/bin/env php
<?php
// Modified by Scheduled Sending contributors, 2026-10-23. GPL-3.0; see LICENSE and CREDITS.md.
// Read-only diagnostic. --check-auth performs SMTP/IMAP login and logout only.
// This program never submits SMTP MAIL/RCPT/DATA or writes/deletes IMAP messages.
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
define('INSTALL_PATH', dirname(__DIR__, 3) . '/');
require INSTALL_PATH . 'program/include/iniset.php';
try {
    $rc = rcmail::get_instance(0);
    require_once dirname(__DIR__) . '/scheduled_sending.php';
    $plugin = new scheduled_sending($rc->plugins);
    if (!$plugin->load_config()) throw new RuntimeException('Plugin configuration unavailable');
    $table = (string) $rc->config->get('db_table_scheduled_sending', 'scheduled_queue');
    if (!preg_match('/^[a-zA-Z0-9_]+$/D', $table)) throw new RuntimeException('Invalid queue table');
    $db = $rc->get_dbh();
    $res = $db->query("SELECT status, COUNT(*) AS n FROM $table GROUP BY status");
    if (!$res || $db->is_error()) throw new RuntimeException('Queue read failed');
    $counts = [];
    while ($row = $db->fetch_assoc($res)) $counts[$row['status']] = (int) $row['n'];
    $res = $db->query("SELECT id, status, scheduled_at, updated_at, meta_json FROM $table WHERE status NOT IN ('sent','canceled','cancelled') ORDER BY id LIMIT 200");
    if (!$res || $db->is_error()) throw new RuntimeException('Queue read failed');
    $checks = []; $ok = in_array('scheduled_sending', (array) $rc->config->get('plugins', []), true);
    $check_auth = in_array('--check-auth', $argv, true);
    $rc->config->set('smtp_debug', false);
    $rc->config->set('imap_debug', false);
    $rc->config->set('smtp_timeout', 30);
    while ($row = $db->fetch_assoc($res)) {
        $meta = json_decode((string) $row['meta_json'], true);
        $smtp_pass = $rc->decrypt($meta['smtp_pass_enc'] ?? '');
        $imap_pass = $rc->decrypt($meta['imap_pass_enc'] ?? '');
        $item = ['id' => (int) $row['id'], 'status' => $row['status'],
            'smtp_credentials_decryptable' => is_string($smtp_pass),
            'imap_credentials_decryptable' => is_string($imap_pass) && $imap_pass !== ''];
        if ($row['status'] === 'processing' && strtotime($row['updated_at'] . ' UTC') < time() - 900) {
            $item['review_required_stale_processing'] = true;
            $ok = false; // Never requeue a possibly submitted message.
        }
        if ($check_auth && $item['smtp_credentials_decryptable'] && $item['imap_credentials_decryptable']) {
            $smtp = new rcube_smtp();
            try { $item['smtp_login_without_submission'] = (bool) $smtp->connect($meta['smtp_host'], null, $meta['smtp_user'], $smtp_pass); }
            finally { $smtp->disconnect(); }
            $imap = new rcube_imap();
            try {
                $imap->set_options(['socket_options' => $rc->config->get('imap_conn_options', []), 'timeout' => 30]);
                $item['imap_login_without_message_changes'] = (bool) $imap->connect($meta['imap_host'], $meta['imap_user'], $imap_pass, (int) $meta['imap_port'], $meta['imap_ssl'] ?? null);
            } finally { $imap->close(); }
            $ok = $ok && $item['smtp_login_without_submission'] && $item['imap_login_without_message_changes'];
        }
        $ok = $ok && $item['smtp_credentials_decryptable'] && $item['imap_credentials_decryptable'];
        $checks[] = $item;
    }
    echo json_encode(['checks_passed' => $ok, 'plugin_version' => scheduled_sending::PLUGIN_VERSION,
        'now_utc' => gmdate('c'), 'queue_counts' => $counts, 'checks' => $checks,
        'note' => 'Existing error jobs remain held. No message submitted or modified.'], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n";
    exit($ok ? 0 : 1);
} catch (Throwable $error) {
    fwrite(STDERR, "scheduled_sending healthcheck failed; inspect local configuration and logs. No message was submitted.\n");
    exit(1);
}
