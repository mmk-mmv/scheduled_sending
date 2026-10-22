/* Modified by Scheduled Sending contributors, 2026-10-23. GPL-3.0; see LICENSE and CREDITS.md. */
/* Update the preferences view only after the server confirms an owner-scoped change. */
(function() {
  if (!window.rcmail) return;
  function t(key) { return rcmail.gettext(key, 'scheduled_sending'); }
  function pad(n) { return String(n).padStart(2, '0'); }
  rcmail.addEventListener('plugin.scheduled_sending.queue_changed', function() { window.location.reload(); });
  function init() {
    document.querySelectorAll('.delete-scheduled-message').forEach(function(link) {
      link.addEventListener('click', function(ev) {
        ev.preventDefault();
        if (!window.confirm(t('delete') + '?')) return;
        rcmail.http_post('plugin.scheduled_sending.queue_delete', {_id: link.dataset.id, _token: rcmail.env.request_token}, rcmail.set_busy(true, 'loading'));
      });
    });
    document.querySelectorAll('.edit-scheduled-message').forEach(function(link) {
      link.addEventListener('click', function(ev) {
        ev.preventDefault();
        var dt = new Date(Number(link.dataset.ts) * 1000);
        var def = dt.getFullYear() + '-' + pad(dt.getMonth()+1) + '-' + pad(dt.getDate()) + ' ' + pad(dt.getHours()) + ':' + pad(dt.getMinutes());
        var value = window.prompt(t('reschedule_prompt'), def);
        if (!value) return;
        if (!/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/.test(value)) { window.alert(t('reschedule_invalid')); return; }
        var when = new Date(value.replace(' ', 'T'));
        if (isNaN(when.getTime()) || when.getFullYear() !== Number(value.slice(0,4)) || when.getMonth()+1 !== Number(value.slice(5,7)) || when.getDate() !== Number(value.slice(8,10)) || when.getHours() !== Number(value.slice(11,13)) || when.getMinutes() !== Number(value.slice(14,16))) { window.alert(t('reschedule_invalid')); return; }
        if (when.getTime() <= Date.now()) { window.alert(t('reschedule_future')); return; }
        rcmail.http_post('plugin.scheduled_sending.queue_reschedule', {id: link.dataset.id, at_ts: Math.floor(when.getTime()/1000), _token: rcmail.env.request_token}, rcmail.set_busy(true, 'loading'));
      });
    });
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init); else init();
})();
