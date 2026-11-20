// Ordinamento (modules/ordering/src/Module.php): la scheda di Barrio → Contenuti che sceglie i tipi di contenuto e le
// tassonomie in ordine manuale, e i suoi numeri nella card Contenuti della Bacheca. Si riordina nella lista di ogni
// elenco (reorder.js).
import apiFetch from '@wordpress/api-fetch';
import { __ } from '@wordpress/i18n';

import { Permissions, TypeCards } from '../../../../assets/src/admin/ui.js';

// Anche nella card Moduli di Barrio → Impostazioni.
export const ORDERING = {
	title: __( 'Ordering', 'barrio-suite' ),
	description: __(
		'A manual order for contents and terms, by drag and drop, wherever the site does not ask for another order.',
		'barrio-suite'
	),
};

// Module::admin_data(): gli elenchi in ordine manuale. Con il modulo spento manca.
export const orderingData = window.barrioAdmin.ordering;

const CARDS = {
	post_types: {
		icon: 'admin-page',
		title: __( 'Content types', 'barrio-suite' ),
		description: __(
			'In manual order, the list of the type has a Reorder button next to Add. The order applies wherever the site lists the contents, also where it asks for the latest ones (such as the Latest Posts block), unless another order is chosen (such as by title), in a search and in the RSS feed; a new content goes to the top. Turned off, the most recent come first again, and the saved order is kept.',
			'barrio-suite'
		),
	},
	taxonomies: {
		icon: 'tag',
		title: __( 'Taxonomies', 'barrio-suite' ),
		description: __(
			'In manual order, the list of the terms has a Reorder button. A new term goes to the end; turned off, the terms are in alphabetical order again, and the saved order is kept.',
			'barrio-suite'
		),
	},
};

// La capability del modulo, nei Permessi (Module::CAP).
const CAPS = { barrio_reorder: __( 'Reorder', 'barrio-suite' ) };

// Per la card Contenuti della Bacheca, dalle impostazioni salvate.
export const orderingStats = () => [
	[
		__( 'Lists in manual order', 'barrio-suite' ),
		Object.keys( CARDS ).reduce(
			( count, group ) =>
				count +
				window.barrioAdmin.contentTypes[ group ].filter( ( { name } ) =>
					orderingData.manual[ group ].includes( name )
				).length,
			0
		),
	],
];

// GET e PUT /barrio/v1/ordering/settings, con il Salva della barra.
export const orderingSection = {
	id: 'ordering',
	load: () => apiFetch( { path: '/barrio/v1/ordering/settings' } ),
	value: ( { role_names: names, ...settings } ) => settings,
	save: ( draft ) =>
		apiFetch( {
			path: '/barrio/v1/ordering/settings',
			method: 'PUT',
			data: draft,
		} ),
	Cards: ( { data, draft, set } ) => (
		<>
			<TypeCards
				cards={ CARDS }
				checked={ ( group, name ) => draft[ group ].includes( name ) }
				onChange={ ( group, name, on ) =>
					set( {
						...draft,
						[ group ]: on
							? [ ...draft[ group ], name ]
							: draft[ group ].filter(
									( item ) => item !== name
								),
					} )
				}
			/>
			<Permissions
				description={ __(
					'Who can reorder the lists in manual order. They also need to be able to edit the contents of others, or the terms: authors and contributors cannot. Administrators can always do everything; only administrators change these settings.',
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
