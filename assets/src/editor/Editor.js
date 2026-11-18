// La pagina Barrio → Editor (Admin::editor_tab() in src/Admin.php): gli strumenti dell'editor, una scheda per ogni
// modulo acceso che chi guarda può aprire.
import { __ } from '@wordpress/i18n';

import {
	DUPLICATE,
	duplicateData,
	duplicateSection,
} from '../../../modules/duplicate/assets/src/Duplicate.js';
import { Tabs } from '../admin/ui.js';
import { General } from '../general/General.js';

const { canManageOptions } = window.barrioAdmin;

// Costante: General ricarica le sezioni quando cambia l'array.
const DUPLICATE_SECTIONS = [ duplicateSection ];

const TABS = [
	duplicateData &&
		canManageOptions && [
			'duplicate',
			DUPLICATE.title,
			() => (
				<General
					title={ DUPLICATE.title }
					sections={ DUPLICATE_SECTIONS }
				/>
			),
		],
].filter( Boolean );

export const editorPage = {
	title: __( 'Editor', 'barrio-suite' ),
	description: __(
		'Tools for whoever writes and manages the contents of the site.',
		'barrio-suite'
	),
	page: <Tabs page="barrio-editor" tabs={ TABS } />,
};
