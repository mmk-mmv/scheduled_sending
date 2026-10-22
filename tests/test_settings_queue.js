/* Modified by Scheduled Sending contributors, 2026-10-23. GPL-3.0; see LICENSE and CREDITS.md. */
const fs = require('fs');
const vm = require('vm');
const assert = require('assert');
const callbacks={}, clicks={}, posts=[];
let confirmed=false, reloaded=0, prompt='2099-10-25 12:00', alerts=0;
const elements={
  '.delete-scheduled-message':{dataset:{id:'7'}},
  '.edit-scheduled-message':{dataset:{id:'7',ts:'1800000000'}},
};
Object.entries(elements).forEach(([key,el])=>el.addEventListener=(name,fn)=>clicks[key]=fn);
const rcmail={env:{request_token:'csrf'},gettext:k=>k,set_busy:()=>{},addEventListener:(n,fn)=>callbacks[n]=fn,http_post:(...args)=>posts.push(args)};
const window={rcmail,confirm:()=>confirmed,prompt:()=>prompt,alert:()=>alerts++,location:{reload:()=>reloaded++}};
const document={readyState:'complete',querySelectorAll:key=>[elements[key]]};
vm.runInNewContext(fs.readFileSync(__dirname+'/../js/settings_queue.js','utf8'),{window,rcmail,document,Date,String,Number});
const click=key=>clicks[key]({preventDefault(){}});
click('.delete-scheduled-message'); assert.equal(posts.length,0);
confirmed=true; click('.delete-scheduled-message'); assert.equal(posts.length,1); assert.equal(reloaded,0);
assert.equal(posts[0][1]._token,'csrf');
prompt='2099-02-31 12:00'; click('.edit-scheduled-message'); assert.equal(posts.length,1); assert.equal(alerts,1);
prompt='2099-10-25 12:00'; click('.edit-scheduled-message'); assert.equal(posts.length,2); assert.equal(reloaded,0);
callbacks['plugin.scheduled_sending.queue_changed'](); assert.equal(reloaded,1);
console.log('PASS queue settings: explicit delete confirmation, impossible dates rejected, reload only after confirmed mutation');
