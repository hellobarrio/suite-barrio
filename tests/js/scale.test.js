import assert from 'node:assert/strict';
// eslint-disable-next-line vitest/no-import-node-test -- node --test, senza dipendenze.
import { test } from 'node:test';

import { scale } from '../../modules/analytics/assets/src/scale.js';

test( 'without data the axis goes from 0 to 4', () =>
	assert.deepEqual( scale( 0 ), [ 0, 1, 2, 3, 4 ] ) );

test( 'small numbers keep whole steps', () => {
	assert.deepEqual( scale( 3 ), [ 0, 1, 2, 3, 4 ] );
	assert.deepEqual( scale( 7 ), [ 0, 2, 4, 6, 8 ] );
	assert.deepEqual( scale( 8 ), [ 0, 2, 4, 6, 8 ] );
	assert.deepEqual( scale( 9 ), [ 0, 5, 10, 15, 20 ] );
} );

test( 'the step is 1, 2 or 5 times a power of 10 and the top is never below the maximum', () => {
	for ( const max of [ 1, 12, 99, 100, 101, 1234, 5000, 98765, 1e6 + 1 ] ) {
		const ticks = scale( max );
		const step = ticks[ 1 ];
		const power = 10 ** Math.floor( Math.log10( step ) );
		assert.ok(
			[ 1, 2, 5 ].includes( step / power ),
			`${ max }: ${ step }`
		);
		assert.ok( ticks[ 4 ] >= max, `${ max }: ${ ticks }` );
	}
	assert.deepEqual( scale( 12 ), [ 0, 5, 10, 15, 20 ] );
	assert.deepEqual( scale( 800 ), [ 0, 200, 400, 600, 800 ] );
	assert.deepEqual( scale( 1234 ), [ 0, 500, 1000, 1500, 2000 ] );
} );
