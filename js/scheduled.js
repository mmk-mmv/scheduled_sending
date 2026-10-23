/* Modified by Scheduled Sending contributors, 2026-10-23. GPL-3.0; see LICENSE and CREDITS.md. */
var __ss_isArray = Array.isArray || function(v){ return Object.prototype.toString.call(v) === '[object Array]'; };
function __ss_collect_attachment_meta(target){
  try{
    if (!target || typeof target !== 'object') return;
    var ids = __ss_isArray(target._attach_ids) ? target._attach_ids.slice() : [];
    var seen = {};
    for (var i=0;i<ids.length;i++){ seen[ids[i]] = true; }
    var probe = [];
    function push(id, name, size){
      id = String(id || '').replace(/^rcmfile/, '');
      if (id && !seen[id]) { ids.push(id); seen[id] = true; }
      probe.push({id:id||'', name:name||'', size:size||''});
    }
    var list = document.getElementById('attachment-list');
    if (list && list.querySelectorAll) {
      var items = list.querySelectorAll('li');
      for (var j=0;j<items.length;j++){
        var li = items[j];
        var aid = li.getAttribute('data-id') || li.getAttribute('rel') || li.id || '';
        var name = li.getAttribute('data-name') || li.textContent || '';
        var sz = li.getAttribute('data-size') || '';
        push(aid, (name || '').trim(), sz);
      }
    }
    if (window.rcmail && rcmail.env) {
      var envList = rcmail.env.attachments || rcmail.env.compose_attachments || null;
      if (envList) {
        if (__ss_isArray(envList)) {
          for (var k=0;k<envList.length;k++){
            var e = envList[k];
            if (!e) continue;
            push(e.id || e.uploadid || e._id || '', e.name || e.filename || '', e.size || e.filesize || '');
          }
        } else {
          for (var key in envList){
            if (!Object.prototype.hasOwnProperty.call(envList, key)) continue;
            var ent = envList[key];
            if (!ent) continue;
            push(ent.id || ent.uploadid || key, ent.name || ent.filename || '', ent.size || ent.filesize || '');
          }
        }
      }
    }
    if (ids.length) target._attach_ids = ids;
    if (probe.length) {
      try { target._ss_attach_probe = JSON.stringify(probe); } catch(e){}
    }
  } catch(_){}
}

/* Single compose handler; navigate only after a confirmed queue transaction. */
(function(){
  if (window.__SS_JS_BOUND) return;
  window.__SS_JS_BOUND = true;

  function bind(){
    var btn = document.getElementById('ss-schedule-btn');
    if (!btn) return;
    function t(key) { return (window.rcmail && rcmail.gettext) ? rcmail.gettext(key, 'scheduled_sending') : key; }
    var whenInput = document.getElementById('ss-when');
    if (whenInput && !whenInput.value) {
      var now = new Date();
      var initial = new Date(now.getTime());
      // Choose the next local 08:00, using calendar days across daylight-saving changes.
      initial.setHours(8, 0, 0, 0);
      if (initial.getTime() <= now.getTime()) initial.setDate(initial.getDate() + 1);
      function pad(n) { return String(n).padStart(2, '0'); }
      whenInput.value = initial.getFullYear() + '-' + pad(initial.getMonth()+1) + '-' + pad(initial.getDate()) + 'T' + pad(initial.getHours()) + ':' + pad(initial.getMinutes());
    }
    var inflight = false;
    btn.addEventListener('click', function(ev){
      ev.preventDefault();
      if (inflight) return;

      var when = document.getElementById('ss-when');
      if (!when || !when.value) { if (window.rcmail) rcmail.display_message(t('pick_future_time'), 'error'); return; }
      var d = new Date(when.value);

      if (isNaN(d.getTime()) || d.getTime() <= Date.now()) { if (window.rcmail) rcmail.display_message(t('pick_future_time'), 'error'); return; }

      var form = document.getElementById('composeform') || (window.rcmail && rcmail.gui_objects.messageform) || btn.closest('form');
      if (!form) { if (window.rcmail) rcmail.display_message(t('compose_form_not_ready'), 'error'); return; }

      var data = {};
      // Keep the same key until this compose operation succeeds. Retrying an
      // uncertain HTTP response must resolve the original database row.
      if (!form.__ssRequestId) {
        var bytes = new Uint8Array(16);
        window.crypto.getRandomValues(bytes);
        form.__ssRequestId = Array.from(bytes, function(b) { return b.toString(16).padStart(2, '0'); }).join('');
      }
      data._ss_request_id = form.__ssRequestId;
      var f = new FormData(form);
      function copyField(name, as){ if (f.has(name)) data[as||name] = f.get(name); }
      copyField('_id'); copyField('_from'); copyField('_to'); copyField('_cc'); copyField('_bcc'); copyField('_subject'); copyField('_is_html');
      if (!((data._to || '').trim() || (data._cc || '').trim() || (data._bcc || '').trim())) {
        var rcpt = window.prompt(t('recipient_prompt'));
        if (!rcpt || !rcpt.trim()) {
          if (window.rcmail) rcmail.display_message(t('recipient_required'), 'error');
          return;
        }
        data._to = rcpt.trim();
        var toField = form.querySelector('[name="_to"]');
        if (toField) toField.value = data._to;
      }
      data['_schedule_at'] = when.value;
      data['_schedule_ts'] = Math.floor(d.getTime()/1000);
      data['_schedule_tzoffset'] = - (new Date().getTimezoneOffset());

      // SS helper: decide if HTML is semantically plain (only <p>/<br> and optional RC sig placeholder)
      function __ss_is_plain_html(html, text){
        try{
          if (!html) return true;
          var s = (''+html);
          // remove Roundcube signature placeholder blocks
          s = s.replace(/<div[^>]*id=['"]?_rc_sig['"]?[^>]*>[\s\S]*?<\/div>/gi, '');
          // strip leading/trailing whitespace and non-breaking spaces
          s = s.replace(/&nbsp;/gi,' ').replace(/\s+/g,' ');
          // only allow p and br tags; detect others
          if (/<(?!\/?(p|br)\b)/i.test(s)) return false;
          // convert basic HTML structure to text for rough equivalence
          var t = s;
          // normalize paragraphs to double newlines
          t = t.replace(/<\/p>\s*<p[^>]*>/gi, '\n\n');
          t = t.replace(/<br\s*\/?>/gi, '\n');
          t = t.replace(/<\/?p[^>]*>/gi, '');
          // remove remaining tags (should be none)
          t = t.replace(/<[^>]+>/g, '');
          t = t.replace(/\s+\n/g, '\n').replace(/\n\s+/g, '\n').trim();
          var plain = (''+(text||'')).replace(/\s+\n/g,'\n').replace(/\n\s+/g,'\n').trim();
          // Treat as plain if equal (or equal ignoring trailing newline)
          if (t === plain || t === plain.replace(/\n+$/,'')) return true;
          return false;
        }catch(_){ return false; }
      }


      // SS: ensure body is included
      try { if (window.tinyMCE && tinyMCE.triggerSave) tinyMCE.triggerSave(); } catch(_){}
      try { if (window.rcmail && rcmail.editor && typeof rcmail.editor.save === 'function') rcmail.editor.save(); } catch(_){}
      (function(){
        var html = '', text = '';
        try {
          if (window.tinyMCE && tinyMCE.activeEditor) {
            html = tinyMCE.activeEditor.getContent({format:'html'}) || '';
            text = tinyMCE.activeEditor.getContent({format:'text'}) || '';
          }
        } catch(_){}
        if (!text) {
          var ta = form && form.querySelector && form.querySelector('textarea[name="_message"]');
          if (ta) text = ta.value || '';
        }
        if (html && !__ss_is_plain_html(html, text)) {
          data['_message_html'] = html;
          data['_is_html'] = 1;
        } else {
          data['_message'] = text || '';
          try { delete data['_message_html']; } catch(_){}
          try { delete data['_is_html']; } catch(_){}
        }
      })();

      __ss_collect_attachment_meta(data);

      
      if (window.rcmail && typeof rcmail.http_post === 'function') {
        __ss_collect_attachment_meta(data);
        data._token = rcmail.env.request_token;
        inflight = true;
        btn.disabled = true;
        var release = function() { inflight = false; btn.disabled = false; };
        try {
          var request = rcmail.http_post('plugin.scheduled_sending.schedule', data, rcmail.set_busy(true, 'loading'));
          if (request && typeof request.always === 'function') request.always(release);
          else release();
        } catch (e) { release(); throw e; }
      }
    }, { once:false });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', bind);
  } else {
    bind();
  }
})();



// Leave compose only after the server confirms that the queue insert succeeded.
if (window.rcmail && rcmail.addEventListener) {
  rcmail.addEventListener('plugin.scheduled_sending.scheduled', function() {
    rcmail.env.compose_submit = true;
    rcmail.env.compose_changes = false;
    window.onbeforeunload = null;
    window.location.assign(rcmail.url('list', {_task: 'mail', _mbox: rcmail.env.mailbox || 'INBOX'}));
  });
}
