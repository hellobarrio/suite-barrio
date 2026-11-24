import assert from 'node:assert/strict';
// eslint-disable-next-line vitest/no-import-node-test -- node --test, senza dipendenze.
import { test } from 'node:test';

import {
	choiceRows,
	fillTranslations,
	keyProblem,
	plainLabel,
	presetRows,
	renameChoice,
	ruleFields,
	setTranslation,
	stable,
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
	// Né dopo il salvataggio.
	assert.deepEqual(
		renameChoice( { value: 'si', label: 'Sì' }, 'Sì, certo', [ 'si' ] ),
		{ value: 'si', label: 'Sì, certo' }
	);
} );

test( 'a value typed over the value of another choice keeps both labels', () => {
	let field = { config: {}, i18n: { it: { label: 'Voto' } } };
	// Come l'editor delle scelte: le righe restano in mano a chi le modifica, il campo ne deriva.
	let rows = [
		{ value: '1', label: 'Pessimo' },
		{ value: '2', label: 'Scarso' },
		{ value: '', label: 'Ottimo' },
	];
	for ( const value of [ '1', '10' ] ) {
		rows = rows.map( ( row, i ) => ( 2 === i ? { ...row, value } : row ) );
		field = withChoices( field, 'it', rows );
	}
	assert.deepEqual( choiceRows( field, 'it' ), rows );
	assert.equal( rows[ 0 ].label, 'Pessimo' );

	// Rilette dal campo a ogni tasto, due righe con lo stesso valore hanno una sola etichetta.
	const typing = withChoices( field, 'it', [
		rows[ 0 ],
		{ value: '1', label: 'Ottimo' },
	] );
	assert.equal( choiceRows( typing, 'it' )[ 0 ].label, 'Ottimo' );
} );

test( 'a field key is checked like on the server', () => {
	assert.equal( keyProblem( 'email_2', [ 'email' ] ), null );
	assert.equal( keyProblem( 'Nome Azienda', [] ), 'format' );
	assert.equal( keyProblem( '2nd', [] ), 'format' );
	assert.equal( keyProblem( '', [] ), 'format' );
	assert.equal( keyProblem( 'a'.repeat( 65 ), [] ), 'format' );
	assert.equal( keyProblem( 'a'.repeat( 64 ), [] ), null );
	assert.equal( keyProblem( 'email', [ 'name', 'email' ] ), 'taken' );
} );

test( 'presets take their labels from the file or from the browser', () => {
	assert.deepEqual(
		presetRows(
			{
				choices: [
					[ 'yes', 'sì' ],
					[ 'no', '' ],
				],
			},
			'it'
		),
		[
			{ value: 'yes', label: 'Sì' },
			{ value: 'no', label: 'No' },
		]
	);
	// I paesi in ordine alfabetico nella lingua del form, i giorni nel loro ordine.
	assert.deepEqual(
		presetRows(
			{
				intl: 'region',
				choices: [
					[ 'IT', '' ],
					[ 'FR', '' ],
				],
			},
			'it'
		),
		[
			{ value: 'FR', label: 'Francia' },
			{ value: 'IT', label: 'Italia' },
		]
	);
	assert.deepEqual(
		presetRows(
			{
				intl: 'weekday',
				choices: [
					[ 'mon', '' ],
					[ 'tue', '' ],
				],
			},
			'it'
		).map( ( row ) => row.label ),
		[ 'Lunedì', 'Martedì' ]
	);
} );

test( 'rules list the fields with a plain label and their choices', () => {
	const consent = {
		key: 'privacy',
		type: 'consent',
		config: {},
		i18n: {
			it: { label: 'Accetto la <a href="/privacy/">privacy</a> ' },
			en: {
				label: 'I accept the <a href="/privacy/">privacy policy</a>',
			},
		},
	};
	assert.equal(
		plainLabel( consent, 'en', 'it' ),
		'I accept the privacy policy'
	);
	// Senza etichetta resta la chiave.
	assert.equal(
		plainLabel( { key: 'utm', i18n: { it: { label: '' } } }, 'it', 'it' ),
		'utm'
	);
	assert.deepEqual(
		ruleFields(
			[
				consent,
				{ key: 'next', type: 'page_break', config: {}, i18n: {} },
				{
					key: 'tipo',
					type: 'select',
					config: { choices: [ 'azienda' ] },
					i18n: {
						it: { label: 'Tipo', options: { azienda: 'Azienda' } },
					},
				},
			],
			'it'
		),
		[
			{
				key: 'privacy',
				type: 'consent',
				label: 'Accetto la privacy',
				choices: null,
			},
			{
				key: 'tipo',
				type: 'select',
				label: 'Tipo',
				choices: [ { value: 'azienda', label: 'Azienda' } ],
			},
		]
	);
} );

test( 'a text removed and written again is not a change', () => {
	const field = {
		key: 'nome',
		i18n: {
			it: { label: 'Nome' },
			en: { label: 'Name', placeholder: 'Mario' },
		},
	};
	const again = setTranslation(
		setTranslation( field, 'en', 'label', '' ),
		'en',
		'label',
		'Name'
	);
	// Stessi testi, in un altro ordine: per JSON.stringify sarebbero due form diversi.
	assert.notEqual( JSON.stringify( again ), JSON.stringify( field ) );
	assert.equal( stable( again ), stable( field ) );
	assert.notEqual(
		stable( setTranslation( field, 'en', 'label', 'Nom' ) ),
		stable( field )
	);
	// L'ordine degli elenchi invece conta.
	assert.notEqual(
		stable( { fields: [ 1, 2 ] } ),
		stable( { fields: [ 2, 1 ] } )
	);
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
