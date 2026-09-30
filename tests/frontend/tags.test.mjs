import {test} from 'node:test';
import assert from 'node:assert/strict';
import {selectionState} from '../../src/component/media/js/tags.mjs';

test('Bulk selection state ignores unavailable tags and reflects partial selection', () => {
  const items = [{checked:true},{checked:false},{checked:true,disabled:true}];
  assert.deepEqual(selectionState(items),{disabled:false,checked:false,indeterminate:true});
  items[1].checked = true;
  assert.deepEqual(selectionState(items),{disabled:false,checked:true,indeterminate:false});
  items[0].checked = items[1].checked = false;
  assert.deepEqual(selectionState(items),{disabled:false,checked:false,indeterminate:false});
  assert.deepEqual(selectionState([items[2]]),{disabled:true,checked:false,indeterminate:false});
});
