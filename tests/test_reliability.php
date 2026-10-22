<?php
// Modified by Scheduled Sending contributors, 2026-10-23. GPL-3.0; see LICENSE and CREDITS.md.
require __DIR__ . '/test_recipients.php';

[$rc, $plugin] = fixture(); add_row($rc, 1);
$scheduled = $rc->db->rows[1]['scheduled_at'] = gmdate('Y-m-d H:i:s', $rc->db->now - 1);
rcube_smtp::$connect_fails = true;
rcube_smtp::$connect_error = 'smtpconnerror';
$result = $plugin->run_cli_worker();
check($result['retry_wait'] === 1 && !$result['failed'] && !rcube_smtp::$sent, 'Pre-delivery network failure must wait without submission');
check($rc->db->rows[1]['status'] === 'retry_wait' && $rc->db->rows[1]['scheduled_at'] === $scheduled, 'Retry changed requested send time');
$plugin->run_cli_worker();
check(count(rcube_smtp::$connections) === 1, 'Retry ignored five-minute delay');
$rc->db->now += 301;
rcube_smtp::$connect_fails = false;
$_SESSION = [];
$result = $plugin->run_cli_worker();
check($result['sent'] === 1 && count(rcube_smtp::$sent) === 1 && $rc->db->rows[1]['status'] === 'sent', 'Retry after network recovery/logout failed');
echo "PASS temporary SMTP connection failure waits, preserves schedule and recovers after logout without duplicate submission\n";

[$rc, $plugin] = fixture(); add_row($rc, 1);
$rc->config->data['scheduled_sending_connect_retries'] = 2;
rcube_smtp::$connect_fails = true; rcube_smtp::$connect_error = 'smtpconnerror';
$failed = 0;
for ($i = 0; $i < 4; $i++) { $failed += $plugin->run_cli_worker()['failed']; $rc->db->now += 301; }
check(count(rcube_smtp::$connections) === 3 && $failed === 1 && !rcube_smtp::$sent && $rc->db->rows[1]['status'] === 'error', 'Retry exhaustion must hold without DATA');
echo "PASS connection retries are bounded and exhausted jobs stay held\n";

[$rc, $plugin] = fixture(); add_row($rc, 1); rcube_smtp::$connect_fails = true;
rcube_smtp::$connect_error = 'smtpautherror';
$plugin->run_cli_worker(); $rc->db->now += 86400; $plugin->run_cli_worker();
check(count(rcube_smtp::$connections) === 1 && !rcube_smtp::$sent && $rc->db->rows[1]['status'] === 'error', 'Authentication error was retried');
echo "PASS authentication failure remains held instead of repeatedly logging in\n";

[$rc, $plugin] = fixture(); add_row($rc, 1);
$rc->db->rows[1]['scheduled_at'] = gmdate('Y-m-d H:i:s', $rc->db->now + 8 * 3600);
$plugin->run_cli_worker();
check(!rcube_smtp::$connections && $rc->db->rows[1]['status'] === 'queued', 'Future job sent too early');
$_SESSION = []; $rc->db->now += 9 * 3600;
$plugin->run_cli_worker();
check(count(rcube_smtp::$sent) === 1 && $rc->db->rows[1]['status'] === 'sent', 'Overnight logged-out send failed');
echo "PASS future/overnight job waits until due and uses captured credentials after session removal\n";

[$rc, $plugin] = fixture(); add_row($rc, 1); add_row($rc, 2);
$rc->config->data['scheduled_sending_worker_budget'] = 1;
rcube_smtp::$delay_us = 1100000;
$result = $plugin->run_cli_worker();
check($result['processed'] === 1 && count(rcube_smtp::$sent) === 1 && $rc->db->rows[2]['status'] === 'queued', 'Slow batch claimed more rows than its runtime budget');
echo "PASS slow batch leaves the next job untouched for a later timer tick\n";
