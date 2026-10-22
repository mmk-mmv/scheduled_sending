/* Modified by Scheduled Sending contributors, 2026-10-23. GPL-3.0; see LICENSE and CREDITS.md. */
const fs = require('fs');
const vm = require('vm');
const assert = require('assert');
const crypto = require('crypto').webcrypto;
const callbacks = {}, handlers = {}, posts = [], navigations = [];
let complete;
const form = {querySelector: () => ({value:'Body with ümlauts'})};
const button = {addEventListener:(e, fn)=>handlers[e]=fn};
const time = {value:'2099-10-25T09:30'};
const document = {readyState:'complete', getElementById:id=>({'ss-schedule-btn':button,'ss-when':time,composeform:form}[id] || null)};
class FormData { has(key) { return ['_id','_from','_to','_subject'].includes(key); } get(key) { return {_id:'compose-test-00001',_from:'1',_to:'"Name, Büro" <to@example.net>',_subject:'Subject'}[key]; } }
const rcmail = {
  env:{request_token:'csrf-fixture',mailbox:'INBOX'}, gettext:k=>k, set_busy:()=>{},
  http_post:(action,data)=>{ posts.push({action,data}); return {always:fn=>complete=fn}; },
  addEventListener:(e,fn)=>callbacks[e]=fn, url:()=>'/mail-list', display_message:()=>{},
};
const window = {rcmail,crypto,location:{assign:url=>navigations.push(url)}};
vm.runInNewContext(fs.readFileSync(__dirname+'/../js/scheduled.js','utf8'), {window,rcmail,document,FormData,Date,Intl,Uint8Array,Array});
const click = ()=>handlers.click({preventDefault(){}});
click(); click();
assert.equal(posts.length,1);
assert(button.disabled);
assert.equal(navigations.length,0);
assert.equal(posts[0].data._message,'Body with ümlauts');
assert.equal(posts[0].data._token,'csrf-fixture');
assert(/^[a-f0-9]{32}$/.test(posts[0].data._ss_request_id));
const key = posts[0].data._ss_request_id;
complete();
assert(!button.disabled);
click();
assert.equal(posts.length,2);
assert.equal(posts[1].data._ss_request_id,key);
assert.equal(navigations.length,0);
callbacks['plugin.scheduled_sending.scheduled']({id:1});
assert.equal(navigations.length,1);
assert(rcmail.env.compose_submit);
console.log('PASS compose: slow request blocks double click, retry reuses key, body survives, navigation requires server confirmation');
