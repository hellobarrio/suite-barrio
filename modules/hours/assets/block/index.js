// Editor del blocco barrio/hours, senza build: la forma (settimana, oggi, stato) si sceglie nelle impostazioni e si
// vede in anteprima, resa dal server come nel sito.
( ( { blocks, element, blockEditor, components, serverSideRender, i18n } ) => {
	const el = element.createElement;
	const { __ } = i18n;

	blocks.registerBlockType( 'barrio/hours', {
		edit( { attributes, setAttributes } ) {
			return el(
				'div',
				blockEditor.useBlockProps(),
				el(
					blockEditor.InspectorControls,
					null,
					el(
						components.PanelBody,
						{ title: __( 'Opening hours', 'barrio-suite' ) },
						el( components.SelectControl, {
							label: __( 'Show', 'barrio-suite' ),
							value: attributes.show,
							options: [
								{
									label: __( 'The week', 'barrio-suite' ),
									value: 'week',
								},
								{
									label: __( 'Today', 'barrio-suite' ),
									value: 'today',
								},
								{
									label: __(
										'Open or closed now',
										'barrio-suite'
									),
									value: 'status',
								},
							],
							onChange: ( show ) => setAttributes( { show } ),
							__nextHasNoMarginBottom: true,
							__next40pxDefaultSize: true,
						} ),
						'week' === attributes.show &&
							el( components.ToggleControl, {
								label: __(
									'Group the days with the same hours',
									'barrio-suite'
								),
								checked: attributes.group,
								onChange: ( group ) =>
									setAttributes( { group } ),
								__nextHasNoMarginBottom: true,
							} )
					)
				),
				el(
					components.Disabled,
					null,
					el( serverSideRender, {
						block: 'barrio/hours',
						attributes,
						EmptyResponsePlaceholder: () =>
							el(
								components.Placeholder,
								{
									icon: 'clock',
									label: __(
										'Opening hours',
										'barrio-suite'
									),
								},
								__(
									'Add the opening hours in Barrio → Opening hours.',
									'barrio-suite'
								)
							),
					} )
				)
			);
		},
	} );
} )( window.wp );
