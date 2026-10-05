import {test} from 'node:test';
import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import {runInNewContext} from 'node:vm';
import {PreviewScheduler} from '../../src/component/media/js/preview.mjs';

function fixture() {
  const requests = [], timers = new Map(), events = {}, status = {};
  let nextTimer = 0;
  const controls = [2,3].map(group => {
    const badge = {textContent:'Original',prepend(lock) {this.lock=lock;}};
    const output = {querySelector:() => badge,setAttribute(name,value) {this[name]=value;}};
    return {id:'jform_rules_intercom.type.compose_'+group, value:'', icon:{}, output, badge,
      closest:() => ({querySelector:() => output})};
  });
  const form = {values:{csrf:'1'}};
  const permissions = {dataset:{previewUrl:'index.php?option=com_intercom&task=management.previewpermissions&format=json'},
    closest:() => form,querySelectorAll:() => controls,
    addEventListener(name,handler,capture) {events[name]={handler,capture};}};
  class FormData {constructor(source) {this.values={...source.values,...Object.fromEntries(controls.map(c => [c.id,c.value]))};}}
  const clock = {setTimeout(fn) {const id=++nextTimer;timers.set(id,fn);return id;},clearTimeout(id) {timers.delete(id);}};
  class Scheduler extends PreviewScheduler {constructor(render,accept,pending,delay) {super(render,accept,pending,delay,clock);}}
  const document = {getElementById(id) {
    if (id==='ic-permissions') return permissions;
    if (id==='ic-permissions-status') return status;
    return controls.find(control => 'icon_'+control.id===id)?.icon;
  },createElement:() => ({setAttribute() {}})};
  const context = {PreviewScheduler:Scheduler,document,FormData,AbortSignal:{timeout:() => 'timeout-signal'},Joomla:{Text:{_:key => key}},
    fetch:(url,options) => new Promise((resolve,reject) => requests.push({url,options,resolve,reject}))};
  const source = readFileSync(new URL('../../src/component/media/js/permissions.js',import.meta.url),'utf8').replace(/^import .*;\n/,'');
  runInNewContext(source,context);
  const change = (control=controls[0]) => {
    let stopped=false;
    events.change.handler({target:control,stopImmediatePropagation() {stopped=true;}});
    return stopped;
  };
  const start = () => {
    const [id,fn]=[...timers][0];timers.delete(id);return fn();
  };
  const response = (text,locked=false) => ({ok:true,json:async () => ({success:true,data:Object.fromEntries(controls.map(control =>
    [control.id,{class:locked?'badge bg-danger':'badge bg-success',text,locked}]))})});
  return {controls,events,status,form,requests,timers,change,start,response};
}

test('Group permission edits intercept native writes, preview with CSRF, and update descendant badges', async () => {
  const f=fixture();
  assert.equal(f.events.change.capture,true);
  f.controls[0].value='1'; assert.equal(f.change(),true);
  assert.match(f.controls[0].icon.className,/spinner/);
  const running=f.start();
  assert.match(f.requests[0].url,/management.previewpermissions/);
  assert.equal(f.requests[0].options.method,'POST');
  assert.equal(f.requests[0].options.body.values.csrf,'1');
  assert.equal(f.requests[0].options.body.values[f.controls[0].id],'1');
  assert.equal(f.requests[0].options.signal,'timeout-signal');
  f.requests[0].resolve(f.response('Allowed')); await running;
  for (const control of f.controls) {
    assert.equal(control.badge.textContent,'Allowed');assert.equal(control.icon.className,'');assert.equal(control.output['aria-busy'],'false');
  }
  assert.equal(f.status.textContent,'COM_INTERCOM_PERMISSIONS_PREVIEW');
  assert.equal(f.change({}),false);
});

test('Rapid permission changes ignore old calculations and clear spinners after errors or timeouts', async () => {
  const f=fixture(); f.change(); const old=f.start();
  f.controls[0].value='0'; f.change(); const recent=f.start();
  f.requests[1].resolve(f.response('Not Allowed (Locked)',true)); await recent;
  assert.equal(f.controls[1].badge.textContent,'Not Allowed (Locked)');assert.ok(f.controls[1].badge.lock);
  f.requests[0].resolve(f.response('Stale Allowed')); await old;
  assert.equal(f.controls[1].badge.textContent,'Not Allowed (Locked)');
  for (const failure of ['http','json','timeout','missing']) {
    f.change(); const running=f.start(); const request=f.requests.at(-1);
    if (failure==='timeout') request.reject(new Error('Request timed out'));
    else if (failure==='json') request.resolve({ok:true,json:async () => {throw new Error('Invalid response');}});
    else if (failure==='missing') request.resolve({ok:true,json:async () => ({success:true,data:{}})});
    else request.resolve({ok:false,json:async () => ({success:false,error:'Denied'})});
    await running;
    assert.equal(f.status.className,'alert alert-danger');
    for (const control of f.controls) {assert.equal(control.icon.className,'');assert.equal(control.output['aria-busy'],'false');}
  }
});
