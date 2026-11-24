// Funzioni pure del builder, senza WordPress: le prova tests/js/builder.test.js.
import {
	textIn,
	withTranslation,
} from '../../../../../assets/src/admin/i18n.js';

export {
	setTranslation,
	textIn,
} from '../../../../../assets/src/admin/i18n.js';

// Un valore leggibile da un testo libero: "Un preventivo" → "un_preventivo".
export const toValue = ( text ) =>
	text
		.normalize( 'NFD' )
		.replace( /[̀-ͯ]/g, '' )
		.toLowerCase()
		.replace( /[^a-z0-9]+/g, '_' )
		.replace( /^_+|_+$/g, '' );

// Cosa non va in una chiave di campo, con le regole del server (RestForms.php, FormRepository): il builder
// lo dice prima di salvare, al posto di "fields[2][key] does not match pattern…".
export function keyProblem( key, others ) {
	if ( ! /^[a-z][a-z0-9_]{0,63}$/.test( key ) ) {
		return 'format';
	}
	return others.includes( key ) ? 'taken' : null;
}

// La prima chiave di campo libera (^[a-z][a-z0-9_]*$, al massimo 64 caratteri): email, email_2…
export function uniqueKey( text, keys ) {
	const base =
		toValue( text )
			.replace( /^[^a-z]+/, '' )
			.slice( 0, 60 ) || 'field';
	let key = base;
	for ( let n = 2; keys.includes( key ); n++ ) {
		key = `${ base }_${ n }`;
	}
	return key;
}

// Le scelte come righe { value, label }: i valori stanno in config, le etichette nei testi della lingua.
export const choiceRows = ( field, lang ) =>
	( field.config.choices ?? [] ).map( ( value ) => ( {
		value,
		label: field.i18n[ lang ]?.options?.[ value ] ?? '',
	} ) );

export const withChoices = ( field, lang, rows ) => ( {
	...field,
	config: { ...field.config, choices: rows.map( ( row ) => row.value ) },
	i18n: {
		...field.i18n,
		[ lang ]: {
			...field.i18n[ lang ],
			options: Object.fromEntries(
				rows.map( ( row ) => [ row.value, row.label ] )
			),
		},
	},
} );

// Finché il valore è quello ricavato dall'etichetta, la segue; non dopo il salvataggio (saved),
// perché registrazioni e condizioni usano il vecchio.
export const renameChoice = ( row, label, saved = [] ) => ( {
	label,
	value:
		row.value === toValue( row.label ) && ! saved.includes( row.value )
			? toValue( label )
			: row.value,
} );

// Le scelte di un preset. Con intl i nomi li dà il browser, nella lingua principale del form.
export function presetRows( { choices, intl }, lang ) {
	const date = ( options, time ) =>
		new Intl.DateTimeFormat( lang, { ...options, timeZone: 'UTC' } ).format(
			time
		);
	const name = {
		region: ( value ) =>
			new Intl.DisplayNames( lang, { type: 'region' } ).of( value ),
		language: ( value ) =>
			new Intl.DisplayNames( lang, { type: 'language' } ).of( value ),
		// Il 1° gennaio 2024 era un lunedì.
		weekday: ( value, i ) =>
			date( { weekday: 'long' }, Date.UTC( 2024, 0, 1 + i ) ),
		month: ( value, i ) =>
			date( { month: 'long' }, Date.UTC( 2024, i, 1 ) ),
	}[ intl ];
	const rows = choices.map( ( [ value, label ], i ) => {
		const text = label || name?.( value, i ) || value;
		return {
			value,
			label: text.charAt( 0 ).toLocaleUpperCase( lang ) + text.slice( 1 ),
		};
	} );
	return [ 'region', 'language' ].includes( intl )
		? rows.sort( ( x, y ) => x.label.localeCompare( y.label, lang ) )
		: rows;
}

// Ogni elemento con i testi per lingua: il form (testi dell'invio), le notifiche, i campi e le
// integrazioni (le impostazioni traducibili).
const eachItem = ( form, change ) => ( {
	...change( form ),
	notifications: form.notifications.map( change ),
	fields: form.fields.map( change ),
	integrations: ( form.integrations ?? [] ).map( change ),
} );

// Il builder modifica i testi della lingua principale. Se mancano (per esempio è cambiata la
// lingua principale di WPML) parte dai primi che ci sono, o da nessuno (un'integrazione senza
// impostazioni traducibili); le altre lingue restano come sono.
export const withTexts = ( form, lang ) =>
	eachItem( form, ( item ) =>
		item.i18n[ lang ]
			? item
			: {
					...item,
					i18n: {
						...item.i18n,
						[ lang ]: Object.values( item.i18n )[ 0 ] ?? {},
					},
				}
	);

// Al caricamento: delle altre lingue attive restano i testi diversi da quelli della lingua principale.
export const trimTranslations = ( form, [ main, ...others ] ) =>
	eachItem( form, ( item ) => {
		const base = item.i18n[ main ];
		return others.reduce( ( next, lang ) => {
			const text = item.i18n[ lang ];
			return text
				? withTranslation( next, lang, {
						...Object.fromEntries(
							Object.entries( text ).filter(
								( [ key, value ] ) =>
									'options' !== key && value !== base[ key ]
							)
						),
						options: Object.fromEntries(
							Object.entries( text.options ?? {} ).filter(
								( [ value, label ] ) =>
									label !== base.options?.[ value ]
							)
						),
					} )
				: next;
		}, item );
	} );

// Al salvataggio ogni lingua tradotta ha la riga completa (il database vuole etichetta, oggetto…):
// i testi non tradotti sono quelli della lingua principale di adesso, anche se è cambiata.
export const fillTranslations = ( form, [ main, ...others ] ) =>
	eachItem( form, ( item ) => {
		const i18n = { ...item.i18n };
		for ( const lang of others.filter( ( code ) => i18n[ code ] ) ) {
			i18n[ lang ] = textIn( item, lang, main );
		}
		return { ...item, i18n };
	} );

// Etichetta senza HTML (il consenso ha un link), per gli elenchi.
export const plainLabel = ( field, lang, main ) =>
	( textIn( field, lang, main ).label ?? '' )
		.replace( /<[^>]*>/g, '' )
		.trim() || field.key;

// I campi che una regola può usare: per un campo quelli che lo precedono, per una notifica o
// un'integrazione tutti.
export const ruleFields = ( fields, main ) =>
	fields
		.filter( ( f ) => 'page_break' !== f.type )
		.map( ( f ) => ( {
			key: f.key,
			type: f.type,
			label: plainLabel( f, main, main ),
			choices: f.config.choices ? choiceRows( f, main ) : null,
		} ) );

// Il form come testo, con le chiavi in ordine: togliere un testo e riscriverlo uguale lo rimette in
// fondo all'oggetto, e non è una modifica.
export const stable = ( form ) =>
	JSON.stringify( form, ( key, value ) =>
		value && 'object' === typeof value && ! Array.isArray( value )
			? Object.fromEntries(
					Object.keys( value )
						.sort()
						.map( ( name ) => [ name, value[ name ] ] )
				)
			: value
	);
