// I testi per lingua di form, popup, banner e periodi: ogni voce ha i18n { lingua: { chiave: testo } }. Funzioni
// pure, senza @wordpress/*: le prova tests/js/builder.test.js.

// Nel builder un'altra lingua ha solo i suoi testi: uno vuoto è quello della lingua
// principale. Senza testi la lingua non ha la riga, e il sito mostra la lingua principale.
export function withTranslation( item, lang, text ) {
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
