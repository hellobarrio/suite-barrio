// La pagina Barrio → Orari (modules/hours/src/Module.php): fonte (agli amministratori), settimana e giorni speciali,
// con un solo Salva; a destra come appaiono nel sito e come inserirli. Con la fonte Google settimana e giorni speciali
// si vedono senza modificarli. La lingua nella barra serve solo alle note dei giorni speciali. Impostazioni, agli
// amministratori: i Permessi. E la card della Bacheca.
import apiFetch from '@wordpress/api-fetch';
import { dateI18n, getSettings, gmdateI18n } from '@wordpress/date';
import { useEffect, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

import {
	AddButton,
	ApplyButton,
	CancelButton,
	Card,
	Dialog,
	Fixed,
	HowTo,
	Icon,
	LangNotice,
	LangPicker,
	Menu,
	ModuleCard,
	Notice,
	Permissions,
	RemoveButton,
	Select,
	Text,
	Toggle,
	errorMessage,
} from '../../../../assets/src/admin/ui.js';

// Anche nella card Moduli di Barrio → Impostazioni.
export const HOURS = {
	icon: 'clock',
	title: __( 'Opening hours', 'barrio-suite' ),
	description: __(
		'Hours and special days, also from Google',
		'barrio-suite'
	),
	color: 'linear-gradient(135deg, #14532d, #15803d)',
};

// Module::admin_data(), a chi modifica gli orari. Con il modulo spento manca.
const hoursData = window.barrioAdmin.hours;

const { canManageOptions, languages } = window.barrioAdmin;
const [ main ] = languages;

// La capability del modulo, nei Permessi (Module::CAP).
const CAPS = { barrio_manage_hours: __( 'Edit', 'barrio-suite' ) };

// I giorni ISO (1 = lunedì) dal primo della settimana del sito, con i nomi della sua lingua.
const { weekdays, startOfWeek } = getSettings().l10n;
const DAYS = Array.from( { length: 7 }, ( v, i ) => {
	const day = ( startOfWeek + i ) % 7;
	return [ String( day || 7 ), weekdays[ day ] ];
} );

const SLOT = { open: '09:00', close: '13:00' };

// Una data Y-m-d nel formato del sito, senza passare dal fuso del browser.
const dayText = ( ymd ) =>
	gmdateI18n( getSettings().formats.date, `${ ymd }T00:00:00Z` );

const dateText = ( { from, to } ) =>
	from === to ? dayText( from ) : `${ dayText( from ) } – ${ dayText( to ) }`;

const slotsText = ( hours ) =>
	hours.length
		? hours
				.map( ( { open, close } ) => `${ open }–${ close }` )
				.join( ', ' )
		: __( 'Closed', 'barrio-suite' );

const noteIn = ( note, lang ) => note[ lang ] || note[ main ] || '';

// Settimana e giorni speciali, solo con la fonte manuale: con Google si vedono senza modificarli.
const editable = ( data ) =>
	'manual' === data.source
		? { week: data.week, exceptions: data.exceptions }
		: {};

/**
 * Le fasce di un giorno: apertura e chiusura, una chiusura non dopo l'apertura vale il giorno dopo. I bottoni sotto
 * sono larghi quanto la card e se la dividono.
 *
 * @param {Object}                 props
 * @param {Array}                  props.slots
 * @param {(slots: Array) => void} props.onChange
 * @param {Object}                 [props.actions] Altri bottoni accanto ad Aggiungi.
 */
function Slots( { slots, onChange, actions } ) {
	const set = ( index, name ) => ( value ) =>
		onChange(
			slots.map( ( slot, i ) =>
				i === index ? { ...slot, [ name ]: value } : slot
			)
		);
	return (
		<div className="barrio-hours-slots">
			{ slots.map( ( slot, index ) => (
				<div className="barrio-row is-nowrap" key={ index }>
					<Text
						hideLabel
						type="time"
						label={ __( 'Opens', 'barrio-suite' ) }
						value={ slot.open }
						onChange={ set( index, 'open' ) }
					/>
					<span aria-hidden="true">–</span>
					<Text
						hideLabel
						type="time"
						label={ __( 'Closes', 'barrio-suite' ) }
						value={ slot.close }
						onChange={ set( index, 'close' ) }
					/>
					<RemoveButton
						onClick={ () =>
							onChange( slots.filter( ( s, i ) => i !== index ) )
						}
					/>
				</div>
			) ) }
			<div className="barrio-row is-fill">
				<button
					type="button"
					className="barrio-button"
					onClick={ () =>
						onChange( [
							...slots,
							slots.length
								? { open: '15:00', close: '19:00' }
								: SLOT,
						] )
					}
				>
					<Icon name="plus-alt2" />
					<span className="barrio-button__text">
						{ __( 'Add hours', 'barrio-suite' ) }
					</span>
				</button>
				{ actions }
			</div>
		</div>
	);
}

function WeekCard( { week, disabled, onChange } ) {
	return (
		<Card
			icon="calendar-alt"
			title={ __( 'Week', 'barrio-suite' ) }
			description={ __(
				'For a closing after midnight, write a closing time before the opening: it counts on the next day. 00:00–00:00 is open 24 hours.',
				'barrio-suite'
			) }
		>
			<Fixed disabled={ disabled }>
				{ DAYS.map( ( [ day, name ] ) => (
					<div
						className="barrio-hours-day"
						key={ day }
						role="group"
						aria-label={ name }
					>
						<Toggle
							label={ name }
							help={
								! week[ day ].length &&
								__( 'Closed', 'barrio-suite' )
							}
							checked={ week[ day ].length > 0 }
							onChange={ ( open ) =>
								onChange( {
									...week,
									[ day ]: open ? [ SLOT ] : [],
								} )
							}
						/>
						{ week[ day ].length > 0 && (
							<Slots
								slots={ week[ day ] }
								onChange={ ( slots ) =>
									onChange( { ...week, [ day ]: slots } )
								}
								actions={
									<button
										type="button"
										className="barrio-button"
										onClick={ () =>
											onChange(
												Object.fromEntries(
													DAYS.map( ( [ other ] ) => [
														other,
														week[ day ],
													] )
												)
											)
										}
									>
										<Icon name="admin-page" />
										<span className="barrio-button__text">
											{ __(
												'Copy to all days',
												'barrio-suite'
											) }
										</span>
									</button>
								}
							/>
						) }
					</div>
				) ) }
			</Fixed>
		</Card>
	);
}

// Data, fasce e nota di un giorno speciale. Nelle altre lingue solo la nota.
function SpecialModal( { special, lang, onSave, onClose } ) {
	// Al vuoto: un giorno solo, che segue Dal.
	const [ value, setValue ] = useState( {
		...special,
		to: special.to === special.from ? '' : special.to,
	} );
	const [ notice, setNotice ] = useState( null );
	const set = ( name ) => ( next ) =>
		setValue( ( now ) => ( { ...now, [ name ]: next } ) );
	const save = () => {
		if ( ! value.from ) {
			setNotice( {
				status: 'error',
				message: __( 'Choose the date.', 'barrio-suite' ),
			} );
			return;
		}
		const to = value.to || value.from;
		if ( to < value.from ) {
			setNotice( {
				status: 'error',
				message: __(
					'A special period cannot end before it starts.',
					'barrio-suite'
				),
			} );
			return;
		}
		onSave( { ...value, to } );
	};
	return (
		<Dialog
			title={ __( 'Special day', 'barrio-suite' ) }
			onRequestClose={ onClose }
			footer={
				<>
					<CancelButton onClick={ onClose } />
					<ApplyButton onClick={ save } />
				</>
			}
		>
			<Notice notice={ notice } />
			<Fixed lang={ lang }>
				<Text
					type="date"
					label={ __( 'From', 'barrio-suite' ) }
					value={ value.from }
					onChange={ set( 'from' ) }
				/>
				<Text
					type="date"
					label={ __( 'To', 'barrio-suite' ) }
					help={ __( 'Empty for a single day.', 'barrio-suite' ) }
					min={ value.from }
					value={ value.to }
					onChange={ set( 'to' ) }
				/>
				{ /* Come un giorno della Settimana, tra due linee. */ }
				<div className="barrio-hours-day">
					<Toggle
						label={ __( 'Open', 'barrio-suite' ) }
						help={
							! value.hours.length &&
							__( 'Closed all day.', 'barrio-suite' )
						}
						checked={ value.hours.length > 0 }
						onChange={ ( open ) =>
							set( 'hours' )( open ? [ SLOT ] : [] )
						}
					/>
					{ value.hours.length > 0 && (
						<Slots
							slots={ value.hours }
							onChange={ set( 'hours' ) }
						/>
					) }
				</div>
			</Fixed>
			<Text
				label={ __( 'Note', 'barrio-suite' ) }
				help={
					lang === main ? (
						__(
							'Next to the hours, for example Christmas or Holidays.',
							'barrio-suite'
						)
					) : (
						<span className="barrio-original">
							{ `${ main.toUpperCase() }: ${
								value.note[ main ] || '—'
							}` }
						</span>
					)
				}
				maxLength={ 100 }
				value={ value.note[ lang ] ?? '' }
				onChange={ ( text ) =>
					set( 'note' )( { ...value.note, [ lang ]: text } )
				}
			/>
		</Dialog>
	);
}

function SpecialCard( { exceptions, lang, google, onChange } ) {
	// L'indice del giorno speciale aperto nella modale; -1 per uno nuovo.
	const [ editing, setEditing ] = useState( null );
	const today = dateI18n( 'Y-m-d', new Date() );
	return (
		<Card
			icon="calendar"
			title={ __( 'Special days', 'barrio-suite' ) }
			description={ __(
				'Holidays and days with other hours. Past days are removed by themselves.',
				'barrio-suite'
			) }
			actions={
				! google &&
				lang === main && (
					<AddButton
						label={ __( 'Add special day', 'barrio-suite' ) }
						onClick={ () => setEditing( -1 ) }
					/>
				)
			}
		>
			{ ! exceptions.length && (
				<p className="barrio-field__help">
					{ __( 'No special days.', 'barrio-suite' ) }
				</p>
			) }
			<div className="barrio-order">
				{ exceptions.map( ( special, index ) => (
					<div className="barrio-order__row" key={ index }>
						<span className="barrio-order__title">
							<strong>{ dateText( special ) }</strong>
							{ ' · ' }
							{ slotsText( special.hours ) }
							{ noteIn( special.note, lang ) &&
								` · ${ noteIn( special.note, lang ) }` }
						</span>
						{ ! google && (
							<Menu
								label={ __( 'Actions', 'barrio-suite' ) }
								items={ [
									{
										label: __( 'Edit', 'barrio-suite' ),
										onClick: () => setEditing( index ),
									},
									lang === main && {
										label: __( 'Delete', 'barrio-suite' ),
										danger: true,
										onClick: () =>
											onChange(
												exceptions.filter(
													( s, i ) => i !== index
												)
											),
									},
								] }
							/>
						) }
					</div>
				) ) }
			</div>
			{ null !== editing && (
				<SpecialModal
					special={
						exceptions[ editing ] ?? {
							from: today,
							to: today,
							hours: [],
							note: {},
						}
					}
					lang={ lang }
					onClose={ () => setEditing( null ) }
					onSave={ ( special ) => {
						const next =
							editing < 0
								? [ ...exceptions, special ]
								: exceptions.map( ( s, i ) =>
										i === editing ? special : s
									);
						onChange(
							next.sort( ( a, b ) =>
								a.from.localeCompare( b.from )
							)
						);
						setEditing( null );
					} }
				/>
			) }
		</Card>
	);
}

function SourceCard( { data, draft, set, onData, onNotice } ) {
	const [ busy, setBusy ] = useState( false );
	const google = 'google' === draft.source;
	// Aggiorna ora con la fonte già salvata: un Place ID nuovo si prende salvando.
	const saved = 'google' === data.source && draft.place_id === data.place_id;
	const sync = () => {
		setBusy( true );
		onNotice( null );
		apiFetch( { path: '/barrio/v1/hours/sync', method: 'POST' } )
			.then( ( fresh ) => {
				onData( fresh );
				onNotice( {
					status: 'success',
					message: __(
						'The opening hours have been updated from Google.',
						'barrio-suite'
					),
				} );
			} )
			.catch( ( error ) => {
				onNotice( { status: 'error', message: errorMessage( error ) } );
				// L'errore resta anche nella card.
				apiFetch( { path: '/barrio/v1/hours' } ).then( onData );
			} )
			.finally( () => setBusy( false ) );
	};
	return (
		<Card
			className="barrio-wide"
			icon="admin-site-alt3"
			title={ __( 'Source', 'barrio-suite' ) }
			description={ __(
				'With Google, the week and the special days of the next 7 days come from the Google Business Profile of the business every night at 3:00 (site time). To change them in Opening hours, switch to manual: it starts from the latest hours.',
				'barrio-suite'
			) }
		>
			<Select
				label={ __( 'Source', 'barrio-suite' ) }
				help={
					google &&
					'manual' === data.source &&
					__(
						'The hours and the special days written by hand are replaced by those of Google.',
						'barrio-suite'
					)
				}
				value={ draft.source }
				options={ [
					{
						value: 'manual',
						label: __( 'Written by hand', 'barrio-suite' ),
					},
					{ value: 'google', label: __( 'Google', 'barrio-suite' ) },
				] }
				onChange={ ( source ) => set( { ...draft, source } ) }
			/>
			{ google && (
				<>
					<Text
						label={ __( 'Place ID', 'barrio-suite' ) }
						help={
							<>
								{ __(
									'The ID of the business on Google Maps, like ChIJ….',
									'barrio-suite'
								) }{ ' ' }
								<a
									href="https://developers.google.com/maps/documentation/places/web-service/place-id"
									target="_blank"
									rel="noreferrer"
								>
									{ __(
										'Find the Place ID',
										'barrio-suite'
									) }
								</a>
							</>
						}
						value={ draft.place_id }
						onChange={ ( placeId ) =>
							set( { ...draft, place_id: placeId.trim() } )
						}
						spellCheck={ false }
					/>
					{ ! data.account && (
						<Notice
							notice={ {
								status: 'error',
								message: __(
									'Add the Google service account in Barrio → Settings: Google is called with it, and the Places API must be enabled in its project.',
									'barrio-suite'
								),
							} }
						/>
					) }
					{ saved && data.google.error && (
						<Notice
							notice={ {
								status: 'error',
								message: data.google.error,
							} }
						/>
					) }
					{ saved && data.google.synced && (
						<p className="barrio-field__help">
							{ sprintf(
								/* translators: 1: date and time; 2: name of the business on Google. */
								__(
									'Updated on %1$s from %2$s.',
									'barrio-suite'
								),
								data.google.synced,
								data.google.name || data.place_id
							) }
						</p>
					) }
					<div className="barrio-row">
						<button
							type="button"
							className="barrio-button"
							disabled={ ! saved || busy }
							onClick={ sync }
						>
							<Icon name="update" />
							<span className="barrio-button__text">
								{ busy
									? __( 'Updating…', 'barrio-suite' )
									: __( 'Update now', 'barrio-suite' ) }
							</span>
						</button>
					</div>
				</>
			) }
		</Card>
	);
}

// Come appare nel sito la settimana, anche non salvata: la scrive il server, come il blocco.
function Preview( { week, exceptions, lang } ) {
	const [ html, setHtml ] = useState( null );
	const [ error, setError ] = useState( null );
	useEffect( () => {
		let current = true;
		const timer = setTimeout(
			() =>
				apiFetch( {
					path: '/barrio/v1/hours/preview',
					method: 'POST',
					data: { week, exceptions, lang },
				} )
					.then( ( result ) => current && setHtml( result.html ) )
					// L'anteprima resta quella di prima; se è la prima, l'errore al posto della card vuota.
					.catch(
						( failure ) =>
							current && setError( errorMessage( failure ) )
					),
			400
		);
		return () => {
			current = false;
			clearTimeout( timer );
		};
	}, [ week, exceptions, lang ] );
	return (
		<Card icon="visibility" title={ __( 'On the site', 'barrio-suite' ) }>
			{ null === html && error && (
				<Notice notice={ { status: 'error', message: error } } />
			) }
			{ '' === html ? (
				<p className="barrio-field__help">
					{ __(
						'Nothing is shown until there are opening hours.',
						'barrio-suite'
					) }
				</p>
			) : (
				<div
					// L'HTML del blocco, scritto dal server con i testi già codificati.
					dangerouslySetInnerHTML={ { __html: html ?? '' } }
				/>
			) }
		</Card>
	);
}

// Con Google gli orari si vedono senza modificarli: si cambia fonte in Impostazioni, che solo gli amministratori aprono.
function GoogleNotice( { google } ) {
	const notice = {
		status: 'info',
		message: canManageOptions
			? __(
					'The opening hours come from Google, every night: to change them here, choose the manual source in Settings.',
					'barrio-suite'
				)
			: __(
					'The opening hours come from Google, every night: to change them here, an administrator has to choose the manual source.',
					'barrio-suite'
				),
		action: canManageOptions && (
			<a className="barrio-button" href="?page=barrio-hours&tab=settings">
				<Icon name="admin-generic" />
				<span className="barrio-button__text">
					{ __( 'Settings', 'barrio-suite' ) }
				</span>
			</a>
		),
	};
	return (
		<>
			<Notice notice={ notice } />
			<Notice
				notice={
					google.error && { status: 'error', message: google.error }
				}
			/>
		</>
	);
}

function HoursCards( { data, draft, set, view } ) {
	const lang = view.lang ?? main;
	const google = 'google' === data.source;
	const week = draft.week ?? data.week;
	const exceptions = draft.exceptions ?? data.exceptions;
	return (
		<>
			{ google && <GoogleNotice google={ data.google } /> }
			<LangNotice lang={ lang } />
			<div className="barrio-fields">
				<div>
					<WeekCard
						week={ week }
						disabled={ google || lang !== main }
						onChange={ ( next ) => set( { ...draft, week: next } ) }
					/>
					<SpecialCard
						exceptions={ exceptions }
						lang={ lang }
						google={ google }
						onChange={ ( next ) =>
							set( { ...draft, exceptions: next } )
						}
					/>
				</div>
				<div className="barrio-panel">
					<Preview
						week={ week }
						exceptions={ exceptions }
						lang={ lang }
					/>
				</div>
			</div>
		</>
	);
}

// La scheda Orari, per General: un solo Salva per settimana e giorni speciali.
export const hoursSection = {
	id: 'hours',
	load: () => apiFetch( { path: '/barrio/v1/hours' } ),
	value: editable,
	save: ( draft ) =>
		apiFetch( { path: '/barrio/v1/hours', method: 'PUT', data: draft } ),
	Tools: ( { view, setView } ) => (
		<LangPicker
			value={ view.lang ?? main }
			onChange={ ( lang ) => setView( { ...view, lang } ) }
		/>
	),
	Cards: HoursCards,
};

// La scheda Impostazioni, agli amministratori: come mostrarli, la fonte e i Permessi, con un solo Salva.
export const hoursSettingsSection = {
	id: 'hours-settings',
	load: () => apiFetch( { path: '/barrio/v1/hours' } ),
	value: ( data ) => ( {
		source: data.source,
		place_id: data.place_id,
		roles: data.roles,
	} ),
	save: ( draft ) =>
		apiFetch( { path: '/barrio/v1/hours', method: 'PUT', data: draft } ),
	Cards: ( { data, draft, set, onData, onNotice } ) => (
		<>
			<HowTo
				description={ __(
					'The Opening hours block, or a shortcode.',
					'barrio-suite'
				) }
				codes={ [
					'[barrio_hours]',
					'[barrio_hours group="1"]',
					'[barrio_hours show="today"]',
					'[barrio_hours show="status"]',
				] }
			/>
			<SourceCard
				data={ data }
				draft={ draft }
				set={ set }
				onData={ onData }
				onNotice={ onNotice }
			/>
			<Permissions
				description={ __(
					'Who can edit the opening hours and the special days. Administrators can always do everything; only administrators choose the source and change these settings.',
					'barrio-suite'
				) }
				caps={ CAPS }
				names={ data.role_names }
				roles={ draft.roles }
				onChange={ ( roles ) => set( { ...draft, roles } ) }
			/>
		</>
	),
};

// Nella Bacheca: oggi, il prossimo giorno speciale, la fonte e l'errore di Google.
export function HoursCard() {
	if ( ! hoursData ) {
		return null;
	}
	return (
		<ModuleCard
			module={ HOURS }
			notice={
				hoursData.error && {
					status: 'error',
					message: hoursData.error,
				}
			}
			stats={ [
				[ __( 'Today', 'barrio-suite' ), hoursData.today ],
				[
					__( 'Next special day', 'barrio-suite' ),
					hoursData.next || '—',
				],
				[
					__( 'Source', 'barrio-suite' ),
					'google' === hoursData.source
						? __( 'Google', 'barrio-suite' )
						: __( 'Written by hand', 'barrio-suite' ),
				],
			] }
			actions={ [
				[
					__( 'Opening hours', 'barrio-suite' ),
					'?page=barrio-hours',
					'clock',
				],
				canManageOptions && [
					__( 'Settings', 'barrio-suite' ),
					'?page=barrio-hours&tab=settings',
					'admin-generic',
				],
			].filter( Boolean ) }
		/>
	);
}
