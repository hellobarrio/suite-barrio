import assert from 'node:assert/strict';
// eslint-disable-next-line vitest/no-import-node-test -- node --test, senza dipendenze.
import { test } from 'node:test';

// no-h1.js è uno script senza build: si registra su window.wp.hooks, qui con le trasformazioni del titolo del core
// (packages/block-library/src/heading/transforms.js).
test( 'no heading 1: not from "# ", "/h1" or a pasted H1', async () => {
	let filter;
	globalThis.window = {
		wp: { hooks: { addFilter: ( hook, name, fn ) => ( filter = fn ) } },
	};
	await import( '../../modules/blocks/assets/editor/no-h1.js' );

	const levels = [ 1, 2, 3, 4, 5, 6 ];
	const heading = {
		attributes: { levelOptions: { type: 'array' } },
		transforms: {
			from: [
				{ type: 'block', blocks: [ 'core/paragraph' ] },
				{
					type: 'raw',
					selector: 'h1,h2,h3,h4,h5,h6',
					transform: ( node ) => ( {
						name: 'core/heading',
						attributes: { level: Number( node.nodeName[ 1 ] ) },
					} ),
				},
				...levels.map( ( level ) => ( {
					type: 'prefix',
					prefix: '#'.repeat( level ),
				} ) ),
				...levels.map( ( level ) => ( {
					type: 'enter',
					regExp: new RegExp( `^/(h|H)${ level }$` ),
				} ) ),
			],
		},
	};
	const { attributes, transforms } = filter( heading, 'core/heading' );
	const of = ( type ) =>
		transforms.from.filter( ( from ) => type === from.type );

	assert.deepEqual( attributes.levelOptions.default, [ 2, 3, 4, 5, 6 ] );
	assert.deepEqual(
		of( 'prefix' ).map( ( { prefix } ) => prefix.length ),
		[ 2, 3, 4, 5, 6 ]
	);
	assert.equal( of( 'enter' ).length, 5 );
	assert.ok( ! of( 'enter' ).some( ( { regExp } ) => regExp.test( '/H1' ) ) );
	assert.equal( of( 'block' ).length, 1 );
	const [ raw ] = of( 'raw' );
	assert.equal( raw.selector, 'h1,h2,h3,h4,h5,h6' );
	assert.equal( raw.transform( { nodeName: 'H1' } ).attributes.level, 2 );
	assert.equal( raw.transform( { nodeName: 'H3' } ).attributes.level, 3 );

	// Un blocco con le sue scelte (il titolo del sito) resta com'è.
	const title = { attributes: { levelOptions: { default: [ 0, 1 ] } } };
	assert.equal( filter( title, 'core/site-title' ), title );
} );
