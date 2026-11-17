// Bacheca (Barrio → Dashboard), come quella del prototipo: la messa online del sito (agli
// amministratori) e una card per ogni modulo acceso, con i suoi numeri e i collegamenti. Si apre con
// read: ogni card controlla la capability del suo modulo.
import apiFetch from '@wordpress/api-fetch';
import { useEffect, useState } from '@wordpress/element';

import { FormsCard } from '../../../modules/forms/assets/src/forms/FormsCard.js';
import { LaunchBanner, launchUrl } from '../launch/Launch.js';

const { canManageOptions } = window.barrioAdmin;

export function Dashboard() {
	const [ launch, setLaunch ] = useState( null );

	useEffect( () => {
		// Senza la messa online la Bacheca resta utile: un suo errore non si mostra.
		if ( canManageOptions ) {
			apiFetch( { path: '/barrio/v1/launch' } )
				.then( setLaunch )
				.catch( () => {} );
		}
	}, [] );

	// Senza barra: la Bacheca non ha azioni sue, e gli avvisi di WordPress stanno sopra l'intestazione.
	return (
		<div className="barrio-stack">
			{ launch && (
				<LaunchBanner launch={ launch } href={ launchUrl() } />
			) }
			<div className="barrio-modules">
				<FormsCard />
			</div>
		</div>
	);
}
