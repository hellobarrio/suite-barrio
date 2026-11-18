// Niente H1 nei titoli per chi ha la restrizione (modules/blocks/src/Module.php), senza build. Cambia solo le scelte:
// un titolo già salvato come H1 resta com'è, e l'attributo predefinito non finisce nel contenuto.
( ( { hooks } ) => {
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
				// "# " all'inizio di un paragrafo crea un H1.
				...( 'core/heading' === name && {
					transforms: {
						...settings.transforms,
						from: settings.transforms.from.filter(
							( { prefix } ) => '#' !== prefix
						),
					},
				} ),
			};
		}
	);
} )( window.wp );
