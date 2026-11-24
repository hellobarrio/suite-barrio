// Bacheca (Barrio → Dashboard), come quella del prototipo: la messa online del sito (agli
// amministratori) e una card per ogni modulo acceso, con i suoi numeri e i collegamenti. Si apre con
// read: ogni card controlla la capability del suo modulo.
import apiFetch from '@wordpress/api-fetch';
import { useEffect, useState } from '@wordpress/element';

import { AltCard } from '../../../modules/alt/assets/src/Alt.js';
import { AnalyticsCard } from '../../../modules/analytics/assets/src/Analytics.js';
import { BannersCard } from '../../../modules/banners/assets/src/Banners.js';
import { CompanyCard } from '../../../modules/company/assets/src/Company.js';
import { FormsCard } from '../../../modules/forms/assets/src/forms/FormsCard.js';
import { HoursCard } from '../../../modules/hours/assets/src/Hours.js';
import { PerformanceCard } from '../../../modules/performance/assets/src/Performance.js';
import { PopupsCard } from '../../../modules/popups/assets/src/Popups.js';
import { SeoCard } from '../../../modules/seo/assets/src/seo/SeoCard.js';
import { ContentsCard } from '../contents/Contents.js';
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
			<CompanyCard />
			<div className="barrio-modules">
				<AnalyticsCard />
				<FormsCard />
				<SeoCard />
				<AltCard />
				<PopupsCard />
				<BannersCard />
				<HoursCard />
				<ContentsCard />
				<PerformanceCard />
			</div>
		</div>
	);
}
