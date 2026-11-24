import assert from 'node:assert/strict';
// eslint-disable-next-line vitest/no-import-node-test -- node --test, senza dipendenze.
import { test } from 'node:test';

import {
	INITIAL,
	VIEW,
	params,
	storedView,
} from '../../modules/forms/assets/src/entries/view.js';

test( 'empty filters are not sent to the server', () => {
	assert.deepEqual( params( { ...INITIAL, search: 'rossi', read: '0' } ), {
		search: 'rossi',
		sort: 'created_at',
		order: 'desc',
		page: 1,
		read: '0',
	} );
} );

test( 'the view of the list comes back only for the same form', () => {
	const stored = {};
	global.window = { sessionStorage: { getItem: ( key ) => stored[ key ] } };
	const initial = { query: INITIAL, shown: {} };
	assert.deepEqual( storedView( 7 ), initial );

	stored[ VIEW ] = JSON.stringify( {
		form: 7,
		query: { page: 3, read: '0' },
		shown: { email: false },
	} );
	// Un filtro aggiunto dopo che la vista è stata salvata parte dal suo valore iniziale.
	assert.deepEqual( storedView( 7 ), {
		query: { ...INITIAL, page: 3, read: '0' },
		shown: { email: false },
	} );
	assert.deepEqual( storedView( 8 ), initial );
	assert.deepEqual( storedView( undefined ), initial );

	stored[ VIEW ] = '{';
	assert.deepEqual( storedView( 7 ), initial );
	delete global.window;
} );
