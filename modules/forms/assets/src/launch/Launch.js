// Messa online del sito (Bacheca → ?page=barrio&launch): un passaggio alla volta, ognuno con le sue
// impostazioni. Fatto o no lo dice il server secondo le impostazioni vere (GET /barrio/v1/launch,
// src/Launch.php); un passaggio si può saltare, e uno saltato non blocca la messa online.
import apiFetch from '@wordpress/api-fetch';
import { useEffect, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

import {
	Bar,
	Card,
	Check,
	Icon,
	Loading,
	Notice,
	SectionHead,
	Select,
	useUnsavedWarning,
} from '../admin/ui.js';
import { errorMessage } from '../builder/model.js';
import {
	RetentionField,
	SmtpFields,
	TemplateFields,
	TestEmail,
} from '../settings/Sections.js';

export const launchUrl = ( step ) =>
	'?' +
	new URLSearchParams( {
		page: 'barrio',
		launch: '',
		...( step && { step } ),
	} );

// L'ordine della guida, un passaggio per modulo più quelli del sito. Quelli che il server non
// restituisce (di un modulo spento) non compaiono. fields: le impostazioni che il passaggio modifica.
const STEPS = [
	{
		id: 'forms',
		fields: [ 'smtp', 'email', 'privacy' ],
		icon: 'feedback',
		title: __( 'Forms', 'barrio-suite' ),
		description: __(
			'The emails leave through an SMTP server, so they do not end up in spam, and carry the logo of the site; the entries are kept only as long as needed, as the GDPR asks.',
			'barrio-suite'
		),
	},
	{
		id: 'timezone',
		fields: [ 'timezone' ],
		icon: 'clock',
		title: __( 'Time zone', 'barrio-suite' ),
		description: __(
			'For the dates of entries and emails.',
			'barrio-suite'
		),
	},
	{
		id: 'search',
		fields: [ 'search' ],
		icon: 'search',
		title: __( 'Search engines', 'barrio-suite' ),
		description: __(
			'While the site was being built WordPress may have asked search engines not to index it.',
			'barrio-suite'
		),
	},
];

// Quelle che il browser conosce; quella salvata anche se il browser non la conosce.
const zones = ( saved ) =>
	[ ...new Set( [ saved, ...Intl.supportedValuesOf( 'timeZone' ) ] ) ]
		.filter( Boolean )
		.map( ( zone ) => ( { value: zone, label: zone } ) );

// I valori salvati di ogni passaggio, nella forma dei campi.
const values = ( launch, settings ) => ( {
	smtp: settings.smtp,
	email: settings.email,
	privacy: settings.privacy,
	timezone: launch.timezone,
	search: launch.steps.search,
} );

const settingsPut = ( data ) =>
	apiFetch( { path: '/barrio/v1/settings', method: 'PUT', data } );

const SAVE = {
	forms: ( draft, password ) =>
		settingsPut( {
			smtp: draft.smtp,
			email: draft.email,
			privacy: draft.privacy,
			...( password && { password } ),
		} ),
	timezone: ( draft ) =>
		apiFetch( {
			path: '/wp/v2/settings',
			method: 'POST',
			data: { timezone: draft.timezone },
		} ),
	search: ( draft ) =>
		apiFetch( {
			path: '/barrio/v1/launch',
			method: 'PUT',
			data: { search_engines: draft.search },
		} ),
};

// I passaggi ancora da configurare: né fatti né saltati.
export const pending = ( launch ) =>
	STEPS.filter(
		( step ) =>
			false === launch.steps[ step.id ] &&
			! launch.skipped.includes( step.id )
	);

/**
 * Nella Bacheca e alla fine della guida: la guida da cominciare, o il sito pronto.
 *
 * @param {Object} props
 * @param {Object} props.launch Da GET /barrio/v1/launch.
 * @param {string} props.href   Dove porta il pulsante quando il sito è pronto.
 */
export function LaunchBanner( { launch, href } ) {
	const left = pending( launch );
	if ( ! left.length ) {
		return (
			<section className="barrio-launch is-done">
				<span className="barrio-launch__icon">
					<Icon name="yes-alt" />
				</span>
				<div className="barrio-launch__text">
					<h2>
						{ __(
							'The site is ready to go online',
							'barrio-suite'
						) }
					</h2>
					<p>
						{ __(
							'Everything in the launch guide is configured.',
							'barrio-suite'
						) }
					</p>
				</div>
				<a className="barrio-button" href={ href }>
					{ href === launchUrl()
						? __( 'Review the guide', 'barrio-suite' )
						: __( 'Back to the dashboard', 'barrio-suite' ) }
				</a>
			</section>
		);
	}
	return (
		<section className="barrio-launch">
			<span className="barrio-launch__icon">
				<Icon name="migrate" />
			</span>
			<div className="barrio-launch__text">
				<h2>{ __( 'Site launch', 'barrio-suite' ) }</h2>
				<p>
					{ sprintf(
						/* translators: %d: number of steps left to set up. */
						__(
							'Follow the step-by-step guide to configure and publish the site: %d steps to go.',
							'barrio-suite'
						),
						left.length
					) }
				</p>
			</div>
			<a className="barrio-button" href={ launchUrl( left[ 0 ].id ) }>
				{ __( 'Start the launch', 'barrio-suite' ) }
				<Icon name="arrow-right-alt" />
			</a>
		</section>
	);
}

export function Launch() {
	const [ launch, setLaunch ] = useState( null );
	const [ saved, setSaved ] = useState( null );
	const [ draft, setDraft ] = useState( null );
	const [ password, setPassword ] = useState( '' );
	const [ current, setCurrent ] = useState(
		new URLSearchParams( window.location.search ).get( 'step' )
	);
	const [ notice, setNotice ] = useState( null );
	const [ busy, setBusy ] = useState( false );

	const load = () =>
		Promise.all( [
			apiFetch( { path: '/barrio/v1/launch' } ),
			apiFetch( { path: '/barrio/v1/settings' } ),
		] ).then( ( [ nextLaunch, settings ] ) => {
			setLaunch( nextLaunch );
			setSaved( settings );
			// Senza un fuso salvato, quello del browser: di solito è quello giusto.
			setDraft( {
				...values( nextLaunch, settings ),
				timezone:
					nextLaunch.timezone ||
					Intl.DateTimeFormat().resolvedOptions().timeZone,
			} );
			setPassword( '' );
			return nextLaunch;
		} );
	useEffect( () => {
		load()
			// Senza ?step= dal primo da fare.
			.then( ( first ) =>
				setCurrent(
					( now ) => now ?? pending( first )[ 0 ]?.id ?? 'end'
				)
			)
			.catch( ( error ) =>
				setNotice( { status: 'error', message: errorMessage( error ) } )
			);
	}, [] );

	const steps = launch
		? STEPS.filter( ( step ) => step.id in launch.steps )
		: [];
	const step = steps.find( ( { id } ) => id === current );
	const dirty =
		!! step &&
		( ( 'forms' === step.id && '' !== password ) ||
			step.fields.some(
				( field ) =>
					JSON.stringify( draft[ field ] ) !==
					JSON.stringify( values( launch, saved )[ field ] )
			) );
	useUnsavedWarning( dirty );

	if ( ! launch ) {
		return notice ? <Notice notice={ notice } /> : <Loading />;
	}

	const go = ( id ) => {
		setCurrent( id );
		setNotice( null );
		window.history.replaceState( null, '', launchUrl( id ) );
	};
	// Al passaggio successivo ancora da fare, poi a quelli lasciati indietro; se non ce n'è, alla fine.
	const advance = ( promise ) => {
		setBusy( true );
		setNotice( null );
		promise
			.then( load )
			.then( ( next ) => {
				const left = pending( next ).filter(
					( { id } ) => id !== current
				);
				const after = steps.slice(
					steps.findIndex( ( { id } ) => id === current ) + 1
				);
				go(
					( after.find( ( s ) => left.includes( s ) ) ?? left[ 0 ] )
						?.id ?? 'end'
				);
			} )
			.catch( ( error ) =>
				setNotice( { status: 'error', message: errorMessage( error ) } )
			)
			.finally( () => setBusy( false ) );
	};
	const set = ( field ) => ( value ) =>
		setDraft( { ...draft, [ field ]: value } );
	const fields = {
		forms: (
			<>
				{ /* Le sezioni delle card di Form → Impostazioni, nello stesso ordine. */ }
				<SectionHead title="SMTP" level={ 3 } />
				<SmtpFields
					smtp={ draft.smtp }
					onChange={ set( 'smtp' ) }
					password={ password }
					onPassword={ setPassword }
					passwordStatus={ saved.password_status }
				/>
				<SectionHead
					title={ __( 'Email template', 'barrio-suite' ) }
					level={ 3 }
				/>
				<TemplateFields
					email={ draft.email }
					onChange={ set( 'email' ) }
				/>
				<SectionHead
					title={ __( 'Test email', 'barrio-suite' ) }
					level={ 3 }
				/>
				<TestEmail dirty={ dirty } onNotice={ setNotice } />
				<SectionHead
					title={ __( 'Privacy', 'barrio-suite' ) }
					level={ 3 }
				/>
				<RetentionField
					privacy={ draft.privacy }
					onChange={ set( 'privacy' ) }
				/>
			</>
		),
		timezone: (
			<Select
				label={ __( 'Time zone', 'barrio-suite' ) }
				value={ draft.timezone }
				options={ zones( launch.timezone ) }
				onChange={ set( 'timezone' ) }
			/>
		),
		search: (
			<Check
				label={ __(
					'Let search engines index the site',
					'barrio-suite'
				) }
				help={ __(
					'The same setting as Search engine visibility in Settings → Reading.',
					'barrio-suite'
				) }
				checked={ draft.search }
				onChange={ set( 'search' ) }
			/>
		),
	};

	let badge = null;
	if ( step && launch.steps[ step.id ] ) {
		badge = (
			<span className="barrio-badge is-success">
				{ __( 'Configured', 'barrio-suite' ) }
			</span>
		);
	} else if ( step && launch.skipped.includes( step.id ) ) {
		badge = (
			<span className="barrio-badge">
				{ __( 'Skipped', 'barrio-suite' ) }
			</span>
		);
	}

	return (
		<div className="barrio-stack">
			<Bar
				title={
					<>
						<a
							className="barrio-icon-button"
							href="?page=barrio"
							aria-label={ __( 'Dashboard', 'barrio-suite' ) }
						>
							<Icon name="arrow-left-alt2" />
						</a>
						<h2 className="barrio-bar__title">
							{ step
								? sprintf(
										/* translators: 1: current step number; 2: total number of steps. */
										__(
											'Step %1$d of %2$d',
											'barrio-suite'
										),
										steps.indexOf( step ) + 1,
										steps.length
									)
								: __( 'Site launch', 'barrio-suite' ) }
						</h2>
					</>
				}
			>
				{ step && ! launch.skipped.includes( step.id ) && (
					<button
						type="button"
						className="barrio-button"
						disabled={ busy }
						onClick={ () =>
							advance(
								apiFetch( {
									path: '/barrio/v1/launch',
									method: 'PUT',
									data: {
										skipped: [ ...launch.skipped, step.id ],
									},
								} )
							)
						}
					>
						<Icon name="controls-skipforward" />
						<span className="barrio-button__text">
							{ __( 'Skip', 'barrio-suite' ) }
						</span>
					</button>
				) }
				{ step && (
					<button
						type="button"
						className="barrio-button is-primary"
						disabled={ busy }
						onClick={ () =>
							advance(
								dirty
									? SAVE[ step.id ]( draft, password )
									: Promise.resolve()
							)
						}
					>
						<Icon name="arrow-right-alt" />
						<span className="barrio-button__text">
							{ dirty
								? __( 'Save and continue', 'barrio-suite' )
								: __( 'Continue', 'barrio-suite' ) }
						</span>
					</button>
				) }
			</Bar>
			<ol className="barrio-steps">
				{ steps.map( ( { id, title }, index ) => {
					let mark = index + 1;
					if ( launch.steps[ id ] ) {
						mark = <Icon name="yes" />;
					} else if ( launch.skipped.includes( id ) ) {
						mark = '–';
					}
					return (
						<li
							key={ id }
							className={
								launch.steps[ id ] ? 'is-done' : undefined
							}
						>
							<button
								type="button"
								aria-current={
									id === current ? 'step' : undefined
								}
								onClick={ () => go( id ) }
							>
								<span className="barrio-steps__mark">
									{ mark }
								</span>
								{ title }
							</button>
						</li>
					);
				} ) }
			</ol>
			<Notice notice={ notice } onClose={ () => setNotice( null ) } />
			{ step ? (
				<Card
					className="barrio-wide"
					icon={ step.icon }
					title={ step.title }
					description={ step.description }
					actions={ badge }
				>
					{ fields[ step.id ] }
				</Card>
			) : (
				<LaunchBanner launch={ launch } href="?page=barrio" />
			) }
		</div>
	);
}
