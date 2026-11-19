// La scheda Duplicazione di Barrio → Contenuti (modules/duplicate/src/Module.php): su quali tipi di contenuto e
// tassonomie compare Duplica, e quali ruoli possono duplicare. E i suoi numeri nella card Contenuti della Bacheca.
import apiFetch from '@wordpress/api-fetch';
import { __ } from '@wordpress/i18n';

import { Permissions, TypeCards } from '../../../../assets/src/admin/ui.js';

// Anche nella card Moduli di Barrio → Impostazioni.
export const DUPLICATE = {
	title: __( 'Duplication', 'barrio-suite' ),
	description: __(
		'A Duplicate link for contents and terms, in the lists and in the editor.',
		'barrio-suite'
	),
};

// Module::admin_data(): i tipi e le tassonomie spenti. Con il modulo spento manca.
export const duplicateData = window.barrioAdmin.duplicate;

// Admin::content_types(): i tipi e le tassonomie con l'interfaccia.
const { contentTypes } = window.barrioAdmin;

// Una card per elenco, come le card di Prestazioni: una riga per tipo.
const CARDS = {
	post_types: {
		icon: 'admin-page',
		title: __( 'Content types', 'barrio-suite' ),
		description: __(
			'Duplicate in the list, in the bulk actions and in the editor. The copy is a draft of whoever duplicates it, with the content, the custom fields and the terms of the original.',
			'barrio-suite'
		),
	},
	taxonomies: {
		icon: 'tag',
		title: __( 'Taxonomies', 'barrio-suite' ),
		description: __(
			'Duplicate in the list of terms. The copy has the description, the parent and the custom fields of the original.',
			'barrio-suite'
		),
	},
};

// La capability del modulo, nei Permessi (Module::CAP).
const CAPS = { barrio_duplicate: __( 'Duplicate', 'barrio-suite' ) };

const enabled = ( group, disabled ) =>
	contentTypes[ group ].filter( ( { name } ) => ! disabled.includes( name ) );

// Per la card Contenuti della Bacheca, dalle impostazioni salvate: un numero per strumento.
export const duplicateStats = () => [
	[
		__( 'Lists with Duplicate', 'barrio-suite' ),
		enabled( 'post_types', duplicateData.disabled.post_types ).length +
			enabled( 'taxonomies', duplicateData.disabled.taxonomies ).length,
	],
];

// GET e PUT /barrio/v1/duplicate, con il Salva della barra (general/General.js). Si salvano i tipi spenti e le
// capability dei ruoli.
export const duplicateSection = {
	id: 'duplicate',
	load: () => apiFetch( { path: '/barrio/v1/duplicate' } ),
	value: ( { role_names: names, ...settings } ) => settings,
	save: ( draft ) =>
		apiFetch( {
			path: '/barrio/v1/duplicate',
			method: 'PUT',
			data: draft,
		} ),
	Cards: ( { data, draft, set } ) => (
		<>
			<TypeCards
				cards={ CARDS }
				checked={ ( group, name ) => ! draft[ group ].includes( name ) }
				onChange={ ( group, name, on ) =>
					set( {
						...draft,
						[ group ]: on
							? draft[ group ].filter( ( item ) => item !== name )
							: [ ...draft[ group ], name ],
					} )
				}
			/>
			<Permissions
				description={ __(
					'Who can duplicate, among the contents and terms they can edit. Administrators can always do everything; only administrators change these settings.',
					'barrio-suite'
				) }
				caps={ CAPS }
				names={ data.role_names }
				roles={ draft.roles }
				onChange={ ( roles ) => set( { ...draft, roles } ) }
			/>
		</>
	),
};
