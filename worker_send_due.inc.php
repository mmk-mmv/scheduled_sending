<?php
// Modified by Scheduled Sending contributors, 2026-10-23. GPL-3.0; see LICENSE and CREDITS.md.
// Local compatibility fixes for scheduled_sending 1.3.2, Roundcube 1.6 / Plesk.
function ss_config_bool($value)
{
    return filter_var($value, FILTER_VALIDATE_BOOLEAN);
}

class scheduled_sending_connection_retry extends RuntimeException {}

trait scheduled_sending_worker_trait
{
    private function mm_capture_credentials()
    {
        $rc = $this->rc;
        $cfg = $rc->config;
        $login = (string) $rc->get_user_name();
        $password = (string) $rc->get_user_password();
        $storage_host = (string) ($_SESSION['storage_host'] ?? '');
        if (!$rc->user || !(int) $rc->user->ID || $login === '' || $password === '' || $storage_host === '') {
            throw new RuntimeException('No authenticated mail session');
        }
        $host = $cfg->get('smtp_host', 'localhost:587');
        if (is_array($host)) $host = $host[$storage_host] ?? '';
        $host = rcube_utils::parse_host((string) $host);
        if (!$host || strpos($host, '%') !== false) throw new RuntimeException('Unresolved SMTP host');
        $user = strtr((string) $cfg->get('smtp_user', '%u'), ['%u' => $login]);
        if (strpos($user, '%') !== false) throw new RuntimeException('Unsupported SMTP username placeholder');
        $pass = $cfg->get('smtp_pass', '%p');
        $pass = $pass === '%p' ? $password : (string) $pass;
        $encrypted = $rc->encrypt($pass);
        $imap_encrypted = $rc->encrypt($password);
        if (($pass !== '' && !$encrypted) || !$imap_encrypted) throw new RuntimeException('Encryption failed');
        return [
            'smtp_host' => $host, 'smtp_user' => $user, 'smtp_pass_enc' => $encrypted,
            'imap_host' => $storage_host, 'imap_user' => $login, 'imap_pass_enc' => $imap_encrypted,
            'imap_port' => (int) ($_SESSION['storage_port'] ?? 143),
            'imap_ssl' => $_SESSION['storage_ssl'] ?? null,
            'sent_mbox' => $cfg->get('sent_mbox', 'Sent'),
            'drafts_mbox' => $cfg->get('drafts_mbox', 'Drafts'),
        ];
    }

    public function run_cli_worker()
    {
        if (PHP_SAPI !== 'cli') throw new RuntimeException('CLI only');
        $this->rc = rcmail::get_instance();
        $this->load_config();
        return $this->run_send_due_worker();
    }

    private function mm_addresses($value)
    {
        return array_values(array_filter(
            rcube_mime::decode_address_list((string) $value, null, false, null, true),
            static function ($address) { return filter_var($address, FILTER_VALIDATE_EMAIL) !== false; }
        ));
    }

    private function mm_clean_credentials($meta)
    {
        unset($meta['smtp_pass_enc'], $meta['imap_pass_enc']);
        return json_encode($meta, JSON_THROW_ON_ERROR);
    }

    private function mm_save_sent($meta, $raw)
    {
        $imap = new rcube_imap();
        try {
            $imap->set_options([
                'socket_options' => $this->rc->config->get('imap_conn_options', []),
                'timeout' => 30,
            ]);
            $password = $this->rc->decrypt($meta['imap_pass_enc'] ?? '');
            if (!is_string($password) || $password === '') throw new RuntimeException('IMAP credentials unavailable');
            if (!$imap->connect($meta['imap_host'], $meta['imap_user'], $password, (int) $meta['imap_port'], $meta['imap_ssl'] ?? null)) throw new RuntimeException('IMAP authentication failed');
            $sent = $meta['sent_mbox'] ?? 'Sent';
            if ($sent) {
                if (!$imap->folder_exists($sent) && !$imap->create_folder($sent, true)) throw new RuntimeException('Sent folder unavailable');
                preg_match('/^Message-ID:\s*(.+)$/mi', $raw, $match);
                $message_id = trim($match[1] ?? '');
                if ($message_id === '') throw new RuntimeException('Message-ID unavailable for Sent copy');
                $existing = $imap->search($sent, 'HEADER Message-ID "' . addcslashes($message_id, '\\"') . '"', 'UTF-8');
                if (!$existing || $existing->is_error()) throw new RuntimeException('Sent search failed');
                if (!$existing->count() && !$imap->save_message($sent, $raw, '', false, ['SEEN'])) throw new RuntimeException('Sent append failed');
            }
            if (!empty($meta['draft_uid']) && !empty($meta['drafts_mbox'])) $imap->delete_message((int) $meta['draft_uid'], $meta['drafts_mbox']);
        } finally {
            $imap->close();
        }
    }

    private function run_send_due_worker()
    {
        $rc = $this->rc;
        $db = $rc->get_dbh();
        $table = $this->ss_queue_table();
        $lock_key = 'scheduled_sending_' . substr(hash('sha256', $table), 0, 32);
        $lock = $db->query('SELECT GET_LOCK(?, 0) AS acquired', $lock_key);
        if (!$lock || $db->is_error()) throw new RuntimeException('Queue lock failed');
        $locked = $db->fetch_assoc($lock);
        if (!$locked || (int) $locked['acquired'] !== 1) return ['ok' => true, 'busy' => true];
        $result = ['ok' => true, 'processed' => 0, 'sent' => 0, 'failed' => 0, 'sent_copy_errors' => 0, 'retry_wait' => 0];
        try {
            $batch = max(1, min(100, (int) $rc->config->get('scheduled_sending_batch', 20)));
            $retry_delay = max(30, min(3600, (int) $rc->config->get('scheduled_sending_retry_delay', 300)));
            $max_retries = max(0, min(288, (int) $rc->config->get('scheduled_sending_connect_retries', 12)));
            $budget = max(1, min(300, (int) $rc->config->get('scheduled_sending_worker_budget', 45)));
            $started = microtime(true);
            $query = $db->query("SELECT * FROM $table WHERE (status='queued' AND scheduled_at <= UTC_TIMESTAMP()) OR (status='retry_wait' AND updated_at <= UTC_TIMESTAMP() - INTERVAL $retry_delay SECOND) OR (status='sent_copy_error' AND updated_at < UTC_TIMESTAMP() - INTERVAL 5 MINUTE) ORDER BY scheduled_at ASC LIMIT $batch");
            if (!$query || $db->is_error()) throw new RuntimeException('Queue database read failed');
            $rows = [];
            while ($row = $db->fetch_assoc($query)) $rows[] = $row;
            foreach ($rows as $row) {
                // Leave remaining rows untouched for the next timer tick.
                if (microtime(true) - $started >= $budget) break;
                $id = (int) $row['id'];
                $copy_only = $row['status'] === 'sent_copy_error';
                $claim = $db->query("UPDATE $table SET status='processing', updated_at=UTC_TIMESTAMP() WHERE id=? AND status=?", $id, $row['status']);
                if ($db->affected_rows($claim) !== 1) continue;
                $result['processed']++;
                $meta = [];
                $accepted = $copy_only;
                try {
                    $meta = json_decode((string) $row['meta_json'], true, 512, JSON_THROW_ON_ERROR);
                    if (!is_array($meta) || empty($row['raw_mime'])) throw new RuntimeException('Incomplete queued message');
                    [$headers, $body] = $this->_ss_split_raw_message($row['raw_mime']);
                    $recipients = [];
                    foreach (['to', 'cc', 'bcc'] as $field) $recipients = array_merge($recipients, $this->mm_addresses($meta[$field] ?? ''));
                    $recipients = array_values(array_unique($recipients));
                    $from = $this->mm_addresses($meta['from'] ?? '');
                    if (!$from || !$recipients) throw new RuntimeException('Missing sender or recipient');
                    foreach (array_keys($headers) as $key) if (in_array(strtolower($key), ['bcc', 'from', 'date'], true)) unset($headers[$key]);
                    $headers['From'] = $meta['from'];
                    $headers['Date'] = gmdate('D, d M Y H:i:s') . ' +0000';
                    if (!preg_grep('/^message-id$/i', array_keys($headers))) {
                        $domain = substr(strrchr($from[0], '@'), 1);
                        $headers['Message-ID'] = '<ss-' . $id . '-' . substr(hash('sha256', $row['raw_mime']), 0, 24) . '@' . $domain . '>';
                    }
                    if (!$copy_only) {
                        $smtp = new rcube_smtp();
                        $timeout = $rc->config->get('smtp_timeout');
                        try {
                            $rc->config->set('smtp_timeout', 30);
                            $password = $rc->decrypt($meta['smtp_pass_enc'] ?? '');
                            if ($meta['smtp_user'] !== '' && !is_string($password)) throw new RuntimeException('SMTP credentials unavailable');
                            if (!$smtp->connect($meta['smtp_host'], null, $meta['smtp_user'], (string) $password)) {
                                $detail = $smtp->get_error();
                                $label = is_array($detail) ? ($detail['label'] ?? 'unknown') : 'unknown';
                                // No MAIL/RCPT/DATA call has happened. Only this specific
                                // pre-delivery connection failure is safe to retry.
                                if ($label === 'smtpconnerror') throw new scheduled_sending_connection_retry('SMTP connection unavailable');
                                throw new RuntimeException('SMTP connection or authentication failed (' . $label . ')');
                            }
                            if (!$smtp->send_mail($from[0], $recipients, $headers, $body)) throw new RuntimeException('SMTP did not confirm acceptance; check mail log before rescheduling');
                            $accepted = true;
                            $result['sent']++;
                            $raw = $this->_ss_headers_to_raw($headers, $body);
                            $persist = $db->query("UPDATE $table SET status='sent_copy_error', raw_mime=?, updated_at=UTC_TIMESTAMP() WHERE id=? AND status='processing'", $raw, $id);
                            if (!$persist || $db->is_error()) throw new RuntimeException('SMTP accepted; delivery state could not be saved. Check mail log');
                        } finally {
                            $smtp->disconnect();
                            $rc->config->set('smtp_timeout', $timeout);
                        }
                    } else {
                        $raw = $row['raw_mime'];
                    }
                    $this->mm_save_sent($meta, $raw);
                    $db->query("UPDATE $table SET status='sent', raw_mime=NULL, meta_json=?, last_error=NULL, updated_at=UTC_TIMESTAMP() WHERE id=?", $this->mm_clean_credentials($meta), $id);
                    $this->log('worker sent', ['id' => $id, 'copy_only' => $copy_only]);
                } catch (Throwable $error) {
                    $status = $accepted ? 'sent_copy_error' : 'error';
                    $message = $error->getMessage();
                    if (!$accepted && $error instanceof scheduled_sending_connection_retry) {
                        $attempt = (int) ($meta['smtp_connect_retries'] ?? 0) + 1;
                        $meta['smtp_connect_retries'] = $attempt;
                        if ($attempt <= $max_retries) {
                            $status = 'retry_wait';
                            $message = 'SMTP unavailable before submission. Retry in ' . $retry_delay . ' seconds (' . $attempt . '/' . $max_retries . ').';
                            $db->query("UPDATE $table SET status=?, last_error=?, meta_json=?, updated_at=UTC_TIMESTAMP() WHERE id=?", $status, $message, json_encode($meta, JSON_THROW_ON_ERROR), $id);
                        } else {
                            $message = 'SMTP unavailable after ' . $max_retries . ' retries. Held for review.';
                            $db->query("UPDATE $table SET status=?, last_error=?, updated_at=UTC_TIMESTAMP() WHERE id=?", $status, $message, $id);
                        }
                    } else {
                        $db->query("UPDATE $table SET status=?, last_error=?, updated_at=UTC_TIMESTAMP() WHERE id=?", $status, $message, $id);
                    }
                    $result[$status === 'retry_wait' ? 'retry_wait' : ($accepted ? 'sent_copy_errors' : 'failed')]++;
                    $this->log('worker error', ['id' => $id, 'status' => $status, 'error' => $message]);
                }
            }
            return $result;
        } finally {
            $db->query('SELECT RELEASE_LOCK(?)', $lock_key);
        }
    }
}
