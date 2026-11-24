import assert from 'node:assert/strict';
// eslint-disable-next-line vitest/no-import-node-test -- node --test, senza dipendenze.
import { test } from 'node:test';

import { flatten, toTree } from '../../modules/ordering/assets/src/tree.js';

test( 'the tree of the list and back, with the parents before the children', () => {
	const tree = toTree( [
		{ id: 1, parent: 0 },
		{ id: 2, parent: 1 },
		{ id: 3, parent: 0 },
		{ id: 4, parent: 2 },
		{ id: 5, parent: 1 },
	] );
	assert.deepEqual( tree, { 0: [ 1, 3 ], 1: [ 2, 5 ], 2: [ 4 ] } );
	assert.deepEqual( flatten( tree ), [ 1, 2, 4, 5, 3 ] );
	// Un genitore che si sposta porta con sé i figli.
	assert.deepEqual( flatten( { ...tree, 0: [ 3, 1 ] } ), [ 3, 1, 2, 4, 5 ] );
	assert.deepEqual( flatten( toTree( [] ) ), [] );
} );

// Riordina confronta con questo, non con l'elenco piatto: se no Salva sarebbe acceso senza aver toccato niente.
test( 'a child listed before its parent comes after it in the tree', () => {
	const saved = [
		{ id: 12, parent: 11 },
		{ id: 11, parent: 0 },
		{ id: 10, parent: 0 },
	];
	assert.deepEqual( flatten( toTree( saved ) ), [ 11, 12, 10 ] );
} );
