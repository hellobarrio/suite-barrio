// La pagina SEO, con le schede Contenuti, Redirect (tab=redirects), e Organizzazione (tab=organization) e
// Impostazioni (tab=settings) solo per manage_options.
import { __ } from '@wordpress/i18n';

import { Tabs } from '../../../../../assets/src/admin/ui.js';
import { Contents } from './Contents.js';
import { Organization } from './Organization.js';
import { Redirects } from './Redirects.js';
import { Settings } from './Settings.js';

const { canManageOptions } = window.barrioAdmin;

const TABS = [
	[ '', __( 'Contents', 'barrio-suite' ), Contents ],
	[ 'redirects', __( 'Redirects', 'barrio-suite' ), Redirects ],
	canManageOptions && [
		'organization',
		__( 'Organization', 'barrio-suite' ),
		Organization,
	],
	canManageOptions && [
		'settings',
		__( 'Settings', 'barrio-suite' ),
		Settings,
	],
].filter( Boolean );

// Nella shell (assets/src/admin/index.js): il nome e la descrizione nell'intestazione.
export const seoPage = {
	title: __( 'SEO', 'barrio-suite' ),
	description: __(
		'Titles, descriptions, indexing and redirects of the site.',
		'barrio-suite'
	),
	page: <Tabs page="barrio-seo" tabs={ TABS } />,
};
