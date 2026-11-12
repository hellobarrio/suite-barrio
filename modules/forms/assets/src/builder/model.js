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

// Finché il valore è quello ricavato dall'etichetta, la segue.
export const renameChoice = ( row, label ) => ( {
	label,
	value: row.value === toValue( row.label ) ? toValue( label ) : row.value,
} );

// Il builder modifica i testi della lingua principale. Se mancano (per esempio è cambiata la
// lingua principale di WPML) parte dai primi che ci sono; le altre lingue restano come sono.
export function withTexts( form, lang ) {
	const fill = ( item ) =>
		item.i18n[ lang ]
			? item
			: {
					...item,
					i18n: {
						...item.i18n,
						[ lang ]: Object.values( item.i18n )[ 0 ],
					},
				};
	return {
		...fill( form ),
		notifications: form.notifications.map( fill ),
		fields: form.fields.map( fill ),
	};
}

// La validazione degli argomenti REST mette i dettagli in data.params ("fields[2][key] …").
export const errorMessage = ( error ) =>
	error.data?.params
		? Object.values( error.data.params ).join( ' ' )
		: error.message;
