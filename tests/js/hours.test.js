import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
// eslint-disable-next-line vitest/no-import-node-test -- node --test, senza dipendenze.
import { test } from 'node:test';

import { status } from '../../modules/hours/assets/frontend/hours.js';

// Le stesse fixture di tests/Unit/HoursStatusTest.php: le due implementazioni non possono divergere.
const { days, text, cases } = JSON.parse(
	readFileSync( new URL( '../fixtures/hours-status.json', import.meta.url ) )
);

for ( const { name, open, now, status: expected } of cases ) {
	test( name, () =>
		assert.deepEqual( status( { days, open, text }, now ), expected )
	);
}
