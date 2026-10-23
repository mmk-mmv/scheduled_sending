<?php
// Modified by Scheduled Sending contributors, 2026-10-23. GPL-3.0; see LICENSE and CREDITS.md.
// Real MIME encoder and Roundcube decoder, isolated DB/SMTP/IMAP only.
require __DIR__ . '/test_reliability.php';
check(class_exists('Mail_mimePart'), 'Official Mail_Mime dependency required');
class UnicodeDraftStore {
    public $saved = [];
    public function folder_exists($folder) { return true; }
    public function save_message($folder, &$raw, ...$unused) { $this->saved[] = $raw; return 501; }
}
function unicode_subject($raw) {
    $p = rcube_mime::parse_message($raw);
    return rcube_mime::decode_header($p->headers['subject'], 'ISO-8859-1');
}
$fixture_dir = dirname(__DIR__).'/.cache/unicode-fixtures';
if (!is_dir($fixture_dir)) mkdir($fixture_dir, 0700, true);
$text = "ÄÖÜ äöü ß – Grüße für das Büro 😀\r\nZweite Zeile: café, 東京, Ελληνικά.\r\n";
$subject = 'Grüße für das Büro – ÄÖÜ äöü ß 😀';
foreach ([false, true, 'attachment'] as $mode) {
    [$rc, $plugin] = schedule_fixture(['_from'=>'1','_to'=>'"Müller, Büro" <to@example.net>', '_cc'=>'"Groß, Jörg" <cc@example.net>', '_bcc'=>'"Geheim, Änne" <hidden@example.net>', '_subject'=>$subject, '_message'=>$text]);
    $rc->storage = new UnicodeDraftStore;
    $rc->config->data['drafts_mbox'] = 'Drafts';
    $expected_body = $text;
    if ($mode === true) {
        $_POST['_message_html'] = $expected_body = '<p>Grüße für das <strong>Büro</strong> – äöü ß 😀</p>';
    }
    $bytes = "\x00\x01\x7F\x80\xFF" . $text;
    $filename = 'Prüfung ÄÖÜ ß 😀 – ' . str_repeat('Übersicht ', 12) . '.bin';
    if ($mode === 'attachment') {
        $_POST['_id'] = 'unicode-compose'; $_POST['_attach_ids'] = ['file1'];
        $_SESSION['compose_data_unicode-compose'] = ['attachments'=>['file1'=>['name'=>$filename,'mimetype'=>'application/octet-stream','content'=>$bytes]]];
    }
    $plugin->action_schedule();
    check(count($rc->db->rows) === 1, 'Unicode message did not enter isolated queue');
    $raw = $rc->db->rows[1]['raw_mime'];
    check(!preg_match('/[^\x00-\x7F]/', $raw), 'Generated MIME must be transport-safe ASCII');
    check(unicode_subject($raw) === $subject, 'Draft/queue subject decoded incorrectly with a legacy fallback');
    check(count($rc->storage->saved) === 1 && unicode_subject($rc->storage->saved[0]) === $subject, 'Actual Draft append used an unencoded subject');
    $kind = $mode === true ? 'html' : ($mode === 'attachment' ? 'attachment' : 'plain');
    file_put_contents($fixture_dir.'/'.$kind.'-draft.eml', $raw);
    file_put_contents($fixture_dir.'/'.$kind.'.json', json_encode(['subject'=>$subject, 'body'=>$expected_body,
        'filename'=>$mode==='attachment'?$filename:null, 'attachment_b64'=>base64_encode($bytes)],JSON_THROW_ON_ERROR));
    $parsed = rcube_mime::parse_message($raw);
    foreach (['to'=>'Müller, Büro', 'cc'=>'Groß, Jörg', 'bcc'=>'Geheim, Änne', 'from'=>'Absender, Büro'] as $field=>$name) {
        $decoded = rcube_mime::decode_address_list($parsed->headers[$field], null, true, 'ISO-8859-1');
        $decoded = (array) reset($decoded);
        check($decoded['name'] === $name, 'Display name lost in '.$field);
    }
    $bodypart = $mode === 'attachment' ? $parsed->parts[0] : $parsed;
    check(rtrim($bodypart->body,"\r\n") === rtrim($expected_body,"\r\n"), 'Unicode text/HTML changed after MIME decoding');
    if ($mode === 'attachment') {
        $attachment = $parsed->parts[1];
        check($attachment->body === $bytes, 'Binary attachment changed');
        check(str_contains($attachment->headers['content-disposition'], 'filename*0*='), 'Long Unicode filename lacks RFC2231 continuation');
    }
    $rc->db->now += 3601;
    $result = $plugin->run_cli_worker();
    check($result['sent'] === 1 && count(rcube_smtp::$sent) === 1, 'Unicode message failed with isolated transports');
    $wire_headers = rcube_smtp::$sent[0][2];
    check(rcube_mime::decode_header($wire_headers['Subject'], 'ISO-8859-1') === $subject, 'SMTP subject changed');
    check(!array_filter(array_keys($wire_headers), fn($k)=>strtolower($k)==='bcc'), 'Bcc visible on wire');
    check(unicode_subject(rcube_imap::$saved[0][2]) === $subject, 'Sent copy subject changed');
    file_put_contents($fixture_dir.'/'.$kind.'-sent.eml', rcube_imap::$saved[0][2]);
}
echo "PASS Unicode through compose, saved Draft, queue, simulated SMTP and Sent: umlauts/ß/emoji, display names, plain text/HTML, binary attachments and long RFC2231 filenames\n";

// A long subject must fold on encoded-word boundaries, never within a UTF-8 character.
[$rc,$plugin] = schedule_fixture(['_to'=>'to@example.net','_subject'=>str_repeat($subject.' ', 34).$subject]);
$plugin->action_schedule();
check(unicode_subject($rc->db->rows[1]['raw_mime']) === str_repeat($subject.' ',34).$subject, 'Long folded UTF-8 subject changed');
echo "PASS long multibyte subjects round-trip without broken encoded words\n";

// Correct already-encoded subjects and arbitrary existing MIME bodies stay intact.
[$rc,$plugin] = fixture(); add_row($rc,1);
$correct = Mail_mimePart::encodeHeader('Subject',$subject,'UTF-8','base64');
$normalize = new ReflectionMethod($plugin,'ss_encode_message_headers');
$existing = "Subject: $correct\r\nContent-Type: text/plain; charset=ISO-8859-1\r\nContent-Transfer-Encoding: quoted-printable\r\n\r\nGr=FC=DFe\r\n";
check($normalize->invoke($plugin,$existing) === $existing, 'Existing encoded MIME was rewritten');
// Reproduce pre-fix queued UTF-8 headers; normalize only at the normal due send.
$legacy = $rc->db->rows[1]['raw_mime'] = "Subject: $subject\r\n".$rc->db->rows[1]['raw_mime'];
$before_body = preg_split('/\r\n\r\n/',$legacy,2)[1];
$result=$plugin->run_cli_worker();
check($result['sent']===1 && rcube_smtp::$sent[0][3] === $before_body, 'Legacy body changed');
check(rcube_mime::decode_header(rcube_smtp::$sent[0][2]['Subject'],'ISO-8859-1') === $subject, 'Legacy queued subject not corrected before normal submission');
echo "PASS already encoded MIME untouched; legacy queued UTF-8 headers corrected at normal send without body/attachment changes\n";

[$rc,$plugin] = fixture(); add_row($rc,1);
$rc->db->rows[1]['raw_mime'] = "Subject: invalid \xC3(\r\n".$rc->db->rows[1]['raw_mime'];
$result=$plugin->run_cli_worker();
check($result['failed']===1 && !rcube_smtp::$connections && !rcube_smtp::$sent, 'Invalid header encoding reached SMTP');
echo "PASS malformed UTF-8 headers held before any SMTP connection\n";
