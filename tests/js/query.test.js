import assert from 'node:assert/strict';
// eslint-disable-next-line vitest/no-import-node-test -- node --test, senza dipendenze.
import { test } from 'node:test';

import {
	PER_PAGE,
	initialQuery,
	localQuery,
	nextSort,
} from '../../modules/forms/assets/src/admin/query.js';

const rows = Array.from( { length: 45 }, ( x, i ) => ( {
	id: 45 - i,
	title: `Form ${ 45 - i }`,
	entries: i % 3,
} ) );

// Come l'elenco dei form: i più recenti per primi.
const DEFAULT_QUERY = initialQuery( 'id', 'desc' );

test( 'sorting has three states and always a sorted column', () => {
	const asc = nextSort(
		{ ...DEFAULT_QUERY, page: 3 },
		'title',
		DEFAULT_QUERY
	);
	assert.deepEqual(
		[ asc.sort, asc.order, asc.page ],
		[ 'title', 'asc', 1 ]
	);
	const desc = nextSort( asc, 'title', DEFAULT_QUERY );
	assert.deepEqual( [ desc.sort, desc.order ], [ 'title', 'desc' ] );
	// Il terzo clic torna alla colonna di default, non a "nessun ordinamento".
	assert.deepEqual( nextSort( desc, 'title', DEFAULT_QUERY ), DEFAULT_QUERY );
	// Sulla colonna di default (già decrescente) il clic alterna i due versi.
	const idAsc = nextSort( DEFAULT_QUERY, 'id', DEFAULT_QUERY );
	assert.equal( idAsc.order, 'asc' );
	assert.deepEqual( nextSort( idAsc, 'id', DEFAULT_QUERY ), DEFAULT_QUERY );
	// Una colonna di default crescente ha i tre stati come le altre.
	const byName = initialQuery( 'title' );
	assert.deepEqual(
		nextSort( { ...byName, order: 'desc' }, 'title', byName ),
		byName
	);
} );

test( 'search, sort and pages', () => {
	const first = localQuery( rows, DEFAULT_QUERY, [ 'title' ] );
	assert.equal( first.total, 45 );
	assert.equal( first.rows.length, PER_PAGE );
	assert.equal( first.rows[ 0 ].id, 45 );

	const last = localQuery( rows, { ...DEFAULT_QUERY, page: 3 }, [ 'title' ] );
	assert.deepEqual(
		last.rows.map( ( r ) => r.id ),
		[ 5, 4, 3, 2, 1 ]
	);
	// Una pagina che non esiste più (per esempio dopo una ricerca) diventa l'ultima.
	assert.equal(
		localQuery( rows, { ...DEFAULT_QUERY, page: 9 }, [ 'title' ] ).rows[ 0 ]
			.id,
		5
	);

	// "Form 9" prima di "Form 10": confronto numerico, non alfabetico.
	const byTitle = localQuery(
		rows,
		{ ...DEFAULT_QUERY, sort: 'title', order: 'asc' },
		[ 'title' ]
	);
	assert.deepEqual(
		byTitle.rows.slice( 8, 11 ).map( ( r ) => r.title ),
		[ 'Form 9', 'Form 10', 'Form 11' ]
	);

	const found = localQuery( rows, { ...DEFAULT_QUERY, search: ' form 4' }, [
		'title',
	] );
	assert.deepEqual(
		found.rows.map( ( r ) => r.id ),
		[ 45, 44, 43, 42, 41, 40, 4 ]
	);

	// A parità di valore resta l'ordine di default.
	const byEntries = localQuery(
		rows,
		{ ...DEFAULT_QUERY, sort: 'entries', order: 'desc' },
		[]
	);
	assert.deepEqual(
		byEntries.rows.slice( 0, 3 ).map( ( r ) => r.id ),
		[ 43, 40, 37 ]
	);
} );
