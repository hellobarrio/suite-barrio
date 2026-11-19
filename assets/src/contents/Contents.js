// La pagina Barrio → Contenuti (Admin::contents_tab() in src/Admin.php), con una scheda per ogni strumento acceso
// che chi guarda può aprire, e la sua card nella Bacheca.
import { __ } from '@wordpress/i18n';

import {
	ALT,
	AltText,
	altData,
	altStats,
} from '../../../modules/alt/assets/src/Alt.js';
import {
	BLOCKS,
	blocksData,
	blocksSection,
	blocksStats,
} from '../../../modules/blocks/assets/src/Blocks.js';
import {
	DUPLICATE,
	duplicateData,
	duplicateSection,
	duplicateStats,
} from '../../../modules/duplicate/assets/src/Duplicate.js';
import {
	ORDERING,
	orderingData,
	orderingSection,
	orderingStats,
} from '../../../modules/ordering/assets/src/Ordering.js';
import { ModuleCard, Tabs } from '../admin/ui.js';
import { General } from '../general/General.js';

const { canManageOptions } = window.barrioAdmin;

const CONTENTS = {
	icon: 'edit-page',
	title: __( 'Contents', 'barrio-suite' ),
	description: __(
		'Tools for writing and managing the contents',
		'barrio-suite'
	),
	color: 'linear-gradient(135deg, #4c1d95, #6d28d9)',
};

// Costante: General ricarica le sezioni quando cambia l'array.
const BLOCKS_SECTIONS = [ blocksSection ];
const ORDERING_SECTIONS = [ orderingSection ];
const DUPLICATE_SECTIONS = [ duplicateSection ];

// Per strumento: la scheda della pagina ([ tab, nome, componente ]) e i numeri della card, che ne mostra tre: con
// tutti e quattro gli strumenti resta fuori l'ultimo, i tipi con Duplica. Testi alternativi anche a chi carica i media.
const TOOLS = [
	blocksData &&
		canManageOptions && {
			tab: [
				'blocks',
				BLOCKS.title,
				() => (
					<General
						title={ BLOCKS.title }
						sections={ BLOCKS_SECTIONS }
					/>
				),
			],
			stats: blocksStats,
		},
	orderingData &&
		canManageOptions && {
			tab: [
				'ordering',
				ORDERING.title,
				() => (
					<General
						title={ ORDERING.title }
						sections={ ORDERING_SECTIONS }
					/>
				),
			],
			stats: orderingStats,
		},
	altData && {
		tab: [ 'alt', ALT.title, AltText ],
		stats: altStats,
	},
	duplicateData &&
		canManageOptions && {
			tab: [
				'duplicate',
				DUPLICATE.title,
				() => (
					<General
						title={ DUPLICATE.title }
						sections={ DUPLICATE_SECTIONS }
					/>
				),
			],
			stats: duplicateStats,
		},
].filter( Boolean );

export const contentsPage = {
	title: CONTENTS.title,
	description: __(
		'Tools for whoever writes and manages the contents of the site.',
		'barrio-suite'
	),
	page: (
		<Tabs page="barrio-contents" tabs={ TOOLS.map( ( { tab } ) => tab ) } />
	),
};

// Tra SEO e Prestazioni, come nel menu.
export function ContentsCard() {
	if ( ! TOOLS.length ) {
		return null;
	}
	return (
		<ModuleCard
			module={ CONTENTS }
			stats={ TOOLS.flatMap( ( { stats } ) => stats() ) }
			actions={ [
				[ __( 'Settings', 'barrio-suite' ), '?page=barrio-contents' ],
			] }
		/>
	);
}
