// La pagina Barrio → Editor (Admin::editor_tab() in src/Admin.php), con una scheda per ogni strumento dell'editor
// acceso che chi guarda può aprire, e la sua card nella Bacheca.
import { __ } from '@wordpress/i18n';

import {
	DUPLICATE,
	duplicateData,
	duplicateSection,
	duplicateStats,
} from '../../../modules/duplicate/assets/src/Duplicate.js';
import { ModuleCard, Tabs } from '../admin/ui.js';
import { General } from '../general/General.js';

const { canManageOptions } = window.barrioAdmin;

const EDITOR = {
	icon: 'edit-page',
	title: __( 'Editor', 'barrio-suite' ),
	description: __(
		'Tools for writing and managing the contents',
		'barrio-suite'
	),
	color: 'linear-gradient(135deg, #4c1d95, #6d28d9)',
};

// Costante: General ricarica le sezioni quando cambia l'array.
const DUPLICATE_SECTIONS = [ duplicateSection ];

// Per strumento: la scheda della pagina ([ tab, nome, componente ]) e i numeri della card.
const TOOLS = [
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

export const editorPage = {
	title: EDITOR.title,
	description: __(
		'Tools for whoever writes and manages the contents of the site.',
		'barrio-suite'
	),
	page: (
		<Tabs page="barrio-editor" tabs={ TOOLS.map( ( { tab } ) => tab ) } />
	),
};

// Tra SEO e Prestazioni, come nel menu.
export function EditorCard() {
	if ( ! TOOLS.length ) {
		return null;
	}
	return (
		<ModuleCard
			module={ EDITOR }
			stats={ TOOLS.flatMap( ( { stats } ) => stats() ) }
			actions={ [
				[ __( 'Settings', 'barrio-suite' ), '?page=barrio-editor' ],
			] }
		/>
	);
}
