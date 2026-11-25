// La card SEO nella Bacheca (assets/src/dashboard): contenuti indicizzati, quelli senza descrizione e i
// redirect.
import apiFetch from '@wordpress/api-fetch';
import { useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

import {
	ModuleCard,
	errorMessage,
} from '../../../../../assets/src/admin/ui.js';
import { pageUrl, seoData } from './ui.js';

// Anche nella card Moduli di Barrio → Impostazioni, dove il modulo può essere spento.
export const SEO = {
	icon: 'search',
	title: __( 'SEO', 'barrio-suite' ),
	description: __(
		'Titles, descriptions, sitemap and redirects',
		'barrio-suite'
	),
	color: 'linear-gradient(135deg, #1e3a8a, #1d4ed8)',
};

const { canManageSeo } = seoData;
const { canManageOptions } = window.barrioAdmin;

export function SeoCard() {
	const [ stats, setStats ] = useState( null );
	const [ notice, setNotice ] = useState( null );

	useEffect( () => {
		if ( canManageSeo ) {
			apiFetch( { path: '/barrio/v1/seo/stats' } )
				.then( setStats )
				.catch( ( error ) =>
					setNotice( {
						status: 'error',
						message: errorMessage( error ),
					} )
				);
		}
	}, [] );

	// Con il modulo spento seoData è vuoto.
	if ( ! canManageSeo ) {
		return null;
	}
	return (
		<ModuleCard
			module={ SEO }
			notice={ notice }
			stats={
				stats && [
					[ __( 'Indexed contents', 'barrio-suite' ), stats.indexed ],
					[
						__( 'Without description', 'barrio-suite' ),
						stats.without_description,
					],
					[ __( 'Redirects', 'barrio-suite' ), stats.redirects ],
				]
			}
			actions={ [
				[ __( 'Contents', 'barrio-suite' ), pageUrl(), SEO.icon ],
				canManageOptions && [
					__( 'Settings', 'barrio-suite' ),
					pageUrl( { tab: 'settings' } ),
					'admin-generic',
				],
			].filter( Boolean ) }
		/>
	);
}
