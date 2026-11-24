// La pagina Barrio → Banner (modules/banners/src/Module.php): l'elenco o, con ?banner=ID (o new), l'editor con
// l'anteprima; agli amministratori la scheda Impostazioni con i Permessi. E la card della Bacheca. I testi si traducono
// come nel builder dei form (TextIn): nelle altre lingue un testo vuoto vale quello della lingua principale.
import apiFetch from '@wordpress/api-fetch';
import { TabPanel } from '@wordpress/components';
import { useEffect, useRef, useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';

import {
	LinkField,
	PeriodCard,
	PeriodList,
	fillTexts,
	trimTexts,
} from '../../../../assets/src/admin/period.js';
import {
	Bar,
	Card,
	Fixed,
	HowTo,
	Icon,
	LangNotice,
	LangPicker,
	Loading,
	ModuleCard,
	Notice,
	Permissions,
	SaveButton,
	errorMessage,
	useUnsavedWarning,
} from '../../../../assets/src/admin/ui.js';
import { textIn } from '../../../../assets/src/admin/i18n.js';
import { TextIn } from '../../../../assets/src/admin/texts.js';

// Anche nella card Moduli di Barrio → Impostazioni.
export const BANNERS = {
	icon: 'megaphone',
	title: __( 'Banners', 'barrio-suite' ),
	description: __( 'Announcements at the top of pages', 'barrio-suite' ),
	color: 'linear-gradient(135deg, #164e63, #0e7490)',
};

// Module::admin_data(), a chi gestisce i banner. Con il modulo spento manca.
const bannersData = window.barrioAdmin.banners;

const { languages } = window.barrioAdmin;
const [ main ] = languages;

// La capability del modulo, nei Permessi (Module::CAP).
const CAPS = { barrio_manage_banners: __( 'Manage', 'barrio-suite' ) };

const pageUrl = ( params = {} ) =>
	'?' + new URLSearchParams( { page: 'barrio-banners', ...params } );

const blank = () => ( {
	is_enabled: true,
	new_tab: false,
	starts_at: null,
	ends_at: null,
	i18n: { [ main ]: { text: '', link_url: null } },
} );

const textOf = ( banner ) =>
	( banner.i18n[ main ] ?? Object.values( banner.i18n )[ 0 ] )?.text ?? '';

// I testi dell'elenco (PeriodList).
const LIST = {
	column: __( 'Text', 'barrio-suite' ),
	title: __( 'Banners', 'barrio-suite' ),
	search: __( 'Search banners', 'barrio-suite' ),
	empty: __( 'No banners found.', 'barrio-suite' ),
	all: __( 'All banners', 'barrio-suite' ),
	add: __( 'New banner', 'barrio-suite' ),
	duplicated: __( 'Banner duplicated: the copy is off.', 'barrio-suite' ),
	on: ( n ) =>
		sprintf(
			/* translators: %d: number of banners turned on. */
			_n(
				'%d banner turned on.',
				'%d banners turned on.',
				n,
				'barrio-suite'
			),
			n
		),
	off: ( n ) =>
		sprintf(
			/* translators: %d: number of banners turned off. */
			_n(
				'%d banner turned off.',
				'%d banners turned off.',
				n,
				'barrio-suite'
			),
			n
		),
	confirm: ( n ) =>
		sprintf(
			/* translators: %d: number of banners to delete. */
			_n(
				'Delete %d banner permanently?',
				'Delete %d banners permanently?',
				n,
				'barrio-suite'
			),
			n
		),
	deleted: ( n ) =>
		sprintf(
			/* translators: %d: number of banners deleted. */
			_n(
				'%d banner deleted.',
				'%d banners deleted.',
				n,
				'barrio-suite'
			),
			n
		),
};

// Una finestra del browser con il banner in cima a una pagina finta, come nel prototipo. Il banner ha lo stile e lo
// script del sito (Module::admin_data()): scorre, e rallenta fino a fermarsi sotto il mouse.
function Preview( { banner, lang } ) {
	const ref = useRef();
	const { text, link_url: link } = textIn( banner, lang, main );
	// Lo script cambia il banner senza che React lo sappia: a ogni testo un banner nuovo.
	const key = JSON.stringify( [ text, !! link ] );
	useEffect( () => {
		let current = true;
		let stop;
		import( /* webpackIgnore: true */ bannersData.script ).then(
			( { start } ) => {
				if ( current ) {
					stop = start( ref.current );
					// Nell'anteprima la scritta si ferma solo sotto il mouse: il bottone di pausa serve nel sito.
					ref.current
						.querySelector( '.barrio-banner__pause' )
						?.remove();
				}
			}
		);
		return () => {
			current = false;
			stop?.();
		};
	}, [ key ] );
	const Body = link ? 'a' : 'div';
	return (
		<Card icon="visibility" title={ __( 'Preview', 'barrio-suite' ) }>
			<div className="barrio-browser" aria-hidden="true">
				<div className="barrio-browser__bar">
					<span />
					<span />
					<span />
					<span className="barrio-browser__url" />
				</div>
				<div key={ key } ref={ ref } className="barrio-banner">
					<Body
						className="barrio-banner__body"
						href={ link ? '#' : undefined }
						tabIndex={ -1 }
						onClick={ ( event ) => event.preventDefault() }
					>
						<span className="barrio-banner__track">
							<span className="barrio-banner__text">
								{ text ||
									__(
										'The text of the banner',
										'barrio-suite'
									) }
							</span>
						</span>
					</Body>
				</div>
				<div className="barrio-browser__page">
					<span />
					<span />
					<span />
				</div>
			</div>
			<p className="barrio-field__help">
				{ __(
					'The preview follows what you write. Move the mouse over the banner: as on the site, it slows down and stops.',
					'barrio-suite'
				) }
			</p>
		</Card>
	);
}

function BannerEditor( { id } ) {
	const [ banner, setBanner ] = useState( id ? null : blank );
	const [ saved, setSaved ] = useState( banner );
	const [ lang, setLang ] = useState( main );
	const [ notice, setNotice ] = useState( null );
	const [ busy, setBusy ] = useState( false );

	const fail = ( error ) =>
		setNotice( { status: 'error', message: errorMessage( error ) } );
	const load = ( { status, ...data } ) => {
		const loaded = trimTexts( data );
		setSaved( loaded );
		setBanner( loaded );
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
	const set = ( changes ) => setBanner( { ...banner, ...changes } );
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
		const { id: current, ...data } = fillTexts( banner );
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
				<SaveButton
					busy={ busy }
					disabled={ banner.id && ! dirty }
					onClick={ save }
				/>
			</Bar>
			<Notice notice={ notice } onClose={ () => setNotice( null ) } />
			<LangNotice lang={ lang } />
			<TabPanel
				tabs={ [
					{ name: 'content', title: __( 'Content', 'barrio-suite' ) },
					{ name: 'options', title: __( 'Options', 'barrio-suite' ) },
				] }
			>
				{ ( tab ) => (
					// L'anteprima solo nel contenuto, come i campi del builder; le opzioni a tutta larghezza.
					<div
						className={
							'content' === tab.name
								? 'barrio-fields'
								: 'barrio-stack'
						}
					>
						<div className="barrio-stack">
							{ 'content' === tab.name ? (
								<Card
									icon="text"
									title={ __( 'Content', 'barrio-suite' ) }
								>
									<TextIn
										item={ banner }
										lang={ lang }
										name="text"
										label={ __( 'Text', 'barrio-suite' ) }
										maxLength={ 255 }
										onChange={ setBanner }
									/>
									<LinkField
										item={ banner }
										lang={ lang }
										label={ __( 'Link', 'barrio-suite' ) }
										help={ __(
											'Optional: the whole banner leads here.',
											'barrio-suite'
										) }
										newTab={ ( item ) => item.new_tab }
										withNewTab={ ( item, on ) => ( {
											...item,
											new_tab: on,
										} ) }
										onChange={ setBanner }
									/>
								</Card>
							) : (
								<Fixed lang={ lang }>
									<PeriodCard
										item={ banner }
										description={ __(
											'Times in the time zone of the site. Without a start it shows up as soon as it is saved, without an end it stays until it is turned off. With more banners on, the most recent one shows up.',
											'barrio-suite'
										) }
										onChange={ set }
									/>
								</Fixed>
							) }
						</div>
						{ 'content' === tab.name && (
							<div className="barrio-panel">
								<Preview banner={ banner } lang={ lang } />
							</div>
						) }
					</div>
				) }
			</TabPanel>
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
		<PeriodList name="banners" label={ textOf } texts={ LIST } />
	);
}

// La scheda Impostazioni, agli amministratori: come mostrarli e i Permessi.
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
		<>
			<HowTo
				description={ __(
					'They show up by themselves at the top of every page.',
					'barrio-suite'
				) }
			/>
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
		</>
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
					// Il sito ne mostra uno solo, il più recente.
					[
						__( 'Active now', 'barrio-suite' ),
						Math.min( 1, count( 'active' ) ),
					],
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
