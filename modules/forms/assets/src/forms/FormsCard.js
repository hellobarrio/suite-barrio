// La card dei form nella Bacheca (assets/src/dashboard): form attivi, registrazioni e quelle da leggere.
import apiFetch from '@wordpress/api-fetch';
import { useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

import {
	ModuleCard,
	errorMessage,
} from '../../../../../assets/src/admin/ui.js';
import { entriesUrl, formsData, pageUrl } from '../ui.js';

// Anche nella card Moduli di Barrio → Impostazioni, dove il modulo può essere spento.
export const FORMS = {
	icon: 'feedback',
	title: __( 'Forms', 'barrio-suite' ),
	description: __(
		'Contact forms with a drag & drop builder',
		'barrio-suite'
	),
	color: 'linear-gradient(135deg, #065f46, #047857)',
};

const { canManageForms, canManageEntries } = formsData;

export function FormsCard() {
	const [ forms, setForms ] = useState( null );
	const [ notice, setNotice ] = useState( null );
	const visible = canManageForms || canManageEntries;

	useEffect( () => {
		if ( visible ) {
			apiFetch( { path: '/barrio/v1/forms' } )
				.then( setForms )
				.catch( ( error ) =>
					setNotice( {
						status: 'error',
						message: errorMessage( error ),
					} )
				);
		}
	}, [ visible ] );

	// Con il modulo spento formsData è vuoto.
	if ( ! visible ) {
		return null;
	}
	const sum = ( key ) => forms.reduce( ( n, form ) => n + form[ key ], 0 );
	return (
		<ModuleCard
			module={ FORMS }
			notice={ notice }
			stats={
				forms && [
					[
						__( 'Active forms', 'barrio-suite' ),
						forms.filter( ( form ) => 'active' === form.status )
							.length,
					],
					[ __( 'Entries', 'barrio-suite' ), sum( 'entries' ) ],
					[ __( 'Unread entries', 'barrio-suite' ), sum( 'unread' ) ],
				]
			}
			actions={ [
				canManageForms && [
					__( 'Manage forms', 'barrio-suite' ),
					pageUrl(),
				],
				canManageEntries && [
					__( 'Entries', 'barrio-suite' ),
					entriesUrl(),
				],
			].filter( Boolean ) }
		/>
	);
}
