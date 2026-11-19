// Le pagine Barrio (src/Admin.php): l'intestazione e la pagina scelta da ?page=. Bacheca, Estensioni e
// Impostazioni sono della suite; ogni modulo acceso ha la sua pagina, con le sue schede. Ogni vista è
// un URL: link normali, niente router.
import { createRoot } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

import {
	ALT,
	AltText,
	altSection,
} from '../../../modules/alt/assets/src/Alt.js';
import { formsPage } from '../../../modules/forms/assets/src/forms/Forms.js';
import {
	HOURS,
	hoursSection,
	hoursSettingsSection,
} from '../../../modules/hours/assets/src/Hours.js';
import {
	PERFORMANCE,
	performanceSection,
} from '../../../modules/performance/assets/src/Performance.js';
import { seoPage } from '../../../modules/seo/assets/src/seo/Seo.js';
import { contentsPage } from '../contents/Contents.js';
import { Dashboard } from '../dashboard/Dashboard.js';
import { Extensions } from '../extensions/Extensions.js';
import { General } from '../general/General.js';
import { Launch } from '../launch/Launch.js';
import { Tabs } from './ui.js';
import './admin.css';

const params = new URLSearchParams( window.location.search );

// Costante: General ricarica le sezioni quando cambia l'array.
const ALT_SECTIONS = [ altSection ];
const HOURS_SECTIONS = [ hoursSection ];
const HOURS_SETTINGS = [ hoursSettingsSection ];

// Il nome della pagina dopo "Barrio Suite" (senza, la Bacheca mostra la versione), la descrizione e
// il contenuto.
const PAGES = {
	barrio: {
		description: __(
			'Forms and site tools by Hellobarrio.',
			'barrio-suite'
		),
		page: <Dashboard />,
	},
	'barrio-forms': formsPage,
	'barrio-seo': seoPage,
	'barrio-contents': contentsPage,
	'barrio-performance': {
		title: PERFORMANCE.title,
		description: __(
			'Everything happens on this server, without external services. Changes apply from the next page load.',
			'barrio-suite'
		),
		page: (
			<General
				title={ PERFORMANCE.title }
				sections={ [ performanceSection ] }
			/>
		),
	},
	'barrio-extensions': {
		title: __( 'Extensions', 'barrio-suite' ),
		description: __(
			'Plugins that connect Barrio Suite to other services.',
			'barrio-suite'
		),
		page: <Extensions />,
	},
	'barrio-settings': {
		title: __( 'Settings', 'barrio-suite' ),
		description: __(
			'Modules, email and the other settings of the whole plugin.',
			'barrio-suite'
		),
		page: <General />,
	},
	'barrio-alt': {
		title: ALT.title,
		description: __(
			'The alternative text of the images, written by Google AI looking at each image: from the image details, in bulk or after the upload.',
			'barrio-suite'
		),
		page: (
			<Tabs
				page="barrio-alt"
				tabs={ [
					[ '', __( 'Images', 'barrio-suite' ), AltText ],
					window.barrioAdmin.canManageOptions && [
						'settings',
						__( 'Settings', 'barrio-suite' ),
						() => (
							<General
								title={ __( 'Settings', 'barrio-suite' ) }
								sections={ ALT_SECTIONS }
							/>
						),
					],
				].filter( Boolean ) }
			/>
		),
	},
	'barrio-hours': {
		title: HOURS.title,
		description: __(
			'The opening hours of the business and its special days, for the site, the schema of the organization and the headless frontend.',
			'barrio-suite'
		),
		page: (
			<Tabs
				page="barrio-hours"
				tabs={ [
					[
						'',
						HOURS.title,
						() => (
							<General
								title={ HOURS.title }
								sections={ HOURS_SECTIONS }
							/>
						),
					],
					window.barrioAdmin.canManageOptions && [
						'settings',
						__( 'Settings', 'barrio-suite' ),
						() => (
							<General
								title={ __( 'Settings', 'barrio-suite' ) }
								sections={ HOURS_SETTINGS }
							/>
						),
					],
				].filter( Boolean ) }
			/>
		),
	},
};
// La messa online è una vista della Bacheca: niente voce di menu.
if ( params.has( 'launch' ) ) {
	PAGES.barrio = {
		title: __( 'Site launch', 'barrio-suite' ),
		description: __(
			'Check that the site is ready to go online and configure what is missing.',
			'barrio-suite'
		),
		page: <Launch />,
	};
}
const { title, description, page } = PAGES[ params.get( 'page' ) ];

createRoot( document.getElementById( 'barrio-admin' ) ).render(
	<div className="barrio-admin">
		<header className="barrio-header">
			{ /* L'icona del prototipo, come nel menu di WordPress. */ }
			<span className="barrio-header__logo">
				<svg
					width="26"
					height="26"
					viewBox="0 0 20 20"
					fill="currentColor"
					aria-hidden="true"
				>
					<path d="M5,5h2.5v2.5H5ZM0,8.75h2.5v2.5H0ZM2.5,11.25h2.5v2.5H2.5ZM5,13.75h10v2.5H5ZM17.5,8.75h2.5v2.5H17.5ZM15,11.25h2.5v2.5H15ZM12.5,5h2.5v2.5H12.5Z" />
				</svg>
			</span>
			<div>
				<h1>
					Barrio Suite{ ' ' }
					{ title ? (
						<span>/ { title }</span>
					) : (
						<span className="barrio-badge">
							v{ window.barrioAdmin.version }
						</span>
					) }
				</h1>
				<p>{ description }</p>
			</div>
		</header>
		{ page }
	</div>
);
