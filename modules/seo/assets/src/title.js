// Titolo e testi come li calcola Seo\Meta, per le anteprime e il punteggio, con le stesse fixture
// (tests/fixtures/seo-title.json e seo-text.json).
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

// Gli shortcode registrati sul sito (Seo\Editor::shortcodes()), con ciò che racchiudono, come strip_shortcodes().
const SHORTCODES = (
	globalThis.barrioSeo?.shortcodes ??
	globalThis.barrioAdmin?.seo?.shortcodes ??
	[]
)
	.map( ( name ) => name.replace( /[.*+?^${}()|[\]\\]/g, '\\$&' ) )
	.join( '|' );
const SHORTCODE =
	SHORTCODES &&
	new RegExp(
		`\\[(${ SHORTCODES })(?![\\w-])[^\\]]*?(?:\\/\\]|\\](?:[\\s\\S]*?\\[\\/\\1\\])?)`,
		'g'
	);

// Senza il browser (node --test) le entità con nome sono solo queste.
const ENTITIES = {
	amp: '&',
	lt: '<',
	gt: '>',
	quot: '"',
	apos: "'",
	nbsp: ' ',
	euro: '€',
	ndash: '–',
	mdash: '—',
	hellip: '…',
	laquo: '«',
	raquo: '»',
	rsquo: '’',
	ldquo: '“',
	rdquo: '”',
};

function entity( match, name ) {
	if ( typeof document !== 'undefined' ) {
		const area = document.createElement( 'textarea' );
		area.innerHTML = match;
		return area.value;
	}
	if ( name[ 0 ] !== '#' ) {
		return ENTITIES[ name.toLowerCase() ] ?? match;
	}
	const code =
		name[ 1 ].toLowerCase() === 'x'
			? parseInt( name.slice( 2 ), 16 )
			: parseInt( name.slice( 1 ), 10 );
	return code <= 0x10ffff ? String.fromCodePoint( code ) : match;
}

// Testo semplice da HTML: senza shortcode, commenti, script, tag, entità e spazi ripetuti; un tag separa le parole.
// ponytail: regex invece di un parser HTML, così gira anche in node --test; basta per anteprime e punteggio.
export const text = ( html = '' ) =>
	( SHORTCODE ? html.replace( SHORTCODE, '' ) : html )
		.replace( /<!--[\s\S]*?-->|<(script|style)\b[\s\S]*?<\/\1>/gi, ' ' )
		.replace( /<[^>]*>/g, ' ' )
		.replace( /&(#x?[\da-f]+|[a-z][a-z\d]*);/gi, entity )
		.replace( /\s+/g, ' ' )
		.trim();

// 160 caratteri al massimo, senza tagliare una parola.
export function excerpt( html ) {
	const plain = text( html );
	return plain.length > 160
		? plain.slice( 0, 160 ).replace( /\s+\S*$/, '' ) + '…'
		: plain;
}
