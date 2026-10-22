<?php
// Modified by Scheduled Sending contributors, 2026-10-23. GPL-3.0; see LICENSE and CREDITS.md.
// Scheduled queue views and owner-scoped actions. Original GPL-3.0 notices retained.
trait scheduled_sending_queue_trait
{
    private function ss_queue_table()
    {
        $legacy = $this->rc->config->get('scheduled_sending_table', 'scheduled_queue');
        $table = $this->rc->config->get('db_table_scheduled_sending', $legacy);
        if (!is_string($table) || !preg_match('/^[a-zA-Z0-9_]+$/D', $table)) {
            throw new RuntimeException('Invalid scheduled queue table');
        }
        if ($this->rc->config->get('scheduled_sending_table') !== null && $legacy !== $table) {
            throw new RuntimeException('Scheduled queue table settings disagree');
        }
        return $table;
    }

    private function ss_queue_error($label)
    {
        $this->rc->output->command('display_message', $this->gettext($label), 'error');
        $this->rc->output->send();
    }

    private function ss_queue_authenticated()
    {
        if (!$this->rc->user || (int) $this->rc->user->ID < 1) {
            $this->ss_queue_error('queue_access_denied');
            return false;
        }
        return true;
    }

    private function ss_queue_check_request()
    {
        if (!$this->ss_queue_authenticated()) return false;
        if (!method_exists($this->rc, 'check_request') || !$this->rc->check_request()) {
            $this->ss_queue_error('queue_invalid_request');
            return false;
        }
        return true;
    }

    private function ss_queue_id($name = 'id', $source = null)
    {
        $value = rcube_utils::get_input_value($name, $source ?? rcube_utils::INPUT_POST);
        return filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 0;
    }

    private function ss_queue_changed($operation, $id, $result)
    {
        $db = $this->rc->get_dbh();
        if (!$result || $db->is_error() || $db->affected_rows($result) !== 1) {
            $this->ss_queue_error('queue_change_failed');
            return;
        }
        $labels = ['cancel' => 'queue_cancel_ok', 'reschedule' => 'queue_resched_ok', 'delete' => 'queue_delete_ok'];
        $this->rc->output->command('display_message', $this->gettext($labels[$operation]), 'confirmation');
        $this->rc->output->command('plugin.scheduled_sending.queue_changed', ['operation' => $operation, 'id' => $id]);
        $this->rc->output->send();
    }

    public function queue_page_body($attrib = [])
    {
        return '<div class="ssq-content"><h1>' . rcube::Q($this->gettext('scheduled_queue_title')) . '</h1>'
            . '<div id="ssq-root" class="ssq-root" role="region" aria-live="polite"></div></div>';
    }

    public function action_queue()
    {
        if (!$this->ss_queue_authenticated()) return;
        $this->rc->output->set_pagetitle($this->gettext('scheduled_queue_title'));
        $this->rc->output->add_handler('plugin.body', [$this, 'queue_page_body']);
        $this->rc->output->send('plugin');
    }

    public function action_queue_list()
    {
        if (!$this->ss_queue_authenticated()) return;
        $db = $this->rc->get_dbh(); $table = $this->ss_queue_table();
        $q = $db->query("SELECT id, status, scheduled_at, created_at, updated_at, meta_json, last_error FROM $table WHERE user_id=? AND status IN ('queued','retry_wait','processing','error','sent_copy_error') ORDER BY scheduled_at ASC LIMIT 200", (int) $this->rc->user->ID);
        if (!$q || $db->is_error()) { $this->ss_queue_error('queue_read_failed'); return; }
        $rows = [];
        while ($r = $db->fetch_assoc($q)) {
            $meta = json_decode((string) $r['meta_json'], true) ?: [];
            $rows[] = [
                'id' => (int) $r['id'], 'status' => $r['status'],
                'scheduled_utc' => $r['scheduled_at'], 'scheduled_ts' => strtotime($r['scheduled_at'] . ' UTC'),
                'created_at' => $r['created_at'], 'updated_at' => $r['updated_at'],
                'to' => (string) ($meta['to'] ?? ''), 'cc' => (string) ($meta['cc'] ?? ''),
                'bcc' => (string) ($meta['bcc'] ?? ''), 'subj' => (string) ($meta['subj'] ?? ''),
                'error' => (string) $r['last_error'],
            ];
        }
        $this->rc->output->command('plugin.scheduled_sending.queue_data', $rows);
        $this->rc->output->send();
    }

    public function action_queue_count()
    {
        if (!$this->ss_queue_authenticated()) return;
        $db = $this->rc->get_dbh(); $table = $this->ss_queue_table();
        $q = $db->query("SELECT COUNT(*) AS n FROM $table WHERE user_id=? AND status IN ('queued','retry_wait','processing','error','sent_copy_error')", (int) $this->rc->user->ID);
        $row = $q && !$db->is_error() ? $db->fetch_assoc($q) : false;
        if (!$row) { $this->ss_queue_error('queue_read_failed'); return; }
        $this->rc->output->command('plugin.scheduled_sending.queue_count', ['count' => (int) $row['n']]);
        $this->rc->output->send();
    }

    public function action_queue_cancel()
    {
        if (!$this->ss_queue_check_request()) return;
        $id = $this->ss_queue_id();
        if (!$id) { $this->ss_queue_error('queue_invalid_request'); return; }
        $table = $this->ss_queue_table();
        $result = $this->rc->get_dbh()->query("UPDATE $table SET status='canceled', updated_at=UTC_TIMESTAMP() WHERE id=? AND user_id=? AND status IN ('queued','retry_wait','error')", $id, (int) $this->rc->user->ID);
        $this->ss_queue_changed('cancel', $id, $result);
    }

    public function action_queue_reschedule()
    {
        if (!$this->ss_queue_check_request()) return;
        $id = $this->ss_queue_id();
        $ts = $this->ss_queue_id('at_ts');
        if (!$id || $ts <= time()) { $this->ss_queue_error('reschedule_future'); return; }
        $table = $this->ss_queue_table();
        $result = $this->rc->get_dbh()->query("UPDATE $table SET status='queued', scheduled_at=?, updated_at=UTC_TIMESTAMP() WHERE id=? AND user_id=? AND status IN ('queued','retry_wait')", gmdate('Y-m-d H:i:s', $ts), $id, (int) $this->rc->user->ID);
        $this->ss_queue_changed('reschedule', $id, $result);
    }

    public function action_queue_delete()
    {
        if (!$this->ss_queue_check_request()) return;
        $id = $this->ss_queue_id('_id');
        if (!$id) { $this->ss_queue_error('queue_invalid_request'); return; }
        $table = $this->ss_queue_table();
        $result = $this->rc->get_dbh()->query("DELETE FROM $table WHERE id=? AND user_id=? AND status IN ('queued','retry_wait','error','canceled','cancelled')", $id, (int) $this->rc->user->ID);
        $this->ss_queue_changed('delete', $id, $result);
    }

    private function ss_queue_source($id)
    {
        if (!$this->rc->user || (int) $this->rc->user->ID < 1 || $id < 1) return false;
        $db = $this->rc->get_dbh(); $table = $this->ss_queue_table();
        $q = $db->query("SELECT raw_mime FROM $table WHERE id=? AND user_id=? AND raw_mime IS NOT NULL LIMIT 1", $id, (int) $this->rc->user->ID);
        return $q && !$db->is_error() ? $db->fetch_assoc($q) : false;
    }

    public function action_queue_download()
    {
        // Read-only, owner-scoped attachment download. Never render queued HTML.
        $id = $this->ss_queue_id('_id', rcube_utils::INPUT_GPC);
        $row = $this->ss_queue_source($id);
        if (!$row) { http_response_code(404); exit; }
        header('Content-Type: message/rfc822');
        header('Content-Disposition: attachment; filename="scheduled-' . $id . '.eml"');
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: private, no-store');
        header('Content-Length: ' . strlen($row['raw_mime']));
        echo $row['raw_mime'];
        exit;
    }
}
