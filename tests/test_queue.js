/* Modified by Scheduled Sending contributors, 2026-10-23. GPL-3.0; see LICENSE and CREDITS.md. */
const fs = require('fs');
const vm = require('vm');
const assert = require('assert');
const callbacks = {}, commands = {}, posts = [];
let navigation = '';
// Use Roundcube's actual URL builder: passing a raw query as an action is invalid.
const app = fs.readFileSync(__dirname + '/../.cache/roundcube/program/js/app.js', 'utf8');
const urlSource = app.slice(app.indexOf('  this.url = function('), app.indexOf('  this.redirect = function('));
const nativeUrl = {};
new Function('$', urlSource).call(nativeUrl, {param:obj=>new URLSearchParams(obj).toString()});
const root = {innerHTML: ''};
const rcmail = {
  addEventListener: (name, callback) => { callbacks[name] = callback; },
  gettext: key => key,
  set_busy: () => {},
  env: {comm_path:'./?_task=settings&_framed=1', action:'preferences', request_token:'csrf'},
  url: nativeUrl.url,
  register_command: (name, callback) => { commands[name] = callback; },
  http_post: (action,data) => { posts.push([action,data]); },
  goto_url: function(action,query) { navigation = this.url(action,query); },
};
vm.runInNewContext(fs.readFileSync(__dirname + '/../js/queue.js', 'utf8'), {
  window: {rcmail, setInterval:()=>{}}, rcmail, document: {getElementById: id => id === 'ssq-root' ? root : null}, Intl, Date,
});
callbacks['plugin.scheduled_sending.queue_data']([{
  id: 1, status: 'error', scheduled_utc: '2026-10-21 05:12:00',
  to: '"Müller, Büro" <named@example.net>', subj: '<img src=x onerror=alert(1)>',
  error: '<script>untrusted error</script>',
}]);
assert(root.innerHTML.includes('&lt;named@example.net&gt;'));
assert(root.innerHTML.includes('&lt;img src=x onerror=alert(1)&gt;'));
assert(!root.innerHTML.includes('<img'));
assert(!root.innerHTML.includes('<named@example.net>'));
assert(root.innerHTML.includes('&lt;script&gt;untrusted error&lt;/script&gt;'));
assert(!root.innerHTML.includes('<script>'));
console.log('PASS queue viewer preserves visible named addresses and escapes untrusted HTML');

callbacks.init();
commands['plugin.scheduled_sending.open_queue']();
const target = new URL(navigation, 'https://webmail.example.org/');
assert.equal(target.searchParams.get('_task'), 'mail');
assert.equal(target.searchParams.get('_action'), 'plugin.scheduled_sending.queue');
assert(!target.searchParams.has('_framed'));
assert.equal(posts[0][0], 'plugin.scheduled_sending.queue_count');
console.log('PASS native Roundcube URL builder: queue navigation switches from settings to mail without a frame');
