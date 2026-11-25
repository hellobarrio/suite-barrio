// Editor del blocco barrio/company, senza build: i dati e il loro ordine, la forma e le etichette si scelgono nelle
// impostazioni e si vedono in anteprima, resa dal server come nel sito.
( ( { blocks, element, blockEditor, components, serverSideRender, i18n } ) => {
	const el = element.createElement;
	const { __ } = i18n;

	// I dati di Module::data(): nel campo si scelgono per etichetta.
	const FIELDS = {
		name: __( 'Name', 'barrio-suite' ),
		legal_name: __( 'Company name', 'barrio-suite' ),
		vat_id: __( 'VAT number', 'barrio-suite' ),
		tax_code: __( 'Tax code', 'barrio-suite' ),
		registration_number: __( 'REA number', 'barrio-suite' ),
		share_capital: __( 'Share capital', 'barrio-suite' ),
		certified_email: __( 'Certified email', 'barrio-suite' ),
		invoice_code: __( 'SDI code', 'barrio-suite' ),
		phone: __( 'Phone', 'barrio-suite' ),
		mobile: __( 'Mobile', 'barrio-suite' ),
		email: __( 'Email', 'barrio-suite' ),
		privacy_email: __( 'Privacy email', 'barrio-suite' ),
		address: __( 'Registered office', 'barrio-suite' ),
		offices: __( 'Operating offices', 'barrio-suite' ),
	};
	const NAMES = Object.fromEntries(
		Object.entries( FIELDS ).map( ( [ name, label ] ) => [ label, name ] )
	);

	blocks.registerBlockType( 'barrio/company', {
		edit( { attributes, setAttributes } ) {
			return el(
				'div',
				blockEditor.useBlockProps(),
				el(
					blockEditor.InspectorControls,
					null,
					el(
						components.PanelBody,
						{ title: __( 'Company details', 'barrio-suite' ) },
						el( components.FormTokenField, {
							label: __( 'Details', 'barrio-suite' ),
							value: attributes.fields.map(
								( name ) => FIELDS[ name ] ?? name
							),
							suggestions: Object.values( FIELDS ),
							__experimentalExpandOnFocus: true,
							// Invio prende il primo suggerimento: si sceglie per etichetta, senza scriverla intera.
							__experimentalAutoSelectFirstMatch: true,
							__experimentalValidateInput: ( label ) =>
								label in NAMES,
							onChange: ( tokens ) =>
								setAttributes( {
									fields: tokens
										.map(
											( token ) =>
												NAMES[ token.value ?? token ]
										)
										.filter( Boolean ),
								} ),
							__nextHasNoMarginBottom: true,
							__next40pxDefaultSize: true,
						} ),
						el(
							'p',
							{ className: 'components-base-control__help' },
							__(
								'In the order shown. Without details, the legal line: company name, registered office, VAT number, tax code, REA number and share capital.',
								'barrio-suite'
							)
						),
						el( components.SelectControl, {
							label: __( 'Layout', 'barrio-suite' ),
							value: attributes.layout,
							options: [
								{
									label: __( 'In a line', 'barrio-suite' ),
									value: 'line',
								},
								{
									label: __( 'As a list', 'barrio-suite' ),
									value: 'list',
								},
							],
							onChange: ( layout ) => setAttributes( { layout } ),
							__nextHasNoMarginBottom: true,
							__next40pxDefaultSize: true,
						} ),
						el( components.ToggleControl, {
							label: __( 'Show the labels', 'barrio-suite' ),
							help: __(
								'Like “VAT number: …”. The names never have one.',
								'barrio-suite'
							),
							checked: attributes.labels,
							onChange: ( labels ) => setAttributes( { labels } ),
							__nextHasNoMarginBottom: true,
						} )
					)
				),
				el(
					components.Disabled,
					null,
					el( serverSideRender, {
						block: 'barrio/company',
						attributes,
						EmptyResponsePlaceholder: () =>
							el(
								components.Placeholder,
								{
									icon: 'id',
									label: __(
										'Company details',
										'barrio-suite'
									),
								},
								__(
									'Add the company details in Barrio → Company details.',
									'barrio-suite'
								)
							),
					} )
				)
			);
		},
	} );
} )( window.wp );
