// I dati, i link della pagina Form e lo shortcode, usati dalle sue schede e dalla Bacheca.
import { __, sprintf } from '@wordpress/i18n';

import { Icon } from '../../../../assets/src/admin/ui.js';

// Forms\Admin::data(). Tutte le pagine sono in un solo bundle: con il modulo spento manca, le sue
// pagine non si aprono e i valori vuoti bastano al codice che li legge mentre il bundle si carica.
export const formsData = window.barrioAdmin.forms ?? {
	types: {},
	presets: {},
	integrations: {},
};

export const pageUrl = ( params = {} ) =>
	'?' + new URLSearchParams( { page: 'barrio-forms', ...params } );

export const entriesUrl = ( params ) =>
	pageUrl( { tab: 'entries', ...params } );

// Si copia con un clic dove il browser lo permette (HTTPS o localhost); altrimenti si seleziona.
export function Shortcode( { id, onCopy } ) {
	const code = `[barrio_form id="${ id }"]`;
	if ( ! window.navigator.clipboard ) {
		return (
			<code className="barrio-chip">
				<span className="barrio-chip__text">{ code }</span>
			</code>
		);
	}
	return (
		<button
			type="button"
			className="barrio-chip"
			aria-label={ sprintf(
				/* translators: %s: form shortcode. */
				__( 'Copy %s', 'barrio-suite' ),
				code
			) }
			onClick={ () =>
				window.navigator.clipboard.writeText( code ).then( onCopy )
			}
		>
			<span className="barrio-button__text barrio-chip__text">
				{ code }
			</span>
			<Icon name="admin-page" />
		</button>
	);
}
