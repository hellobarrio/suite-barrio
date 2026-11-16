// Prepara una release GitHub: dopo npm run zip e zip:extensions scrive catalog.json, che ogni sito legge
// da …/releases/latest/download/catalog.json (src/Extensions.php) per la pagina Estensioni e per gli
// aggiornamenti. I dati vengono dagli header dei plugin; il repository dal loro "Update URI".
import { readFileSync, readdirSync, writeFileSync } from 'node:fs';

const files = {
	'barrio-suite': 'barrio-suite.php',
	...Object.fromEntries(
		// Solo le cartelle: in macOS c'è anche .DS_Store.
		readdirSync( 'extensions', { withFileTypes: true } )
			.filter( ( entry ) => entry.isDirectory() )
			.map( ( { name } ) => [
				name,
				`extensions/${ name }/${ name }.php`,
			] )
	),
};
const header = ( file, name ) =>
	readFileSync( file, 'utf8' )
		.match( new RegExp( `^\\s*\\*\\s*${ name }:(.*)$`, 'm' ) )?.[ 1 ]
		.trim() ?? '';
const repo = header( files[ 'barrio-suite' ], 'Update URI' );

const plugins = {};
for ( const [ slug, file ] of Object.entries( files ) ) {
	plugins[ slug ] = {
		name: header( file, 'Plugin Name' ),
		description: header( file, 'Description' ),
		version: header( file, 'Version' ),
		requires: header( file, 'Requires at least' ),
		requires_php: header( file, 'Requires PHP' ),
	};
}
writeFileSync(
	'catalog.json',
	JSON.stringify( { plugins }, null, '\t' ) + '\n'
);

const assets = [
	'catalog.json',
	...Object.keys( files ).map( ( s ) => `${ s }.zip` ),
];
// eslint-disable-next-line no-console -- istruzioni per chi pubblica.
console.log(
	`Pubblica una release su ${ repo }/releases/new (tag v${ plugins[ 'barrio-suite' ].version } o successivo) con questi file:\n  ${ assets.join( '\n  ' ) }`
);
