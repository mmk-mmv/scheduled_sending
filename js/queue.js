/* Modified by Scheduled Sending contributors, 2026-10-23. GPL-3.0; see LICENSE and CREDITS.md. */
/* Queue navigation and rendering. Read-only refresh; mutations wait for server confirmation. */
(function() {
  if (!window.rcmail) return;
  function t(key) { return rcmail.gettext ? rcmail.gettext(key, 'scheduled_sending') : key; }
  function esc(value) { return String(value == null ? '' : value).replace(/[&<>"']/g, function(ch) { return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[ch]; }); }
  function count() { rcmail.http_post('plugin.scheduled_sending.queue_count', {}); }
  function list() { rcmail.http_post('plugin.scheduled_sending.queue_list', {}, rcmail.set_busy(true, 'loading')); }
  rcmail.addEventListener('init', function() {
    rcmail.register_command('plugin.scheduled_sending.open_queue', function() {
      rcmail.goto_url('_task=mail&_action=plugin.scheduled_sending.queue');
    }, true);
    count();
    if (document.getElementById('ssq-root')) list();
    window.setInterval(function() {
      if (document.hidden) return;
      count();
      if (document.getElementById('ssq-root')) list();
    }, 60000);
  });
  rcmail.addEventListener('plugin.scheduled_sending.queue_count', function(ev) {
    var button = document.getElementById('scheduled-sending-menu');
    if (!button) return;
    var badge = button.querySelector('.scheduled-count');
    var n = Math.max(0, Number(ev.count) || 0);
    if (!n) { if (badge) badge.remove(); button.setAttribute('aria-label', t('scheduled_queue_title')); return; }
    if (!badge) { badge = document.createElement('span'); badge.className = 'scheduled-count'; badge.setAttribute('aria-hidden', 'true'); button.appendChild(badge); }
    badge.textContent = String(n);
    button.setAttribute('aria-label', t('scheduled_queue_title') + ': ' + n);
  });
  rcmail.addEventListener('plugin.scheduled_sending.queue_changed', function() {
    count();
    if (document.getElementById('ssq-root')) list();
  });
  rcmail.addEventListener('plugin.scheduled_sending.queue_data', function(rows) {
    try {
      var root = document.getElementById('ssq-root');
      if (!root) return;
      rows = Array.isArray(rows) ? rows : [];
      var html = '<table class="ssq-table"><thead><tr>';
      ['id','status','local_time','to','subject','actions'].forEach(function(key) { html += '<th scope="col">' + esc(t(key)) + '</th>'; });
      html += '</tr></thead><tbody>';
      rows.forEach(function(r) {
        var id = Number(r.id);
        if (!Number.isSafeInteger(id) || id < 1) return;
        var time = r.scheduled_ts ? new Date(Number(r.scheduled_ts) * 1000).toLocaleString() : '';
        var status = esc(t('status_' + r.status)) + (r.error ? '<small>' + esc(r.error) + '</small>' : '');
        var actions = '<a class="ssq-download" href="' + esc(rcmail.url('plugin.scheduled_sending.queue_download', {_task:'mail',_id:id})) + '">' + esc(t('download_eml')) + '</a>';
        if (r.status === 'queued' || r.status === 'retry_wait') {
          actions += '<button type="button" class="button ssq-cancel" data-id="' + id + '">' + esc(t('cancel')) + '</button>';
          actions += '<button type="button" class="button ssq-bump10" data-id="' + id + '">' + esc(t('bump10')) + '</button>';
        }
        html += '<tr><td>' + id + '</td><td>' + status + '</td><td>' + esc(time) + '</td><td>' + esc(r.to || r.cc || r.bcc) + '</td><td>' + esc(r.subj) + '</td><td>' + actions + '</td></tr>';
      });
      html += '</tbody></table>';
      if (!rows.length) html = '<p>' + esc(t('no_queued_messages')) + '</p>';
      root.innerHTML = html;
      root.onclick = function(ev) {
        var target = ev.target;
        if (!target || !target.classList) return;
        var id = Number(target.getAttribute('data-id'));
        if (!Number.isSafeInteger(id) || id < 1) return;
        if (target.classList.contains('ssq-cancel')) {
          if (window.confirm(t('cancel') + '?')) rcmail.http_post('plugin.scheduled_sending.queue_cancel', {id:id,_token:rcmail.env.request_token}, rcmail.set_busy(true, 'loading'));
        } else if (target.classList.contains('ssq-bump10')) {
          rcmail.http_post('plugin.scheduled_sending.queue_reschedule', {id:id,at_ts:Math.floor(Date.now()/1000)+600,_token:rcmail.env.request_token}, rcmail.set_busy(true, 'loading'));
        }
      };
    } finally { rcmail.set_busy(false); }
  });
})();
