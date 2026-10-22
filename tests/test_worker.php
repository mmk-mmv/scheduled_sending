<?php
// Modified by Scheduled Sending contributors, 2026-10-23. GPL-3.0; see LICENSE and CREDITS.md.
error_reporting(E_ALL & ~E_DEPRECATED);
class rcube_plugin { public function load_config() {} public function gettext($key) { return $key; } }
class rcmail {
    public static $instance;
    public static function get_instance($env = null) { return self::$instance; }
}
class rcube extends rcmail {}
if (getenv('ROUNDCUBE_CORE')) {
    define('INSTALL_PATH', rtrim(getenv('ROUNDCUBE_CORE'), '/') . '/');
    require INSTALL_PATH . 'program/include/iniset.php';
    $_SERVER['HTTP_HOST'] = 'webmail.example.org';
} else {
class rcube_utils {
    public static function parse_host($host) { return str_replace('%h', $_SESSION['storage_host'], $host); }
}
class rcube_mime {
    public static function decode_address_list($value, ...$unused) {
        preg_match_all('/[a-zA-Z0-9._+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}/', $value, $m);
        return $m[0];
    }
}
}
class TestConfig {
    public $data = ['smtp_timeout' => 9, 'db_table_scheduled_sending' => 'scheduled_queue'];
    public function get($key, $default = null) { return $this->data[$key] ?? $default; }
    public function set($key, $value) { $this->data[$key] = $value; }
}
class TestRows { public $rows; public function __construct($rows) { $this->rows = $rows; } }
class TestOutput {
    public $framed = false, $commands = [], $responses = 0;
    public function get_charset() { return 'UTF-8'; }
    public function command(...$args) { $this->commands[] = $args; }
    public function send() { $this->responses++; }
}
class TestDB {
    public $rows = [], $affected = 0, $busy = false, $read_error = false;
    public $now;
    public function __construct() { $this->now = time(); }
    public function query($sql, ...$args) {
        $this->affected = 0;
        if (str_contains($sql, 'GET_LOCK')) return new TestRows([['acquired' => $this->busy ? 0 : 1]]);
        if (str_contains($sql, 'RELEASE_LOCK')) return new TestRows([]);
        if (str_starts_with($sql, 'SELECT *')) {
            preg_match('/INTERVAL (\d+) SECOND/', $sql, $delay);
            return $this->read_error ? false : new TestRows(array_values(array_filter($this->rows, function($r) use ($delay) {
                if ($r['status'] === 'queued') return empty($r['scheduled_at']) || strtotime($r['scheduled_at'].' UTC') <= $this->now;
                if ($r['status'] === 'retry_wait') return ($r['updated_at'] ?? 0) <= $this->now - (int) ($delay[1] ?? 300);
                if ($r['status'] === 'sent_copy_error') return ($r['updated_at'] ?? 0) < $this->now - 300;
                return false;
            })));
        }
        if (str_contains($sql, "SET status='processing'")) {
            [$id, $expected] = $args;
            if ($this->rows[$id]['status'] === $expected) { $this->rows[$id]['status'] = 'processing'; $this->rows[$id]['updated_at'] = $this->now; $this->affected = 1; }
        } elseif (str_contains($sql, "SET status='sent_copy_error'")) {
            [$raw, $id] = $args;
            $this->rows[$id]['status'] = 'sent_copy_error'; $this->rows[$id]['raw_mime'] = $raw; $this->affected = 1;
        } elseif (str_contains($sql, "SET status='sent'")) {
            [$meta, $id] = $args;
            $this->rows[$id]['status'] = 'sent'; $this->rows[$id]['raw_mime'] = null; $this->rows[$id]['meta_json'] = $meta; $this->affected = 1;
        } elseif (str_contains($sql, 'SET status=?')) {
            if (count($args) === 4) { [$status, $error, $meta, $id] = $args; $this->rows[$id]['meta_json'] = $meta; }
            else { [$status, $error, $id] = $args; }
            $this->rows[$id]['status'] = $status; $this->rows[$id]['last_error'] = $error; $this->affected = 1;
            $this->rows[$id]['updated_at'] = $this->now;
        } else throw new RuntimeException('Unexpected SQL in test');
        return new TestRows([]);
    }
    public function fetch_assoc($result) { return array_shift($result->rows); }
    public function affected_rows($result = null) { return $this->affected; }
    public function is_error() { return false; }
}
class TestUser {
    public $ID = 7;
    public function list_identities() {
        return [['identity_id' => 1, 'email' => 'sender@example.org', 'name' => 'Absender, Büro', 'standard' => 1]];
    }
}
class TestRC {
    public $config, $db, $user, $output, $request_valid = true;
    public function __construct() { $this->config = new TestConfig; $this->db = new TestDB; $this->user = new TestUser; $this->output = new TestOutput; }
    public function get_dbh() { return $this->db; }
    public function check_request() { return $this->request_valid; }
    public function get_user_name() { return 'fixture@example.org'; }
    public function get_user_password() { return 'test-password-only'; }
    public function encrypt($value) {
        if ($value === '') return '';
        $iv = random_bytes(12); $tag = '';
        return base64_encode($iv . openssl_encrypt($value, 'aes-256-gcm', str_repeat('k', 32), OPENSSL_RAW_DATA, $iv, $tag) . $tag);
    }
    public function decrypt($value) {
        $raw = base64_decode($value);
        if (strlen($raw) < 28) return false;
        return openssl_decrypt(substr($raw, 12, -16), 'aes-256-gcm', str_repeat('k', 32), OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, -16));
    }
}
class rcube_smtp {
    public static $sent = [], $connections = [], $connect_fails = false, $send_fails = false;
    public static $connect_error = 'smtpautherror', $delay_us = 0;
    private $connected = false;
    public function connect($host, $port, $user, $pass) {
        self::$connections[] = [$host, $user, $pass];
        if (self::$delay_us) usleep(self::$delay_us);
        return $this->connected = !self::$connect_fails;
    }
    public function get_error() { return ['label' => self::$connect_error]; }
    public function send_mail($from, $to, $headers, $body) {
        if (!$this->connected) throw new RuntimeException('SMTP was not connected');
        self::$sent[] = [$from, $to, $headers, $body];
        return !self::$send_fails;
    }
    public function disconnect() { $this->connected = false; }
}
class rcube_imap {
    public static $saved = [], $deleted = [], $fail_save = false;
    private $user;
    public function set_options($options) {}
    public function connect($host, $user, $pass, $port, $ssl) { $this->user = $user; return $pass !== ''; }
    public function folder_exists($folder) { return true; }
    public function create_folder($folder, $subscribe) { return true; }
    public function search($folder, $query, $charset) {
        $hits = count(array_filter(self::$saved, fn($item) => $item[0] === $this->user && $item[1] === $folder));
        return new class($hits) { private $hits; public function __construct($hits) { $this->hits = $hits; } public function is_error() { return false; } public function count() { return $this->hits; } };
    }
    public function save_message($folder, &$raw, ...$unused) { if (self::$fail_save) return false; self::$saved[] = [$this->user, $folder, $raw]; return 42; }
    public function delete_message($uid, $folder) { self::$deleted[] = [$uid, $folder]; }
    public function close() {}
}
require dirname(__DIR__) . '/scheduled_sending.php';
function check($condition, $message) { if (!$condition) throw new RuntimeException($message); }
function fixture() {
    rcmail::$instance = $rc = new TestRC;
    rcube_smtp::$sent = rcube_smtp::$connections = rcube_imap::$saved = rcube_imap::$deleted = [];
    rcube_smtp::$connect_fails = rcube_smtp::$send_fails = rcube_imap::$fail_save = false;
    rcube_smtp::$connect_error = 'smtpautherror'; rcube_smtp::$delay_us = 0;
    return [$rc, new scheduled_sending];
}
function add_row($rc, $id, $user = 'one@example.org') {
    $meta = ['from' => 'Fixture <'.$user.'>', 'to' => '"Doe, Jane" <to@example.net>', 'cc' => 'cc@example.net', 'bcc' => 'hidden@example.net', 'smtp_host' => 'tls://mail.example.org:587', 'smtp_user' => $user, 'smtp_pass_enc' => $rc->encrypt('secret-'.$id), 'imap_host' => 'mail.example.org', 'imap_user' => $user, 'imap_pass_enc' => $rc->encrypt('secret-'.$id), 'imap_port' => 993, 'imap_ssl' => 'ssl', 'sent_mbox' => 'Sent', 'drafts_mbox' => 'Drafts', 'draft_uid' => 77 + $id];
    $body = "--fixture\r\nContent-Type: text/plain\r\n\r\nHallo äöü\r\n--fixture\r\nContent-Type: application/octet-stream\r\nContent-Transfer-Encoding: base64\r\n\r\nAAECAwQ=\r\n--fixture--\r\n";
    $rc->db->rows[$id] = ['id' => $id, 'status' => 'queued', 'meta_json' => json_encode($meta), 'raw_mime' => "From: wrong@example.org\r\nTo: to@example.net\r\nbCc: hidden@example.net\r\nContent-Type: multipart/mixed; boundary=fixture\r\n\r\n".$body];
    return $body;
}

[$rc, $plugin] = fixture(); $body = add_row($rc, 1); add_row($rc, 2, 'two@example.org');
$result = $plugin->run_cli_worker();
check($result['sent'] === 2 && $result['failed'] === 0, 'Two accounts must send');
check(count(rcube_smtp::$connections) === 2 && rcube_smtp::$connections[1][1] === 'two@example.org' && rcube_smtp::$connections[1][2] === 'secret-2', 'Each account must use its own credentials');
check(rcube_smtp::$sent[0][3] === $body, 'MIME body and attachment bytes changed');
check(count(rcube_smtp::$sent[0][1]) === 3, 'To/Cc/Bcc envelope recipients must survive');
check(!in_array('bcc', array_map('strtolower', array_keys(rcube_smtp::$sent[0][2])), true), 'Bcc leaked on wire');
check(rcube_smtp::$sent[0][0] === 'one@example.org', 'Envelope sender must use selected identity');
check(rcube_imap::$saved[1][0] === 'two@example.org', 'Sent copy crossed accounts');
check(!str_contains($rc->db->rows[1]['meta_json'], 'pass_enc') && $rc->db->rows[1]['raw_mime'] === null, 'Sent payload/credentials not cleared');
check($rc->config->get('smtp_timeout') === 9, 'SMTP config was not restored');
echo "PASS multi-account SMTP, Cc/Bcc privacy, MIME/attachment preservation, Sent, secret cleanup\n";

[$rc, $plugin] = fixture(); add_row($rc, 1); rcube_smtp::$connect_fails = true;
$result = $plugin->run_cli_worker();
check($result['failed'] === 1 && !$result['sent'] && $rc->db->rows[1]['status'] === 'error' && !rcube_imap::$saved, 'SMTP failure must be visible without fallback');
echo "PASS failed SMTP is visible and is not submitted through PHP mail()\n";

[$rc, $plugin] = fixture(); add_row($rc, 1); rcube_smtp::$send_fails = true;
$plugin->run_cli_worker(); $plugin->run_cli_worker();
check(count(rcube_smtp::$sent) === 1 && $rc->db->rows[1]['status'] === 'error', 'Ambiguous SMTP acceptance was retried');
echo "PASS ambiguous SMTP failure is never automatically resent\n";

[$rc, $plugin] = fixture(); add_row($rc, 1); rcube_imap::$fail_save = true;
$result = $plugin->run_cli_worker();
check($result['sent'] === 1 && $rc->db->rows[1]['status'] === 'sent_copy_error', 'Sent-copy failure lost delivered state');
rcube_imap::$fail_save = false; $rc->db->now += 301; $result = $plugin->run_cli_worker();
check(count(rcube_smtp::$sent) === 1 && $rc->db->rows[1]['status'] === 'sent', 'Sent-copy retry resent the email');
echo "PASS Sent-copy recovery never resends SMTP\n";

[$rc, $plugin] = fixture(); add_row($rc, 1); $rc->db->busy = true;
check($plugin->run_cli_worker()['busy'] && !rcube_smtp::$sent, 'Concurrent worker ignored lock');
$rc->db->busy = false; $rc->db->read_error = true;
try { $plugin->run_cli_worker(); throw new RuntimeException('DB read failure was hidden'); } catch (RuntimeException $e) { check($e->getMessage() === 'Queue database read failed', 'Unexpected DB error'); }
echo "PASS concurrency lock and database failure reporting\n";

[$rc, $plugin] = fixture();
$rc->config->data += ['smtp_host' => 'tls://%h:587', 'smtp_user' => '%u', 'smtp_pass' => '%p'];
$_SESSION = ['storage_host' => 'mail.example.org', 'storage_port' => 993, 'storage_ssl' => 'ssl'];
$prop = new ReflectionProperty($plugin, 'rc'); $prop->setValue($plugin, $rc);
$method = new ReflectionMethod($plugin, '_ss_capture_smtp_credentials');
$captured = $method->invoke($plugin);
check(!str_contains(json_encode($captured), 'test-password-only'), 'Plain password persisted');
check($rc->decrypt($captured['smtp_pass_enc']) === 'test-password-only', 'Encrypted credential cannot round-trip');
check($captured['smtp_host'] === 'tls://mail.example.org:587' && $captured['smtp_user'] === 'fixture@example.org', 'Connection settings not resolved');
echo "PASS captured credentials are encrypted and SMTP placeholders resolved\n";

$attachment_method = new ReflectionMethod($plugin, '_ss_build_mime_from_compose');
$selected = ['attachments' => ['a' => ['name' => 'selected.txt', 'content' => 'SELECTED-COMPOSE-A']]];
$other = ['attachments' => ['b' => ['name' => 'other.txt', 'content' => 'UNRELATED-COMPOSE-B']]];
$context = ['id' => 'a', 'bucket' => $selected, 'session' => ['compose_data_a' => $selected, 'compose_data_b' => $other]];
$raw = $attachment_method->invoke($plugin, 'a', 'fixture@example.org', 'to@example.net', '', '', 'Fixture', 'Body', false, ['a'], $context);
check(str_contains($raw, 'selected.txt') && !str_contains($raw, 'other.txt'), 'Attachments leaked between compose tabs');
$raw = $attachment_method->invoke($plugin, 'a', 'fixture@example.org', 'to@example.net', '', '', 'Fixture', 'Body', false, ['unknown'], $context);
check($raw === '', 'Unknown attachment IDs included unrelated data');
echo "PASS attachment selection is confined to the selected compose tab\n";
