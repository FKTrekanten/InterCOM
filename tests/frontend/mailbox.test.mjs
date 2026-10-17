import {test} from 'node:test';
import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import {runInNewContext} from 'node:vm';

function mailboxHarness(fetchPage = async () => {throw new Error('Unexpected request');}, initialDraft = {id:42}) {
  const source = readFileSync(new URL('../../src/component/media/js/app.js', import.meta.url), 'utf8');
  const handlers = {}, requests = [], errors = [];
  let current, now = 1000;
  const url = box => new URL(`http://example.test/?box=${box}`);
  const node = box => ({box, cloneNode:() => node(box), contains:() => false, setAttribute(){}, removeAttribute(){},
    replaceWith(value){current = value;}, querySelector:() => null});
  const page = (box, others = []) => ({getElementById:() => node(box), querySelectorAll:() => others.map(other => ({
    dataset:{mailboxUrl:String(url(other))}, content:{querySelector:() => node(other)},
  }))});
  current = node('drafts');
  const document = {...page('drafts', ['sent','attention','closed']), getElementById:() => current,
    addEventListener:(type, handler) => {handlers[type] = handler;}};
  const window = {location:{href:String(url('drafts'))}, history:{replaceState:(_, __, value) => {window.location.href = String(value);}}};
  class FormData {constructor(form){this.values = form.values;} [Symbol.iterator](){return this.values[Symbol.iterator]();}}
  const sandbox = {
    document, window, initial:{mailbox:true}, draft:initialDraft, URL, Map, FormData, Date:{now:() => now},
    syncStatus(){}, text:value => value, report:value => errors.push(value),
    fetch:async request => {requests.push(String(request));return {ok:true,text:async () => fetchPage(new URL(request))};},
    DOMParser:class {parseFromString(value){return value;}},
  };
  runInNewContext(source.slice(source.indexOf('  const mailboxPages ='), source.indexOf("  document.getElementById('ic-status-refresh')")), sandbox);
  const settle = async () => {await new Promise(resolve => setImmediate(resolve));};
  return {persistDraft(id) {sandbox.draft={id};const currentUrl=new URL(window.location.href);currentUrl.searchParams.set('id',id);currentUrl.searchParams.delete('new');window.location.href=String(currentUrl);}, requests, errors, current:() => current.box, url:() => new URL(window.location.href), advance:ms => {now += ms;},
    async click(box, modifiers = {}) {
      let prevented = false;
      handlers.click({button:0, ...modifiers, target:{closest:() => ({href:String(url(box))})}, preventDefault(){prevented = true;}});
      await settle();return prevented;
    },
    async search(term) {
      handlers.submit({target:{matches:() => true, values:[['box','sent'],['mail_search',term]]},preventDefault(){}});
      await settle();
    }, page, settle};
}

test('All preloaded tabs switch without requests and retain the current composer draft', async () => {
  const h = mailboxHarness();
  for (const box of ['sent','attention','closed','drafts']) {
    assert.equal(await h.click(box),true);
    assert.equal(h.current(),box);
    assert.equal(h.url().searchParams.get('id'),'42');
  }
  assert.equal(h.requests.length,0);
  assert.equal(await h.click('sent',{ctrlKey:true}),false);
  assert.equal(h.current(),'drafts');
});

test('Expired tabs fetch fresh data; search updates just the mailbox', async () => {
  const h = mailboxHarness(url => h.page(url.searchParams.get('box'), ['drafts','attention','closed']));
  h.advance(60001);
  await h.click('sent');
  assert.equal(h.requests.length,1);
  await h.click('attention');
  assert.equal(h.requests.length,1);
  await h.search('Camp');
  assert.equal(h.requests.length,2);
  assert.equal(h.url().searchParams.get('mail_search'),'Camp');
  assert.equal(h.current(),'sent');
});

test('A slow older response cannot replace the latest tab selection', async () => {
  const pending = [];
  const h = mailboxHarness(url => new Promise(resolve => pending.push(() => resolve(h.page(url.searchParams.get('box'))))));
  h.advance(60001);
  await h.click('sent');
  await h.click('closed');
  pending[1](); await h.settle();
  assert.equal(h.current(),'closed');
  pending[0](); await h.settle();
  assert.equal(h.current(),'closed');
  assert.equal(h.url().searchParams.get('box'),'closed');
});

test('A failed list refresh preserves the current mailbox', async () => {
  const h = mailboxHarness(async () => {throw new Error('Offline');});
  h.advance(60001);
  await h.click('sent');
  assert.equal(h.current(),'drafts');
  assert.deepEqual(h.errors,['Offline']);
});


test('A slow mailbox response preserves a draft created while it was pending', async () => {
  let finish;
  const h = mailboxHarness(() => new Promise(resolve => {finish=() => resolve(h.page('sent'));}), null);
  h.advance(60001);
  await h.click('sent');
  h.persistDraft(42);
  finish(); await h.settle();
  assert.equal(h.current(),'sent');
  assert.equal(h.url().searchParams.get('id'),'42');
  assert.equal(h.url().searchParams.get('box'),'sent');
});
