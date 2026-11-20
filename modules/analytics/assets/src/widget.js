// Il widget Statistiche nella Bacheca di WordPress (Module::dashboard() in modules/analytics/src/Module.php): una build a
// parte, come Riordina, perché la Bacheca di WordPress non carica l'app delle pagine Barrio.
import { createRoot } from '@wordpress/element';

import { Widget } from './Analytics.js';

createRoot( document.getElementById( 'barrio-analytics-widget' ) ).render(
	<Widget />
);
