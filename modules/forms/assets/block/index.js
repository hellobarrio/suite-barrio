// Editor del blocco barrio/form, senza build: il form si sceglie dall'elenco e si vede in anteprima.
( ( {
	apiFetch,
	blocks,
	element,
	blockEditor,
	components,
	serverSideRender,
	i18n,
} ) => {
	const el = element.createElement;
	const { __ } = i18n;

	blocks.registerBlockType( 'barrio/form', {
		edit( { attributes, setAttributes } ) {
			const [ forms, setForms ] = element.useState( null );
			element.useEffect( () => {
				apiFetch( { path: '/barrio/v1/forms' } )
					.then( setForms )
					.catch( () => setForms( [] ) );
			}, [] );

			// Un form nel cestino non si offre, ma se è già quello scelto resta visibile.
			const options = ( forms ?? [] )
				.filter(
					( form ) =>
						form.status !== 'trash' || form.id === attributes.id
				)
				.map( ( form ) => ( { label: form.title, value: form.id } ) );
			const picker = el( components.SelectControl, {
				label: __( 'Form', 'barrio-suite' ),
				value: attributes.id ?? '',
				options: [
					{
						label: forms
							? __( 'Choose a form', 'barrio-suite' )
							: __( 'Loading…', 'barrio-suite' ),
						value: '',
					},
					...options,
				],
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
					? el(
							components.Disabled,
							null,
							el( serverSideRender, {
								block: 'barrio/form',
								attributes,
							} )
						)
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
