// "Genera con AI" nei dettagli di un'immagine (Module::fields() in modules/alt/src/Module.php), nella finestra dei
// media e nella schermata di modifica, senza build. Riempie i campi come se li si scrivesse: la finestra dei media li
// salva subito, la schermata di modifica con Aggiorna.
( ( { apiFetch } ) => {
	const fill = ( field, value ) => {
		if ( field && value ) {
			field.value = value;
			field.dispatchEvent( new Event( 'change', { bubbles: true } ) );
		}
	};

	document.addEventListener( 'click', ( event ) => {
		const button = event.target.closest( '.barrio-alt-generate' );
		if ( ! button ) {
			return;
		}
		// Nella finestra dei media i campi sono nei dettagli dell'immagine; nella schermata di modifica nella pagina.
		const root = button.closest( '.attachment-details' ) ?? document;
		const status = button.nextElementSibling;
		const label = button.textContent;
		button.disabled = true;
		button.textContent = window.barrioAlt.generating;
		status.textContent = '';
		apiFetch( {
			path: `/barrio/v1/alt/${ button.dataset.id }`,
			method: 'POST',
		} )
			.then( ( text ) => {
				fill(
					root.querySelector(
						'[data-setting="alt"] textarea, #attachment_alt'
					),
					text.alt
				);
				fill(
					root.querySelector(
						'[data-setting="title"] input, #title'
					),
					text.title
				);
			} )
			.catch( ( error ) => {
				status.textContent = error.message;
			} )
			.finally( () => {
				button.disabled = false;
				button.textContent = label;
			} );
	} );
} )( window.wp );
