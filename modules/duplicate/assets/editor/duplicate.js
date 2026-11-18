// Il pulsante Duplica nel pannello dello stato dell'editor a blocchi (modules/duplicate/src/Module.php), senza
// build. Copia il contenuto salvato: prima di lasciare modifiche non salvate il browser chiede conferma.
( ( { components, data, editor, element, plugins } ) => {
	const { url, label } = window.barrioDuplicate;

	plugins.registerPlugin( 'barrio-duplicate', {
		render() {
			// Un contenuto mai salvato non ha niente da copiare.
			const saved = data.useSelect(
				( select ) =>
					select( editor.store ).getCurrentPostAttribute(
						'status'
					) !== 'auto-draft',
				[]
			);
			return (
				saved &&
				element.createElement(
					editor.PluginPostStatusInfo,
					null,
					element.createElement(
						components.Button,
						{
							variant: 'secondary',
							href: url,
							__next40pxDefaultSize: true,
						},
						label
					)
				)
			);
		},
	} );
} )( window.wp );
