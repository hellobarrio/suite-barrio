// La scheda Duplicazione di Barrio → Editor (modules/duplicate/src/Module.php): su quali tipi di contenuto e
// tassonomie compare Duplica. E i suoi numeri nella card Editor della Bacheca.
import apiFetch from '@wordpress/api-fetch';
import { __, sprintf } from '@wordpress/i18n';

import { Card, Toggle } from '../../../../assets/src/admin/ui.js';

// Anche nella card Moduli di Barrio → Impostazioni.
export const DUPLICATE = {
	title: __( 'Duplication', 'barrio-suite' ),
	description: __(
		'A Duplicate link for contents and terms, in the lists and in the editor.',
		'barrio-suite'
	),
};

// Module::admin_data(): i tipi e le tassonomie con l'interfaccia, e quelli spenti. Con il modulo spento manca.
export const duplicateData = window.barrioAdmin.duplicate;

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

const enabled = ( group, disabled ) =>
	duplicateData[ group ].filter(
		( { name } ) => ! disabled.includes( name )
	);

// Per la card Editor della Bacheca, dalle impostazioni salvate.
export const duplicateStats = () => [
	[
		__( 'Content types with Duplicate', 'barrio-suite' ),
		enabled( 'post_types', duplicateData.disabled.post_types ).length,
	],
	[
		__( 'Taxonomies with Duplicate', 'barrio-suite' ),
		enabled( 'taxonomies', duplicateData.disabled.taxonomies ).length,
	],
];

// GET e PUT /barrio/v1/duplicate, con il Salva della barra (general/General.js). Si salvano i tipi spenti.
export const duplicateSection = {
	id: 'duplicate',
	load: () => apiFetch( { path: '/barrio/v1/duplicate' } ),
	value: ( settings ) => settings,
	save: ( draft ) =>
		apiFetch( {
			path: '/barrio/v1/duplicate',
			method: 'PUT',
			data: draft,
		} ),
	Cards: ( { draft, set } ) =>
		Object.entries( CARDS ).map( ( [ group, card ] ) => (
			<Card key={ group } className="barrio-wide" { ...card }>
				{ duplicateData[ group ].map( ( type ) => (
					<Toggle
						key={ type.name }
						label={ type.label }
						help={
							type.types &&
							sprintf(
								/* translators: %s: content types, separated by commas. */
								__( 'Used by %s', 'barrio-suite' ),
								type.types.join( ', ' )
							)
						}
						checked={ ! draft[ group ].includes( type.name ) }
						onChange={ ( on ) =>
							set( {
								...draft,
								[ group ]: on
									? draft[ group ].filter(
											( name ) => name !== type.name
										)
									: [ ...draft[ group ], type.name ],
							} )
						}
					/>
				) ) }
			</Card>
		) ),
};
