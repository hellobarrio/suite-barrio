// Niente H1 nei titoli per chi ha la restrizione (modules/blocks/src/Module.php), senza build. Cambia solo le scelte:
// un titolo già salvato come H1 resta com'è, e l'attributo predefinito non finisce nel contenuto.
( ( { hooks } ) => {
	// Un H1 incollato (da Word, da una pagina, in Markdown) diventa un H2.
	const pasted = ( from ) => ( {
		...from,
		transform( node ) {
			const block = from.transform( node );
			if ( 1 === block.attributes.level ) {
				block.attributes.level = 2;
			}
			return block;
		},
	} );

	hooks.addFilter(
		'blocks.registerBlockType',
		'barrio/blocks/no-h1',
		( settings, name ) => {
			const levelOptions = settings.attributes?.levelOptions;
			// Un blocco con le sue scelte (il titolo del sito) le tiene.
			if ( ! levelOptions || levelOptions.default ) {
				return settings;
			}
			return {
				...settings,
				attributes: {
					...settings.attributes,
					levelOptions: {
						...levelOptions,
						default: [ 2, 3, 4, 5, 6 ],
					},
				},
				...( 'core/heading' === name && {
					transforms: {
						...settings.transforms,
						from: settings.transforms.from
							// "# " all'inizio di un paragrafo e "/h1" con Invio creano un H1.
							.filter(
								( { prefix, regExp } ) =>
									'#' !== prefix && ! regExp?.test( '/h1' )
							)
							.map( ( from ) =>
								'raw' === from.type ? pasted( from ) : from
							),
					},
				} ),
			};
		}
	);
} )( window.wp );
