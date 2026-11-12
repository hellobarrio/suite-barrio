// eslint-disable-next-line vitest/no-import-node-test -- node --test, senza dipendenze.
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { visibility } from '../../modules/forms/assets/frontend/conditions.js';

// Le stesse fixture di tests/Unit/ConditionsTest.php: le due implementazioni non possono divergere.
const cases = JSON.parse(
	readFileSync( new URL( '../fixtures/conditions.json', import.meta.url ) )
);

for ( const { name, fields, values, visible } of cases ) {
	test( name, () =>
		assert.deepEqual( visibility( fields, values ), visible )
	);
}
