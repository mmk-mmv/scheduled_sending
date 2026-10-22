<?php
// Modified by Scheduled Sending contributors, 2026-10-23. GPL-3.0; see LICENSE and CREDITS.md.
// Destructive only inside a database explicitly named scheduled_sending_test.
// SMTP and IMAP remain the isolated stubs asserted by test_recipients.php.
require __DIR__ . '/test_reliability.php';
$dsn = getenv('TEST_MYSQL_DSN');
if (!$dsn) throw new RuntimeException('TEST_MYSQL_DSN is required');
$pdo = new PDO($dsn, getenv('TEST_MYSQL_USER') ?: 'root', getenv('TEST_MYSQL_PASSWORD') ?: '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
check($pdo->query('SELECT DATABASE()')->fetchColumn() === 'scheduled_sending_test', 'Refusing any database except scheduled_sending_test');
$pdo->exec('DROP TABLE IF EXISTS scheduled_queue');
$schema = file_get_contents(dirname(__DIR__) . '/SQL/mysql.initial.sql');
foreach (explode(';', $schema) as $sql) if (trim($sql)) $pdo->exec($sql);
class PdoTestDB {
    public $pdo, $error = false, $queries = 0, $fail_commit = false;
    public function __construct($pdo) { $this->pdo = $pdo; }
    public function query($sql, ...$args) {
        $this->queries++;
        try { $q = $this->pdo->prepare($sql); $q->execute($args); return $q; }
        catch (PDOException $e) { $this->error = true; throw $e; }
    }
    public function fetch_assoc($q) { return $q->fetch(PDO::FETCH_ASSOC); }
    public function affected_rows($q = null) { return $q ? $q->rowCount() : 0; }
    public function is_error() { return $this->error; }
    public function startTransaction() { return $this->pdo->beginTransaction(); }
    public function endTransaction() { if ($this->fail_commit) return false; return $this->pdo->commit(); }
    public function rollbackTransaction() { return $this->pdo->rollBack(); }
}
function db_fixture($pdo, $user = 7) {
    [$rc, $plugin] = schedule_fixture(['_to' => 'recipient@example.net', '_from' => '1']);
    $rc->user->ID = $user;
    $rc->db = new PdoTestDB($pdo);
    return [$rc, $plugin];
}
function fresh_output($rc) { $rc->output = new TestOutput; }
function changed($rc) { return count(array_filter($rc->output->commands, fn($c) => $c[0] === 'plugin.scheduled_sending.queue_changed')) > 0; }
function input($values) { $_GET = []; $_POST = $_REQUEST = $values; }
[$rc, $plugin] = db_fixture($pdo);
$plugin->action_schedule();
$id = (int) $pdo->query('SELECT id FROM scheduled_queue')->fetchColumn();
check($id > 0 && !$pdo->inTransaction(), 'Scheduling did not commit');
$original = $pdo->query('SELECT * FROM scheduled_queue')->fetch(PDO::FETCH_ASSOC);
fresh_output($rc); $_POST['_subject'] = 'Uncertain response retried';
$plugin->action_schedule();
check($pdo->query('SELECT COUNT(*) FROM scheduled_queue')->fetchColumn() == 1, 'Repeated request created another job');
check($pdo->query('SELECT * FROM scheduled_queue')->fetch(PDO::FETCH_ASSOC) === $original, 'Duplicate request changed original payload or credentials');
check($rc->output->commands[0][1] === 'already_scheduled', 'Duplicate was not acknowledged');
echo "PASS MariaDB: identical request is atomic and idempotent, original payload unchanged\n";

// Verify isolation during a transaction using an independent connection.
$observer = new PDO($dsn, getenv('TEST_MYSQL_USER') ?: 'root', getenv('TEST_MYSQL_PASSWORD') ?: '');
$pdo->beginTransaction();
$pdo->exec("UPDATE scheduled_queue SET status='processing' WHERE id=$id");
check($observer->query("SELECT status FROM scheduled_queue WHERE id=$id")->fetchColumn() === 'queued', 'Observer saw uncommitted state');
$pdo->rollBack();
[$other, $other_plugin] = db_fixture($pdo, 8);
$other_plugin->action_schedule();
check($pdo->query('SELECT COUNT(*) FROM scheduled_queue')->fetchColumn() == 2, 'Idempotency crossed user boundary');
$pdo->exec('DELETE FROM scheduled_queue WHERE user_id=8');
echo "PASS MariaDB: uncommitted state remains invisible; request keys are scoped per user\n";

foreach (['action_queue_cancel' => ['id' => $id], 'action_queue_reschedule' => ['id' => $id, 'at_ts' => time()+7200], 'action_queue_delete' => ['_id' => $id]] as $method => $fields) {
    [$other, $other_plugin] = db_fixture($pdo, 8); input($fields);
    $other_plugin->$method();
    check(!changed($other) && $pdo->query('SELECT * FROM scheduled_queue')->fetch(PDO::FETCH_ASSOC) === $original, 'Cross-owner mutation allowed: '.$method);
    [$rc, $plugin] = db_fixture($pdo); input($fields); $rc->request_valid = false;
    $before_queries = $rc->db->queries; $plugin->$method();
    check($rc->db->queries === $before_queries && !changed($rc), 'Invalid CSRF request reached database');
}
$source = new ReflectionMethod($other_plugin, 'ss_queue_source');
check($source->invoke($other_plugin, $id) === false, 'Cross-owner MIME source exposed');
$other_plugin->action_queue_list(); $other_plugin->action_queue_count();
foreach ($other->output->commands as $command) {
    if ($command[0] === 'plugin.scheduled_sending.queue_data') check($command[1] === [], 'Cross-owner list exposed');
    if ($command[0] === 'plugin.scheduled_sending.queue_count') check($command[1]['count'] === 0, 'Cross-owner count exposed');
}
echo "PASS MariaDB: cross-owner list/count/download/mutations denied; invalid CSRF rejected before SQL\n";

[$rc, $plugin] = db_fixture($pdo);
foreach (['processing', 'sent', 'sent_copy_error'] as $status) {
    $pdo->prepare('UPDATE scheduled_queue SET status=? WHERE id=?')->execute([$status, $id]);
    foreach (['action_queue_cancel' => ['id'=>$id], 'action_queue_reschedule' => ['id'=>$id,'at_ts'=>time()+7200], 'action_queue_delete'=>['_id'=>$id]] as $method => $fields) {
        input($fields); fresh_output($rc); $plugin->$method();
        check(!changed($rc) && $pdo->query("SELECT status FROM scheduled_queue WHERE id=$id")->fetchColumn() === $status, 'Protected state changed: '.$status.'/'.$method);
    }
}
$pdo->exec("UPDATE scheduled_queue SET status='error' WHERE id=$id");
input(['id'=>$id, 'at_ts'=>time()+7200]); fresh_output($rc); $plugin->action_queue_reschedule();
check(!changed($rc) && $pdo->query("SELECT status FROM scheduled_queue WHERE id=$id")->fetchColumn() === 'error', 'Held ambiguous failure was requeued');
$pdo->exec("UPDATE scheduled_queue SET status='queued' WHERE id=$id");
foreach (['1 OR 1=1', '1abc', '-1', '0', ['1']] as $invalid) {
    input(['id'=>$invalid]); fresh_output($rc); $plugin->action_queue_cancel();
    check(!changed($rc), 'Invalid job ID accepted');
}
input(['id'=>$id, 'at_ts'=>time()-30]); fresh_output($rc); $plugin->action_queue_reschedule();
check(!changed($rc), 'Past schedule accepted');
echo "PASS MariaDB: processing/sent/Sent-copy states protected; malformed IDs and past times rejected\n";

foreach (['action_queue_reschedule'=>['id'=>$id,'at_ts'=>time()+7200], 'action_queue_cancel'=>['id'=>$id], 'action_queue_delete'=>['_id'=>$id]] as $method=>$fields) {
    input($fields); fresh_output($rc); $plugin->$method(); check(changed($rc), 'Valid owner operation not confirmed: '.$method);
}
check($pdo->query('SELECT COUNT(*) FROM scheduled_queue')->fetchColumn() == 0, 'Owner delete failed');
echo "PASS MariaDB: valid owner cancel/reschedule/delete confirms actual affected row\n";

[$rc, $plugin] = db_fixture($pdo); $rc->db->fail_commit = true;
$plugin->action_schedule();
check(!$pdo->inTransaction() && $pdo->query('SELECT COUNT(*) FROM scheduled_queue')->fetchColumn() == 0, 'Commit failure left a visible queued row');
check(!array_filter($rc->output->commands, fn($c)=>$c[0]==='plugin.scheduled_sending.scheduled'), 'Commit failure reported success');
foreach (['attacker@example.org', '999', ['1']] as $sender) {
    [$rc, $plugin] = db_fixture($pdo); $_POST['_from'] = $sender;
    $plugin->action_schedule();
    check($pdo->query('SELECT COUNT(*) FROM scheduled_queue')->fetchColumn() == 0, 'Unowned sender entered queue');
}
echo "PASS MariaDB: failed commit rolls back; arbitrary addresses and unowned identities rejected\n";
check(!rcube_smtp::$sent && !rcube_smtp::$connections, 'Database tests must never send or connect to SMTP');
