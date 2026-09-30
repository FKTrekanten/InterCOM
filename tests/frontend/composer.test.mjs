import {test} from 'node:test';
import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import {runInNewContext} from 'node:vm';
import {DraftCache, composerControls} from '../../src/component/media/js/draft-cache.mjs';
import {PreviewScheduler, requiresTeam, subjectLabel} from '../../src/component/media/js/preview.mjs';

// Run the real composer event handlers against a small DOM/editor boundary.
// No external library or live provider is involved in this interaction test.
test('Subject typing updates the envelope without requesting or replacing email HTML; body typing is delayed', async () => {
  const fields = Object.fromEntries(Object.entries({type:'club',sender:'Club',subject_da:'DA',subject_en:'Old',body_da:'Dansk',body_en:'English',gender:'',age_from:0,age_to:0,send_at:''}).map(([name,value]) => [name,{name,value,classList:{contains:() => false}}]));
  fields['tags[]'] = fields['memberships[]'] = {selectedOptions:[]};
  const elements = new Map(), buttons = new Map(), events = new Map(), tasks = new Map(), calls = [];
  const element = id => {
    if (!elements.has(id)) elements.set(id,{textContent:'',checked:false,value:'',srcdoc:'',setAttribute(){},removeAttribute(){},addEventListener(){}});
    return elements.get(id);
  };
  let time = 0, timer = 0, paints = 0, html = '';
  Object.defineProperty(element('ic-preview-frame'),'srcdoc',{get:() => html,set:value => {paints++;html=value;}});
  const form = {elements:fields,action:'/render',querySelectorAll:() => [],querySelector:key => {
    if (!buttons.has(key)) buttons.set(key,{});
    return buttons.get(key);
  },addEventListener:(event,handler) => events.set(event,handler)};
  class FormData {
    values = Object.fromEntries(Object.entries(fields).map(([key,field]) => [key,field.value]));
    get(key) {return this.values[key];}
    set(key,value) {this.values[key]=value;}
    getAll() {return [];}
  }
  const sandbox = {DraftCache,composerControls,PreviewScheduler,requiresTeam,subjectLabel,FormData,Map,console,
    JoomlaEditor:{get:() => null},Joomla:{getOptions:() => ({language:'en',types:{club:{prefixes:{en:'Club News'}}}}),Text:{_:key => key}},
    document:{addEventListener(){},getElementById:id => id === 'ic-form' ? form : element(id),querySelectorAll:() => []},window:{addEventListener(){}},
    setTimeout:(callback,delay) => {tasks.set(++timer,{callback,at:time+delay});return timer;},clearTimeout:id => tasks.delete(id),setInterval(){},
    fetch:async (_,request) => {
      const message = JSON.parse(request.body.get('message')); calls.push(message);
      return {ok:true,json:async () => ({success:true,data:{da:message.body_da,en:message.body_en}})};
    }};
  sandbox.PreviewScheduler = class extends PreviewScheduler {
    constructor(render,accept,pending) {super(render,accept,pending,800,{setTimeout:sandbox.setTimeout,clearTimeout:sandbox.clearTimeout});}
  };
  const source = readFileSync(new URL('../../src/component/media/js/app.js',import.meta.url),'utf8').replace(/^import .*;\n/gm,'');
  runInNewContext(source,sandbox);
  const advance = async ms => {
    time += ms;
    for (const [id,task] of tasks) if (task.at <= time) {tasks.delete(id); task.callback();}
    await new Promise(resolve => setImmediate(resolve));
  };
  await advance(0);
  assert.equal(calls.length,1); assert.equal(paints,1);
  fields.subject_en.value = 'New subject'; events.get('input')({target:fields.subject_en});
  assert.equal(element('ic-preview-subject').textContent,'[Club News] New subject');
  await advance(1000);
  assert.equal(calls.length,1); assert.equal(paints,1);
  for (let i=0;i<10;i++) {fields.body_en.value='Body '+i;events.get('input')({target:fields.body_en});await advance(50);}
  assert.equal(calls.length,1); assert.equal(html,'English');
  await advance(749); assert.equal(calls.length,1);
  await advance(1); assert.equal(calls.length,2); assert.equal(html,'Body 9');
  assert.equal(calls.at(-1).subject_en,'New subject');
});

function composerHarness(initial, api) {
  const fields=Object.fromEntries(Object.entries({type:'club',sender:'Club',subject_da:'DA',subject_en:'EN',body_da:'Dansk',body_en:'English',gender:'',age_from:0,age_to:0,send_at:''}).map(([name,value])=>[name,{name,value,classList:{contains:()=>false},checkValidity:()=>true}]));
  fields['tags[]']=fields['memberships[]']={selectedOptions:[]};
  const elements=new Map(),events=new Map(),actions=new Map(),steps=new Map();
  const element=id=>{if(!elements.has(id))elements.set(id,{textContent:'',hidden:false,checked:false,srcdoc:'',setAttribute(){},removeAttribute(){},addEventListener(){}});return elements.get(id);};
  const action=name=>{if(!actions.has(name))actions.set(name,{dataset:{action:name},addEventListener:(_,handler)=>actions.get(name).click=handler});return actions.get(name);};
  ['save','preview','release','delete','restore','cancel'].forEach(action);
  const go={dataset:{go:'1'},addEventListener:(_,handler)=>steps.set('content',handler)};
  const form={elements:fields,action:'/api',querySelector:selector=>action(selector.match(/data-action=(\w+)/)?.[1]),querySelectorAll:selector=>selector==='[data-action]'?[...actions.values()]:selector==='[data-step],[data-go]'?[go]:selector==='input,select'?Object.values(fields).filter(f=>f.checkValidity):[],addEventListener:(event,handler)=>events.set(event,handler)};
  class FormData {
    values=Object.fromEntries(Object.entries(fields).map(([key,field])=>[key,field.value]));
    get(key){return this.values[key];} set(key,value){this.values[key]=value;} getAll(){return [];}
  }
  let url='http://example.test/intercom';
  const sandbox={DraftCache,composerControls,PreviewScheduler,requiresTeam,subjectLabel,FormData,Map,URL,
    JoomlaEditor:{get:()=>null},Joomla:{getOptions:()=>({language:'en',types:{club:{prefixes:{}}},...initial}),Text:{_:key=>key}},
    document:{getElementById:id=>id==='ic-form'?form:element(id),querySelectorAll:()=>[],addEventListener(){}},window:{location:{href:url},history:{replaceState:(_,__,value)=>url=String(value)},addEventListener(){}},
    setTimeout(){},clearTimeout(){},setInterval(){},fetch:async (_,request)=>({ok:true,json:async()=>({success:true,data:request.body.get('task')==='api.render'?{da:'Preview',en:'Preview'}:await api(request.body)})})};
  runInNewContext(readFileSync(new URL('../../src/component/media/js/app.js',import.meta.url),'utf8').replace(/^import .*;\n/gm,''),sandbox);
  return {fields,element,action,content:()=>steps.get('content')(),url:()=>url,events};
}

test('Slow counts retain the saved draft URL and editing controls; saves wait for the current count before using its revision', async () => {
  let finishCount; const count=new Promise(resolve=>finishCount=resolve),calls=[];
  const draft={id:8,revision:1,state:'draft',estimate_count:null};
  const h=composerHarness({},async body=>{
    calls.push(body.get('task'));
    if(body.get('task')==='api.saveaudience')return draft;
    if(body.get('task')==='api.estimate')return count;
    assert.equal(body.get('revision'),1);
    return {...draft,revision:2,estimate_count:1};
  });
  const estimating=h.content(); await new Promise(resolve=>setImmediate(resolve));
  assert.match(h.url(),/id=8/); assert.equal(h.element('ic-new-message').hidden,false);
  assert.equal(h.action('save').disabled,false);
  const saving=h.action('save').click(); await new Promise(resolve=>setImmediate(resolve));
  assert.deepEqual(calls,['api.saveaudience','api.estimate']);
  finishCount({...draft,estimate_count:1}); await estimating; await saving;
  assert.deepEqual(calls,['api.saveaudience','api.estimate','api.save']);
  assert.equal(h.action('save').disabled,false);
});

test('Send test saves dirty current content first, then tests that exact server revision', async () => {
  const calls=[];
  const h=composerHarness({releaseApproved:true},async body=>{
    calls.push(body.get('task'));
    if(body.get('task')==='api.save'){
      assert.equal(JSON.parse(body.get('message')).body_en,'English');
      return {id:12,revision:3,state:'draft',estimate_count:1};
    }
    assert.equal(body.get('task'),'api.preview'); assert.equal(body.get('id'),12); assert.equal(body.get('revision'),3);
    return {id:12,revision:3,state:'tested',estimate_count:1};
  });
  assert.equal(h.action('preview').disabled,false);
  await h.action('preview').click();
  assert.deepEqual(calls,['api.save','api.preview']);
  assert.equal(h.element('ic-confirm').disabled,false);
  h.fields.subject_en.value='Changed after test'; h.events.get('input')({target:h.fields.subject_en});
  assert.equal(h.element('ic-confirm').disabled,true);
});
