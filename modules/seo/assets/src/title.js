// Titolo e testi come li calcola Seo\Meta, per le anteprime e il punteggio. documentTitle() ha le stesse
// fixture di Meta::document_title() (tests/fixtures/seo-title.json).
import { __, sprintf } from '@wordpress/i18n';

/**
 * Il <title>: "{titolo} {sep} {sito}" o al contrario, con la pagina dopo la prima. Senza titolo, o con un
 * titolo uguale al nome del sito, solo il sito.
 *
 * @param {string}                                           title
 * @param {{separator: string, order: string, name: string}} site
 * @param {number}                                           page
 */
export function documentTitle( title, site, page = 1 ) {
	const sep = ` ${ site.separator } `;
	const full = [
		title,
		/* translators: %d: page number. */
		page > 1 ? sprintf( __( 'Page %d', 'barrio-suite' ), page ) : '',
	]
		.filter( Boolean )
		.join( sep );
	const parts = [ full, site.name ].filter(
		( part, i, all ) => part && all.indexOf( part ) === i
	);
	return ( site.order === 'site_page' ? parts.reverse() : parts ).join( sep );
}

const ENTITIES = {
	amp: '&',
	lt: '<',
	gt: '>',
	quot: '"',
	apos: "'",
	nbsp: ' ',
};

// Testo semplice da HTML: senza commenti dei blocchi, tag, shortcode, entità e spazi ripetuti.
// ponytail: regex invece di un parser HTML, così gira anche in node --test; basta per anteprime e punteggio.
export const text = ( html = '' ) =>
	html
		.replace( /<!--[\s\S]*?-->|<(script|style)\b[\s\S]*?<\/\1>/gi, ' ' )
		.replace( /<[^>]*>|\[\/?[a-z][\w-]*[^\]]*\]/gi, ' ' )
		.replace( /&(#x?[\da-f]+|[a-z]+);/gi, ( entity, name ) => {
			if ( name[ 0 ] !== '#' ) {
				return ENTITIES[ name.toLowerCase() ] ?? entity;
			}
			return String.fromCodePoint(
				name[ 1 ].toLowerCase() === 'x'
					? parseInt( name.slice( 2 ), 16 )
					: parseInt( name.slice( 1 ), 10 )
			);
		} )
		.replace( /\s+/g, ' ' )
		.trim();

// 160 caratteri al massimo, senza tagliare una parola.
export function excerpt( html ) {
	const plain = text( html );
	return plain.length > 160
		? plain.slice( 0, 160 ).replace( /\s+\S*$/, '' ) + '…'
		: plain;
}
