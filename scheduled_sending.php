<?php
// Modified by Scheduled Sending contributors, 2026-10-23. GPL-3.0; see LICENSE and CREDITS.md.
require_once __DIR__ . '/worker_send_due.inc.php';
require_once __DIR__ . '/queue.inc.php';
/**
 * Roundcube Scheduled Sending - clean rebuild (hotfix16b)
 * Compatible with Roundcube 1.6.x
 */
class scheduled_sending extends rcube_plugin
{
    const PLUGIN_VERSION = '1.4.0-beta.2';
    const PLUGIN_INFO = array(
        'name' => 'scheduled_sending',
        'vendor' => 'Gene Hawkins',
        'version' => self::PLUGIN_VERSION,
        'license' => 'GPL-3.0',
        'uri' => 'https://github.com/mmk-mmv/scheduled_sending',
    );

    public static function info(): array
    {
        return self::PLUGIN_INFO;
    }
    
	public $version = self::PLUGIN_VERSION;
        
    // Unified debug logger; respects config('scheduled_debug', false)
    private function ss_debug($payload) {
        try {
            $rc = $this->rc ?: rcmail::get_instance();
            if ($rc && ss_config_bool($rc->config->get('scheduled_debug', false)) && function_exists('write_log')) {
                if (!is_scalar($payload)) {
                    $payload = json_encode($payload);
                }
                write_log('scheduled_sending_debug', $payload);
            }
        } catch (Exception $e) {
            // never let logging break sending
        }
    }
// SS: normalize trivial HTML to text/plain, strip RC signature placeholder
    private function _ss_is_semantically_plain_html($html, $text_guess='')
    {
        if (!is_string($html) || $html === '') return true;
        $s = $html;
        // strip Roundcube signature placeholder blocks (any content inside)
        $s = preg_replace('~<div[^>]*id=["\']?_rc_sig["\']?[^>]*>.*?</div>~is', '', $s);
        // normalize nbsp and whitespace
        $s = str_ireplace('&nbsp;', ' ', $s);
        // Only p/br allowed? If other tags present, it's not plain
        if (preg_match('~<(?!/?(?:p|br)\b)~i', $s)) {
            return false;
        }
        // Reduce to text approximation
        $t = $s;
        $t = preg_replace('~</p>\s*<p[^>]*>~i', "\n\n", $t);
        $t = preg_replace('~<br\s*/?>~i', "\n", $t);
        $t = preg_replace('~</?p[^>]*>~i', '', $t);
        $t = preg_replace('~<[^>]+>~', '', $t); // any leftover
        $t = preg_replace("~\r\n?~", "\n", $t);
        $t = preg_replace("~[ \t]+\n~", "\n", $t);
        $t = trim($t);
        $plain = is_string($text_guess) ? trim(preg_replace("~\r\n?~", "\n", $text_guess)) : '';
        if ($plain !== '') {
            if ($t === $plain || rtrim($t, "\n") === rtrim($plain, "\n")) return true;
        }
        // If visible text equals the original stripped of HTML tags, call it plain
        $only_text = trim(strip_tags($s));
        return ($only_text === $t);
    }

    private function _ss_text_from_trivial_html($html)
    {
        $s = (string)$html;
        $s = preg_replace('~<div[^>]*id=["\']?_rc_sig["\']?[^>]*>.*?</div>~is', '', $s);
        $s = str_ireplace('&nbsp;', ' ', $s);
        $s = preg_replace('~</p>\s*<p[^>]*>~i', "\n\n", $s);
        $s = preg_replace('~<br\s*/?>~i', "\n", $s);
        $s = preg_replace('~</?p[^>]*>~i', '', $s);
        $s = preg_replace('~<[^>]+>~', '', $s);
        // Collapse multiple blank lines to at most 2
        $s = preg_replace("~\n{3,}~", "\n\n", $s);
        // Trim trailing whitespace
        $s = preg_replace("~[ \t]+\n~", "\n", $s);
        return trim($s);
    }

    use scheduled_sending_worker_trait, scheduled_sending_queue_trait;
    public $task = 'login|mail|settings|addressbook';
    private $rc;
    private $logname = 'scheduled_sending';
    
    /** 
     * Try to fetch the full raw MIME (with attachments) from the Drafts folder
     * matching the current Subject and most recent date.
     * Returns string MIME on success, or empty string on failure.
     */
    
    /** 
     * Try to fetch the full raw MIME (with attachments) from the Drafts folder.
     * Strategy:
     *  - exact Subject match, pick latest
     *  - else scan recent N drafts and pick the first with same Subject, and multipart content
     *  - require presence of "Content-Type: multipart/" to avoid picking a body-only draft
     * Logs decisions to scheduled_sending for debugging.
     */
    private function _ss_try_fetch_draft_mime($subject, $from_hint = '')
    {
        try {
            $rc = $this->rc ?: rcmail::get_instance();
            $cfg = $rc->config;
            $storage = $rc->get_storage();
            $drafts = $cfg->get('drafts_mbox', 'Drafts');
            if (empty($drafts)) return '';

            if (!$storage->folder_exists($drafts)) return '';
            $storage->set_folder($drafts);

            $uid_candidate = null;
            $picked_via = 'none';

            // 1) Exact Subject header search
            $index = $storage->search($drafts, 'HEADER', array('Subject' => $subject));
            if ($index && !empty($index->count)) {
                $last = null;
                foreach ($index->get() as $msg) { $last = $msg; }
                if ($last && isset($last->uid)) { $uid_candidate = $last->uid; $picked_via = 'subject_exact'; }
            }

            // 2) Fallback: recent drafts, prefer multipart
            if (!$uid_candidate) {
                $hdrs = $storage->list_messages($drafts, 1, null, null, null, 'DATE DESC', 50);
                if (is_array($hdrs)) {
                    foreach ($hdrs as $h) {
                        $h_subj = isset($h->subject) ? trim($h->subject) : '';
                        if ($h_subj === trim($subject)) {
                            $uid_candidate = $h->uid;
                            $picked_via = 'recent_subject';
                            break;
                        }
                    }
                }
            }

            if (!$uid_candidate) {
                $this->ss_debug(array('msg'=>'_ss_try_fetch_draft_mime no_candidate','subject'=>$subject));
                return '';
            }

            // Pull raw source (prefer get_raw_message)
            $raw = '';
            if (method_exists($storage, 'get_raw_message')) {
                try { $raw = (string)$storage->get_raw_message($uid_candidate, $drafts); } catch (\Exception $e) { $raw = ''; }
            }
            if ($raw === '' && method_exists($storage, 'get_raw_body')) {
                try { $raw = (string)$storage->get_raw_body($uid_candidate, $drafts); } catch (\Exception $e) { $raw = ''; }
            }
            if ($raw === '') {
                $msgobj = $storage->get_message($uid_candidate, $drafts);
                if ($msgobj && isset($msgobj->headers->raw) && isset($msgobj->body)) {
                    $raw = (string)$msgobj->headers->raw . "\r\n\r\n" . (string)$msgobj->body;
                } elseif ($msgobj && !empty($msgobj->body)) {
                    $raw = (string)$msgobj->body;
                }
            }

            if ($raw === '') {
                $this->ss_debug(array('msg'=>'_ss_try_fetch_draft_mime empty_raw','uid'=>$uid_candidate,'via'=>$picked_via));
                return '';
            }

            // Heuristic: require MIME headers and preferably multipart if we expect attachments
            $has_mime = (stripos($raw, "MIME-Version:") !== false);
            $is_multipart = (stripos($raw, "Content-Type: multipart/") !== false);
            $this->ss_debug(array('msg'=>'_ss_try_fetch_draft_mime picked','uid'=>$uid_candidate,'via'=>$picked_via,'mime'=>$has_mime,'multipart'=>$is_multipart));

            if (!$has_mime) return '';
            // Don't *require* multipart — user might send no attachments — but prefer it
            return $raw;
        } catch (\Exception $e) {
            $this->ss_debug(array('msg'=>'_ss_try_fetch_draft_mime fail','err'=>$e->getMessage()));
            return '';
        }
    }

    private function _ss_get_compose_context($compose_id)
    {
        try {
            if (session_status() !== PHP_SESSION_ACTIVE) {
                @session_start();
            }
        } catch (\Exception $e) {
        }

        $sess = (isset($_SESSION) && is_array($_SESSION)) ? $_SESSION : array();
        $ctx = array('id' => $compose_id, 'bucket' => null, 'session' => $sess);

        if (empty($ctx['id'])) {
            $pools = array();
            if (isset($sess['compose_data']) && is_array($sess['compose_data'])) {
                $pools[] = $sess['compose_data'];
            }
            if (isset($sess['rcmail.compose']) && is_array($sess['rcmail.compose'])) {
                $pools[] = $sess['rcmail.compose'];
            }
            if (isset($sess['rcmail']) && isset($sess['rcmail']['compose']) && is_array($sess['rcmail']['compose'])) {
                $pools[] = $sess['rcmail']['compose'];
            }
            foreach ($pools as $pool) {
                if (!is_array($pool) || !count($pool)) continue;
                $keys = array_keys($pool);
                if (!empty($keys)) {
                    $ctx['id'] = end($keys);
                    break;
                }
            }
        }

        $compose_keys = array();
        foreach ($sess as $k => $v) {
            if (stripos($k, 'compose') !== false) {
                $compose_keys[] = $k;
            }
        }
        if (!empty($compose_keys)) {
            $this->ss_debug(array('msg' => 'compose_session_keys', 'keys' => $compose_keys));
        }

        $bucket = null;
        $cid = $ctx['id'];
        if ($cid) {
            $direct = 'compose_data_' . $cid;
            if (isset($sess[$direct]) && is_array($sess[$direct])) {
                $bucket = $sess[$direct];
            } elseif (isset($sess['compose_data']) && isset($sess['compose_data'][$cid])) {
                $bucket = $sess['compose_data'][$cid];
            } elseif (isset($sess['rcmail.compose']) && isset($sess['rcmail.compose'][$cid])) {
                $bucket = $sess['rcmail.compose'][$cid];
            } elseif (isset($sess['rcmail']) && isset($sess['rcmail']['compose']) && isset($sess['rcmail']['compose'][$cid])) {
                $bucket = $sess['rcmail']['compose'][$cid];
            }
        }

        if (is_array($bucket)) {
            $ctx['bucket'] = $bucket;
            $this->ss_debug(array('msg' => 'compose_bucket_keys', 'compose_id' => $cid, 'keys' => array_keys($bucket)));
        } elseif ($cid) {
            $this->ss_debug(array('msg' => 'compose_bucket_missing', 'compose_id' => $cid));
        }

        return $ctx;
    }

    private function _ss_body_is_empty($body, $is_html)
    {
        if (!is_string($body) || $body === '') return true;
        $text = $body;
        if ($is_html) {
            $text = preg_replace('~<div[^>]*id=["\']?_rc_sig["\']?[^>]*>.*?</div>~is', '', $text);
            $text = strip_tags($text);
        }
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace("/\xC2\xA0/u", ' ', $text);
        $text = preg_replace('/\s+/u', ' ', $text);
        return trim($text) === '';
    }

    private function _ss_looks_like_base64_blob($string)
    {
        if (!is_string($string) || $string === '') return false;
        $sample = substr($string, 0, 256);
        $sample_stripped = preg_replace('/[\r\n\s]+/', '', $sample);
        if (strlen($sample_stripped) < 200) return false;
        return (bool)preg_match('/^[A-Za-z0-9+\/=]+$/', $sample_stripped);
    }

    private function _ss_extract_body_candidate($node)
    {
        if (!is_array($node)) return null;
        $stack = array(array('', $node));
        $visited = 0; $max_nodes = 4000;
        $best = null; $global_html = null;

        while ($stack) {
            $cur = array_pop($stack);
            $path = $cur[0];
            $val = $cur[1];
            if (!is_array($val)) continue;
            if (++$visited > $max_nodes) break;
            foreach ($val as $k => $v) {
                $key = is_string($k) ? $k : (string)$k;
                $lk = strtolower($key);
                $next_path = $path === '' ? $lk : $path . '.' . $lk;
                if ($lk === 'is_html' && !is_array($v) && $global_html === null) {
                    $global_html = (bool)$v;
                }
                if (is_array($v)) {
                    $stack[] = array($next_path, $v);
                    continue;
                }
                if (!is_string($v)) continue;
                $trim = trim($v);
                if ($trim === '') continue;
                if ($this->_ss_looks_like_base64_blob($trim)) continue;

                $length = strlen($trim);
                $score = null; $html = false;
                $pineedle = strpos($lk, 'plain') !== false;
                $msgneedle = strpos($lk, 'message') !== false;

                if (in_array($lk, array('html', 'body_html', 'message_html', 'htmlbody'))) {
                    $score = 100; $html = true;
                } elseif ($msgneedle && strpos($lk, 'html') !== false) {
                    $score = 95; $html = true;
                } elseif (strpos($lk, 'html') !== false && strpos($lk, 'subject') === false) {
                    $score = 85; $html = true;
                } elseif (in_array($lk, array('body', 'text', 'message', 'plain'))) {
                    $score = $pineedle ? 75 : 70;
                } elseif (strpos($lk, 'body') !== false || strpos($lk, 'text') !== false) {
                    $score = 60;
                }

                if ($score === null) continue;
                if ($length > 1000) {
                    $score += 5;
                } elseif ($length > 400) {
                    $score += 2;
                }

                $looks_html = $html;
                if (!$looks_html) {
                    if ($global_html) $looks_html = true;
                    elseif (strip_tags($trim) !== $trim) $looks_html = true;
                }
                if ($best === null || $score > $best['score']) {
                    $best = array('body' => $trim, 'is_html' => $looks_html, 'score' => $score, 'path' => $next_path);
                }
            }
        }
        return $best;
    }

    private function _ss_resolve_body_from_context($ctx, $body, $is_html)
    {
        if (!$this->_ss_body_is_empty($body, $is_html)) {
            return array('body' => $body, 'is_html' => $is_html);
        }
        $candidate = null;
        if (is_array($ctx)) {
            if (isset($ctx['bucket']) && is_array($ctx['bucket'])) {
                $candidate = $this->_ss_extract_body_candidate($ctx['bucket']);
            }
        }
        if ($candidate && !empty($candidate['body'])) {
            $this->ss_debug(array('msg' => 'compose_body_hydrated', 'is_html' => (int)$candidate['is_html'], 'path' => isset($candidate['path']) ? $candidate['path'] : '?', 'len' => strlen($candidate['body'])));
            return array('body' => $candidate['body'], 'is_html' => $candidate['is_html']);
        }
        return array('body' => $body, 'is_html' => $is_html);
    }

    private function _ss_capture_smtp_credentials()
    {
        return $this->mm_capture_credentials();
    }

    private function _ss_apply_smtp_credentials($meta)
    {
        $restore = array();

        if (!is_array($meta)) return $restore;

        try {
            $rc = $this->rc ?: rcmail::get_instance();
            $cfg = $rc->config;

            if (!empty($meta['smtp_user'])) {
                $restore['smtp_user'] = $cfg->get('smtp_user', null);
                $cfg->set('smtp_user', (string) $meta['smtp_user']);
            }
        } catch (Exception $e) {
            $this->ss_debug(array('msg' => 'smtp_credential_apply_failed', 'err' => $e->getMessage()));
        }

        return $restore;
    }

    private function _ss_restore_config_values($values)
    {
        if (!is_array($values) || !count($values)) return;

        try {
            $rc = $this->rc ?: rcmail::get_instance();
            foreach ($values as $key => $value) {
                $rc->config->set($key, $value);
            }
        } catch (Exception $e) {
            $this->ss_debug(array('msg' => 'smtp_config_restore_failed', 'err' => $e->getMessage()));
        }
    }

    private function _ss_split_raw_message($raw)
    {
        $parts = preg_split("/\r?\n\r?\n/", (string) $raw, 2);
        $raw_headers = isset($parts[0]) ? $parts[0] : '';
        $body = isset($parts[1]) ? $parts[1] : '';
        $headers = array();
        $current = '';

        foreach (preg_split("/\r?\n/", $raw_headers) as $line) {
            if ($line === '') continue;
            if (($line[0] === ' ' || $line[0] === "\t") && $current !== '') {
                $current .= ' ' . trim($line);
                continue;
            }
            if ($current !== '' && preg_match('/^([A-Za-z0-9\-]+):\s*(.*)$/', $current, $m)) {
                $headers[$m[1]] = $m[2];
            }
            $current = $line;
        }

        if ($current !== '' && preg_match('/^([A-Za-z0-9\-]+):\s*(.*)$/', $current, $m)) {
            $headers[$m[1]] = $m[2];
        }

        return array($headers, $body, $raw_headers);
    }

    private function _ss_headers_to_raw($headers, $body)
    {
        $lines = array();
        foreach ((array) $headers as $key => $value) {
            if (strcasecmp((string) $key, 'Bcc') === 0) continue;
            $lines[] = $key . ': ' . $value;
        }

        return implode("\r\n", $lines) . "\r\n\r\n" . (string) $body;
    }

    private function _ss_format_scheduled_at_for_display($utc)
    {
        try {
            $tz = (string) $this->rc->config->get('scheduled_timezone', 'UTC');
            if ($tz === '') $tz = 'UTC';
            $dt = new DateTime((string) $utc, new DateTimeZone('UTC'));
            $dt->setTimezone(new DateTimeZone($tz));
            return $dt->format('Y-m-d H:i:s');
        } catch (Exception $e) {
            $this->ss_debug(array('msg' => 'scheduled_at_display_tz_failed', 'err' => $e->getMessage()));
            return (string) $utc;
        }
    }

    private function _ss_public_plugin_asset_url($rel)
    {
        $rel = ltrim((string) $rel, '/');
        $script = '';

        foreach (array('SCRIPT_NAME', 'PHP_SELF') as $key) {
            if (!empty($_SERVER[$key])) {
                $script = (string) $_SERVER[$key];
                break;
            }
        }

        if ($script === '' && !empty($_SERVER['REQUEST_URI'])) {
            $script = parse_url((string) $_SERVER['REQUEST_URI'], PHP_URL_PATH);
        }

        $script = str_replace('\\', '/', (string) $script);
        $base = '';

        if ($script !== '' && preg_match('~^(.*?)/public_html(?:/.*)?$~', $script, $m)) {
            $base = $m[1];
        } elseif ($script !== '') {
            $base = rtrim(dirname($script), '/');
            if (basename($base) === 'public_html') {
                $base = rtrim(dirname($base), '/');
            }
        }

        return ($base === '' ? '' : $base) . '/plugins/scheduled_sending/' . $rel;
    }

    private function _ss_format_from_header($identity)
    {
        if (is_object($identity)) {
            $identity = (array) $identity;
        }
        if (!is_array($identity) || empty($identity['email'])) return '';

        $email = trim((string) $identity['email']);
        if ($email === '' || preg_match('/[\r\n]/', $email)) return '';

        $name = isset($identity['name']) && trim((string) $identity['name']) !== ''
            ? trim((string) $identity['name'])
            : $email;

        if (preg_match('/[\r\n]/', $name)) {
            $name = $email;
        }

        return sprintf('"%s" <%s>', addcslashes($name, '"\\'), $email);
    }

    private function _ss_resolve_from_identity($posted_from)
    {
        $rc = $this->rc ?: rcmail::get_instance();
        if (!is_string($posted_from) && $posted_from !== null) return ['id' => 0, 'from' => '', 'identity' => []];
        $posted = trim((string) $posted_from);
        $identity = null;
        // Always resolve through the authenticated user's identities. Never accept
        // an arbitrary From address supplied by a browser or another account's ID.
        $identities = $rc->user && method_exists($rc->user, 'list_identities')
            ? $rc->user->list_identities() : [];
        foreach ((array) $identities as $candidate) {
            $candidate = (array) $candidate;
            if (empty($candidate['email'])) continue;
            $id = (int) ($candidate['identity_id'] ?? $candidate['id'] ?? 0);
            if ($posted !== '') {
                if (ctype_digit($posted)) {
                    if ($id !== (int) $posted) continue;
                } else {
                    $email = $posted;
                    if (preg_match('/<([^<>]+)>$/D', $posted, $m)) $email = trim($m[1]);
                    if (strcasecmp($email, $candidate['email']) !== 0) continue;
                }
                $identity = $candidate;
                break;
            }
            if ($identity === null || !empty($candidate['standard'])) $identity = $candidate;
            if (!empty($candidate['standard'])) break;
        }
        $identity_id = (int) ($identity['identity_id'] ?? $identity['id'] ?? 0);
        $from = $this->_ss_format_from_header($identity);
        return ['id' => $identity_id, 'from' => $from, 'identity' => $identity ?: []];
    }

    private function _ss_ensure_from_header($raw, $from)
    {
        $raw = (string) $raw;
        $from = trim((string) $from);
        if ($raw === '' || $from === '') return $raw;

        if (preg_match('/^From:\s*\S/im', $raw)) {
            return $raw;
        }

        if (preg_match('/^From:\s*$/im', $raw)) {
            return preg_replace('/^From:\s*$/im', 'From: ' . $from, $raw, 1);
        }

        return 'From: ' . $from . "\r\n" . $raw;
    }

    private function _ss_read_recipients($raw_mime = '')
    {
        $headers = $raw_mime !== '' ? $this->ss_parse_headers($raw_mime) : array();
        $recipients = array();
        foreach (array('to' => 'An', 'cc' => 'Cc', 'bcc' => 'Bcc') as $field => $label) {
            // Roundcube's default HTML filter removes <address@example.org>.
            // Preserve address syntax here, validate it, and escape only for display.
            $value = rcube_utils::get_input_value('_' . $field, rcube_utils::INPUT_POST, true);
            if ($value === null) $value = isset($headers[$field]) ? $headers[$field] : '';
            if (!is_string($value) || preg_match('/[\r\n\x00]/', $value)) {
                throw new InvalidArgumentException('Planung fehlgeschlagen: Ungültige Empfängerangabe im Feld ' . $label . '.');
            }
            $value = trim($value);
            if ($value !== '') {
                $addresses = rcube_mime::decode_address_list($value, null, false, null, true);
                if (!$addresses) {
                    throw new InvalidArgumentException('Planung fehlgeschlagen: E-Mail-Adresse im Feld ' . $label . ' prüfen.');
                }
                foreach ($addresses as $address) {
                    if (filter_var($address, FILTER_VALIDATE_EMAIL) === false) {
                        throw new InvalidArgumentException('Planung fehlgeschlagen: E-Mail-Adresse im Feld ' . $label . ' prüfen.');
                    }
                }
            }
            $recipients[$field] = $value;
        }
        if (!$this->_ss_has_recipient($recipients['to'], $recipients['cc'], $recipients['bcc'])) {
            throw new InvalidArgumentException('Planung fehlgeschlagen: Mindestens eine gültige Empfängeradresse ist erforderlich.');
        }
        return $recipients;
    }

    private function _ss_has_recipient($to, $cc = '', $bcc = '')
    {
        foreach (array($to, $cc, $bcc) as $list) {
            if ($this->mm_addresses($list)) return true;
        }
        return false;
    }

    /**
     * Build full MIME using Roundcube compose session (attachments in $_SESSION).
     * Returns raw RFC822 or empty string on failure.
     */
    
    /**
     * Build MIME from Roundcube compose session.
     * - Recursively searches $_SESSION for attachment-like entries under any compose bucket.
     * - If $attach_ids is provided (array of ids/keys), only include those.
     */
    private function _ss_build_mime_from_compose($compose_id, $from, $to, $cc, $bcc, $subject, $body, $is_html, $attach_ids = null, $compose_ctx = null)
    {
        try {
            if ($compose_ctx === null || !is_array($compose_ctx)) {
                $compose_ctx = $this->_ss_get_compose_context($compose_id);
            }
            $compose_id = isset($compose_ctx['id']) ? $compose_ctx['id'] : $compose_id;
            $sess = (isset($compose_ctx['session']) && is_array($compose_ctx['session'])) ? $compose_ctx['session'] : ((isset($_SESSION) && is_array($_SESSION)) ? $_SESSION : array());
            $bucket = isset($compose_ctx['bucket']) ? $compose_ctx['bucket'] : null;
            if ($bucket === null && $compose_id) {
                $this->ss_debug(array('msg'=>'compose_bucket_missing','compose_id'=>$compose_id));
            }

            $attachments = array();
            $seen_attachments = array();
            $collect_attachment = function($key, $arr) use (&$attachments, &$seen_attachments) {
                if (!is_array($arr)) return;
                $name = isset($arr['name']) ? $arr['name'] : (isset($arr['filename']) ? $arr['filename'] : 'file');
                $type = isset($arr['mimetype']) ? $arr['mimetype'] : (isset($arr['type']) ? $arr['type'] : 'application/octet-stream');
                $path = null;
                if (!empty($arr['path'])) $path = $arr['path'];
                elseif (!empty($arr['file'])) $path = $arr['file'];
                elseif (!empty($arr['tmp_name'])) $path = $arr['tmp_name'];
                $inline_data = null;
                $inline_b64  = null;
                if ($path && !@is_readable($path)) {
                    $path = null;
                }
                if (!$path) {
                    if (isset($arr['content_b64'])) $inline_b64 = $arr['content_b64'];
                    elseif (isset($arr['content'])) $inline_data = $arr['content'];
                    elseif (isset($arr['data'])) $inline_data = $arr['data'];
                    elseif (isset($arr['body'])) $inline_data = $arr['body'];
                    elseif (isset($arr['contents'])) $inline_data = $arr['contents'];
                }
                $has_inline = ($inline_b64 !== null) || ($inline_data !== null && $inline_data !== '');
                if (!$path && !$has_inline) {
                    return;
                }
                $fingerprint = $path ? ('path:' . $path) : ('mem:' . ($inline_b64 !== null ? sha1($inline_b64) : sha1((string)$inline_data)));
                if (isset($seen_attachments[$fingerprint])) {
                    return;
                }
                $seen_attachments[$fingerprint] = true;
                $attachments[$key] = array(
                    'name' => $name,
                    'type' => $type ?: 'application/octet-stream',
                    'path' => $path,
                    'data' => $inline_data,
                    'data_b64' => $inline_b64,
                );
            };

            if (is_array($bucket) && isset($bucket['attachments']) && is_array($bucket['attachments'])) {
                foreach ($bucket['attachments'] as $akey=>$aval) {
                    if (is_array($aval)) {
                        $this->ss_debug(array('msg'=>'compose_bucket_attachment_keys','aid'=>$akey,'keys'=>array_keys($aval)));
                        if (empty($aval['path']) && empty($aval['file']) && empty($aval['tmp_name']) && isset($aval['id'])) {
                            try {
                                $rc = $this->rc ?: rcmail::get_instance();
                                $tmpdir = $rc->config->get('temp_dir');
                                if ($tmpdir) {
                                    $try = rtrim($tmpdir, '/').'/'.$aval['id'];
                                    if (is_readable($try)) $aval['path'] = $try;
                                    else {
                                        $alt = rtrim($tmpdir, '/').'/rcmattach-'.$aval['id'];
                                        if (is_readable($alt)) $aval['path'] = $alt;
                                    }
                                }
                            } catch (\Exception $e) {}
                        }
                        $collect_attachment($akey, $aval);
                    }
                }
            }

            // Gather possible attachment arrays by recursive walk (depth-limited)
            $visited = 0;
            $max_nodes = 2000;

            $walk = function($node, $path='') use (&$walk, &$attachments, &$visited, $max_nodes, $collect_attachment) {
                if ($visited++ > $max_nodes) return;
                if (is_array($node)) {
                    // detect array-of-attachments
                    $keys = array_keys($node);
                    $has_file_ref = (isset($node['path']) || isset($node['file']) || isset($node['tmp_name']));
                    $has_inline = (isset($node['content']) || isset($node['data']) || isset($node['body']) || isset($node['contents']) || isset($node['content_b64']));
                    $is_attachment = (isset($node['name']) && ($has_file_ref || $has_inline));
                    if ($is_attachment) {
                        $collect_attachment($path, $node);
                        return;
                    }
                    // walk children
                    foreach ($node as $k=>$v) {
                        $npath = ($path === '') ? (string)$k : ($path.'.'.$k);
                        $walk($v, $npath);
                    }
                }
            };

            // Prefer obvious compose buckets first
            $candidates = array();
            $keys_try = array(
                'compose_data_'.$compose_id,
                'compose_data',
                'rcmail.compose',
                'compose',
                'rcmail',
            );
            foreach ($keys_try as $ck) {
                if (isset($sess[$ck])) $candidates[] = $sess[$ck];
                // nested dotted path e.g. rcmail.compose
                if (strpos($ck,'.') !== false) {
                    list($a,$b) = explode('.', $ck, 2);
                    if (isset($sess[$a]) && is_array($sess[$a]) && isset($sess[$a][$b])) $candidates[] = $sess[$a][$b];
                }
            }
            if (!count($candidates)) $candidates[] = $sess;

            // Never collect attachments from another compose tab in this user's session.
            $candidates = is_array($bucket) ? array($bucket) : array();
            foreach ($candidates as $cand) $walk($cand, '');

            // If client posted specific attach ids, filter by those
            if (is_array($attach_ids) && count($attach_ids)) {
                $filtered = array();
                foreach ($attachments as $k=>$a) {
                    foreach ($attach_ids as $want) {
                        if ($k === $want || substr($k, -strlen($want)) === $want) {
                            $filtered[$k] = $a; break;
                        }
                    }
                }
                $attachments = $filtered;
            }

            $this->ss_debug(array('msg'=>'compose_session_found_attach','count'=>count($attachments)));

            if (!count($attachments)) return '';

            // Build multipart/mixed as before
            $nl = "\r\n";
            $boundary = '=_SS_' . bin2hex(random_bytes(12));

            $headers = array();
            if ($from !== '') $headers[] = 'From: ' . $from;
            if ($to !== '') $headers[] = 'To: ' . $to;
            if ($cc !== '') $headers[] = 'Cc: ' . $cc;
            if ($bcc !== '') $headers[] = 'Bcc: ' . $bcc;
            if ($subject !== '') $headers[] = 'Subject: ' . $subject;
            $headers[] = 'Message-ID: <ss-' . bin2hex(random_bytes(8)) . '@localhost>';
            $headers[] = 'MIME-Version: 1.0';
            $headers[] = 'Content-Type: multipart/mixed; boundary="' . $boundary . '"';

            $body_part  = '--' . $boundary . $nl;
            $body_part .= ($is_html ? 'Content-Type: text/html; charset=UTF-8' : 'Content-Type: text/plain; charset=UTF-8') . $nl;
            $body_part .= 'Content-Transfer-Encoding: 8bit' . $nl . $nl;
            $body_part .= (string)$body . $nl;

            $att_parts = '';
            foreach ($attachments as $k=>$a) {
                $data = '';
                if (!empty($a['path']) && @is_readable($a['path'])) {
                    $data = @file_get_contents($a['path']);
                } elseif (!empty($a['data_b64'])) {
                    $decoded = base64_decode($a['data_b64'], true);
                    if ($decoded !== false) $data = $decoded;
                } elseif (isset($a['data'])) {
                    $data = $a['data'];
                }
                if ($data === false || $data === null) continue;
                if ($data === '' && $data !== '0') continue;
                $b64 = rtrim(chunk_split(base64_encode($data)));
                $fname = addcslashes($a['name'], '\"\\');
                $ctype = $a['type'] ?: 'application/octet-stream';
                $att_parts .= '--' . $boundary . $nl
                    . 'Content-Type: ' . $ctype . '; name="' . $fname . '"' . $nl
                    . 'Content-Transfer-Encoding: base64' . $nl
                    . 'Content-Disposition: attachment; filename="' . $fname . '"' . $nl . $nl
                    . $b64 . $nl;
            }
            $closing = '--' . $boundary . '--' . $nl;

            $raw = implode($nl, $headers) . $nl . $nl . $body_part . $att_parts . $closing;
            $this->ss_debug(array('msg'=>'compose_mime_built','compose_id'=>$compose_id,'attachments'=>count($attachments)));
            return $raw;
        } catch (\Exception $e) {
            $this->ss_debug(array('msg'=>'compose_mime_error','err'=>$e->getMessage()));
            return '';
        }
    }
    
    /**
     * Build multipart from posted JSON attachments (name, type, content_b64).
     */
    private function _ss_build_mime_from_json($from, $to, $cc, $bcc, $subject, $body, $is_html, $attach_list)
    {
        try {
            if (!is_array($attach_list) || !count($attach_list)) return '';

            $nl = "\r\n";
            $boundary = '=_SSJSON_' . bin2hex(random_bytes(12));

            $headers = array();
            if ($from !== '') $headers[] = 'From: ' . $from;
            if ($to !== '') $headers[] = 'To: ' . $to;
            if ($cc !== '') $headers[] = 'Cc: ' . $cc;
            if ($bcc !== '') $headers[] = 'Bcc: ' . $bcc;
            if ($subject !== '') $headers[] = 'Subject: ' . $subject;
            $headers[] = 'Message-ID: <ss-' . bin2hex(random_bytes(8)) . '@localhost>';
            $headers[] = 'MIME-Version: 1.0';
            $headers[] = 'Content-Type: multipart/mixed; boundary="' . $boundary . '"';

            $body_part  = '--' . $boundary . $nl;
            $body_part .= ($is_html ? 'Content-Type: text/html; charset=UTF-8' : 'Content-Type: text/plain; charset=UTF-8') . $nl;
            $body_part .= 'Content-Transfer-Encoding: 8bit' . $nl . $nl;
            $body_part .= (string)$body . $nl;

            $att_parts = '';
            foreach ($attach_list as $a) {
                if (!isset($a['content_b64'])) continue;
                $b64 = preg_replace('/\s+/', '', (string)$a['content_b64']);
                $fname = isset($a['name']) ? addcslashes($a['name'], '\"\\') : 'file';
                $ctype = isset($a['type']) && $a['type'] ? $a['type'] : 'application/octet-stream';
                $att_parts .= '--' . $boundary . $nl
                    . 'Content-Type: ' . $ctype . '; name="' . $fname . '"' . $nl
                    . 'Content-Transfer-Encoding: base64' . $nl
                    . 'Content-Disposition: attachment; filename="' . $fname . '"' . $nl . $nl
                    . chunk_split($b64) . $nl;
            }
            $closing = '--' . $boundary . '--' . $nl;

            $raw = implode($nl, $headers) . $nl . $nl . $body_part . $att_parts . $closing;
            $this->ss_debug(array('msg'=>'json_mime_built','attachments'=>count($attach_list)));
            return $raw;
        } catch (\Exception $e) {
            $this->ss_debug(array('msg'=>'json_mime_error','err'=>$e->getMessage()));
            return '';
        }
    }

    private function _ss_mime_attachment_count($raw)
    {
        if (!is_string($raw) || $raw === '') return 0;
        if (preg_match_all('/Content-Disposition:\s*attachment/iu', $raw, $m)) {
            return count($m[0]);
        }
        return 0;
    }

    private function _ss_mime_body_length($raw)
    {
        if (!is_string($raw) || $raw === '') return 0;
        $payload = '';
        if (preg_match('/boundary="([^"]+)"/i', $raw, $mm)) {
            $boundary = $mm[1];
            $parts = preg_split('/--' . preg_quote($boundary, '/') . '/', $raw);
            if (count($parts) > 1) {
                $first = $parts[1];
                $sections = preg_split("/\r?\n\r?\n/", $first, 2);
                if (isset($sections[1])) $payload = $sections[1];
            }
        }
        if ($payload === '') {
            $sections = preg_split("/\r?\n\r?\n/", $raw, 2);
            if (isset($sections[1])) $payload = $sections[1];
        }
        $payload = preg_replace('/--[^\r\n]+--\s*$/', '', $payload);
        $text = strip_tags($payload);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/\s+/', '', $text);
        return strlen(trim($text));
    }

    function init()
    {
        $this->rc = rcmail::get_instance();

		// Load plugin config for all tasks/actions so scheduled_timezone is available
		// also in Settings > Scheduled messages, not only in worker/send_due actions.		
		$this->load_config();

        // Load plugin localization for all tasks/actions
        $this->add_texts('localization', true);

        // Queue UI actions
        $this->register_action('plugin.scheduled_sending.queue', array($this, 'action_queue'));
        $this->register_action('plugin.scheduled_sending.queue_list', array($this, 'action_queue_list'));
        $this->register_action('plugin.scheduled_sending.queue_cancel', array($this, 'action_queue_cancel'));
        $this->register_action('plugin.scheduled_sending.queue_delete', array($this, 'action_queue_delete'));
        $this->register_action('plugin.scheduled_sending.queue_reschedule', array($this, 'action_queue_reschedule'));
        $this->register_action('plugin.scheduled_sending.queue_count', array($this, 'action_queue_count'));
        $this->register_action('plugin.scheduled_sending.queue_download', array($this, 'action_queue_download'));

        // Add hook for preferences sections
        if ($this->rc->task == 'settings') {
            $this->add_hook('preferences_sections_list', array($this, 'preferences_sections_list'));
            $this->add_hook('preferences_list', array($this, 'preferences_list'));
        // Client command to open queue
        }
        if ($this->rc->user && (int) $this->rc->user->ID > 0 && !$this->rc->output->framed) {
            $this->include_script('js/queue.js');
            $this->include_stylesheet('queue.css');
            $this->add_button(array('id' => 'scheduled-sending-menu', 'command' => 'plugin.scheduled_sending.open_queue', 'type' => 'link', 'class' => 'scheduled_sending', 'classact' => 'scheduled_sending active', 'label' => 'scheduled_sending.scheduled_queue_title', 'innerclass' => 'inner'), 'taskbar');
        }

        /* SS: early worker intercept (handles login bounce via _url) */
        $act = rcube_utils::get_input_value('_action', rcube_utils::INPUT_GPC);
        $urlq = rcube_utils::get_input_value('_url', rcube_utils::INPUT_GPC);
        if (!$act && $urlq) {
            parse_str($urlq, $u);
            if (!empty($u['_action'])) $act = $u['_action'];
            if (empty($_REQUEST['_token']) && !empty($u['_token'])) {
                $_REQUEST['_token'] = $_GET['_token'] = $_POST['_token'] = $u['_token'];
            }
        }
        if ($act === 'plugin.scheduled_sending.send_due') {
            $this->load_config();
            $this->action_send_due();
            exit;
        }

        if ($act === 'plugin.scheduled_sending.worker') {
                $this->load_config();
            $this->action_worker();
            exit;
        }

        // server handler
        $this->register_action('plugin.scheduled_sending.schedule', array($this, 'action_schedule'));

        // compose render hook (inject panel)
        $this->add_hook('render_page', array($this, 'on_render_page'));

        // assets
        $skin = $this->rc->config->get('skin', 'larry');
        $this->include_script('js/scheduled.js');
        $this->include_stylesheet($this->local_skin_path() . '/scheduled.css');
    }

    private function log($msg, $ctx = array())
    {
        // Be defensive: only log when explicitly enabled and RC logger is available.
        $enabled = ss_config_bool($this->rc->config->get(
            'scheduled_sending_logging',
            ss_config_bool($this->rc->config->get('scheduled_debug', false))
        ));
        if (function_exists('write_log') && $enabled) {
            $entry = array('msg'=>$msg,'time'=>gmdate('c'),'ctx'=>$ctx);
            write_log($this->logname, $entry);
        }
    }

    public function on_render_page($p)
    {
        // only in compose view
        if ($this->rc->action !== 'compose') {
            return $p;
        }

        // inject panel once just after compose headers container opens
        $panel = $this->build_inline_panel_html();

        $patterns = array(
            '/(<div id="composeheaders"[^>]*>)/i',
            '/(<div id="compose-headers"[^>]*>)/i',
        );

        $already = (strpos($p['content'], 'id="ss-inline-schedule"') !== false);
        if (!$already) {
            foreach ($patterns as $pat) {
                if (preg_match($pat, $p['content'])) {
                    $p['content'] = preg_replace($pat, '$1' . $panel, $p['content'], 1);
                    $this->log('inline panel injected', array('skin'=>$this->rc->config->get('skin','?')));
                    break;
                }
            }
        }
        return $p;
    }

    private function build_inline_panel_html()
    {
        $label = rcube::Q($this->gettext('sendlater'));
        return '<div id="ss-inline-schedule" class="ss-row">'
            . '<label class="ss-label" for="ss-when">Versandzeit</label>'
            . '<input id="ss-when" type="datetime-local" name="_schedule_at" required />'
            . '<button type="button" id="ss-schedule-btn" class="button">' . $label . '</button></div>';
    }

    public function action_schedule()
    {
        $rc = $this->rc;
        if (!$this->ss_queue_check_request()) return;

        // pull time
        $when    = rcube_utils::get_input_value('_schedule_at', rcube_utils::INPUT_POST);
        $epoch   = (int) rcube_utils::get_input_value('_schedule_ts', rcube_utils::INPUT_POST);
        $epoch_src = ($epoch > 0) ? 'absolute' : 'none';
        $tzoff   = (int) rcube_utils::get_input_value('_schedule_tzoffset', rcube_utils::INPUT_POST); // minutes east of UTC

        if ($epoch <= 0 && $when) {
            $t = strtotime($when);
            if ($t) {
                $epoch = $t;
                $epoch_src = 'local';
            }
        }

        if ($epoch <= 0) {
            $this->log('schedule error', array('error'=>'invalid_or_past','epoch'=>$epoch,'now'=>time()));
            $rc->output->command('display_message', 'Invalid or past time', 'error');
            $rc->output->send();
            return;
        }

        // Convert to UTC only when client did NOT send absolute epoch (_schedule_ts)
        if ($epoch_src !== 'absolute' && $tzoff) {
            $epoch = $epoch - ($tzoff * 60);
        }
        if ($epoch <= time()) {
            $this->log('schedule error', array('error'=>'invalid_or_past','epoch'=>$epoch,'now'=>time()));
            $rc->output->command('display_message', 'Invalid or past time', 'error');
            $rc->output->send();
            return;
        }

        $scheduled_at = gmdate('Y-m-d H:i:00', $epoch);

        $request_id = rcube_utils::get_input_value('_ss_request_id', rcube_utils::INPUT_POST);
        if (!is_string($request_id) || !preg_match('/^[A-Za-z0-9_-]{16,80}$/D', $request_id)) {
            $this->ss_queue_error('queue_invalid_request');
            return;
        }
        // user + identity
        $user_id = $rc->user ? (int) $rc->user->ID : 0;
        $from_info = $this->_ss_resolve_from_identity(rcube_utils::get_input_value('_from', rcube_utils::INPUT_POST, true));
        $identity_id = (int) $from_info['id'];
        $resolved_from = (string) $from_info['from'];
        if ($resolved_from === '') {
            $this->ss_debug(array('msg' => 'schedule_error_no_from', 'identity_id' => $identity_id));
            $rc->output->command('display_message', 'Unable to schedule: sender identity not found', 'error');
            $rc->output->send();
            return;
        }

        $raw_mime = rcube_utils::get_input_value('_raw_mime', rcube_utils::INPUT_POST, true);
        if ($raw_mime === null) $raw_mime = '';
        $subject = rcube_utils::get_input_value('_subject', rcube_utils::INPUT_POST, true);
        if ($subject === null) $subject = '';
        try {
            if (!is_string($raw_mime) || !is_string($subject) || preg_match('/[\r\n\x00]/', $subject)) {
                throw new InvalidArgumentException('Planung fehlgeschlagen: Ungültiger Nachrichtenkopf.');
            }
            $recipients = $this->_ss_read_recipients($raw_mime);
        } catch (InvalidArgumentException $e) {
            $rc->output->command('display_message', $e->getMessage(), 'error');
            $rc->output->send();
            return;
        }

        // Use the same validated recipient values for queue metadata and MIME.
        $meta = array_merge($recipients, array(
            'from' => $resolved_from,
            'subj' => $subject,
            'html' => (int) rcube_utils::get_input_value('_is_html', rcube_utils::INPUT_POST),
        ));
        try {
            $meta = array_merge($meta, $this->_ss_capture_smtp_credentials());
        } catch (Throwable $e) {
            $rc->output->command('display_message', 'Planung fehlgeschlagen: Zugangsdaten konnten nicht sicher gespeichert werden.', 'error');
            $rc->output->send();
            return;
        }

        // DB insert (no NULL raw_mime)
        $table = $this->ss_queue_table();
        $db = $rc->get_dbh();
        // Build or accept raw MIME payload (minimal fallback if client does not send _raw_mime)
        $raw_mime = $this->_ss_ensure_from_header($raw_mime, $resolved_from);
        if ($raw_mime === '') {
            $to  = $meta['to'];
            $cc  = $meta['cc'];
            $bcc = $meta['bcc'];

                        $body    = (string) rcube_utils::get_input_value('_message', rcube_utils::INPUT_POST, true);
            $msg_html = rcube_utils::get_input_value('_message_html', rcube_utils::INPUT_POST, true);
            $is_html = false;
            if ($msg_html) {
                // Decide if HTML is trivial wrappers only; prefer plain text in that case
                $maybe_plain = $this->_ss_is_semantically_plain_html($msg_html, $body);
                if ($maybe_plain) {
                    $body = $this->_ss_text_from_trivial_html($msg_html);
                    $is_html = false;
                } else {
                    $body = (string)$msg_html;
                    $is_html = true;
                }
            }


            $from = $resolved_from;

            $compose_id = rcube_utils::get_input_value('_id', rcube_utils::INPUT_POST);
            $compose_ctx = $this->_ss_get_compose_context($compose_id);
            $compose_id = isset($compose_ctx['id']) ? $compose_ctx['id'] : $compose_id;
            $body_was_posted = rcube_utils::get_input_value('_message', rcube_utils::INPUT_POST, true) !== null
                || rcube_utils::get_input_value('_message_html', rcube_utils::INPUT_POST, true) !== null;
            $hydrated = $body_was_posted ? array('body' => $body, 'is_html' => $is_html)
                : $this->_ss_resolve_body_from_context($compose_ctx, $body, $is_html);
            $body = $hydrated['body'];
            $is_html = $hydrated['is_html'];
            $current_body_available = $body_was_posted || !$this->_ss_body_is_empty($body, $is_html);

            $attach_ids = rcube_utils::get_input_value('_attach_ids', rcube_utils::INPUT_POST);
            if (!is_array($attach_ids)) { $attach_ids = $attach_ids ? array($attach_ids) : array(); }
            $attach_probe_json = rcube_utils::get_input_value('_ss_attach_probe', rcube_utils::INPUT_POST, true);
            $attach_probe = array();
            if ($attach_probe_json) {
                $tmp_probe = json_decode($attach_probe_json, true);
                if (is_array($tmp_probe)) $attach_probe = $tmp_probe;
                $this->ss_debug(array('msg'=>'attach_probe_client','probe'=>$attach_probe_json));
            }
            $expected_attachments = count($attach_ids);
            if (!$expected_attachments && count($attach_probe)) $expected_attachments = count($attach_probe);
            if (isset($compose_ctx['bucket']['attachments']) && is_array($compose_ctx['bucket']['attachments'])) {
                $expected_attachments = max($expected_attachments, count($compose_ctx['bucket']['attachments']));
            }
            $compose_mime = '';
            $compose_attach_count = 0;
            if ($compose_id) {
                $compose_mime = $this->_ss_build_mime_from_compose($compose_id, $from, $to, $cc, $bcc, $subject, $body, $is_html, $attach_ids, $compose_ctx);
                if ($compose_mime !== '') {
                    $compose_attach_count = $this->_ss_mime_attachment_count($compose_mime);
                } else {
                    $this->ss_debug(array('msg'=>'compose_mime_prefetch_empty','compose_id'=>$compose_id));
                }
            } else {
                $this->ss_debug(array('msg'=>'compose_id_missing'));
            }

            // Use draft data only when there is no current compose body to avoid stale draft text.
            $draft_uid_post = (int) rcube_utils::get_input_value('_draft_uid', rcube_utils::INPUT_POST);
            $draft_mime = '';
            if (!$current_body_available && $draft_uid_post > 0) {
                try {
                    $rc = $this->rc ?: rcmail::get_instance();
                    $storage = $rc->get_storage();
                    $drafts = $rc->config->get('drafts_mbox', 'Drafts');
                    if ($drafts && $storage->folder_exists($drafts)) {
                        $raw_try = '';
                        if (method_exists($storage, 'get_raw_message')) {
                            $raw_try = (string)$storage->get_raw_message($draft_uid_post, $drafts);
                        }
                        if ($raw_try === '' && method_exists($storage, 'get_raw_body')) {
                            $raw_try = (string)$storage->get_raw_body($draft_uid_post, $drafts);
                        }
                        if ($raw_try !== '') {
                            $draft_mime = $raw_try;
                            $this->ss_debug(array('msg'=>'draft_uid_used','uid'=>$draft_uid_post));
                        } else {
                            $this->ss_debug(array('msg'=>'draft_uid_empty_raw','uid'=>$draft_uid_post));
                        }
                    }
                } catch (Exception $e) {
                    $this->ss_debug(array('msg'=>'draft_uid_fetch_err','err'=>$e->getMessage()));
                }
            }

            $draft_used = false;
            if ($current_body_available && $compose_mime !== '') {
                $draft_mime = $compose_mime;
                $draft_used = true;
                $this->ss_debug(array('msg'=>'compose_mime_used','compose_id'=>$compose_id,'reason'=>'current_body'));
            } elseif ($draft_mime === '' && $compose_mime !== '') {
                $draft_mime = $compose_mime;
                $draft_used = true;
                $this->ss_debug(array('msg'=>'compose_mime_used','compose_id'=>$compose_id,'reason'=>'no_draft'));
            } elseif (!$current_body_available && $draft_mime !== '' && $compose_mime !== '' && $compose_mime !== $draft_mime) {
                $draft_attach_count = $this->_ss_mime_attachment_count($draft_mime);
                $draft_body_len = $this->_ss_mime_body_length($draft_mime);
                $input_body_text = $is_html ? trim(strip_tags((string)$body)) : trim((string)$body);
                $input_body_len = strlen(preg_replace('/\s+/', '', $input_body_text));

                $prefer_compose = false;
                if ($compose_attach_count > $draft_attach_count) {
                    $prefer_compose = true;
                }
                if ($expected_attachments && $compose_attach_count >= $expected_attachments && $draft_attach_count < $expected_attachments) {
                    $prefer_compose = true;
                }
                if ($input_body_len > 0 && $draft_body_len === 0) {
                    $prefer_compose = true;
                }
                if ($prefer_compose) {
                    $draft_mime = $compose_mime;
                    $draft_used = true;
                    $this->ss_debug(array('msg'=>'compose_mime_preferred','compose_id'=>$compose_id,'draft_attach'=>$draft_attach_count,'compose_attach'=>$compose_attach_count,'body_len'=>$input_body_len));
                }
            }

            $this->ss_debug(array('msg'=>'draft_probe','used'=>($draft_mime!=='') && !$draft_used,'subject'=>$subject,'uid_post'=>$draft_uid_post));
            if ($draft_mime !== '') {
                $raw_mime = $draft_mime;
            } else {
                $raw_mime = $this->build_minimal_mime($from, $to, $cc, $bcc, $subject, $body, $is_html);
            }

            // Safety net: if body ended up empty (headers-only), try to build from compose session even without explicit _id
            $parts_check = preg_split("/\r?\n\r?\n/", (string)$raw_mime, 2);
            $body_check  = isset($parts_check[1]) ? trim($parts_check[1]) : '';
            if ($body_check === '' && $compose_mime !== '' && $raw_mime !== $compose_mime) {
                $raw_mime = $compose_mime;
                $this->ss_debug(array('msg'=>'auto_compose_fallback_used','compose_id'=>$compose_id));
            } elseif ($body_check === '' && $compose_mime === '') {
                $this->ss_debug(array('msg'=>'auto_compose_fallback_failed'));
            }

        }

        if (!empty($expected_attachments) && $this->_ss_mime_attachment_count($raw_mime) < $expected_attachments) {
            $rc->output->command('display_message', 'Planung fehlgeschlagen: Die Anhänge konnten nicht vollständig übernommen werden. Der Entwurf bleibt geöffnet.', 'error');
            $rc->output->send();
            return;
        }
        if (strlen($raw_mime) > (int) $rc->config->get('scheduled_sending_max_message_size', 8388608)) {
            $rc->output->command('display_message', 'Diese Nachricht ist für den geplanten Versand zu groß (maximal 8 MiB einschließlich Anhängen und Kodierung).', 'error');
            $rc->output->send();
            return;
        }

        $dedupe_key = hash('sha256', $user_id . ':' . $request_id);
        $meta['_schedule_nonce'] = bin2hex(random_bytes(16));
        if (!$db->startTransaction()) { $this->ss_queue_error('queue_change_failed'); return; }
        try {
            $q = "INSERT INTO $table (user_id, identity_id, scheduled_at, status, raw_mime, meta_json, dedupe_key, created_at, updated_at)
                  VALUES (?, ?, ?, 'queued', ?, ?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())
                  ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id)";
            $ok = $db->query($q, $user_id, $identity_id, $scheduled_at, $raw_mime, json_encode($meta), $dedupe_key);
            if (!$ok || $db->is_error()) throw new RuntimeException('Queue insert failed');
            $read = $db->query("SELECT id, meta_json FROM $table WHERE dedupe_key=? AND user_id=? LIMIT 1", $dedupe_key, $user_id);
            $job = $read && !$db->is_error() ? $db->fetch_assoc($read) : false;
            if (!$job) throw new RuntimeException('Queue confirmation failed');
            $job_id = (int) $job['id'];
            $stored_meta = json_decode($job['meta_json'], true);
            if (($stored_meta['_schedule_nonce'] ?? '') !== $meta['_schedule_nonce']) {
                if (!$db->endTransaction()) throw new RuntimeException('Queue commit failed');
                $rc->output->command('display_message', $this->gettext('already_scheduled'), 'confirmation');
                $rc->output->command('plugin.scheduled_sending.scheduled', ['id' => $job_id]);
                $rc->output->send();
                return;
            }
        } catch (Throwable $e) {
            try { $db->rollbackTransaction(); } catch (Throwable $ignored) {}
            $this->ss_queue_error('queue_change_failed');
            return;
        }

        // Save a real Draft copy; Sent is populated only after SMTP accepts the message.
        try {
            $sentmb = $rc->config->get('drafts_mbox', 'Drafts');
            if ($sentmb) {
                $storage = $rc->get_storage();
                if (!$storage->folder_exists($sentmb)) {
                    $storage->create_folder($sentmb, true);
                }
                $saved = $storage->save_message($sentmb, $raw_mime, null, false, array('SEEN'));
                $this->ss_debug(array('msg'=>'initial saved to sent','ok'=>(bool)$saved,'folder'=>$sentmb));
                $meta['drafts_mbox'] = $sentmb;
                if ($saved && isset($job_id) && $job_id) {
                    if (!is_array($meta)) { $meta = array(); }
                    $meta['draft_uid'] = $saved;
                    $updated = $db->query("UPDATE $table SET meta_json=? WHERE id=? AND user_id=?", json_encode($meta), (int)$job_id, $user_id);
                    if (!$updated || $db->is_error()) throw new RuntimeException('Draft metadata update failed');
                    if (!empty($draft_uid_post) && (int) $draft_uid_post !== (int) $saved) {
                        $storage->delete_message((int) $draft_uid_post, $sentmb);
                    }
                }
            }
        } catch (Throwable $e) {
            // The MIME in the queue is authoritative; a missing optional Draft
            // copy must not discard the message. Database failures still roll back.
            if ($db->is_error()) {
                $db->rollbackTransaction();
                $this->ss_queue_error('queue_change_failed');
                return;
            }
            $this->ss_debug(['msg' => 'draft save failed']);
        }
        try {
            if (!$db->endTransaction()) throw new RuntimeException('Queue commit failed');
        } catch (Throwable $e) {
            try { $db->rollbackTransaction(); } catch (Throwable $ignored) {}
            $this->ss_queue_error('queue_change_failed');
            return;
        }

        $this->log('queue insert', array('ok'=>(bool)$ok, 'scheduled_at'=>$scheduled_at, 'framed'=>(int)$rc->output->framed, 'identity_id'=>$identity_id));

        // Friendly toast back to client
        if ($ok) {
            $this->ss_debug(array('msg'=>'schedule success','time'=>date('c')));
            // Build local wall-time string for toast
            $offmin = (int) rcube_utils::get_input_value('_schedule_tzoffset', rcube_utils::INPUT_POST);
            $local_epoch = $epoch;
            if ($offmin) { $local_epoch = $epoch - ($offmin * 60); }
            $local_text = date('M j, Y g:i A', $local_epoch);
            $rc->output->command('display_message', 'Geplant für ' . $this->_ss_format_scheduled_at_for_display($scheduled_at), 'confirmation');
            $rc->output->command('plugin.scheduled_sending.scheduled', array('id' => (int) $job_id));
        } else {
            $rc->output->command('display_message', 'Unable to schedule (DB)', 'error');
        }
        $rc->output->send(); // JSON response for AJAX
    }
    private function build_minimal_mime($from, $to, $cc, $bcc, $subject, $body, $is_html)
    {
        $nl = "\r\n";
        $headers = array();
        $headers[] = 'Date: ' . date('r');
        if ($from)   $headers[] = 'From: ' . $from;
        if ($to)     $headers[] = 'To: ' . $to;
        if ($cc)     $headers[] = 'Cc: ' . $cc;
        if ($subject !== '') $headers[] = 'Subject: ' . $subject;
        $headers[] = 'Message-ID: <' . uniqid() . '@localhost>';
        $headers[] = 'MIME-Version: 1.0';
        if ($is_html) {
            $headers[] = 'Content-Type: text/html; charset=UTF-8';
        } else {
            $headers[] = 'Content-Type: text/plain; charset=UTF-8';
        }
        $headers[] = 'Content-Transfer-Encoding: 8bit';
        return implode($nl, $headers) . $nl . $nl . (string)$body . $nl;
    }
    /**
     * HTTP worker: send all queued messages scheduled at/earlier than now.
     * URL: ?_task=mail&_action=plugin.scheduled_sending.send_due&_token=YOUR_SECRET
     * Guarded by config: $config['scheduled_sending_worker_token']
     */
    public function action_send_due()
    {
        $this->action_worker();
    }

    private function ss_parse_headers($raw)
    {
        $lines = preg_split("/\\r?\\n/", $raw);
        $headers = array();
        $current = '';
        foreach ($lines as $ln) {
            if ($ln === '') break;
            if (preg_match('/^\\s+/', $ln) && $current) {
                $headers[$current] .= ' ' . trim($ln);
                continue;
            }
            if (strpos($ln, ':') !== false) {
                list($k, $v) = explode(':', $ln, 2);
                $current = strtolower(trim($k));
                $headers[$current] = trim($v);
            }
        }
        return $headers;
    }

    // Run worker very early (before auth) when requested
    public function on_startup($args)
    {
        $this->ss_debug(array('msg'=>'startup hook','task'=>$this->rc->task,'action'=>rcube_utils::get_input_value('_action', rcube_utils::INPUT_GPC)));
        $action = rcube_utils::get_input_value('_action', rcube_utils::INPUT_GPC);

        // If login bounced our request, original params are in _url
        $urlq = rcube_utils::get_input_value('_url', rcube_utils::INPUT_GPC);
        if ($urlq) {
            parse_str($urlq, $u);
            if (isset($u['_action'])) {
                $action = $u['_action'];
            }
            if (isset($u['_token']) && !rcube_utils::get_input_value('_token', rcube_utils::INPUT_GPC)) {
                $_REQUEST['_token'] = $_GET['_token'] = $_POST['_token'] = $u['_token'];
            }
        }

        if ($action === 'plugin.scheduled_sending.send_due') {
            // Load config so token is available
            $this->load_config();
            $this->action_send_due();
            exit;
        }
        
        return $args;
    }

    public function action_worker()
    {
        // Delivery is restricted to the local CLI worker. No public delivery endpoint.
        header('Content-Type: application/json', true, 403);
        echo json_encode(array('ok' => false, 'error' => 'CLI worker only'));
        exit;
    }

    public function preferences_sections_list($args)
    {
        $args['list']['scheduled_sending'] = array('id' => 'scheduled_sending', 'section' => $this->gettext('scheduledsending'));
        return $args;
    }

    
    public function preferences_list($args)
    {
        if ($args['section'] == 'scheduled_sending') {
            $this->add_texts('localization', true);
            $this->rc->output->set_pagetitle($this->gettext('scheduledmessages'));

            // include the table class
            $this->rc->output->include_script('list.js');
            $table = new html_table(array(
                'width' => '75%',
                'cols'  => 8,
                'class' => 'scheduled-messages-table',
                'id'    => 'scheduled-messages-table',
            ));

            $table->add_header(array('width' => '2%',  'align' => 'left', 'class' => 'id'), $this->gettext('id'));
            $table->add_header(array('width' => '15%', 'align' => 'left', 'class' => 'scheduled_at'), $this->gettext('scheduled_at'));
            $table->add_header(array('width' => '28%', 'align' => 'left', 'class' => 'subject'), $this->gettext('subject'));
            $table->add_header(array('width' => '28%', 'align' => 'left', 'class' => 'to'), $this->gettext('to'));
            $table->add_header(array('width' => '8%',  'align' => 'left', 'class' => 'status'), $this->gettext('status'));
            $table->add_header(array('width' => '15%', 'align' => 'left', 'class' => 'created_at'), $this->gettext('created_at'));
            $table->add_header(array('width' => '4%',  'align' => 'left', 'class' => 'edit'), $this->gettext('edit'));
            $table->add_header(array('width' => '6%',  'align' => 'left', 'class' => 'delete'), $this->gettext('delete'));

            $db = $this->rc->get_dbh();
            $table_name = $this->ss_queue_table();
            $user_id = $this->rc->user->ID;

            $sql = "SELECT id, scheduled_at, meta_json, status, last_error, created_at FROM {$table_name} ".
                   "WHERE user_id = ? AND status NOT IN ('sent', 'cancelled', 'canceled') ORDER BY scheduled_at ASC";
            $res = $db->query($sql, $user_id);

            if (!$res || $db->is_error()) { $this->ss_queue_error('queue_read_failed'); return $args; }
            while ($row = $db->fetch_assoc($res)) {
                $meta = json_decode($row['meta_json'], true);
                $to = isset($meta['to']) ? rcube::Q($meta['to']) : '';
                $subject = isset($meta['subj']) ? rcube::Q($meta['subj']) : '';
                $scheduled_display = $this->_ss_format_scheduled_at_for_display($row['scheduled_at']);

                $table->add_row();
                $table->add(array('class' => 'id'), rcube::Q($row['id']));
                $table->add(array('class' => 'scheduled_at'), rcube::Q($scheduled_display));
                $table->add(array('class' => 'subject'), $subject);
                $table->add(array('class' => 'to'), $to);
                $status = rcube::Q($this->gettext('status_' . $row['status']));
                if (!empty($row['last_error'])) $status .= '<br><small>' . rcube::Q($row['last_error']) . '</small>';
                $table->add(array('class' => 'status'), $status);
                $table->add(array('class' => 'created_at'), rcube::Q($row['created_at']));

                $scheduled_ts = (int) strtotime($row['scheduled_at'] . ' UTC');

                // Resolve plugin skin asset for *any* active skin, then fall back safely.
                $skin = (string) $this->rc->config->get('skin', 'default');
                $try  = array(
                    "skins/$skin/images/trash.svg",   // active skin override in this plugin
                    "skins/default/images/trash.svg", // plugin default skin fallback
                    "skins/images/trash.svg",         // legacy layout
                    "images/trash.svg",               // last resort in plugin root
                );

                $src = '';
                foreach ($try as $rel) {
                    if (is_file($this->home . '/' . $rel)) {
                        $src = $this->_ss_public_plugin_asset_url($rel);
                        break;
                    }
                }

                $delete_button = '<a href="#" class="delete-scheduled-message" data-id="' . rcube::Q($row['id']) . '">'
                               . '<img style="vertical-align: middle;" src="' . rcube::Q($src) . '" height="16" alt="' . rcube::Q($this->gettext('delete')) . '">'
                               . '</a>';

                $edit_button = '<a href="#" class="edit-scheduled-message" data-id="' . rcube::Q($row['id']) . '" data-ts="' . $scheduled_ts . '">' . rcube::Q($this->gettext('edit')) . '</a>';

                if (!in_array($row['status'], ['queued', 'retry_wait'], true)) $edit_button = '';
                if (in_array($row['status'], ['processing', 'sent', 'sent_copy_error'], true)) $delete_button = '';
                $table->add(array('class' => 'edit'), $edit_button);
                $table->add(array('class' => 'delete'), $delete_button);
            }

            $html = $table->show();
            $this->include_script('js/settings_queue.js');

            $args['blocks']['scheduled_sending'] = array(
                'name' => $this->gettext('scheduledmessages'),
                'content' => $html,
            );
        }

        return $args;
    }

}
