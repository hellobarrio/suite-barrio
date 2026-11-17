// Il pannello SEO nell'editor classico e nei termini (src/Editor.php): window.barrioSeo porta i meta, e il
// pannello li scrive in un campo nascosto del form, che li salva con il contenuto o il termine.
import { createRoot, useEffect, useState } from '@wordpress/element';

import { Panel, analyze } from './Panel.js';
import './editor.css';

const context = window.barrioSeo;

// I campi della pagina che cambiano il pannello mentre si scrive: titolo, riassunto e contenuto dei post,
// nome e descrizione dei termini.
// ponytail: il contenuto dell'editor visuale (TinyMCE) arriva nel textarea solo salvando o passando al
// testo, quindi i controlli sul contenuto si aggiornano allora; ascoltare TinyMCE se serve di più.
const FIELDS = {
	title: [ 'title', 'name' ],
	excerpt: [ 'excerpt' ],
	content: [ 'content' ],
	description: [ 'description' ],
};

function Form() {
	const [ meta, setMeta ] = useState( context.meta );
	const [ page, setPage ] = useState( {
		content: null,
		...context.page,
	} );
	useEffect( () => {
		const off = Object.entries( FIELDS ).flatMap( ( [ key, ids ] ) =>
			ids
				.map( ( id ) => document.getElementById( id ) )
				.filter( Boolean )
				.map( ( field ) => {
					const update = () =>
						setPage( ( current ) => ( {
							...current,
							[ key ]: field.value,
						} ) );
					field.addEventListener( 'input', update );
					return () => field.removeEventListener( 'input', update );
				} )
		);
		return () => off.forEach( ( remove ) => remove() );
	}, [] );

	return (
		<>
			<input
				type="hidden"
				name="barrio_seo"
				value={ JSON.stringify( meta ) }
			/>
			<Panel
				meta={ meta }
				page={ page }
				context={ context }
				seo={ analyze( meta, page, context ) }
				onChange={ ( changes ) =>
					setMeta( ( current ) => ( { ...current, ...changes } ) )
				}
			/>
		</>
	);
}

createRoot( document.getElementById( 'barrio-seo-panel' ) ).render( <Form /> );
