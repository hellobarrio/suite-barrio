import assert from 'node:assert/strict';
// eslint-disable-next-line vitest/no-import-node-test -- node --test, senza dipendenze.
import { test } from 'node:test';

import {
	ADDRESS,
	body,
	editable,
	problem,
} from '../../modules/company/assets/src/model.js';

const office = { ...ADDRESS, street: 'Via Roma 1', country: 'IT' };
const saved = {
	name: 'Trattoria Mario',
	email: '',
	certified_email: '',
	privacy_email: '',
	address: office,
	operating_address: ADDRESS,
	profiles: [],
};

test( 'the switch of the operating office follows its address and is not saved', () => {
	const draft = editable( { ...saved, roles: {}, role_names: {} } );
	assert.equal( draft.operating, false );
	assert.equal( 'roles' in draft, false );
	assert.deepEqual( body( draft ), saved );
	assert.equal(
		editable( { ...saved, operating_address: office } ).operating,
		true
	);
} );

test( 'a switched off operating office is emptied', () => {
	const sent = body( {
		...saved,
		operating_address: office,
		operating: false,
	} );
	assert.deepEqual( sent.operating_address, ADDRESS );
} );

test( 'addresses get the protocol, emails lose the spaces, empty profiles are dropped', () => {
	const sent = body( {
		...saved,
		email: ' info@example.com ',
		address: { ...office, maps_url: ' maps.app.goo.gl/abc' },
		profiles: [
			' instagram.com/x',
			'  ',
			'http://example.com',
			'HTTPS://example.org',
		],
		operating: false,
	} );
	assert.equal( sent.email, 'info@example.com' );
	assert.equal( sent.address.maps_url, 'https://maps.app.goo.gl/abc' );
	assert.deepEqual( sent.profiles, [
		'https://instagram.com/x',
		'http://example.com',
		'HTTPS://example.org',
	] );
	assert.equal( problem( sent ), null );
} );

test( 'a wrong email or country is named before saving', () => {
	assert.equal( problem( saved ), null );
	assert.deepEqual( problem( { ...saved, certified_email: 'mario@' } ), [
		'certified_email',
		'email',
	] );
	assert.deepEqual(
		problem( { ...saved, operating_address: { ...office, country: 'I' } } ),
		[ 'operating_address', 'country' ]
	);
} );
