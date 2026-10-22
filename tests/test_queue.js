/* Modified by Scheduled Sending contributors, 2026-10-23. GPL-3.0; see LICENSE and CREDITS.md. */
const fs = require('fs');
const vm = require('vm');
const assert = require('assert');
const callbacks = {};
const root = {innerHTML: ''};
const rcmail = {
  addEventListener: (name, callback) => { callbacks[name] = callback; },
  gettext: key => key,
  set_busy: () => {},
  url: (action, args) => './?_action=' + action + '&_id=' + args._id,
};
vm.runInNewContext(fs.readFileSync(__dirname + '/../js/queue.js', 'utf8'), {
  window: {rcmail}, rcmail, document: {getElementById: () => root}, Intl, Date,
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
