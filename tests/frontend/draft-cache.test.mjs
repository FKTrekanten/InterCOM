import {test} from 'node:test';
import assert from 'node:assert/strict';
import {DraftCache, composerControls} from '../../src/component/media/js/draft-cache.mjs';

const message = {type:'club',sender:'Club',subject_da:'Ufærdig',subject_en:'',body_da:'<p>Dansk</p>',body_en:'',tags:['group.Youth'],memberships:[],age_from:0,age_to:0,gender:'',format:'html'};
const storage = () => {const values=new Map(); return {getItem:key=>values.get(key),setItem:(key,value)=>values.set(key,value),removeItem:key=>values.delete(key)};};

test('Bilingual incomplete content survives reload with its language and step; server revisions and account/list/user contexts stay isolated', () => {
  const db=storage(), clock={now:()=>100000}, cache=new DraftCache(db,'user.account.list',30,clock);
  assert.equal(cache.save(8,2,message,'da',1),true);
  assert.deepEqual(new DraftCache(db,'user.account.list',30,clock).load(8,2),{version:1,revision:2,message,language:'da',step:1,at:100000});
  assert.equal(cache.load(8,3),null);
  assert.deepEqual(cache.load(8,2).message,message,'A conflicting tab does not silently erase recovery');
  assert.equal(cache.load(0,0),null);
  assert.equal(new DraftCache(db,'other.account.list',30,clock).load(8,2),null);
  assert.equal(new DraftCache(db,'user.account.otherlist',30,clock).load(8,2),null);
  cache.clear(8); assert.equal(cache.load(8,2),null);
});

test('Expired/future records and malformed content cannot be restored; unavailable browser storage is nonfatal', () => {
  const db=storage(), clock={now:()=>100000000}, cache=new DraftCache(db,'context',1,clock);
  cache.save(0,0,message,'en',2);
  clock.now=()=>200000000; assert.equal(cache.load(0,0),null);
  clock.now=()=>100000000; cache.save(0,0,message,'en',2);
  clock.now=()=>0; assert.equal(cache.load(0,0),null);
  clock.now=()=>100000000;
  for (const bad of [{...message,tags:'all'},{...message,body_en:null},{...message,age_from:-1},{...message,gender:'other'}]) {
    cache.save(0,0,bad,'en',2); assert.equal(cache.load(0,0),null);
  }
  cache.save(0,0,message,'en',99); assert.equal(cache.load(0,0),null);
  const blocked=new DraftCache({getItem(){throw Error('Privacy');},setItem(){throw Error('Quota');},removeItem(){throw Error('Privacy');}},'context');
  assert.equal(blocked.save(0,0,message,'en',0),false); assert.equal(blocked.load(0,0),null); blocked.clear(0);
  assert.equal(new DraftCache(undefined,'context').save(0,0,message,'en',0),false);
});

test('Saving and testing incomplete/new drafts are available; sending requires an unchanged tested positive-count draft and verified acceptance', () => {
  assert.deepEqual(composerControls(null),{save:true,preview:true,delete:true,confirm:false});
  const tested={state:'tested',estimate_count:1};
  assert.equal(composerControls(tested,{approved:true}).confirm,true);
  for (const options of [{approved:false},{approved:true,dirty:true},{approved:true,audienceDirty:true},{approved:true,busy:true}]) assert.equal(composerControls(tested,options).confirm,false);
  assert.equal(composerControls({...tested,estimate_count:0},{approved:true}).confirm,false);
  for (const state of ['submitted','completed','uncertain','deleted']) assert.deepEqual(composerControls({state}),{save:false,preview:false,delete:false,confirm:false});
  assert.equal(composerControls({state:'cancelled'}).delete,true);
});
