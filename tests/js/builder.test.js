import assert from 'node:assert/strict';
// eslint-disable-next-line vitest/no-import-node-test -- node --test, senza dipendenze.
import { test } from 'node:test';

import {
	choiceRows,
	errorMessage,
	fillTranslations,
	renameChoice,
	setTranslation,
	textIn,
	toValue,
	trimTranslations,
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

test( 'translations keep only their own texts and are completed when saved', () => {
	const languages = [ 'it', 'en' ];
	const form = {
		i18n: {
			it: { submit_label: 'Invia', success_message: null },
			en: { submit_label: 'Send', success_message: null },
		},
		notifications: [
			{
				i18n: {
					it: { subject: 'Ciao', heading: null, body: 'x' },
					en: { subject: 'Ciao', heading: null, body: 'x' },
				},
			},
		],
		fields: [
			{
				config: { choices: [ 'a', 'b' ] },
				i18n: {
					it: {
						label: 'Scelta',
						placeholder: 'Scegli',
						options: { a: 'Uno', b: 'Due' },
					},
					en: {
						label: 'Choice',
						placeholder: 'Scegli',
						options: { a: 'One', b: 'Due', old: 'Old' },
					},
					fr: { label: 'Choix' },
				},
			},
		],
	};

	const trimmed = trimTranslations( form, languages );
	assert.deepEqual( trimmed.i18n.en, { submit_label: 'Send' } );
	// Tutto uguale alla lingua principale: niente da tradurre.
	assert.equal( trimmed.notifications[ 0 ].i18n.en, undefined );
	const field = trimmed.fields[ 0 ];
	assert.deepEqual( field.i18n.en, {
		label: 'Choice',
		options: { a: 'One', old: 'Old' },
	} );
	// Una lingua non più attiva resta com'è.
	assert.deepEqual( field.i18n.fr, { label: 'Choix' } );

	// La lingua principale cambia: i testi non tradotti la seguono, le scelte tolte spariscono.
	const edited = {
		...trimmed,
		fields: [
			{
				...field,
				i18n: {
					...field.i18n,
					it: { ...field.i18n.it, placeholder: 'Scegli…' },
				},
			},
		],
	};
	const filled = fillTranslations( edited, languages );
	assert.deepEqual( filled.i18n.en, {
		submit_label: 'Send',
		success_message: null,
	} );
	assert.equal( filled.notifications[ 0 ].i18n.en, undefined );
	assert.deepEqual( filled.fields[ 0 ].i18n.en, {
		label: 'Choice',
		placeholder: 'Scegli…',
		options: { a: 'One', b: 'Due' },
	} );
	assert.deepEqual( filled.fields[ 0 ].i18n.fr, { label: 'Choix' } );

	// Ciò che vede il visitatore: la traduzione o la lingua principale, anche per le scelte.
	assert.deepEqual( textIn( field, 'en', 'it' ), {
		label: 'Choice',
		placeholder: 'Scegli',
		options: { a: 'One', b: 'Due' },
	} );
	assert.equal( textIn( field, 'it', 'it' ).label, 'Scelta' );

	// Un testo svuotato torna quello della lingua principale; senza testi la lingua sparisce.
	const cleared = setTranslation( field, 'en', 'label', '' );
	assert.deepEqual( cleared.i18n.en, { options: { a: 'One', old: 'Old' } } );
	assert.equal(
		setTranslation( cleared, 'en', 'options', { a: '', old: '' } ).i18n.en,
		undefined
	);
} );

test( 'integrations translate their translatable settings like the other texts', () => {
	const languages = [ 'it', 'en' ];
	const form = {
		i18n: { it: { submit_label: 'Invia' } },
		notifications: [],
		fields: [],
		integrations: [
			{
				slug: 'mailchimp',
				settings: { email_field: 'email' },
				i18n: {
					it: { list_id: 'lista-it', tags: 'Sito' },
					en: { list_id: 'list-en', tags: 'Sito' },
				},
			},
			// Senza impostazioni traducibili.
			{ slug: 'crm', settings: {}, i18n: {} },
		],
	};

	const loaded = trimTranslations( withTexts( form, 'it' ), languages );
	assert.deepEqual( loaded.integrations[ 0 ].i18n.en, {
		list_id: 'list-en',
	} );
	assert.deepEqual( loaded.integrations[ 1 ].i18n, { it: {} } );

	const filled = fillTranslations( loaded, languages );
	assert.deepEqual( filled.integrations[ 0 ].i18n.en, {
		list_id: 'list-en',
		tags: 'Sito',
	} );
	assert.deepEqual( filled.integrations[ 1 ].i18n, { it: {} } );
} );
