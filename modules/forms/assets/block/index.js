// Editor del blocco barrio/form, senza build: il form si sceglie per id e si vede in anteprima.
// ponytail: id da digitare; un elenco dei form arriva con la REST admin (fase 4).
( ( { blocks, element, blockEditor, components, serverSideRender, i18n } ) => {
	const el = element.createElement;
	const { __ } = i18n;

	blocks.registerBlockType( 'barrio/form', {
		edit( { attributes, setAttributes } ) {
			const picker = el( components.TextControl, {
				type: 'number',
				label: __( 'Form ID', 'barrio-suite' ),
				value: attributes.id ?? '',
				onChange: ( value ) =>
					setAttributes( { id: parseInt( value, 10 ) || undefined } ),
				__nextHasNoMarginBottom: true,
				__next40pxDefaultSize: true,
			} );
			return el(
				'div',
				blockEditor.useBlockProps(),
				el(
					blockEditor.InspectorControls,
					null,
					el(
						components.PanelBody,
						{ title: __( 'Form', 'barrio-suite' ) },
						picker
					)
				),
				attributes.id
					? el( serverSideRender, {
							block: 'barrio/form',
							attributes,
						} )
					: el(
							components.Placeholder,
							{
								icon: 'feedback',
								label: __( 'Form', 'barrio-suite' ),
							},
							picker
						)
			);
		},
	} );
} )( window.wp );
