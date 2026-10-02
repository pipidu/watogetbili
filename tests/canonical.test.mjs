import { readFileSync } from 'node:fs';
import { test } from 'node:test';
import assert from 'node:assert/strict';

const source = readFileSync(new URL('../bilibili-sync.user.js', import.meta.url), 'utf8');
const match = source.match(/\/\/ <canonical>\n([\s\S]*?)\/\/ <\/canonical>/);
if (!match) throw new Error('canonical block missing');
const canonicalize = new Function(match[1] + '\nreturn canonicalize;')();
const fixtures = JSON.parse(readFileSync(new URL('./canonical-fixtures.json', import.meta.url), 'utf8'));

test('bilibili url canonicalization matches the PHP backend', () => {
  fixtures.forEach((fixture, index) => {
    assert.deepEqual(canonicalize(fixture.url, fixture.title), fixture.expect, 'fixture ' + index);
  });
  const long = Array.from({ length: 81 }, () => '测').join('');
  const got = canonicalize('https://www.bilibili.com/video/BV1xx411c7mD', long);
  assert.equal(Array.from(got.title).length, 80);
});
