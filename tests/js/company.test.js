import assert from 'node:assert/strict';
// eslint-disable-next-line vitest/no-import-node-test -- node --test, senza dipendenze.
import { test } from 'node:test';

import {
	OFFICE,
	body,
	editable,
	officeTitle,
	problem,
} from '../../modules/company/assets/src/model.js';

const office = { ...OFFICE, street: 'Via Roma 1', country: 'IT' };
const saved = {
	name: 'Trattoria Mario',
	email: '',
	certified_email: '',
	privacy_email: '',
	address: office,
	offices: [],
	profiles: [],
};

test( 'the permissions are not part of the page, and nothing changes on the way back', () => {
	const draft = editable( { ...saved, roles: {}, role_names: {} } );
	assert.equal( 'roles' in draft, false );
	assert.deepEqual( body( draft ), saved );
} );

test( 'empty operating offices are dropped, the others keep their order', () => {
	const milan = { ...OFFICE, name: 'Milan warehouse' };
	const sent = body( {
		...saved,
		offices: [ OFFICE, milan, { ...OFFICE, street: '  ' } ],
	} );
	assert.deepEqual( sent.offices, [ milan ] );
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

test( 'a wrong email or country is named before saving, with the operating office', () => {
	assert.equal( problem( saved ), null );
	assert.deepEqual( problem( { ...saved, certified_email: 'mario@' } ), [
		'certified_email',
		'email',
	] );
	assert.deepEqual(
		problem( { ...saved, address: { ...office, country: 'I' } } ),
		[ 'address', 'country' ]
	);
	assert.deepEqual(
		problem( {
			...saved,
			offices: [ office, { ...office, country: 'Italy' } ],
		} ),
		[ 'offices', 'country', 1 ]
	);
} );

test( 'office contacts lose the spaces and the empty rows, and make an office filled', () => {
	const phones = [
		{ label: ' Sales ', value: ' +39 011 4813111 ' },
		{ label: 'Empty', value: '  ' },
		{ label: '', value: '+39 011 4813133' },
	];
	const sent = body( {
		...saved,
		address: { ...office, phones },
		offices: [
			{ ...OFFICE, emails: [ { label: '', value: 'info@example.com' } ] },
		],
	} );
	assert.deepEqual( sent.address.phones, [
		{ label: 'Sales', value: '+39 011 4813111' },
		{ label: '', value: '+39 011 4813133' },
	] );
	assert.equal( sent.offices.length, 1, 'Only contacts, still an office.' );
	assert.equal( problem( sent ), null );
	assert.deepEqual(
		problem( {
			...sent,
			offices: [
				{ ...OFFICE, emails: [ { label: '', value: 'mario@' } ] },
			],
		} ),
		[ 'offices', 'email', 0 ]
	);
} );

test( 'an office is called by its name, or by its address', () => {
	assert.equal(
		officeTitle( { ...office, name: ' Head office ' } ),
		'Head office'
	);
	assert.equal(
		officeTitle( { ...office, locality: 'Napoli' } ),
		'Via Roma 1, Napoli'
	);
	assert.equal( officeTitle( OFFICE ), '' );
} );
