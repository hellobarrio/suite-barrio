// SEO → Impostazioni (manage_options): titoli e meta, indicizzazione con la sitemap, llms.txt, redirect e
// permessi, con un solo Salva (SettingsPage.js). L'organizzazione ha una scheda sua (Organization.js).
import apiFetch from '@wordpress/api-fetch';
import { Fragment, useEffect, useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';

import {
	Card,
	Group,
	Notice,
	SectionHead,
	Select,
	Text,
	Toggle,
	errorMessage,
} from '../../../../../assets/src/admin/ui.js';
import { documentTitle } from '../title.js';
import { SettingsPage, editable, main } from './SettingsPage.js';
import { MediaField, seoData } from './ui.js';

const { types, llms, sitemap, robots } = seoData;

// Le capability del modulo, nei Permessi (Module::CAPS).
const CAPS = {
	barrio_manage_seo: __( 'Use Contents and Redirects', 'barrio-suite' ),
	barrio_edit_seo: __(
		'Edit the SEO of the contents they can edit',
		'barrio-suite'
	),
};

const SEPARATORS = [ '—', '–', '-', '|', '·', '•', '»', '/' ];

const LLMS = [
	[ 'include', __( 'Include, with the text', 'barrio-suite' ) ],
	[ 'links', __( 'Links only', 'barrio-suite' ) ],
	[ 'exclude', __( 'Leave out', 'barrio-suite' ) ],
].map( ( [ value, label ] ) => ( { value, label } ) );

// Indicizza e segui di un tipo, di una tassonomia o di un archivio, con il robots che ne risulta e cosa
// mette nella sitemap.
function Robots( { label, value, onChange, inSitemap } ) {
	return (
		<Group
			label={ label }
			help={ [
				`${ value.index ? 'index' : 'noindex' }, ${
					value.follow ? 'follow' : 'nofollow'
				}`,
				inSitemap,
			]
				.filter( Boolean )
				.join( ' · ' ) }
		>
			<div className="barrio-checks">
				<Toggle
					label={ __( 'Show in search results', 'barrio-suite' ) }
					checked={ value.index }
					onChange={ ( index ) => onChange( { ...value, index } ) }
				/>
				<Toggle
					label={ __( 'Follow the links', 'barrio-suite' ) }
					checked={ value.follow }
					onChange={ ( follow ) => onChange( { ...value, follow } ) }
				/>
			</div>
		</Group>
	);
}

const DefaultDescription = ( { value, onChange } ) => (
	<Text
		multiline
		label={ __( 'Default description', 'barrio-suite' ) }
		help={ __(
			'For the pages without a description, an excerpt or a text of their own. Search engines show about 160 characters.',
			'barrio-suite'
		) }
		value={ value }
		onChange={ onChange }
	/>
);

const DefaultImage = ( { value, onChange } ) => (
	<MediaField
		label={ __( 'Default sharing image', 'barrio-suite' ) }
		help={ __(
			'Shown when a page without an image of its own is shared on Facebook, LinkedIn, WhatsApp or X. 1200 × 630 pixels is best.',
			'barrio-suite'
		) }
		value={ value }
		onChange={ onChange }
	/>
);

function SettingsCards( {
	saved,
	seo,
	site,
	texts,
	setSeo,
	setSite,
	setText,
} ) {
	// I numeri della sitemap sono quelli salvati: si ricontano dopo ogni salvataggio.
	const [ counts, setCounts ] = useState( null );
	useEffect( () => {
		apiFetch( { path: '/barrio/v1/seo/settings/sitemap' } )
			.then( setCounts )
			.catch( ( error ) =>
				setCounts( { error: errorMessage( error ) } )
			);
	}, [ saved ] );

	// Un tipo indicizzato e seguito non si salva: una chiave assente vale già sì.
	const setRobots = ( group, name ) => ( value ) => {
		const next = { ...seo.index[ group ], [ name ]: value };
		if ( value.index && value.follow ) {
			delete next[ name ];
		}
		setSeo( 'index' )( { ...seo.index, [ group ]: next } );
	};
	const setArchive = ( name ) => ( value ) =>
		setSeo( 'index' )( {
			...seo.index,
			archives: { ...seo.index.archives, [ name ]: value },
		} );
	// Includi è il valore di una chiave assente.
	const setLlms = ( group, name ) => ( value ) => {
		const next = { ...seo.llms[ group ], [ name ]: value };
		if ( 'include' === value ) {
			delete next[ name ];
		}
		setSeo( 'llms' )( { ...seo.llms, [ group ]: next } );
	};
	// Nell'ordine di CAPS, così il confronto con quelle salvate non dipende dall'ordine dei clic.
	const setCap = ( role, cap ) => ( on ) =>
		setSeo( 'roles' )( {
			...seo.roles,
			[ role ]: Object.keys( CAPS ).filter( ( name ) =>
				name === cap ? on : seo.roles[ role ].includes( name )
			),
		} );
	const robotsOf = ( index, group, name ) => ( {
		index: true,
		follow: true,
		...index[ group ][ name ],
	} );
	// Fuori subito se non è indicizzato; il numero salvato se lo era già.
	const inSitemap = ( group, name ) => {
		if ( ! robotsOf( seo.index, group, name ).index ) {
			return __( 'not in the sitemap', 'barrio-suite' );
		}
		if ( ! robotsOf( saved.seo.index, group, name ).index ) {
			return __( 'in the sitemap after saving', 'barrio-suite' );
		}
		const count = counts?.counts?.[ group ][ name ];
		return (
			undefined !== count &&
			sprintf(
				/* translators: %d: number of addresses. */
				_n(
					'%d address in the sitemap',
					'%d addresses in the sitemap',
					count,
					'barrio-suite'
				),
				count
			)
		);
	};
	const groups = [
		[ 'post_types', __( 'Content types', 'barrio-suite' ) ],
		[ 'taxonomies', __( 'Taxonomies', 'barrio-suite' ) ],
	];

	return (
		<>
			<Card
				className="barrio-wide"
				icon="editor-textcolor"
				title={ __( 'Titles and meta', 'barrio-suite' ) }
				description={ __(
					'How the pages of the site look in search results and when they are shared. Each page can change its own in its SEO panel.',
					'barrio-suite'
				) }
			>
				<Text
					label={ __( 'Site name', 'barrio-suite' ) }
					value={ site.title }
					onChange={ setSite( 'title' ) }
				/>
				<MediaField
					label={ __( 'Site icon', 'barrio-suite' ) }
					help={ __(
						'In the browser tabs and next to the site in Google. Square, at least 512 pixels wide.',
						'barrio-suite'
					) }
					value={ site.site_icon }
					onChange={ setSite( 'site_icon' ) }
				/>
				<Select
					label={ __( 'Title separator', 'barrio-suite' ) }
					value={ seo.title.separator }
					options={ Array.from(
						new Set( [ seo.title.separator, ...SEPARATORS ] )
					).map( ( value ) => ( { value, label: value } ) ) }
					onChange={ ( separator ) =>
						setSeo( 'title' )( { ...seo.title, separator } )
					}
				/>
				<Select
					label={ __( 'Title order', 'barrio-suite' ) }
					value={ seo.title.order }
					options={ [
						{
							value: 'page_site',
							label: __(
								'Page title, then site name',
								'barrio-suite'
							),
						},
						{
							value: 'site_page',
							label: __(
								'Site name, then page title',
								'barrio-suite'
							),
						},
					] }
					onChange={ ( order ) =>
						setSeo( 'title' )( { ...seo.title, order } )
					}
				/>
				<Group label={ __( 'Preview', 'barrio-suite' ) }>
					<div className="barrio-seo-google" aria-hidden="true">
						<span className="barrio-seo-google__site">
							{ site.title }
						</span>
						<span className="barrio-seo-google__url">
							{ new URL( window.location.href ).host }
						</span>
						<span className="barrio-seo-google__title">
							{ documentTitle(
								__( 'Page title', 'barrio-suite' ),
								{ ...seo.title, name: site.title }
							) }
						</span>
						<span className="barrio-seo-google__description">
							{ texts.default_description }
						</span>
					</div>
				</Group>
				<DefaultDescription
					value={ texts.default_description }
					onChange={ setText( 'default_description' ) }
				/>
				<DefaultImage
					value={ seo.default_image }
					onChange={ setSeo( 'default_image' ) }
				/>
			</Card>

			<Card
				className="barrio-wide"
				icon="visibility"
				title={ __( 'Indexing', 'barrio-suite' ) }
				description={ __(
					'What search engines may show and whose links they may follow. Each page can change it in its SEO panel; what is not shown is left out of the sitemap.',
					'barrio-suite'
				) }
			>
				{ groups.map( ( [ group, title ] ) => (
					<Fragment key={ group }>
						<SectionHead title={ title } level={ 3 } />
						{ types
							.filter( ( type ) => type.group === group )
							.map( ( type ) => (
								<Robots
									key={ type.name }
									label={ type.label }
									value={ robotsOf(
										seo.index,
										group,
										type.name
									) }
									onChange={ setRobots( group, type.name ) }
									inSitemap={ inSitemap( group, type.name ) }
								/>
							) ) }
					</Fragment>
				) ) }
				<SectionHead
					title={ __( 'Archives', 'barrio-suite' ) }
					level={ 3 }
				/>
				<Robots
					label={ __( 'Authors', 'barrio-suite' ) }
					value={ seo.index.archives.author }
					onChange={ setArchive( 'author' ) }
					inSitemap={ inSitemap( 'archives', 'author' ) }
				/>
				<Robots
					label={ __( 'Dates', 'barrio-suite' ) }
					value={ seo.index.archives.date }
					onChange={ setArchive( 'date' ) }
				/>
				<Group
					label={ __( 'Sitemap', 'barrio-suite' ) }
					help={ [
						counts?.error ??
							( counts &&
								sprintf(
									/* translators: %d: number of addresses. */
									_n(
										'%d address in all.',
										'%d addresses in all.',
										counts.total,
										'barrio-suite'
									),
									counts.total
								) ),
						sitemap.enabled
							? __(
									'WordPress publishes it with only what is indexed and lists it in robots.txt: submit it in Google Search Console.',
									'barrio-suite'
								)
							: __(
									'WordPress does not publish it while the site asks search engines not to index it (Settings → Reading).',
									'barrio-suite'
								),
					]
						.filter( Boolean )
						.join( ' ' ) }
				>
					<div className="barrio-row">
						{ [ sitemap.enabled && sitemap.url, robots.url ]
							.filter( Boolean )
							.map( ( url ) => (
								<a
									key={ url }
									href={ url }
									target="_blank"
									rel="noreferrer"
								>
									{ url.split( '/' ).pop() }
								</a>
							) ) }
					</div>
				</Group>
				{ robots.file && (
					<Notice
						notice={ {
							status: 'warning',
							message: sprintf(
								/* translators: %s: line to add to robots.txt, with the address of the sitemap. */
								__(
									'The folder of the site has a robots.txt file: the server sends it instead of the one of WordPress, which lists the sitemap. Add the line "%s" to it, or delete it.',
									'barrio-suite'
								),
								`Sitemap: ${ sitemap.url }`
							),
						} }
					/>
				) }
			</Card>

			<Card
				className="barrio-wide"
				icon="media-text"
				title="llms.txt"
				description={ __(
					'A summary of the site for AI assistants such as ChatGPT and Claude, and the full text of the pages in llms-full.txt. What is not indexed is never included.',
					'barrio-suite'
				) }
			>
				{ types.map( ( type ) => (
					<Select
						key={ `${ type.group }/${ type.name }` }
						label={ type.label }
						value={
							seo.llms[ type.group ][ type.name ] ?? 'include'
						}
						options={ LLMS }
						onChange={ setLlms( type.group, type.name ) }
					/>
				) ) }
				<Text
					multiline
					label={ __( 'Introduction', 'barrio-suite' ) }
					help={ __(
						'At the beginning of both files, after the name of the site.',
						'barrio-suite'
					) }
					value={ texts.llms_intro }
					onChange={ setText( 'llms_intro' ) }
				/>
				<Group label={ __( 'Files', 'barrio-suite' ) }>
					<div className="barrio-row">
						{ llms.map( ( url ) => (
							<a
								key={ url }
								href={ url }
								target="_blank"
								rel="noreferrer"
							>
								{ url.split( '/' ).pop() }
							</a>
						) ) }
					</div>
				</Group>
			</Card>

			<Card
				className="barrio-wide"
				icon="randomize"
				title={ __( 'Redirects', 'barrio-suite' ) }
			>
				<Toggle
					label={ __(
						'Create a redirect when an address changes',
						'barrio-suite'
					) }
					help={ __(
						'When the address of a published page or of a category changes, also by moving it under another one, the old address leads to the new one, with the pages below it. Useful above all with a headless frontend: WordPress alone already does it for posts.',
						'barrio-suite'
					) }
					checked={ seo.auto_redirects }
					onChange={ setSeo( 'auto_redirects' ) }
				/>
			</Card>

			<Card
				className="barrio-wide"
				icon="groups"
				title={ __( 'Permissions', 'barrio-suite' ) }
				description={ __(
					'What each role can do in SEO. Administrators can always do everything; only administrators change these settings.',
					'barrio-suite'
				) }
			>
				{ /* Gli amministratori non sono in roles: tutto spuntato e fisso. */ }
				{ Object.entries( saved.seo.role_names ).map(
					( [ role, name ] ) => (
						<Group key={ role } label={ name }>
							<div className="barrio-checks">
								{ Object.entries( CAPS ).map(
									( [ cap, label ] ) => (
										<Toggle
											key={ cap }
											label={ label }
											disabled={ ! seo.roles[ role ] }
											checked={
												! seo.roles[ role ] ||
												seo.roles[ role ].includes(
													cap
												)
											}
											onChange={ setCap( role, cap ) }
										/>
									)
								) }
							</div>
						</Group>
					)
				) }
			</Card>
		</>
	);
}

export const Settings = () => (
	<SettingsPage
		title={ __( 'Settings', 'barrio-suite' ) }
		render={ ( form ) => <SettingsCards { ...form } /> }
	/>
);

// Nella guida alla messa online (assets/src/launch): fatto con la descrizione e l'immagine predefinite, e con
// nome e logo dell'organizzazione (Seo\Module::launch_steps()).
export const seoStep = {
	id: 'seo',
	icon: 'search',
	title: __( 'SEO', 'barrio-suite' ),
	description: __(
		'What search engines and social networks show for the pages without texts and images of their own, and the business behind the site.',
		'barrio-suite'
	),
	load: () => apiFetch( { path: '/barrio/v1/seo/settings' } ),
	value: ( data ) => {
		const settings = editable( data );
		return {
			default_image: settings.default_image,
			organization: settings.organization,
			texts: settings.texts,
		};
	},
	save: ( draft ) =>
		apiFetch( {
			path: '/barrio/v1/seo/settings',
			method: 'PUT',
			data: draft,
		} ),
	Fields: ( { draft, set } ) => {
		const setOrganization = ( name ) => ( value ) =>
			set( {
				...draft,
				organization: { ...draft.organization, [ name ]: value },
			} );
		return (
			<>
				<DefaultDescription
					value={ draft.texts[ main ].default_description }
					onChange={ ( value ) =>
						set( {
							...draft,
							texts: {
								...draft.texts,
								[ main ]: {
									...draft.texts[ main ],
									default_description: value,
								},
							},
						} )
					}
				/>
				<DefaultImage
					value={ draft.default_image }
					onChange={ ( value ) =>
						set( { ...draft, default_image: value } )
					}
				/>
				<Text
					label={ __( 'Name of the business', 'barrio-suite' ) }
					value={ draft.organization.name }
					onChange={ setOrganization( 'name' ) }
				/>
				<MediaField
					label={ __( 'Logo', 'barrio-suite' ) }
					value={ draft.organization.logo }
					onChange={ setOrganization( 'logo' ) }
				/>
			</>
		);
	},
};
