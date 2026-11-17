// La pagina SEO, con le schede Contenuti, Redirect (tab=redirects), e Organizzazione (tab=organization) e
// Impostazioni (tab=settings) solo per manage_options. Ogni vista è un URL: link normali, niente router.
import { __ } from '@wordpress/i18n';

import { Contents } from './Contents.js';
import { Organization } from './Organization.js';
import { Redirects } from './Redirects.js';
import { Settings } from './Settings.js';
import { pageUrl } from './ui.js';

const { canManageOptions } = window.barrioAdmin;

function SeoPage() {
	const tab = new URLSearchParams( window.location.search ).get( 'tab' );
	const tabs = [
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
	const [ current, , Content ] =
		tabs.find( ( [ value ] ) => value === tab ) ?? tabs[ 0 ];
	return (
		<>
			<nav
				className="barrio-nav"
				aria-label={ __( 'Sections', 'barrio-suite' ) }
			>
				{ tabs.map( ( [ value, label ] ) => (
					<a
						key={ value }
						href={ pageUrl( value ? { tab: value } : {} ) }
						aria-current={ value === current ? 'page' : undefined }
						// Sugli schermi stretti le schede scorrono: quella aperta resta in vista.
						ref={
							value === current
								? ( link ) =>
										link?.scrollIntoView( {
											block: 'nearest',
											inline: 'nearest',
										} )
								: undefined
						}
					>
						{ label }
					</a>
				) ) }
			</nav>
			<Content />
		</>
	);
}

// Nella shell (assets/src/admin/index.js): il nome e la descrizione nell'intestazione.
export const seoPage = {
	title: __( 'SEO', 'barrio-suite' ),
	description: __(
		'Titles, descriptions, indexing and redirects of the site.',
		'barrio-suite'
	),
	page: <SeoPage />,
};
