// La pagina Barrio → Banner (modules/banners/src/Module.php): l'elenco o, con ?banner=ID (o new), l'editor con
// l'anteprima; agli amministratori la scheda Impostazioni con i Permessi. E la card della Bacheca. I testi si traducono
// come nel builder dei form (TextIn): nelle altre lingue un testo vuoto vale quello della lingua principale.
import apiFetch from '@wordpress/api-fetch';
import { useEffect, useRef, useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';

import {
	initialQuery,
	localQuery,
} from '../../../../assets/src/admin/query.js';
import {
	Bar,
	Card,
	Fixed,
	Icon,
	Lang,
	LangNotice,
	LangPicker,
	Loading,
	Lucide,
	Menu,
	ModuleCard,
	Notice,
	Permissions,
	Select,
	Table,
	Text,
	Toggle,
	errorMessage,
	langOption,
	useUnsavedWarning,
} from '../../../../assets/src/admin/ui.js';
import {
	PeriodCard,
	RULES,
	STATUS,
	WhereCard,
	WhoCard,
	periodText,
	whereText,
} from '../../../../assets/src/admin/visibility.js';
import {
	setTranslation,
	textIn,
} from '../../../forms/assets/src/builder/model.js';
import { TextIn } from '../../../forms/assets/src/builder/Texts.js';

// Anche nella card Moduli di Barrio → Impostazioni.
export const BANNERS = {
	icon: 'megaphone',
	title: __( 'Banners', 'barrio-suite' ),
	description: __(
		'Announcements at the top or bottom of pages',
		'barrio-suite'
	),
	color: 'linear-gradient(135deg, #164e63, #0e7490)',
};

// Module::admin_data(), a chi gestisce i banner. Con il modulo spento manca.
const bannersData = window.barrioAdmin.banners;

const { languages } = window.barrioAdmin;
const [ main, ...others ] = languages;

// La capability del modulo, nei Permessi (Module::CAP).
const CAPS = { barrio_manage_banners: __( 'Manage', 'barrio-suite' ) };

const pageUrl = ( params = {} ) =>
	'?' + new URLSearchParams( { page: 'barrio-banners', ...params } );

const blank = () => ( {
	is_enabled: true,
	starts_at: null,
	ends_at: null,
	settings: {
		position: 'top',
		scroll: false,
		closable: true,
		new_tab: false,
		colors: { background: '', text: '' },
		...RULES,
	},
	i18n: { [ main ]: { text: '', link_url: null } },
} );

// Al caricamento le altre lingue tengono solo i testi diversi da quelli della lingua principale.
const trim = ( banner ) =>
	others.reduce(
		( item, lang ) =>
			Object.keys( item.i18n[ lang ] ?? {} ).reduce(
				( next, key ) =>
					next.i18n[ lang ]?.[ key ] === next.i18n[ main ]?.[ key ]
						? setTranslation( next, lang, key, '' )
						: next,
				item
			),
		banner
	);

// Al salvataggio ogni lingua tradotta ha la riga completa, come vuole il database.
const fill = ( banner ) => ( {
	...banner,
	i18n: Object.fromEntries(
		Object.keys( banner.i18n ).map( ( lang ) => [
			lang,
			textIn( banner, lang, main ),
		] )
	),
} );

const textOf = ( banner ) =>
	( banner.i18n[ main ] ?? Object.values( banner.i18n )[ 0 ] )?.text ?? '';

// Il più recente per primo. status '' = tutti; lang '' = in qualsiasi lingua.
const INITIAL = { ...initialQuery( 'id', 'desc' ), status: '', lang: '' };

function BannerList() {
	const [ banners, setBanners ] = useState( null );
	const [ query, setQuery ] = useState( INITIAL );
	const [ notice, setNotice ] = useState( null );

	const fail = ( error ) =>
		setNotice( { status: 'error', message: errorMessage( error ) } );
	// Senza return: un effetto non deve restituire la Promise.
	const load = () => {
		apiFetch( { path: '/barrio/v1/banners' } )
			.then( setBanners )
			.catch( fail );
	};
	useEffect( load, [] );

	const act = ( request, message ) => {
		setNotice( null );
		apiFetch( request )
			.then( () => setNotice( { status: 'success', message } ) )
			.catch( fail )
			.finally( load );
	};
	const bulk = ( action, ids, message ) =>
		act(
			{
				path: '/barrio/v1/banners/bulk',
				method: 'POST',
				data: { action, ids },
			},
			message
		);
	const turn = ( on, ids ) =>
		bulk(
			on ? 'enable' : 'disable',
			ids,
			on
				? sprintf(
						/* translators: %d: number of banners turned on. */
						_n(
							'%d banner turned on.',
							'%d banners turned on.',
							ids.length,
							'barrio-suite'
						),
						ids.length
					)
				: sprintf(
						/* translators: %d: number of banners turned off. */
						_n(
							'%d banner turned off.',
							'%d banners turned off.',
							ids.length,
							'barrio-suite'
						),
						ids.length
					)
		);
	const remove = ( ids ) => {
		if (
			// eslint-disable-next-line no-alert -- conferma prima di un'azione che non si annulla.
			window.confirm(
				sprintf(
					/* translators: %d: number of banners to delete. */
					_n(
						'Delete %d banner permanently?',
						'Delete %d banners permanently?',
						ids.length,
						'barrio-suite'
					),
					ids.length
				)
			)
		) {
			bulk(
				'delete',
				ids,
				sprintf(
					/* translators: %d: number of banners deleted. */
					_n(
						'%d banner deleted.',
						'%d banners deleted.',
						ids.length,
						'barrio-suite'
					),
					ids.length
				)
			);
		}
	};

	if ( ! banners ) {
		return notice ? <Notice notice={ notice } /> : <Loading />;
	}
	const all = banners.map( ( banner ) => ( {
		...banner,
		text: textOf( banner ),
		where: whereText(
			banner.settings.where,
			banner.labels,
			bannersData.types
		),
		period: periodText( banner.starts_at, banner.ends_at ),
		// Per ordinare: gli stati nell'ordine di STATUS, il periodo dall'inizio.
		order: Object.keys( STATUS ).indexOf( banner.status ),
		start: banner.starts_at ?? '',
		translations: others.filter( ( lang ) => banner.i18n[ lang ] ),
	} ) );
	const count = ( status ) =>
		all.filter( ( banner ) => banner.status === status ).length;
	const visible = all.filter(
		( banner ) => ! query.status || banner.status === query.status
	);
	// Con la lingua principale quelli non tradotti, con un'altra quelli tradotti in quella.
	const inLanguage = ( lang ) =>
		visible.filter( ( banner ) =>
			lang === main
				? ! banner.translations.length
				: banner.translations.includes( lang )
		);
	const { rows, total } = localQuery(
		( query.lang ? inLanguage( query.lang ) : visible ).map(
			( banner ) => ( {
				...banner,
				count: banner.translations.length,
			} )
		),
		query,
		[ 'text', 'where', 'period' ]
	);

	const columns = [
		{
			key: 'text',
			label: __( 'Text', 'barrio-suite' ),
			sort: 'text',
			render: ( banner ) => (
				<a
					className="barrio-table__title"
					href={ pageUrl( { banner: banner.id } ) }
				>
					{ banner.text }
				</a>
			),
		},
		{
			key: 'status',
			label: __( 'Status', 'barrio-suite' ),
			sort: 'order',
			hideable: true,
			render: ( banner ) => (
				<span
					className={ `barrio-badge ${ STATUS[ banner.status ][ 1 ] }` }
				>
					{ STATUS[ banner.status ][ 0 ] }
				</span>
			),
		},
		{
			key: 'where',
			label: __( 'Where', 'barrio-suite' ),
			sort: 'where',
			hideable: true,
		},
		{
			key: 'period',
			label: __( 'Period', 'barrio-suite' ),
			sort: 'start',
			hideable: true,
		},
		others.length > 0 && {
			key: 'translations',
			label: __( 'Translations', 'barrio-suite' ),
			sort: 'count',
			hideable: true,
			render: ( banner ) =>
				banner.translations.length ? (
					<span className="barrio-row is-nowrap">
						{ banner.translations.map( ( lang ) => (
							<span key={ lang } className="barrio-badge">
								<Lang code={ lang } />
							</span>
						) ) }
					</span>
				) : (
					'—'
				),
		},
		{
			key: 'actions',
			label: (
				<span className="screen-reader-text">
					{ __( 'Actions', 'barrio-suite' ) }
				</span>
			),
			className: 'is-narrow is-end',
			render: ( banner ) => (
				<Menu
					label={ sprintf(
						/* translators: %s: name of the row: a form, a page, a post or a term. */
						__( 'Actions for "%s"', 'barrio-suite' ),
						banner.text
					) }
					items={ [
						{
							label: __( 'Edit', 'barrio-suite' ),
							icon: 'edit',
							href: pageUrl( { banner: banner.id } ),
						},
						{
							label: __( 'Duplicate', 'barrio-suite' ),
							icon: 'admin-page',
							onClick: () =>
								act(
									{
										path: `/barrio/v1/banners/${ banner.id }/duplicate`,
										method: 'POST',
									},
									__(
										'Banner duplicated: the copy is off.',
										'barrio-suite'
									)
								),
						},
						banner.is_enabled
							? {
									label: __( 'Turn off', 'barrio-suite' ),
									icon: 'hidden',
									onClick: () => turn( false, [ banner.id ] ),
								}
							: {
									label: __( 'Turn on', 'barrio-suite' ),
									icon: 'visibility',
									onClick: () => turn( true, [ banner.id ] ),
								},
						{
							label: __( 'Delete', 'barrio-suite' ),
							icon: 'trash',
							danger: true,
							onClick: () => remove( [ banner.id ] ),
						},
					] }
				/>
			),
		},
	];

	return (
		<div className="barrio-stack">
			<Notice notice={ notice } onClose={ () => setNotice( null ) } />
			<Table
				columns={ columns }
				rows={ rows }
				total={ total }
				query={ query }
				initial={ INITIAL }
				onQuery={ setQuery }
				title={ __( 'Banners', 'barrio-suite' ) }
				search={ __( 'Search banners', 'barrio-suite' ) }
				empty={ __( 'No banners found.', 'barrio-suite' ) }
				filters={ [
					{
						key: 'status',
						label: __( 'Status', 'barrio-suite' ),
						options: [
							{
								value: '',
								label: __( 'All banners', 'barrio-suite' ),
								count: all.length,
							},
							...Object.entries( STATUS ).map(
								( [ value, [ label ] ] ) => ( {
									value,
									label,
									count: count( value ),
								} )
							),
						],
					},
					others.length > 0 && {
						key: 'lang',
						label: __( 'Language', 'barrio-suite' ),
						options: [
							{
								value: '',
								label: __( 'All languages', 'barrio-suite' ),
								count: visible.length,
							},
							...languages.map( ( lang ) => ( {
								...langOption( lang ),
								count: inLanguage( lang ).length,
							} ) ),
						],
					},
				] }
				bulk={ ( ids ) => [
					{
						label: __( 'Turn on', 'barrio-suite' ),
						icon: 'visibility',
						onClick: () => turn( true, ids ),
					},
					{
						label: __( 'Turn off', 'barrio-suite' ),
						icon: 'hidden',
						onClick: () => turn( false, ids ),
					},
					{
						label: __( 'Delete', 'barrio-suite' ),
						icon: 'trash',
						danger: true,
						onClick: () => remove( ids ),
					},
				] }
				toolbar={
					<a
						className="barrio-button is-primary"
						href={ pageUrl( { banner: 'new' } ) }
					>
						<Icon name="plus-alt2" />
						<span className="barrio-button__text">
							{ __( 'New banner', 'barrio-suite' ) }
						</span>
					</a>
				}
			/>
		</div>
	);
}

const CLOSE = (
	<svg
		viewBox="0 0 24 24"
		width="20"
		height="20"
		fill="none"
		stroke="currentColor"
		strokeWidth="2"
		strokeLinecap="round"
		aria-hidden="true"
	>
		<path d="M18 6 6 18M6 6l12 12" />
	</svg>
);

// Come nel sito, con lo stile e lo script del sito (Module::menu()); in fondo non resta fisso sullo schermo.
function Preview( { banner, lang } ) {
	const ref = useRef();
	const { settings } = banner;
	const text = textIn( banner, lang, main ).text;
	// Un banner nuovo a ogni cambio di ciò che lo script misura: lo script non sa di React.
	const key = JSON.stringify( [ text, settings.scroll, settings.closable ] );
	useEffect( () => {
		let current = true;
		let stop;
		import( /* webpackIgnore: true */ bannersData.script ).then(
			( { start } ) => {
				if ( current ) {
					stop = start( ref.current );
				}
			}
		);
		return () => {
			current = false;
			stop?.();
		};
	}, [ key ] );
	return (
		<Card icon="visibility" title={ __( 'Preview', 'barrio-suite' ) }>
			<div className="barrio-banner-preview">
				<div
					key={ key }
					ref={ ref }
					className={ `barrio-banner is-${ settings.position }${
						settings.scroll ? ' is-scroll' : ''
					}` }
					style={
						settings.colors.background
							? {
									'--barrio-banner-background':
										settings.colors.background,
									'--barrio-banner-color':
										settings.colors.text,
								}
							: {}
					}
				>
					<div className="barrio-banner__body">
						<span className="barrio-banner__track">
							<span className="barrio-banner__text">
								{ text ||
									__(
										'The text of the banner',
										'barrio-suite'
									) }
							</span>
						</span>
					</div>
					{ settings.closable && (
						<span className="barrio-banner__close">{ CLOSE }</span>
					) }
				</div>
			</div>
			<p className="barrio-field__help">
				{ 'top' === settings.position
					? __( 'At the top of every page.', 'barrio-suite' )
					: __(
							'Fixed at the bottom of the screen while scrolling.',
							'barrio-suite'
						) }
			</p>
		</Card>
	);
}

function BannerEditor( { id } ) {
	const [ banner, setBanner ] = useState( id ? null : blank );
	const [ saved, setSaved ] = useState( banner );
	const [ labels, setLabels ] = useState( { posts: {}, terms: {} } );
	const [ lang, setLang ] = useState( main );
	const [ notice, setNotice ] = useState( null );
	const [ busy, setBusy ] = useState( false );

	const fail = ( error ) =>
		setNotice( { status: 'error', message: errorMessage( error ) } );
	const load = ( { labels: names, status, ...data } ) => {
		const loaded = trim( data );
		setSaved( loaded );
		setBanner( loaded );
		setLabels( names );
	};
	useEffect( () => {
		if ( id ) {
			apiFetch( { path: `/barrio/v1/banners/${ id }` } )
				.then( load )
				.catch( fail );
		}
	}, [ id ] );

	const dirty = JSON.stringify( banner ) !== JSON.stringify( saved );
	useUnsavedWarning( dirty );

	if ( ! banner ) {
		return notice ? <Notice notice={ notice } /> : <Loading />;
	}
	const { settings } = banner;
	const set = ( changes ) =>
		setBanner( { ...banner, settings: { ...settings, ...changes } } );
	const save = () => {
		if ( ! banner.i18n[ main ]?.text.trim() ) {
			setNotice( {
				status: 'error',
				message: __( 'Write the text of the banner.', 'barrio-suite' ),
			} );
			return;
		}
		setBusy( true );
		setNotice( null );
		const { id: current, ...data } = fill( banner );
		apiFetch(
			current
				? {
						path: `/barrio/v1/banners/${ current }`,
						method: 'PUT',
						data,
					}
				: { path: '/barrio/v1/banners', method: 'POST', data }
		)
			.then( ( result ) => {
				load( result );
				if ( ! current ) {
					window.history.replaceState(
						null,
						'',
						pageUrl( { banner: result.id } )
					);
				}
				setNotice( {
					status: 'success',
					message: __( 'Banner saved.', 'barrio-suite' ),
				} );
			} )
			.catch( fail )
			.finally( () => setBusy( false ) );
	};

	return (
		<div className="barrio-stack">
			<Bar
				title={
					<>
						<a
							className="barrio-icon-button"
							href={ pageUrl() }
							aria-label={ __( 'All banners', 'barrio-suite' ) }
						>
							<Icon name="arrow-left-alt2" />
						</a>
						<h2 className="barrio-bar__title">
							{ banner.id
								? __( 'Edit banner', 'barrio-suite' )
								: __( 'New banner', 'barrio-suite' ) }
						</h2>
					</>
				}
			>
				<LangPicker value={ lang } onChange={ setLang } />
				<button
					type="button"
					className="barrio-button is-primary"
					disabled={ busy || ( banner.id && ! dirty ) }
					onClick={ save }
				>
					<Lucide name="save" />
					<span className="barrio-button__text">
						{ busy
							? __( 'Saving…', 'barrio-suite' )
							: __( 'Save', 'barrio-suite' ) }
					</span>
				</button>
			</Bar>
			<Notice notice={ notice } onClose={ () => setNotice( null ) } />
			<LangNotice lang={ lang } />
			<div className="barrio-fields">
				<div>
					<Card icon="text" title={ __( 'Content', 'barrio-suite' ) }>
						<TextIn
							item={ banner }
							lang={ lang }
							name="text"
							label={ __( 'Text', 'barrio-suite' ) }
							maxLength={ 255 }
							onChange={ setBanner }
						/>
						<TextIn
							item={ banner }
							lang={ lang }
							name="link_url"
							optional
							label={ __( 'Link', 'barrio-suite' ) }
							help={ __(
								'Optional: the whole banner leads here. A path like /contacts/ or a full address.',
								'barrio-suite'
							) }
							spellCheck={ false }
							onChange={ setBanner }
						/>
						<Fixed lang={ lang }>
							<Toggle
								label={ __(
									'Open the link in a new tab',
									'barrio-suite'
								) }
								checked={ settings.new_tab }
								onChange={ ( on ) => set( { new_tab: on } ) }
							/>
						</Fixed>
					</Card>
					<Card
						icon="admin-appearance"
						title={ __( 'Appearance', 'barrio-suite' ) }
					>
						<Fixed lang={ lang }>
							<Select
								label={ __( 'Position', 'barrio-suite' ) }
								value={ settings.position }
								options={ [
									{
										value: 'top',
										label: __(
											'At the top of the page',
											'barrio-suite'
										),
									},
									{
										value: 'bottom',
										label: __(
											'Fixed at the bottom of the screen',
											'barrio-suite'
										),
									},
								] }
								onChange={ ( position ) => set( { position } ) }
							/>
							<Toggle
								label={ __( 'Scrolling text', 'barrio-suite' ) }
								help={ __(
									'It stops under the mouse, on focus and while touched, and stays still for those who chose to reduce motion.',
									'barrio-suite'
								) }
								checked={ settings.scroll }
								onChange={ ( scroll ) => set( { scroll } ) }
							/>
							<Toggle
								label={ __( 'Can be closed', 'barrio-suite' ) }
								help={ __(
									'With an X on the side.',
									'barrio-suite'
								) }
								checked={ settings.closable }
								onChange={ ( closable ) => set( { closable } ) }
							/>
							{ settings.closable && (
								<Text
									type="number"
									min={ 0 }
									max={ 365 }
									label={ __(
										'Back after (days)',
										'barrio-suite'
									) }
									help={ __(
										'Once closed, it shows up again for the same visitor after these days; with 0, on the next page.',
										'barrio-suite'
									) }
									value={ String( settings.days ) }
									onChange={ ( value ) =>
										set( {
											days: Math.min(
												365,
												Math.max(
													0,
													parseInt( value, 10 ) || 0
												)
											),
										} )
									}
								/>
							) }
							<Toggle
								label={ __( 'Own colors', 'barrio-suite' ) }
								help={ __(
									'Without, those of the banner style, which the theme can change.',
									'barrio-suite'
								) }
								checked={ !! settings.colors.background }
								onChange={ ( on ) =>
									set( {
										colors: on
											? {
													background: '#1e1e1e',
													text: '#ffffff',
												}
											: { background: '', text: '' },
									} )
								}
							/>
							{ !! settings.colors.background && (
								<div className="barrio-row is-fill">
									<Text
										type="color"
										label={ __(
											'Background',
											'barrio-suite'
										) }
										value={ settings.colors.background }
										onChange={ ( background ) =>
											set( {
												colors: {
													...settings.colors,
													background,
												},
											} )
										}
									/>
									<Text
										type="color"
										label={ __( 'Text', 'barrio-suite' ) }
										value={ settings.colors.text }
										onChange={ ( color ) =>
											set( {
												colors: {
													...settings.colors,
													text: color,
												},
											} )
										}
									/>
								</div>
							) }
						</Fixed>
					</Card>
					<Fixed lang={ lang }>
						<WhereCard
							where={ settings.where }
							labels={ labels }
							types={ bannersData.types }
							onChange={ ( where, next ) => {
								set( { where } );
								setLabels( next );
							} }
						/>
						<WhoCard
							who={ settings.who }
							onChange={ ( who ) => set( { who } ) }
						/>
						<PeriodCard
							enabled={ banner.is_enabled }
							start={ banner.starts_at }
							end={ banner.ends_at }
							onChange={ ( changes ) =>
								setBanner( { ...banner, ...changes } )
							}
						/>
					</Fixed>
				</div>
				<div className="barrio-panel">
					<Preview banner={ banner } lang={ lang } />
				</div>
			</div>
		</div>
	);
}

// La scheda Banner: l'elenco o l'editor, secondo ?banner=.
export function BannersTab() {
	const param = new URLSearchParams( window.location.search ).get( 'banner' );
	if ( 'new' === param ) {
		return <BannerEditor />;
	}
	return /^[1-9]\d*$/.test( param ?? '' ) ? (
		<BannerEditor id={ Number( param ) } />
	) : (
		<BannerList />
	);
}

// La scheda Impostazioni, agli amministratori: i Permessi.
export const bannersSettingsSection = {
	id: 'banners-settings',
	load: () => apiFetch( { path: '/barrio/v1/banners/settings' } ),
	value: ( data ) => data.roles,
	save: ( roles ) =>
		apiFetch( {
			path: '/barrio/v1/banners/settings',
			method: 'PUT',
			data: { roles },
		} ),
	Cards: ( { data, draft, set } ) => (
		<Permissions
			description={ __(
				'Who can create, change and delete the banners. Administrators can always do everything; only administrators change these settings.',
				'barrio-suite'
			) }
			caps={ CAPS }
			names={ data.role_names }
			roles={ draft }
			onChange={ set }
		/>
	),
};

// Nella Bacheca: quelli attivi ora, i programmati e tutti.
export function BannersCard() {
	const [ banners, setBanners ] = useState( null );
	const [ notice, setNotice ] = useState( null );
	useEffect( () => {
		if ( bannersData ) {
			apiFetch( { path: '/barrio/v1/banners' } )
				.then( setBanners )
				.catch( ( error ) =>
					setNotice( {
						status: 'error',
						message: errorMessage( error ),
					} )
				);
		}
	}, [] );
	if ( ! bannersData ) {
		return null;
	}
	const count = ( status ) =>
		banners.filter( ( banner ) => banner.status === status ).length;
	return (
		<ModuleCard
			module={ BANNERS }
			notice={ notice }
			stats={
				banners && [
					[ __( 'Active now', 'barrio-suite' ), count( 'active' ) ],
					[ __( 'Scheduled', 'barrio-suite' ), count( 'scheduled' ) ],
					[ __( 'All banners', 'barrio-suite' ), banners.length ],
				]
			}
			actions={ [
				[ __( 'Manage banners', 'barrio-suite' ), pageUrl() ],
				[
					__( 'New banner', 'barrio-suite' ),
					pageUrl( { banner: 'new' } ),
				],
			] }
		/>
	);
}
