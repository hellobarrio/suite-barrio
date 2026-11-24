import assert from 'node:assert/strict';
// eslint-disable-next-line vitest/no-import-node-test -- node --test, senza dipendenze.
import { test } from 'node:test';

import { allowed } from '../../modules/popups/assets/frontend/popup.js';

const DAY = 86400000;
const now = Date.UTC( 2030, 5, 15, 12 );
const at = ( ms ) => ms / 1000;

test( 'without an end and never closed it opens', () =>
	assert.ok( allowed( { end: null, days: 7 }, now, null ) ) );

test( 'a cached page keeps out what has ended', () => {
	assert.ok( ! allowed( { end: at( now ), days: 7 }, now, null ) );
	assert.ok( allowed( { end: at( now + 1000 ), days: 7 }, now, null ) );
} );

test( 'once closed it stays away for the chosen days', () => {
	const rules = { end: null, days: 7 };
	assert.ok( ! allowed( rules, now, now - 6 * DAY ) );
	assert.ok( allowed( rules, now, now - 7 * DAY ) );
} );

test( 'with 0 days it comes back on the next page', () =>
	assert.ok( allowed( { end: null, days: 0 }, now, now ) ) );

test( 'after a form sent in it, it never comes back', () =>
	assert.ok(
		! allowed( { end: null, days: 0 }, now, Number( String( Infinity ) ) )
	) );
