import {test} from 'node:test';
import assert from 'node:assert/strict';
import {PreviewScheduler, requiresTeam, subjectLabel} from '../../src/component/media/js/preview.mjs';

class Clock {
  tasks = new Map();
  time = 0;
  id = 0;
  setTimeout(callback, delay) {const id = ++this.id; this.tasks.set(id, {callback, at:this.time + delay}); return id;}
  clearTimeout(id) {this.tasks.delete(id);}
  advance(ms) {
    this.time += ms;
    for (const [id, task] of this.tasks) if (task.at <= this.time) {this.tasks.delete(id); task.callback();}
  }
}
const settle = () => new Promise(resolve => setImmediate(resolve));

test('Rapid body edits produce one render after the final 800 ms pause', async () => {
  const clock = new Clock(); let calls = 0; const accepted = [];
  const scheduler = new PreviewScheduler(async () => ++calls, value => accepted.push(value), () => {}, 800, clock);
  for (let i = 0; i < 30; i++) {scheduler.schedule(); clock.advance(100);}
  assert.equal(calls, 0);
  clock.advance(699); assert.equal(calls, 0);
  clock.advance(1); await settle();
  assert.equal(calls, 1); assert.deepEqual(accepted, [1]);
});

test('Older in-flight responses cannot paint over newer content or clear its pending state', async () => {
  const clock = new Clock(), responses = [], accepted = [], pending = [];
  const scheduler = new PreviewScheduler(() => new Promise(resolve => responses.push(resolve)), value => accepted.push(value), value => pending.push(value), 800, clock);
  scheduler.schedule(); clock.advance(800);
  scheduler.schedule(); clock.advance(800);
  responses[0]('old'); await settle();
  assert.deepEqual(accepted, []); assert.deepEqual(pending, [true, true]);
  responses[1]('current'); await settle();
  assert.deepEqual(accepted, ['current']); assert.equal(pending.at(-1), false);
});

test('Failed renders report an error without accepting empty or older HTML', async () => {
  const clock = new Clock(); const error = new Error('offline'), accepted = [], pending = [];
  const scheduler = new PreviewScheduler(async () => {throw error;}, (value, failure) => accepted.push([value, failure]), value => pending.push(value), 800, clock);
  scheduler.schedule(); clock.advance(800); await settle();
  assert.deepEqual(accepted, [[null, error]]); assert.deepEqual(pending, [true, false]);
});

test('Team-required navigation needs an available permitted team, not a membership or old tag', () => {
  const definition = {requireGroup:true}, available = ['group.Youth'];
  assert.equal(requiresTeam(definition, [], available), true);
  assert.equal(requiresTeam(definition, ['membership.Active'], available), true);
  assert.equal(requiresTeam(definition, ['group.Deleted'], available), true);
  assert.equal(requiresTeam(definition, ['group.Youth'], available), false);
  assert.equal(requiresTeam({requireGroup:false}, [], available), false);
  assert.equal(subjectLabel('Club News', 'New subject'), '[Club News] New subject');
});
