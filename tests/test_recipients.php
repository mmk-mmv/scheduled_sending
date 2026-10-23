<?php
// Modified by Scheduled Sending contributors, 2026-10-23. GPL-3.0; see LICENSE and CREDITS.md.
// Isolated regression test: real Roundcube input/parser libraries, fake DB/SMTP/IMAP.
// No production bootstrap, queue, credentials, or network transport is used.
if (!getenv('ROUNDCUBE_CORE')) throw new RuntimeException('Real Roundcube parser required');
require __DIR__ . '/test_worker.php';
foreach (['rcube_smtp', 'rcube_imap'] as $class) {
    check((new ReflectionClass($class))->getFileName() === __DIR__ . '/test_worker.php', 'Real transport must never be used by tests');
}
class ScheduleTestDB extends TestDB {
    private $insert_id = 0;
    public function query($sql, ...$args) {
        if (str_starts_with($sql, 'INSERT INTO')) {
            [$user_id, $identity_id, $scheduled_at, $raw, $meta, $dedupe] = $args;
            foreach ($this->rows as $row) if (($row['dedupe_key'] ?? '') === $dedupe) return new TestRows([]);
            $id = ++$this->insert_id;
            $this->rows[$id] = ['id' => $id, 'user_id' => $user_id, 'identity_id' => $identity_id,
                'scheduled_at' => $scheduled_at, 'status' => 'queued', 'raw_mime' => $raw, 'meta_json' => $meta, 'dedupe_key' => $dedupe];
            return new TestRows([]);
        }
        if (str_starts_with($sql, 'UPDATE') && str_contains($sql, 'SET meta_json=?')) {
            [$meta, $id, $user] = $args;
            if ($this->rows[$id]['user_id'] === $user) $this->rows[$id]['meta_json'] = $meta;
            return new TestRows([]);
        }
        if (str_starts_with($sql, 'SELECT id, meta_json')) {
            return new TestRows(array_values(array_filter($this->rows, fn($r) => $r['dedupe_key'] === $args[0] && $r['user_id'] === $args[1])));
        }
        return parent::query($sql, ...$args);
    }
    public function startTransaction() { return true; }
    public function endTransaction() { return true; }
    public function rollbackTransaction() { return true; }
    public function insert_id() { return $this->insert_id; }
}
function schedule_fixture($recipients, $raw = '') {
    [$rc, $plugin] = fixture();
    $rc->db = new ScheduleTestDB;
    $rc->config->data += ['drafts_mbox' => '', 'smtp_user' => '%u', 'smtp_pass' => '%p'];
    $_SESSION = ['storage_host' => 'mail.example.org', 'storage_port' => 993, 'storage_ssl' => 'ssl'];
    $_GET = [];
    $_POST = array_merge(['_from' => '"Absender, Büro" <sender@example.org>', '_subject' => 'Test <Planung> & Grüße',
        '_ss_request_id' => 'fixture-request-00001', '_schedule_ts' => time() + 3600, '_message' => 'Isolated test body', '_raw_mime' => $raw], $recipients);
    $_REQUEST = $_POST;
    (new ReflectionProperty($plugin, 'rc'))->setValue($plugin, $rc);
    return [$rc, $plugin];
}
function schedule_case($fields, $expected, $raw = '') {
    [$rc, $plugin] = schedule_fixture($fields, $raw);
    $plugin->action_schedule();
    check(count($rc->db->rows) === 1, 'Valid addresses must enter the fake queue');
    $row = $rc->db->rows[1];
    $meta = json_decode($row['meta_json'], true);
    foreach (['to', 'cc', 'bcc'] as $field) {
        $value = array_key_exists('_'.$field, $fields) ? $fields['_'.$field] : null;
        if ($value !== null) check($meta[$field] === trim($value), 'Address syntax or display names lost in '.$field);
    }
    check($meta['subj'] === 'Test <Planung> & Grüße', 'Subject angle brackets or Unicode lost');
    check($meta['from'] === '"Absender, Büro" <sender@example.org>', 'Named sender lost');
    check(!rcube_smtp::$sent && !rcube_smtp::$connections, 'Scheduling must not submit mail');
    $rc->db->now += 3601;
    $result = $plugin->run_cli_worker(); // Stub transports only, as asserted above.
    check($result['sent'] === 1 && $result['failed'] === 0, 'Fake worker could not parse scheduled recipient metadata');
    $actual = rcube_smtp::$sent[0][1]; sort($actual); sort($expected);
    check($actual === $expected, 'Envelope recipients differ from compose input');
    check(!in_array('bcc', array_map('strtolower', array_keys(rcube_smtp::$sent[0][2])), true), 'Bcc visible in MIME headers');
    return [$rc, $row];
}

schedule_case(['_to' => '"Doe, Jane (Example)" <jane@example.net>, second+tag@example.net,',
    '_cc' => 'Example Club <club@example.org>,',
    '_bcc' => '"Müller, Büro" <hidden@example.net>,'],
    ['jane@example.net', 'second+tag@example.net', 'club@example.org', 'hidden@example.net']);
echo "PASS actual compose input through fake queue and worker: named/multiple/Unicode/plus addresses, To/Cc/Bcc\n";
schedule_case(['_to' => '', '_cc' => '"Müller, Büro" <cc@example.net>', '_bcc' => ''], ['cc@example.net']);
schedule_case(['_to' => '', '_cc' => '', '_bcc' => '"Verdeckt, Büro" <hidden@example.net>'], ['hidden@example.net']);
schedule_case(['_to' => 'plain@example.net', '_cc' => '', '_bcc' => ''], ['plain@example.net']);
echo "PASS plain addresses, Cc-only and Bcc-only messages\n";
$raw = "To: \"Empfänger, Büro\" <raw@example.net>\r\nCc: rawcc@example.net\r\nBcc: oldhidden@example.net\r\nSubject: Fixture\r\n\r\nRaw body\r\n";
schedule_case(['_bcc' => ''], ['raw@example.net', 'rawcc@example.net'], $raw);
echo "PASS missing fields use MIME headers; explicitly cleared Bcc is never restored\n";
foreach ([
    ['_to' => '"Doe, Jane" ,', '_cc' => 'Nur Name ,', '_bcc' => ''],
    ['_to' => '', '_cc' => '', '_bcc' => ''],
    ['_to' => 'good@example.net, "Nur Name"', '_cc' => '', '_bcc' => ''],
    ['_to' => 'good@example.net', '_cc' => 'not-an-email', '_bcc' => ''],
    ['_to' => "good@example.net\r\nBcc: injected@example.net", '_cc' => '', '_bcc' => ''],
    ['_to' => ['array@example.net'], '_cc' => '', '_bcc' => ''],
] as $fields) {
    [$rc, $plugin] = schedule_fixture($fields);
    $plugin->action_schedule();
    check(!$rc->db->rows && !rcube_smtp::$connections && !rcube_smtp::$sent, 'Invalid input must be rejected before queue insertion');
    check($rc->output->responses === 1 && $rc->output->commands[0][2] === 'error', 'Invalid input must show compose error');
}
echo "PASS missing/mixed-invalid recipients, arrays and header injection rejected before queue insertion\n";
[$rc, $plugin] = fixture(); add_row($rc, 1);
$rc->db->rows[1]['status'] = 'error';
$before = $rc->db->rows;
$plugin->run_cli_worker();
check($rc->db->rows === $before && !rcube_smtp::$connections && !rcube_smtp::$sent, 'Existing error row was changed or retried');
echo "PASS existing error rows stay unchanged and are never retried\n";
