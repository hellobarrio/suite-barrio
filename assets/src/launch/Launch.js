// Messa online del sito (Bacheca → ?page=barrio&launch): un passaggio alla volta, ognuno con le sue
// impostazioni. Fatto o no lo dice il server secondo le impostazioni vere (GET /barrio/v1/launch,
// src/Launch.php); un passaggio si può saltare, e uno saltato non blocca la messa online.
import apiFetch from '@wordpress/api-fetch';
import { useEffect, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

import { formsStep } from '../../../modules/forms/assets/src/settings/Sections.js';
import {
	Bar,
	Card,
	Check,
	Icon,
	Loading,
	Notice,
	Select,
	errorMessage,
	useUnsavedWarning,
} from '../admin/ui.js';
import { launchSteps } from '../extensions/Extensions.js';
import { smtpStep } from '../general/Smtp.js';

export const launchUrl = ( step ) =>
	'?' +
	new URLSearchParams( {
		page: 'barrio',
		launch: '',
		...( step && { step } ),
	} );

// Quelle che il browser conosce; quella salvata anche se il browser non la conosce. Array.from e non
// [ ...new Set() ]: il parser di wp i18n make-pot non legge la build e perde le stringhe.
const zones = ( saved ) =>
	Array.from( new Set( [ saved, ...Intl.supportedValuesOf( 'timeZone' ) ] ) )
		.filter( Boolean )
		.map( ( zone ) => ( { value: zone, label: zone } ) );

/*
 * Un passaggio: id (la chiave di Launch::steps()), icon, title, description, load() per i suoi dati
 * (facoltativo), value( data, launch ) per i valori salvati nella forma dei campi, initial() se la
 * guida propone altro, save( draft ) e Fields, i campi, con draft, set, data, launch, dirty e onNotice.
 */
const timezoneStep = {
	id: 'timezone',
	icon: 'clock',
	title: __( 'Time zone', 'barrio-suite' ),
	description: __( 'For the dates of entries and emails.', 'barrio-suite' ),
	value: ( data, launch ) => launch.timezone,
	// Senza un fuso salvato, quello del browser: di solito è quello giusto.
	initial: ( data, launch ) =>
		launch.timezone || Intl.DateTimeFormat().resolvedOptions().timeZone,
	save: ( draft ) =>
		apiFetch( {
			path: '/wp/v2/settings',
			method: 'POST',
			data: { timezone: draft },
		} ),
	Fields: ( { draft, set, launch } ) => (
		<Select
			label={ __( 'Time zone', 'barrio-suite' ) }
			value={ draft }
			options={ zones( launch.timezone ) }
			onChange={ set }
		/>
	),
};

const searchStep = {
	id: 'search',
	icon: 'search',
	title: __( 'Search engines', 'barrio-suite' ),
	description: __(
		'While the site was being built WordPress may have asked search engines not to index it.',
		'barrio-suite'
	),
	value: ( data, launch ) => launch.steps.search,
	save: ( draft ) =>
		apiFetch( {
			path: '/barrio/v1/launch',
			method: 'PUT',
			data: { search_engines: draft },
		} ),
	Fields: ( { draft, set } ) => (
		<Check
			label={ __( 'Let search engines index the site', 'barrio-suite' ) }
			help={ __(
				'The same setting as Search engine visibility in Settings → Reading.',
				'barrio-suite'
			) }
			checked={ draft }
			onChange={ set }
		/>
	),
};

// L'ordine della guida: le email, un passaggio per modulo, poi il sito. Quelli che il server non
// restituisce (di un modulo spento) non compaiono. launchSteps dipende dal branch
// (extensions/Extensions.js).
const STEPS = [ smtpStep, formsStep, timezoneStep, searchStep, ...launchSteps ];

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
	// Per passaggio: i suoi dati (da load()) e i valori dei campi.
	const [ data, setData ] = useState( null );
	const [ draft, setDraft ] = useState( null );
	const [ current, setCurrent ] = useState(
		new URLSearchParams( window.location.search ).get( 'step' )
	);
	const [ notice, setNotice ] = useState( null );
	const [ busy, setBusy ] = useState( false );

	const load = () =>
		apiFetch( { path: '/barrio/v1/launch' } ).then( ( next ) => {
			const present = STEPS.filter( ( { id } ) => id in next.steps );
			return Promise.all(
				present.map( ( step ) => ( step.load ? step.load() : null ) )
			).then( ( loaded ) => {
				const nextData = Object.fromEntries(
					present.map( ( { id }, index ) => [ id, loaded[ index ] ] )
				);
				setLaunch( next );
				setData( nextData );
				setDraft(
					Object.fromEntries(
						present.map( ( step ) => [
							step.id,
							( step.initial ?? step.value )(
								nextData[ step.id ],
								next
							),
						] )
					)
				);
				return next;
			} );
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
		JSON.stringify( draft[ step.id ] ) !==
			JSON.stringify( step.value( data[ step.id ], launch ) );
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
									? step.save( draft[ step.id ] )
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
					<step.Fields
						draft={ draft[ step.id ] }
						set={ ( value ) =>
							setDraft( ( now ) => ( {
								...now,
								[ step.id ]: value,
							} ) )
						}
						data={ data[ step.id ] }
						launch={ launch }
						dirty={ dirty }
						onNotice={ setNotice }
					/>
				</Card>
			) : (
				<LaunchBanner launch={ launch } href="?page=barrio" />
			) }
		</div>
	);
}
