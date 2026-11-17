// Funzioni pure del builder, senza WordPress: le prova tests/js/builder.test.js.

// Un valore leggibile da un testo libero: "Un preventivo" → "un_preventivo".
export const toValue = ( text ) =>
	text
		.normalize( 'NFD' )
		.replace( /[̀-ͯ]/g, '' )
		.toLowerCase()
		.replace( /[^a-z0-9]+/g, '_' )
		.replace( /^_+|_+$/g, '' );

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

// Nel builder un'altra lingua ha solo i suoi testi: uno vuoto è quello della lingua
// principale. Senza testi la lingua non ha la riga, e il sito mostra la lingua principale.
function withTranslation( item, lang, text ) {
	const own = {};
	for ( const [ key, value ] of Object.entries( text ) ) {
		if ( 'options' !== key ) {
			if ( value ) {
				own[ key ] = value;
			}
			continue;
		}
		const options = Object.entries( value ?? {} ).filter(
			( [ , label ] ) => label
		);
		if ( options.length ) {
			own.options = Object.fromEntries( options );
		}
	}
	const i18n = { ...item.i18n, [ lang ]: own };
	if ( ! Object.keys( own ).length ) {
		delete i18n[ lang ];
	}
	return { ...item, i18n };
}

// Per le scelte key è "options" e value l'oggetto { valore: etichetta }.
export const setTranslation = ( item, lang, key, value ) =>
	withTranslation( item, lang, { ...item.i18n[ lang ], [ key ]: value } );

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

// Il testo che il visitatore vede in lang: la traduzione o, dove manca, la lingua principale.
export function textIn( item, lang, main ) {
	const base = item.i18n[ main ] ?? {};
	const own = ( lang !== main && item.i18n[ lang ] ) || {};
	const text = { ...base, ...own };
	if ( base.options ) {
		// Solo le scelte che ci sono ancora.
		text.options = Object.fromEntries(
			Object.entries( base.options ).map( ( [ value, label ] ) => [
				value,
				own.options?.[ value ] ?? label,
			] )
		);
	}
	return text;
}

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

// La validazione degli argomenti REST mette i dettagli in data.params ("fields[2][key] …").
export const errorMessage = ( error ) =>
	error.data?.params
		? Object.values( error.data.params ).join( ' ' )
		: error.message;
