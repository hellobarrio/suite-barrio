// Il punteggio SEO, ispirato ai test di Rank Math: controlli superati o no, ognuno con un peso. Si calcola
// solo qui, non si salva; lo usano il pannello e la tabella dei contenuti. Lo prova tests/js/score.test.js.
import { text } from './title.js';

// Il peso di ogni controllo. Quelli sul contenuto valgono solo dove c'è un contenuto (non nei termini).
const WEIGHTS = {
	keyword: 5,
	title_keyword: 15,
	title_start: 3,
	title_length: 5,
	description_keyword: 5,
	description_length: 5,
	url_keyword: 5,
	url_length: 3,
	image: 5,
	content_length: 8,
	content_keyword: 5,
	content_start: 3,
	density: 5,
	subheading_keyword: 3,
	image_alt_keyword: 2,
	internal_links: 5,
	external_links: 3,
	short_paragraphs: 3,
	media: 5,
};

// Senza accenti e maiuscole: "Città" e "citta" sono la stessa parola chiave.
const fold = ( value ) =>
	value.normalize( 'NFD' ).replace( /\p{M}/gu, '' ).toLowerCase();

const words = ( value ) => value.match( /[\p{L}\p{N}]+/gu ) ?? [];

// Le volte in cui la parola chiave compare come parole intere ("arte" non è in "parte").
function count( haystack, keyword ) {
	if ( ! keyword ) {
		return 0;
	}
	const escaped = fold( keyword ).replace( /[.*+?^${}()|[\]\\]/g, '\\$&' );
	return [
		...fold( haystack ).matchAll(
			new RegExp(
				`(?<![\\p{L}\\p{N}])${ escaped }(?![\\p{L}\\p{N}])`,
				'gu'
			)
		),
	].length;
}

const slug = ( value ) =>
	fold( value )
		.replace( /[^a-z0-9]+/g, '-' )
		.replace( /^-|-$/g, '' );

const tags = ( html, pattern ) => [ ...html.matchAll( pattern ) ];

// Un href scritto male non è un link.
function parse( href, base ) {
	try {
		return new URL( href, base );
	} catch {
		return null;
	}
}

/**
 * @param {Object}      page
 * @param {string}      page.title       Il titolo SEO, o quello del contenuto, senza il nome del sito.
 * @param {string}      page.fullTitle   Il <title> completo.
 * @param {string}      page.description La descrizione che esce nella pagina.
 * @param {string[]}    page.keywords    La prima è la principale.
 * @param {string}      page.url         L'URL della pagina.
 * @param {string}      page.home        L'URL della home, per riconoscere i link interni.
 * @param {boolean}     page.image       Un'immagine di condivisione sua, non quella predefinita del sito.
 * @param {string|null} page.content     L'HTML del contenuto; null per i termini.
 * @return {{score: number, checks: Array<{id: string, pass: boolean, value?: number}>}} Il punteggio da 0
 *         a 100 e i controlli, con il numero che li spiega (caratteri, parole, percentuale).
 */
export function score( page ) {
	const keyword = page.keywords?.[ 0 ] ?? '';
	const path = decodeURIComponent(
		parse( page.url || '/', page.home )?.pathname ?? ''
	);
	const titleAt = keyword
		? fold( page.title ).indexOf( fold( keyword ) )
		: -1;
	const checks = [
		{ id: 'keyword', pass: keyword !== '' },
		{ id: 'title_keyword', pass: count( page.title, keyword ) > 0 },
		{
			id: 'title_start',
			pass: titleAt >= 0 && titleAt < page.title.length / 2,
		},
		{
			id: 'title_length',
			pass: page.fullTitle.length > 0 && page.fullTitle.length <= 60,
			value: page.fullTitle.length,
		},
		{
			id: 'description_keyword',
			pass: count( page.description, keyword ) > 0,
		},
		{
			id: 'description_length',
			pass:
				page.description.length >= 120 &&
				page.description.length <= 160,
			value: page.description.length,
		},
		{
			id: 'url_keyword',
			pass: keyword !== '' && slug( path ).includes( slug( keyword ) ),
		},
		{ id: 'url_length', pass: path.length <= 75, value: path.length },
		{ id: 'image', pass: Boolean( page.image ) },
	];

	if ( typeof page.content === 'string' ) {
		const html = page.content;
		const all = words( text( html ) );
		const occurrences = count( text( html ), keyword );
		const density = all.length ? ( occurrences / all.length ) * 100 : 0;
		const start = all
			.slice( 0, Math.max( 1, Math.ceil( all.length / 10 ) ) )
			.join( ' ' );
		const home = parse( page.home )?.host;
		const web = tags( html, /<a\b[^>]*\bhref=["']([^"']+)["']/gi )
			.map( ( [ , href ] ) => parse( href, page.url || page.home ) )
			.filter( ( link ) =>
				[ 'http:', 'https:' ].includes( link?.protocol )
			);
		checks.push(
			{
				id: 'content_length',
				pass: all.length >= 600,
				value: all.length,
			},
			{ id: 'content_keyword', pass: occurrences > 0 },
			{ id: 'content_start', pass: count( start, keyword ) > 0 },
			{
				id: 'density',
				pass: density >= 0.5 && density <= 2.5,
				value: Math.round( density * 100 ) / 100,
			},
			{
				id: 'subheading_keyword',
				pass: tags(
					html,
					/<h[2-6]\b[^>]*>([\s\S]*?)<\/h[2-6]>/gi
				).some(
					( [ , heading ] ) => count( text( heading ), keyword ) > 0
				),
			},
			{
				id: 'image_alt_keyword',
				pass: tags( html, /<img\b[^>]*\balt=["']([^"']*)["']/gi ).some(
					( [ , alt ] ) => count( text( alt ), keyword ) > 0
				),
			},
			{
				id: 'internal_links',
				pass: web.some( ( link ) => link.host === home ),
			},
			{
				id: 'external_links',
				pass: web.some( ( link ) => link.host !== home ),
			},
			{
				id: 'short_paragraphs',
				pass: tags( html, /<p\b[^>]*>([\s\S]*?)<\/p>/gi ).every(
					( [ , paragraph ] ) =>
						words( text( paragraph ) ).length <= 120
				),
			},
			{
				id: 'media',
				pass: /<(img|video|iframe)\b|wp-block-embed/i.test( html ),
			}
		);
	}

	const weight = ( list ) =>
		list.reduce( ( sum, check ) => sum + WEIGHTS[ check.id ], 0 );
	return {
		score: Math.round(
			( weight( checks.filter( ( check ) => check.pass ) ) /
				weight( checks ) ) *
				100
		),
		checks,
	};
}

// Come in Rank Math: più di 80 è buono, più di 50 da migliorare.
export const level = ( value ) => {
	if ( value > 80 ) {
		return 'good';
	}
	return value > 50 ? 'ok' : 'bad';
};
