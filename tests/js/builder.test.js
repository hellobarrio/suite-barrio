import assert from 'node:assert/strict';
// eslint-disable-next-line vitest/no-import-node-test -- node --test, senza dipendenze.
import { test } from 'node:test';

import {
	choiceRows,
	errorMessage,
	renameChoice,
	toValue,
	uniqueKey,
	withChoices,
	withTexts,
} from '../../modules/forms/assets/src/builder/model.js';

test( 'keys and values from free text', () => {
	assert.equal( toValue( 'Città di nascita?' ), 'citta_di_nascita' );
	assert.equal( uniqueKey( 'Email', [] ), 'email' );
	assert.equal( uniqueKey( 'Email', [ 'email', 'email_2' ] ), 'email_3' );
	// Una chiave deve iniziare con una lettera.
	assert.equal( uniqueKey( '18 anni?', [] ), 'anni' );
	assert.equal( uniqueKey( '!!!', [ 'field' ] ), 'field_2' );
	assert.match(
		uniqueKey( 'a'.repeat( 100 ), [ 'a'.repeat( 60 ) ] ),
		/^a{60}_2$/
	);
} );

test( 'choices are values in config and labels in the texts', () => {
	const field = {
		config: { choices: [ 'to', 'cn' ] },
		i18n: { it: { label: 'Provincia', options: { to: 'Torino' } } },
	};
	const rows = choiceRows( field, 'it' );
	assert.deepEqual( rows, [
		{ value: 'to', label: 'Torino' },
		{ value: 'cn', label: '' },
	] );

	const edited = withChoices( field, 'it', [
		rows[ 1 ],
		{ value: 'at', label: 'Asti' },
	] );
	assert.deepEqual( edited.config.choices, [ 'cn', 'at' ] );
	assert.deepEqual( edited.i18n.it, {
		label: 'Provincia',
		options: { cn: '', at: 'Asti' },
	} );

	// Il valore segue l'etichetta solo finché nessuno l'ha cambiato a mano.
	assert.deepEqual(
		renameChoice( { value: 'si', label: 'Sì' }, 'Sì, certo' ),
		{
			value: 'si_certo',
			label: 'Sì, certo',
		}
	);
	assert.deepEqual( renameChoice( { value: 'yes', label: 'Sì' }, 'Certo' ), {
		value: 'yes',
		label: 'Certo',
	} );
} );

test( 'missing texts of the main language start from another language', () => {
	const form = withTexts(
		{
			i18n: { en: { submit_label: 'Send' } },
			notifications: [],
			fields: [
				{ i18n: { it: { label: 'Nome' }, en: { label: 'Name' } } },
			],
		},
		'it'
	);
	assert.deepEqual( form.i18n, {
		en: { submit_label: 'Send' },
		it: { submit_label: 'Send' },
	} );
	assert.equal( form.fields[ 0 ].i18n.it.label, 'Nome' );
} );

test( 'validation errors show the details', () => {
	assert.equal(
		errorMessage( {
			message: 'Invalid parameter(s): fields',
			data: { params: { fields: 'fields[0][key] is not valid.' } },
		} ),
		'fields[0][key] is not valid.'
	);
	assert.equal( errorMessage( { message: 'Conflict.' } ), 'Conflict.' );
} );
