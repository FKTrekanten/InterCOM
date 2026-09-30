import {test} from 'node:test';
import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import {runInNewContext} from 'node:vm';

test('List changes clear the old form and ignore stale responses; scope failures leave no selection', async () => {
  const pending = [], events = {};
  const group = {value:'758666',addEventListener:(name,handler) => {events[name]=handler;}};
  const forms = {disabled:false,items:[],replaceChildren(...items) {this.items=items;}};
  class Option {constructor(text,value) {this.text=text;this.value=value;}}
  const context = {Option,Number,encodeURIComponent,Joomla:{Text:{_:key => key}},
    document:{getElementById:id => id === 'jform_group_id' ? group : forms,querySelectorAll:() => [],addEventListener:(_,handler) => handler()},
    fetch:url => new Promise(resolve => pending.push({url,resolve}))};
  runInNewContext(readFileSync(new URL('../../src/component/media/js/options.js',import.meta.url),'utf8'),context);
  const first = events.change();
  assert.equal(forms.disabled,true); assert.equal(forms.items[0].value,'');
  group.value='999'; const second=events.change();
  pending[1].resolve({ok:true,json:async () => ({success:true,data:[{id:'new-form',name:'New list'}]})}); await second;
  assert.equal(forms.disabled,false);assert.equal(forms.items[1].value,'new-form');
  pending[0].resolve({ok:true,json:async () => ({success:true,data:[{id:'stale-form',name:'Old list'}]})}); await first;
  assert.equal(forms.items[1].value,'new-form');
  const failed=events.change(); pending[2].resolve({ok:false,json:async () => ({success:false})}); await failed;
  assert.equal(forms.items.length,1);assert.equal(forms.items[0].value,'');assert.equal(forms.disabled,false);
  assert.equal(forms.items[0].text,'COM_INTERCOM_UNSUBSCRIBE_LOAD_ERROR');
  group.value='0'; await events.change();assert.equal(pending.length,3);
  assert.equal(forms.items[0].text,'COM_INTERCOM_CHOOSE_UNSUBSCRIBE');
});
