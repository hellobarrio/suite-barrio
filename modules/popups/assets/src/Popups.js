// La pagina Barrio → Popup (modules/popups/src/Module.php): l'elenco o, con ?popup=ID (o new), l'editor con l'anteprima;
// agli amministratori la scheda Impostazioni con i Permessi. E la card della Bacheca.
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
	Group,
	Icon,
	LangNotice,
	LangPicker,
	Loading,
	ModuleCard,
	Notice,
	Permissions,
	SaveButton,
	Text,
	errorMessage,
	useUnsavedWarning,
} from '../../../../assets/src/admin/ui.js';
import { setTranslation, textIn } from '../../../../assets/src/admin/i18n.js';
import { TextIn } from '../../../../assets/src/admin/texts.js';
import { MediaField } from '../../../seo/assets/src/seo/ui.js';

// Anche nella card Moduli di Barrio → Impostazioni.
export const POPUPS = {
	icon: 'format-chat',
	title: __( 'Popups', 'barrio-suite' ),
	description: __( 'A window in the middle of every page', 'barrio-suite' ),
	color: 'linear-gradient(135deg, #701a75, #a21caf)',
};

// Module::admin_data(), a chi gestisce i popup. Con il modulo spento manca.
const popupsData = window.barrioAdmin.popups;

const { languages } = window.barrioAdmin;
const [ main ] = languages;
const MAIN = main.toUpperCase();

// La capability del modulo, nei Permessi (Module::CAP).
const CAPS = { barrio_manage_popups: __( 'Manage', 'barrio-suite' ) };

const pageUrl = ( params = {} ) =>
	'?' + new URLSearchParams( { page: 'barrio-popups', ...params } );

// Un popup nuovo: chi lo chiude non lo rivede per una settimana.
const blank = () => ( {
	is_enabled: true,
	starts_at: null,
	ends_at: null,
	image_id: null,
	settings: {
		new_tab: false,
		days: 7,
	},
	i18n: {
		[ main ]: {
			title: '',
			content: null,
			button_label: null,
			link_url: null,
		},
	},
} );

const titleOf = ( popup ) =>
	( popup.i18n[ main ] ?? Object.values( popup.i18n )[ 0 ] )?.title ?? '';

const number = ( value, max ) =>
	Math.min( max, Math.max( 0, parseInt( value, 10 ) || 0 ) );

// I testi dell'elenco (PeriodList).
const LIST = {
	column: __( 'Title', 'barrio-suite' ),
	title: __( 'Popups', 'barrio-suite' ),
	search: __( 'Search popups', 'barrio-suite' ),
	empty: __( 'No popups found.', 'barrio-suite' ),
	all: __( 'All popups', 'barrio-suite' ),
	add: __( 'New popup', 'barrio-suite' ),
	duplicated: __( 'Popup duplicated: the copy is off.', 'barrio-suite' ),
	on: ( n ) =>
		sprintf(
			/* translators: %d: number of popups turned on. */
			_n(
				'%d popup turned on.',
				'%d popups turned on.',
				n,
				'barrio-suite'
			),
			n
		),
	off: ( n ) =>
		sprintf(
			/* translators: %d: number of popups turned off. */
			_n(
				'%d popup turned off.',
				'%d popups turned off.',
				n,
				'barrio-suite'
			),
			n
		),
	confirm: ( n ) =>
		sprintf(
			/* translators: %d: number of popups to delete. */
			_n(
				'Delete %d popup permanently?',
				'Delete %d popups permanently?',
				n,
				'barrio-suite'
			),
			n
		),
	deleted: ( n ) =>
		sprintf(
			/* translators: %d: number of popups deleted. */
			_n( '%d popup deleted.', '%d popups deleted.', n, 'barrio-suite' ),
			n
		),
};

/**
 * Il testo formattato con l'editor classico di WordPress, ridotto a grassetto, corsivo, elenchi e link. Si scrive in
 * HTML, senza wpautop. Il valore conta solo all'apertura: per cambiarlo si rimonta con un'altra key.
 *
 * @param {Object}                 props
 * @param {string}                 props.id
 * @param {string}                 props.value
 * @param {(html: string) => void} props.onChange
 */
function RichText( { id, value, onChange } ) {
	const change = useRef( onChange );
	change.current = onChange;
	useEffect( () => {
		const editor = window.wp.oldEditor;
		const start = () =>
			editor.initialize( id, {
				tinymce: {
					wpautop: false,
					toolbar1:
						'bold,italic,bullist,numlist,link,unlink,undo,redo',
					toolbar2: '',
					setup: ( tinymce ) =>
						tinymce.on( 'input change undo redo', () =>
							change.current( tinymce.getContent() )
						),
				},
				quicktags: false,
				mediaButtons: false,
			} );
		// Le impostazioni di TinyMCE WordPress le stampa in fondo alla pagina, dopo questo script.
		if ( 'loading' === document.readyState ) {
			document.addEventListener( 'DOMContentLoaded', start );
		} else {
			start();
		}
		return () => {
			document.removeEventListener( 'DOMContentLoaded', start );
			editor.remove( id );
		};
	}, [ id ] );
	return (
		<textarea
			id={ id }
			className="barrio-input"
			rows={ 8 }
			defaultValue={ value }
		/>
	);
}

// Il testo nella lingua scelta. Nelle altre lingue parte da quello della lingua principale, come TextIn.
function ContentIn( { popup, lang, onChange } ) {
	const original = popup.i18n[ main ]?.content;
	const own = lang === main ? original : popup.i18n[ lang ]?.content;
	if ( lang !== main && ! original && ! own ) {
		return (
			<Group label={ __( 'Text', 'barrio-suite' ) }>
				<p className="barrio-field__help">
					{ sprintf(
						/* translators: %s: code of the main language, like IT. */
						__(
							'Empty in %s, so there is nothing to translate.',
							'barrio-suite'
						),
						MAIN
					) }
				</p>
			</Group>
		);
	}
	return (
		<Group
			label={ __( 'Text', 'barrio-suite' ) }
			help={
				lang === main
					? __(
							'Optional. A form goes here with its shortcode, like [barrio_form id="3"]: once sent, the popup closes and does not come back.',
							'barrio-suite'
						)
					: ! own &&
						sprintf(
							/* translators: %s: code of the main language, like IT. */
							__(
								'Not translated: this is the text in %s. Change it to translate it.',
								'barrio-suite'
							),
							MAIN
						)
			}
		>
			<RichText
				key={ lang }
				id={ `barrio-popup-content-${ lang }` }
				value={ own ?? original ?? '' }
				onChange={ ( html ) =>
					onChange(
						lang === main
							? {
									...popup,
									i18n: {
										...popup.i18n,
										[ main ]: {
											...popup.i18n[ main ],
											content: html || null,
										},
									},
								}
							: setTranslation( popup, lang, 'content', html )
					)
				}
			/>
		</Group>
	);
}

// L'HTML del sito in un iframe, con lo stile del popup e, se il testo ha un form, i suoi asset (Module::preview()).
function Preview( { popup, lang } ) {
	const [ html, setHtml ] = useState( null );
	const [ error, setError ] = useState( null );
	const data = JSON.stringify( {
		lang,
		image_id: popup.image_id,
		settings: popup.settings,
		text: textIn( popup, lang, main ),
	} );
	useEffect( () => {
		let current = true;
		const timer = setTimeout(
			() =>
				apiFetch( {
					path: '/barrio/v1/popups/preview',
					method: 'POST',
					data: JSON.parse( data ),
				} )
					.then( ( result ) => current && setHtml( result.html ) )
					// L'anteprima resta quella di prima; se è la prima, l'errore al posto della rotella.
					.catch(
						( failure ) =>
							current && setError( errorMessage( failure ) )
					),
			300
		);
		return () => {
			current = false;
			clearTimeout( timer );
		};
	}, [ data ] );
	return (
		<Card icon="visibility" title={ __( 'Preview', 'barrio-suite' ) }>
			<div className="barrio-browser">
				<div className="barrio-browser__bar" aria-hidden="true">
					<span />
					<span />
					<span />
					<span className="barrio-browser__url" />
				</div>
				{ null === html && error && (
					<Notice notice={ { status: 'error', message: error } } />
				) }
				{ null === html && ! error && <Loading /> }
				{ null !== html && (
					// allow-same-origin: con l'origine opaca gli script module del form non si caricherebbero.
					<iframe
						className="barrio-popup-frame"
						title={ __( 'Preview', 'barrio-suite' ) }
						sandbox="allow-scripts allow-same-origin allow-forms"
						srcDoc={ html }
					/>
				) }
			</div>
			<p className="barrio-field__help">
				{ __(
					'As on the site, without the styles of the theme. A form in the text is not sent.',
					'barrio-suite'
				) }
			</p>
		</Card>
	);
}

function PopupEditor( { id } ) {
	const [ popup, setPopup ] = useState( id ? null : blank );
	const [ saved, setSaved ] = useState( popup );
	const [ lang, setLang ] = useState( main );
	const [ notice, setNotice ] = useState( null );
	const [ busy, setBusy ] = useState( false );

	const fail = ( error ) =>
		setNotice( { status: 'error', message: errorMessage( error ) } );
	const load = ( { status, ...data } ) => {
		const loaded = trimTexts( data );
		setSaved( loaded );
		setPopup( loaded );
	};
	useEffect( () => {
		if ( id ) {
			apiFetch( { path: `/barrio/v1/popups/${ id }` } )
				.then( load )
				.catch( fail );
		}
	}, [ id ] );

	const dirty = JSON.stringify( popup ) !== JSON.stringify( saved );
	useUnsavedWarning( dirty );

	if ( ! popup ) {
		return notice ? <Notice notice={ notice } /> : <Loading />;
	}
	const { settings } = popup;
	const set = ( changes ) => setPopup( { ...popup, ...changes } );
	const setSettings = ( changes ) =>
		set( { settings: { ...settings, ...changes } } );
	const save = () => {
		const full = fillTexts( popup );
		const missing = ! full.i18n[ main ]?.title.trim();
		const button = Object.values( full.i18n ).some(
			( text ) => text.button_label && ! text.link_url
		);
		if ( missing || button ) {
			setNotice( {
				status: 'error',
				message: missing
					? __( 'Write the title of the popup.', 'barrio-suite' )
					: __( 'A button needs a link.', 'barrio-suite' ),
			} );
			return;
		}
		setBusy( true );
		setNotice( null );
		const { id: current, ...data } = full;
		apiFetch(
			current
				? {
						path: `/barrio/v1/popups/${ current }`,
						method: 'PUT',
						data,
					}
				: { path: '/barrio/v1/popups', method: 'POST', data }
		)
			.then( ( result ) => {
				load( result );
				if ( ! current ) {
					window.history.replaceState(
						null,
						'',
						pageUrl( { popup: result.id } )
					);
				}
				setNotice( {
					status: 'success',
					message: __( 'Popup saved.', 'barrio-suite' ),
				} );
			} )
			.catch( fail )
			.finally( () => setBusy( false ) );
	};
	const texts = { item: popup, lang, onChange: setPopup };

	return (
		<div className="barrio-stack">
			<Bar
				title={
					<>
						<a
							className="barrio-icon-button"
							href={ pageUrl() }
							aria-label={ __( 'All popups', 'barrio-suite' ) }
						>
							<Icon name="arrow-left-alt2" />
						</a>
						<h2 className="barrio-bar__title">
							{ popup.id
								? __( 'Edit popup', 'barrio-suite' )
								: __( 'New popup', 'barrio-suite' ) }
						</h2>
					</>
				}
			>
				<LangPicker value={ lang } onChange={ setLang } />
				<SaveButton
					busy={ busy }
					disabled={ popup.id && ! dirty }
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
					<div className="barrio-fields">
						<div>
							{ 'content' === tab.name ? (
								<Card
									icon="text"
									title={ __( 'Content', 'barrio-suite' ) }
								>
									<TextIn
										{ ...texts }
										name="title"
										label={ __( 'Title', 'barrio-suite' ) }
										maxLength={ 255 }
									/>
									<ContentIn
										popup={ popup }
										lang={ lang }
										onChange={ setPopup }
									/>
									<Fixed lang={ lang }>
										<MediaField
											label={ __(
												'Image',
												'barrio-suite'
											) }
											help={ __(
												'Optional, above the title.',
												'barrio-suite'
											) }
											value={ popup.image_id ?? 0 }
											onChange={ ( image ) =>
												set( {
													image_id: image || null,
												} )
											}
										/>
									</Fixed>
									<LinkField
										item={ popup }
										lang={ lang }
										label={ __( 'Button', 'barrio-suite' ) }
										help={ __(
											'Optional: with a link the popup has a button.',
											'barrio-suite'
										) }
										text="button_label"
										textLabel={ __(
											'Button text',
											'barrio-suite'
										) }
										textHelp={ __(
											'Leave empty for "Learn more".',
											'barrio-suite'
										) }
										fallback={ __(
											'Learn more',
											'barrio-suite'
										) }
										newTab={ ( item ) =>
											item.settings.new_tab
										}
										withNewTab={ ( item, on ) => ( {
											...item,
											settings: {
												...item.settings,
												new_tab: on,
											},
										} ) }
										onChange={ setPopup }
									/>
								</Card>
							) : (
								<Fixed lang={ lang }>
									<Card
										icon="external"
										title={ __(
											'Opening',
											'barrio-suite'
										) }
										description={
											popup.id
												? sprintf(
														/* translators: 1: a link like #barrio-popup-12, 2: an HTML attribute like data-barrio-popup="12". */
														__(
															'It opens 2 seconds after the page loads, and always from a link to %1$s or a button with %2$s.',
															'barrio-suite'
														),
														`#barrio-popup-${ popup.id }`,
														`data-barrio-popup="${ popup.id }"`
													)
												: __(
														'It opens 2 seconds after the page loads; once saved, also from a link.',
														'barrio-suite'
													)
										}
									>
										<Text
											type="number"
											min={ 0 }
											max={ 365 }
											label={ __(
												'Back after (days)',
												'barrio-suite'
											) }
											help={ __(
												'Once closed, it opens again for the same visitor after these days; with 0, on the next page. The date stays only in their browser.',
												'barrio-suite'
											) }
											value={ String( settings.days ) }
											onChange={ ( value ) =>
												setSettings( {
													days: number( value, 365 ),
												} )
											}
										/>
									</Card>
									<PeriodCard
										item={ popup }
										description={ __(
											'Times in the time zone of the site. Without a start it shows up as soon as it is saved, without an end it stays until it is turned off. With more popups on, the most recent one opens.',
											'barrio-suite'
										) }
										onChange={ set }
									/>
								</Fixed>
							) }
						</div>
						<div className="barrio-panel">
							<Preview popup={ popup } lang={ lang } />
						</div>
					</div>
				) }
			</TabPanel>
		</div>
	);
}

// La scheda Popup: l'elenco o l'editor, secondo ?popup=.
export function PopupsTab() {
	const param = new URLSearchParams( window.location.search ).get( 'popup' );
	if ( 'new' === param ) {
		return <PopupEditor />;
	}
	return /^[1-9]\d*$/.test( param ?? '' ) ? (
		<PopupEditor id={ Number( param ) } />
	) : (
		<PeriodList name="popups" label={ titleOf } texts={ LIST } />
	);
}

// La scheda Impostazioni, agli amministratori: i Permessi.
export const popupsSettingsSection = {
	id: 'popups-settings',
	load: () => apiFetch( { path: '/barrio/v1/popups/settings' } ),
	value: ( data ) => data.roles,
	save: ( roles ) =>
		apiFetch( {
			path: '/barrio/v1/popups/settings',
			method: 'PUT',
			data: { roles },
		} ),
	Cards: ( { data, draft, set } ) => (
		<Permissions
			description={ __(
				'Who can create, change and delete the popups. Administrators can always do everything; only administrators change these settings.',
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
export function PopupsCard() {
	const [ popups, setPopups ] = useState( null );
	const [ notice, setNotice ] = useState( null );
	useEffect( () => {
		if ( popupsData ) {
			apiFetch( { path: '/barrio/v1/popups' } )
				.then( setPopups )
				.catch( ( error ) =>
					setNotice( {
						status: 'error',
						message: errorMessage( error ),
					} )
				);
		}
	}, [] );
	if ( ! popupsData ) {
		return null;
	}
	const count = ( status ) =>
		popups.filter( ( popup ) => popup.status === status ).length;
	return (
		<ModuleCard
			module={ POPUPS }
			notice={ notice }
			stats={
				popups && [
					// Il sito ne mostra uno solo, il più recente.
					[
						__( 'Active now', 'barrio-suite' ),
						Math.min( 1, count( 'active' ) ),
					],
					[ __( 'Scheduled', 'barrio-suite' ), count( 'scheduled' ) ],
					[ __( 'All popups', 'barrio-suite' ), popups.length ],
				]
			}
			actions={ [
				[ __( 'Manage popups', 'barrio-suite' ), pageUrl() ],
				[
					__( 'New popup', 'barrio-suite' ),
					pageUrl( { popup: 'new' } ),
				],
			] }
		/>
	);
}
