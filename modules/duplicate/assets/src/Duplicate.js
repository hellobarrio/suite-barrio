// La scheda Duplicazione di Barrio → Editor (modules/duplicate/src/Module.php): su quali tipi di contenuto e
// tassonomie compare Duplica.
import apiFetch from '@wordpress/api-fetch';
import { __ } from '@wordpress/i18n';

import { Card, Group, Toggle } from '../../../../assets/src/admin/ui.js';

// Anche nella card Moduli di Barrio → Impostazioni.
export const DUPLICATE = {
	title: __( 'Duplication', 'barrio-suite' ),
	description: __(
		'A Duplicate link for contents and terms, in the lists and in the editor.',
		'barrio-suite'
	),
};

// Module::admin_data(): i tipi e le tassonomie con l'interfaccia. Con il modulo spento manca.
export const duplicateData = window.barrioAdmin.duplicate;

const GROUPS = {
	post_types: __( 'Content types', 'barrio-suite' ),
	taxonomies: __( 'Taxonomies', 'barrio-suite' ),
};

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
	Cards: ( { draft, set } ) => (
		<Card
			className="barrio-wide"
			icon="admin-page"
			title={ DUPLICATE.title }
			description={ __(
				'Duplicate appears in the lists, in the bulk actions and in the editor of these types. The copy is a draft of whoever duplicates it, with the content, the custom fields and the terms of the original.',
				'barrio-suite'
			) }
		>
			{ Object.entries( GROUPS ).map( ( [ group, label ] ) => (
				<Group key={ group } label={ label }>
					<div className="barrio-checks">
						{ duplicateData[ group ].map( ( type ) => (
							<Toggle
								key={ type.name }
								label={ type.label }
								checked={
									! draft[ group ].includes( type.name )
								}
								onChange={ ( on ) =>
									set( {
										...draft,
										[ group ]: on
											? draft[ group ].filter(
													( name ) =>
														name !== type.name
												)
											: [ ...draft[ group ], type.name ],
									} )
								}
							/>
						) ) }
					</div>
				</Group>
			) ) }
		</Card>
	),
};
