// La pagina Barrio → Contenuti (Admin::contents_tab() in src/Admin.php), con una scheda per ogni strumento acceso
// che chi guarda può aprire, e la sua card nella Bacheca.
import { __ } from '@wordpress/i18n';

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

// Per strumento: la scheda della pagina ([ tab, nome, componente ]) e i numeri della card. Si chiama una volta per
// strumento, qui sotto: le sezioni restano lo stesso array (General le ricarica quando cambia).
const tool = ( data, id, module, section, stats ) => {
	const sections = [ section ];
	return (
		data &&
		canManageOptions && {
			tab: [
				id,
				module.title,
				() => <General title={ module.title } sections={ sections } />,
			],
			stats,
		}
	);
};

const TOOLS = [
	tool( blocksData, 'blocks', BLOCKS, blocksSection, blocksStats ),
	tool( orderingData, 'ordering', ORDERING, orderingSection, orderingStats ),
	tool(
		duplicateData,
		'duplicate',
		DUPLICATE,
		duplicateSection,
		duplicateStats
	),
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
				[
					__( 'Settings', 'barrio-suite' ),
					'?page=barrio-contents',
					'admin-generic',
				],
			] }
		/>
	);
}
