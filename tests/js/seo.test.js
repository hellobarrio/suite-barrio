import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
// eslint-disable-next-line vitest/no-import-node-test -- node --test, senza dipendenze.
import { test } from 'node:test';

// Gli shortcode registrati che le fixture usano, come Seo\Editor::shortcodes(): title.js li legge caricandosi.
globalThis.barrioSeo = { shortcodes: [ 'gallery', 'caption' ] };
const { score, level } =
	await import( '../../modules/seo/assets/src/score.js' );
const { documentTitle, excerpt, text } =
	await import( '../../modules/seo/assets/src/title.js' );

// Le stesse fixture di SeoEditorTest::test_the_title_is_the_same_in_php_and_js.
const titles = JSON.parse(
	readFileSync( new URL( '../fixtures/seo-title.json', import.meta.url ) )
);
for ( const {
	name,
	title,
	page,
	site,
	separator,
	order,
	expected,
} of titles ) {
	test( `title: ${ name }`, () =>
		assert.equal(
			documentTitle( title, { name: site, separator, order }, page ),
			expected
		) );
}

// Le stesse fixture di SeoEditorTest::test_the_text_is_the_same_in_php_and_js.
const texts = JSON.parse(
	readFileSync( new URL( '../fixtures/seo-text.json', import.meta.url ) )
);
for ( const { name, html, text: plain, excerpt: cut } of texts ) {
	test( `text: ${ name }`, () => {
		assert.equal( text( html ), plain );
		assert.equal( excerpt( html ), cut );
	} );
}

const page = {
	title: 'Pizza napoletana a Torino',
	fullTitle: 'Pizza napoletana a Torino — Da Mario',
	description:
		'La vera pizza napoletana a Torino: impasto a lunga lievitazione, forno a legna e ingredienti dalla Campania. Prenota un tavolo da Mario in centro.',
	keywords: [ 'pizza napoletana', 'torino' ],
	url: 'https://example.com/pizza-napoletana-torino/',
	home: 'https://example.com/',
	image: true,
	content: null,
};
const checks = ( result ) =>
	Object.fromEntries(
		result.checks.map( ( check ) => [ check.id, check.pass ] )
	);

test( 'a term with everything right scores 100 without the content checks', () => {
	const result = score( page );
	assert.equal( result.score, 100 );
	assert.equal( result.checks.length, 9 );
	assert.equal( level( result.score ), 'good' );
} );

test( 'an address with an invalid % still has a score', () => {
	for ( const url of [
		'https://example.com/100%-sconto/',
		'https://example.com/%postname%/',
	] ) {
		const result = checks( score( { ...page, url } ) );
		assert.equal( result.url_length, true, url );
	}
} );

test( 'the content checks say they are about the content', () => {
	const result = score( { ...page, content: '<p>Pizza napoletana.</p>' } );
	assert.deepEqual(
		result.checks
			.filter( ( check ) => ! check.content )
			.map( ( check ) => check.id ),
		score( page ).checks.map( ( check ) => check.id ),
		'The others are those of a term.'
	);
	assert.ok( result.checks.some( ( check ) => check.content ) );
} );

test( 'without a keyword every keyword check fails', () => {
	const result = checks( score( { ...page, keywords: [] } ) );
	for ( const id of [
		'keyword',
		'title_keyword',
		'title_start',
		'description_keyword',
		'url_keyword',
	] ) {
		assert.equal( result[ id ], false, id );
	}
	assert.equal( result.title_length, true );
} );

test( 'keywords match whole words, without accents and case', () => {
	const result = checks(
		score( {
			...page,
			keywords: [ 'Città' ],
			title: 'La nostra citta',
			description: 'Una capacità rara',
			url: 'https://example.com/la-citta/',
		} )
	);
	assert.equal( result.title_keyword, true );
	assert.equal( result.title_start, false, 'in the second half' );
	assert.equal( result.description_keyword, false, 'capacità ≠ città' );
	assert.equal( result.url_keyword, true );
} );

test( 'lengths of title, description and URL', () => {
	const result = score( {
		...page,
		fullTitle: 'x'.repeat( 61 ),
		description: 'Breve.',
		url: `https://example.com/${ 'a'.repeat( 80 ) }/`,
	} );
	assert.deepEqual(
		result.checks
			.filter( ( check ) => check.value !== undefined )
			.map( ( { id, pass, value } ) => [ id, pass, value ] ),
		[
			[ 'title_length', false, 61 ],
			[ 'description_length', false, 6 ],
			[ 'url_length', false, 82 ],
		]
	);
} );

test( 'content checks', () => {
	const filler = ( n ) =>
		'<p>Una pizza napoletana: ' +
		'impasto forno legna '.repeat( n ) +
		'</p>';
	const html = [
		'<p>Da Mario la pizza napoletana si cuoce nel forno a legna.</p>',
		'<h2>La nostra pizza napoletana</h2>',
		filler( 35 ),
		filler( 35 ),
		filler( 35 ),
		filler( 35 ),
		filler( 35 ),
		filler( 35 ),
		'<figure><img src="a.jpg" alt="Una pizza napoletana"></figure>',
		'<p><a href="/menu/">Menu</a> e <a href="https://www.google.com/maps">mappa</a>.</p>',
	].join( '\n' );
	const result = score( { ...page, content: html } );
	assert.deepEqual( checks( result ), {
		...checks( score( page ) ),
		content_length: true,
		content_keyword: true,
		content_start: true,
		density: true,
		subheading_keyword: true,
		image_alt_keyword: true,
		internal_links: true,
		external_links: true,
		short_paragraphs: true,
		media: true,
	} );
	assert.equal( result.score, 100 );

	const poor = checks(
		score( {
			...page,
			content: `<p>${ 'parola '.repeat( 130 ) }</p><p><a href="mailto:a@b.it">a</a> <a href="https://example.com/x">x</a></p>`,
		} )
	);
	assert.equal( poor.content_length, false );
	assert.equal( poor.content_keyword, false );
	assert.equal( poor.short_paragraphs, false );
	assert.equal( poor.internal_links, true );
	assert.equal( poor.external_links, false, 'mailto is not a page' );
	assert.equal( poor.media, false );
} );

test( 'keyword stuffing fails the density check', () => {
	const result = score( {
		...page,
		content: '<p>' + 'pizza napoletana '.repeat( 50 ) + '</p>',
	} );
	assert.equal( checks( result ).density, false );
	assert.equal(
		result.checks.find( ( check ) => check.id === 'density' ).value,
		50
	);
} );

test( 'levels', () => {
	assert.deepEqual( [ 81, 80, 51, 50 ].map( level ), [
		'good',
		'ok',
		'ok',
		'bad',
	] );
} );
