// La pagina Barrio → Statistiche (modules/analytics/src/Module.php). Panoramica: il periodo nella barra, i numeri con la
// variazione, l'andamento e gli elenchi, da GET /analytics/report (Google, o la cache di un'ora); la pagina si mostra
// prima che arrivino. Impostazioni, agli amministratori: la proprietà GA4 e i Permessi. La card della Bacheca e il
// widget della Bacheca di WordPress (widget.js) leggono gli ultimi 28 giorni dalla stessa rotta e dalla stessa cache.
import apiFetch from '@wordpress/api-fetch';
import {
	useEffect,
	useLayoutEffect,
	useRef,
	useState,
} from '@wordpress/element';
import { __, _x, sprintf } from '@wordpress/i18n';

import {
	Bar,
	Card,
	Details,
	Loading,
	ModuleCard,
	Notice,
	Permissions,
	Picker,
	Select,
	Text,
	Toggle,
	errorMessage,
} from '../../../../assets/src/admin/ui.js';
import { scale } from './scale.js';

// Anche nella card Moduli di Barrio → Impostazioni.
export const ANALYTICS = {
	icon: 'chart-area',
	title: __( 'Analytics', 'barrio-suite' ),
	description: __(
		'The visits of the site, from Google Analytics',
		'barrio-suite'
	),
	color: 'linear-gradient(135deg, #78350f, #b45309)',
};

// Module::admin_data(), a chi legge le statistiche: missing ('account', 'property' o '') e yesterday. Con il modulo
// spento manca; nel widget non c'è window.barrioAdmin, e non serve.
const analyticsData = () => window.barrioAdmin?.analytics;

// La capability del modulo, nei Permessi (Module::CAP).
const CAPS = { barrio_view_analytics: __( 'View', 'barrio-suite' ) };

// I numeri, le date e i paesi nella lingua di chi guarda.
const locale = document.documentElement.lang || undefined;
const formatNumber = ( value ) =>
	new Intl.NumberFormat( locale ).format( Math.round( value ) );
const formatPercent = ( value ) =>
	new Intl.NumberFormat( locale, {
		style: 'percent',
		maximumFractionDigits: 1,
	} ).format( value );
const formatDuration = ( value ) => {
	const seconds = Math.round( value );
	return sprintf(
		/* translators: 1: minutes, 2: seconds. */
		__( '%1$d min %2$d s', 'barrio-suite' ),
		Math.floor( seconds / 60 ),
		seconds % 60
	);
};
// La variazione rispetto al periodo prima, con il segno: +12%.
const formatChange = ( { value, previous } ) =>
	new Intl.NumberFormat( locale, {
		style: 'percent',
		maximumFractionDigits: 0,
		signDisplay: 'exceptZero',
	} ).format( ( value - previous ) / previous );

// I numeri in alto, con le chiavi di Module::METRICS.
const NUMBERS = [
	[ 'users', __( 'Users', 'barrio-suite' ), formatNumber ],
	[ 'views', __( 'Views', 'barrio-suite' ), formatNumber ],
	[ 'sessions', __( 'Sessions', 'barrio-suite' ), formatNumber ],
	// Della sessione: "Durata media della sessione" non sta in una card stretta.
	[ 'duration', __( 'Average duration', 'barrio-suite' ), formatDuration ],
	[ 'bounce', __( 'Bounce rate', 'barrio-suite' ), formatPercent ],
];

const PERIODS = [
	{ value: '7', label: __( 'Last 7 days', 'barrio-suite' ) },
	{ value: '28', label: __( 'Last 28 days', 'barrio-suite' ) },
	{ value: '90', label: __( 'Last 90 days', 'barrio-suite' ) },
	{ value: '365', label: __( 'Last 12 months', 'barrio-suite' ) },
	{ value: 'custom', label: __( 'Custom', 'barrio-suite' ) },
];

// I canali predefiniti di GA4 e i dispositivi sono insiemi chiusi; un nome che non c'è resta quello di GA.
const CHANNELS = {
	Direct: _x( 'Direct', 'traffic channel', 'barrio-suite' ),
	'Organic Search': _x( 'Organic search', 'traffic channel', 'barrio-suite' ),
	'Paid Search': _x( 'Paid search', 'traffic channel', 'barrio-suite' ),
	'Organic Social': _x( 'Organic social', 'traffic channel', 'barrio-suite' ),
	'Paid Social': _x( 'Paid social', 'traffic channel', 'barrio-suite' ),
	'Organic Video': _x( 'Organic video', 'traffic channel', 'barrio-suite' ),
	'Paid Video': _x( 'Paid video', 'traffic channel', 'barrio-suite' ),
	'Organic Shopping': _x(
		'Organic shopping',
		'traffic channel',
		'barrio-suite'
	),
	'Paid Shopping': _x( 'Paid shopping', 'traffic channel', 'barrio-suite' ),
	'Paid Other': _x( 'Other paid', 'traffic channel', 'barrio-suite' ),
	'Cross-network': _x( 'Cross-network', 'traffic channel', 'barrio-suite' ),
	Display: _x( 'Display', 'traffic channel', 'barrio-suite' ),
	Email: _x( 'Email', 'traffic channel', 'barrio-suite' ),
	Referral: _x( 'Referral', 'traffic channel', 'barrio-suite' ),
	Affiliates: _x( 'Affiliates', 'traffic channel', 'barrio-suite' ),
	Audio: _x( 'Audio', 'traffic channel', 'barrio-suite' ),
	SMS: _x( 'SMS', 'traffic channel', 'barrio-suite' ),
	'Mobile Push Notifications': _x(
		'Push notifications',
		'traffic channel',
		'barrio-suite'
	),
	'AI Assistant': _x( 'AI assistants', 'traffic channel', 'barrio-suite' ),
	Unassigned: _x( 'Unassigned', 'traffic channel', 'barrio-suite' ),
};

const DEVICES = {
	desktop: _x( 'Desktop', 'device', 'barrio-suite' ),
	mobile: _x( 'Mobile', 'device', 'barrio-suite' ),
	tablet: _x( 'Tablet', 'device', 'barrio-suite' ),
	'smart tv': _x( 'Smart TV', 'device', 'barrio-suite' ),
};

// Il nome di un paese dal suo codice (IT); un codice che il browser non conosce resta com'è.
const country = ( code ) => {
	if ( '(not set)' === code ) {
		return __( 'Unknown', 'barrio-suite' );
	}
	try {
		return new Intl.DisplayNames( locale, { type: 'region' } ).of( code );
	} catch {
		return code;
	}
};

// Il primo giorno di un punto (Y-m-d), a mezzogiorno: nessun fuso lo sposta di un giorno.
const day = ( date ) => new Date( `${ date }T12:00:00` );
const dateFormat = ( options ) => new Intl.DateTimeFormat( locale, options );
const SHORT = { day: 'numeric', month: 'short' };

// Sotto l'asse, e per intero nel riquadro e nella tabella.
const axisLabel = ( unit, date ) =>
	dateFormat(
		'month' === unit ? { month: 'short', year: '2-digit' } : SHORT
	).format( day( date ) );
const pointLabel = ( unit, date ) => {
	if ( 'week' === unit ) {
		return sprintf(
			/* translators: %s: the first day of a week, like 3 Nov. */
			__( 'Week of %s', 'barrio-suite' ),
			dateFormat( SHORT ).format( day( date ) )
		);
	}
	return dateFormat(
		'month' === unit
			? { month: 'long', year: 'numeric' }
			: { weekday: 'short', ...SHORT }
	).format( day( date ) );
};

// Y-m-d di $days giorni prima.
const daysBefore = ( date, days ) => {
	const value = new Date( `${ date }T12:00:00Z` );
	value.setUTCDate( value.getUTCDate() - days );
	return value.toISOString().slice( 0, 10 );
};

/**
 * GET /analytics/report: { report } quando arriva, { notice } con un errore, {} mentre si carica. Senza query non
 * chiama Google.
 *
 * @param {Object|null} query period, e from e to con custom.
 */
function useReport( query ) {
	const [ state, setState ] = useState( {} );
	const path =
		query &&
		`/barrio/v1/analytics/report?${ new URLSearchParams( query ) }`;
	useEffect( () => {
		if ( ! path ) {
			return;
		}
		let current = true;
		setState( {} );
		apiFetch( { path } )
			.then( ( report ) => current && setState( { report } ) )
			.catch(
				( error ) =>
					current &&
					setState( {
						notice: {
							status: 'error',
							message: errorMessage( error ),
						},
					} )
			);
		return () => {
			current = false;
		};
	}, [ path ] );
	return state;
}

// La variazione rispetto al periodo prima, nessuna se prima era zero. Per il rimbalzo scendere è verde.
function Change( { number, lower } ) {
	if ( ! number.previous ) {
		return null;
	}
	const rounded = Math.round(
		( ( number.value - number.previous ) / number.previous ) * 100
	);
	let tone = '';
	if ( rounded ) {
		tone = ( lower ? rounded < 0 : rounded > 0 ) ? ' is-good' : ' is-bad';
	}
	return (
		<span className={ `barrio-change${ tone }` }>
			<span className="screen-reader-text">
				{ __(
					'Compared with the previous period:',
					'barrio-suite'
				) }{ ' ' }
			</span>
			{ formatChange( number ) }
		</span>
	);
}

// I 5 numeri con la variazione; trattini mentre si caricano.
export const Numbers = ( { numbers } ) => (
	<dl className="barrio-numbers">
		{ NUMBERS.map( ( [ key, label, format ] ) => (
			<div key={ key } className="barrio-number">
				<dt>{ label }</dt>
				<dd>
					<strong>
						{ numbers ? format( numbers[ key ].value ) : '—' }
					</strong>
					{ numbers && (
						<Change
							number={ numbers[ key ] }
							lower={ 'bounce' === key }
						/>
					) }
				</dd>
			</div>
		) ) }
	</dl>
);

// La larghezza di un elemento, che segue la finestra: il grafico si disegna in pixel, così il testo non si rimpicciolisce.
function useWidth() {
	const ref = useRef();
	const [ width, setWidth ] = useState( 0 );
	useLayoutEffect( () => {
		const observer = new window.ResizeObserver( ( [ entry ] ) =>
			setWidth( Math.floor( entry.contentRect.width ) )
		);
		observer.observe( ref.current );
		return () => observer.disconnect();
	}, [] );
	return [ ref, width ];
}

const SERIES = [
	[ 'views', __( 'Views', 'barrio-suite' ) ],
	[ 'users', __( 'Users', 'barrio-suite' ) ],
];

// Margini del disegno: a sinistra i numeri dell'asse, sotto le date.
const LEFT = 48;
const RIGHT = 8;
const TOP = 8;
const BOTTOM = 24;

/**
 * L'andamento: due linee in un SVG scritto a mano, con la legenda. Il punto si sceglie col mouse o, dopo il Tab, con le
 * frecce, Home e Fine: è uno slider, e dice data e valori. La tabella nascosta ha tutti i punti per gli screen reader.
 * ponytail: grafico a mano; una libreria se servono altri tipi di grafico.
 *
 * @param {Object} props
 * @param {Object} props.trend  { unit: day|week|month, points: [ { date, views, users } ] }.
 * @param {number} props.height
 */
export function Chart( { trend, height = 240 } ) {
	const [ ref, width ] = useWidth();
	const [ active, setActive ] = useState( null );
	const { unit, points } = trend;
	const ticks = scale(
		Math.max(
			...points.flatMap( ( point ) => [ point.views, point.users ] )
		)
	);
	const top = ticks[ ticks.length - 1 ];
	const plot = Math.max( width - LEFT - RIGHT, 1 );
	const last = points.length - 1;
	const x = ( index ) => LEFT + ( last ? ( index * plot ) / last : plot / 2 );
	const y = ( value ) =>
		TOP + ( height - TOP - BOTTOM ) * ( 1 - value / top );
	// Una data ogni 80px circa.
	const every = Math.ceil( points.length / Math.max( 1, plot / 80 ) );
	const values = ( point ) =>
		SERIES.map(
			( [ key, label ] ) =>
				`${ label }: ${ formatNumber( point[ key ] ) }`
		).join( ', ' );
	const current = points[ active ?? last ];

	const pick = ( event ) => {
		const box = event.currentTarget.getBoundingClientRect();
		const index = Math.round(
			( ( event.clientX - box.left - LEFT ) / plot ) * last
		);
		setActive( Math.min( last, Math.max( 0, index ) ) );
	};
	const move = ( event ) => {
		const step = { ArrowLeft: -1, ArrowRight: 1, Home: -last, End: last }[
			event.key
		];
		if ( step ) {
			event.preventDefault();
			setActive( ( now ) =>
				Math.min( last, Math.max( 0, ( now ?? last ) + step ) )
			);
		}
	};

	return (
		<div className="barrio-chart" ref={ ref }>
			<ul className="barrio-chart__legend" aria-hidden="true">
				{ SERIES.map( ( [ key, label ] ) => (
					<li key={ key } className={ `is-${ key }` }>
						{ label }
					</li>
				) ) }
			</ul>
			{ width > 0 && (
				<svg
					width={ width }
					height={ height }
					role="slider"
					tabIndex="0"
					aria-label={ __( 'Trend', 'barrio-suite' ) }
					aria-valuemin="0"
					aria-valuemax={ last }
					aria-valuenow={ active ?? last }
					aria-valuetext={ `${ pointLabel(
						unit,
						current.date
					) }: ${ values( current ) }` }
					onMouseMove={ pick }
					onMouseLeave={ () => setActive( null ) }
					onFocus={ () => setActive( last ) }
					onBlur={ () => setActive( null ) }
					onKeyDown={ move }
				>
					{ ticks.map( ( tick ) => (
						<g key={ tick }>
							<line
								className="barrio-chart__grid"
								x1={ LEFT }
								x2={ width - RIGHT }
								y1={ y( tick ) }
								y2={ y( tick ) }
							/>
							<text
								className="barrio-chart__tick"
								x={ LEFT - 8 }
								y={ y( tick ) }
								dy="0.32em"
								textAnchor="end"
							>
								{ formatNumber( tick ) }
							</text>
						</g>
					) ) }
					{ points.map(
						( point, index ) =>
							index % every === 0 && (
								<text
									key={ point.date }
									className="barrio-chart__tick"
									x={ x( index ) }
									y={ height - 6 }
									textAnchor={
										index === last && last
											? 'end'
											: 'middle'
									}
								>
									{ axisLabel( unit, point.date ) }
								</text>
							)
					) }
					{ SERIES.map( ( [ key ] ) => (
						<polyline
							key={ key }
							className={ `barrio-chart__line is-${ key }` }
							points={ points
								.map(
									( point, index ) =>
										`${ x( index ) },${ y( point[ key ] ) }`
								)
								.join( ' ' ) }
						/>
					) ) }
					{ null !== active && (
						<g>
							<line
								className="barrio-chart__cursor"
								x1={ x( active ) }
								x2={ x( active ) }
								y1={ TOP }
								y2={ height - BOTTOM }
							/>
							{ SERIES.map( ( [ key ] ) => (
								<circle
									key={ key }
									className={ `barrio-chart__dot is-${ key }` }
									cx={ x( active ) }
									cy={ y( points[ active ][ key ] ) }
									r="4"
								/>
							) ) }
						</g>
					) }
				</svg>
			) }
			{ null !== active && (
				<div
					className="barrio-chart__tip"
					aria-hidden="true"
					// Dalla parte con più spazio.
					style={
						x( active ) > width / 2
							? { right: width - x( active ) + 12 }
							: { left: x( active ) + 12 }
					}
				>
					<strong>{ pointLabel( unit, current.date ) }</strong>
					{ SERIES.map( ( [ key, label ] ) => (
						<span key={ key } className={ `is-${ key }` }>
							{ label }: { formatNumber( current[ key ] ) }
						</span>
					) ) }
				</div>
			) }
			{ /* In un div: una tabella non prende l'altezza di 1px di screen-reader-text, e allungherebbe la pagina. */ }
			<div className="screen-reader-text">
				<table>
					<caption>{ __( 'Trend', 'barrio-suite' ) }</caption>
					<thead>
						<tr>
							<th scope="col">
								{ __( 'Date', 'barrio-suite' ) }
							</th>
							{ SERIES.map( ( [ key, label ] ) => (
								<th key={ key } scope="col">
									{ label }
								</th>
							) ) }
						</tr>
					</thead>
					<tbody>
						{ points.map( ( point ) => (
							<tr key={ point.date }>
								<th scope="row">
									{ pointLabel( unit, point.date ) }
								</th>
								{ SERIES.map( ( [ key ] ) => (
									<td key={ key }>
										{ formatNumber( point[ key ] ) }
									</td>
								) ) }
							</tr>
						) ) }
					</tbody>
				</table>
			</div>
		</div>
	);
}

/**
 * Uno degli elenchi dei primi 10, con una barra in proporzione al primo. Un elenco, non una tabella.
 *
 * @param {Object}                  props
 * @param {string}                  props.icon
 * @param {string}                  props.title
 * @param {string}                  props.metric Cosa contano i numeri.
 * @param {Array}                   props.rows   { name, value, url? }; undefined mentre si caricano.
 * @param {(row: Object) => Object} props.name   Il testo di una riga.
 */
function List( { icon, title, metric, rows, name } ) {
	const max = Math.max( 1, ...( rows ?? [] ).map( ( row ) => row.value ) );
	return (
		<Card
			icon={ icon }
			title={ title }
			actions={ <span className="barrio-list-metric">{ metric }</span> }
		>
			{ ! rows && <Loading /> }
			{ rows?.length === 0 && (
				<p className="barrio-card__description">
					{ __( 'No data in this period.', 'barrio-suite' ) }
				</p>
			) }
			{ rows?.length > 0 && (
				<ol className="barrio-bars">
					{ rows.map( ( row, index ) => (
						// Due pagine possono avere lo stesso percorso su due host.
						<li key={ index }>
							<span className="barrio-bars__name">
								{ name( row ) }
							</span>
							<span className="barrio-bars__value">
								{ formatNumber( row.value ) }
							</span>
							<span
								className="barrio-bars__bar"
								style={ {
									width: `${ ( row.value / max ) * 100 }%`,
								} }
							/>
						</li>
					) ) }
				</ol>
			) }
		</Card>
	);
}

// La pagina: il titolo del contenuto, con il link al sito che GA ha registrato (il frontend nei siti headless).
const pageName = ( row ) =>
	row.url ? (
		<a href={ row.url } target="_blank" rel="noreferrer" title={ row.path }>
			{ row.name }
		</a>
	) : (
		<span title={ row.path }>{ row.name }</span>
	);

// Senza service account o proprietà: il passo che manca, agli amministratori con il link; agli altri chi può farlo.
function Setup( { missing } ) {
	if ( ! window.barrioAdmin.canManageOptions ) {
		return (
			<Notice
				notice={ {
					status: 'info',
					message: __(
						'Analytics is not set up yet: ask an administrator to connect Google Analytics.',
						'barrio-suite'
					),
				} }
			/>
		);
	}
	const [ message, link, text ] =
		'account' === missing
			? [
					__(
						'To read the visits from Google Analytics, connect the Google service account first.',
						'barrio-suite'
					),
					'?page=barrio-settings',
					__( 'Open Settings', 'barrio-suite' ),
				]
			: [
					__(
						'Choose the Google Analytics property of the site.',
						'barrio-suite'
					),
					'?page=barrio-analytics&tab=settings',
					__( 'Choose the property', 'barrio-suite' ),
				];
	return (
		<Notice
			notice={ {
				status: 'info',
				message,
				action: (
					<a className="barrio-button" href={ link }>
						{ text }
					</a>
				),
			} }
		/>
	);
}

// Numeri, andamento ed elenchi del periodo; le card ci sono subito, i dati quando arrivano.
function Report( { report } ) {
	return (
		<>
			<Numbers numbers={ report?.numbers } />
			<Card icon="chart-line" title={ __( 'Trend', 'barrio-suite' ) }>
				{ report ? <Chart trend={ report.trend } /> : <Loading /> }
			</Card>
			<div className="barrio-lists">
				<List
					icon="admin-page"
					title={ __( 'Most viewed pages', 'barrio-suite' ) }
					metric={ __( 'Views', 'barrio-suite' ) }
					rows={ report?.pages }
					name={ pageName }
				/>
				<List
					icon="randomize"
					title={ __( 'Channels', 'barrio-suite' ) }
					metric={ __( 'Sessions', 'barrio-suite' ) }
					rows={ report?.channels }
					name={ ( row ) => CHANNELS[ row.name ] ?? row.name }
				/>
				<List
					icon="smartphone"
					title={ __( 'Devices', 'barrio-suite' ) }
					metric={ __( 'Users', 'barrio-suite' ) }
					rows={ report?.devices }
					name={ ( row ) => DEVICES[ row.name ] ?? row.name }
				/>
				<List
					icon="admin-site-alt3"
					title={ __( 'Countries', 'barrio-suite' ) }
					metric={ __( 'Users', 'barrio-suite' ) }
					rows={ report?.countries }
					name={ ( row ) => country( row.name ) }
				/>
			</div>
			{ report && (
				<p className="barrio-note">
					{ report.thresholded &&
						__(
							'Google Analytics hides the groups with few users, so the lists can add up to less than the totals.',
							'barrio-suite'
						) }{ ' ' }
					{ sprintf(
						/* translators: %s: a time, like 10:32. */
						__(
							'Data from Google Analytics, updated at %s.',
							'barrio-suite'
						),
						report.updated
					) }
				</p>
			) }
		</>
	);
}

// Barrio → Statistiche → Panoramica.
export function Overview() {
	const { missing, yesterday } = analyticsData();
	const [ period, setPeriod ] = useState( '28' );
	// Personalizzato parte dagli ultimi 28 giorni.
	const [ dates, setDates ] = useState( {
		from: daysBefore( yesterday, 27 ),
		to: yesterday,
	} );
	let query = { period };
	if ( missing ) {
		query = null;
	} else if ( 'custom' === period ) {
		query =
			dates.from && dates.to && dates.from <= dates.to
				? { period, ...dates }
				: null;
	}
	const { report, notice } = useReport( query );

	return (
		<div className="barrio-stack">
			<Bar title={ __( 'Overview', 'barrio-suite' ) }>
				{ ! missing && (
					<Picker
						label={ __( 'Period', 'barrio-suite' ) }
						text={
							PERIODS.find(
								( option ) => option.value === period
							).label
						}
						icon="calendar-alt"
						options={ PERIODS }
						value={ period }
						onChange={ setPeriod }
					/>
				) }
			</Bar>
			{ missing && <Setup missing={ missing } /> }
			{ ! missing && 'custom' === period && (
				<div className="barrio-card barrio-dates">
					<Text
						type="date"
						label={ __( 'From', 'barrio-suite' ) }
						max={ dates.to || yesterday }
						value={ dates.from }
						onChange={ ( from ) => setDates( { ...dates, from } ) }
					/>
					<Text
						type="date"
						label={ __( 'To', 'barrio-suite' ) }
						min={ dates.from }
						max={ yesterday }
						value={ dates.to }
						onChange={ ( to ) => setDates( { ...dates, to } ) }
					/>
				</div>
			) }
			<Notice notice={ notice } />
			{ ! missing && ! notice && <Report report={ report } /> }
		</div>
	);
}

// Il widget della Bacheca di WordPress (widget.js): numeri e andamento degli ultimi 28 giorni.
export function Widget() {
	const { report, notice } = useReport( { period: '28' } );
	return (
		<div className="barrio-admin barrio-widget">
			<p className="barrio-note">
				{ __( 'Last 28 days', 'barrio-suite' ) }
			</p>
			<Notice notice={ notice } />
			{ ! notice && (
				<>
					<Numbers numbers={ report?.numbers } />
					{ report ? (
						<Chart trend={ report.trend } height={ 160 } />
					) : (
						<Loading />
					) }
				</>
			) }
			<a className="barrio-button" href="admin.php?page=barrio-analytics">
				{ __( 'All analytics', 'barrio-suite' ) }
			</a>
		</div>
	);
}

// Nella Bacheca: utenti e visualizzazioni degli ultimi 28 giorni con la variazione, e la pagina più vista.
export function AnalyticsCard() {
	const data = analyticsData();
	const { report, notice } = useReport(
		data && ! data.missing ? { period: '28' } : null
	);
	if ( ! data ) {
		return null;
	}
	const number = ( key ) => {
		const text = formatNumber( report.numbers[ key ].value );
		return report.numbers[ key ].previous
			? `${ text } · ${ formatChange( report.numbers[ key ] ) }`
			: text;
	};
	let stats = null;
	if ( data.missing ) {
		stats = [
			[
				__( 'Google Analytics', 'barrio-suite' ),
				__( 'To be set up', 'barrio-suite' ),
			],
		];
	} else if ( report ) {
		stats = [
			[ __( 'Users in 28 days', 'barrio-suite' ), number( 'users' ) ],
			[ __( 'Views in 28 days', 'barrio-suite' ), number( 'views' ) ],
			[
				__( 'Most viewed page', 'barrio-suite' ),
				report.pages[ 0 ]?.name ?? '—',
			],
		];
	}
	return (
		<ModuleCard
			module={ ANALYTICS }
			notice={ notice }
			stats={ stats }
			actions={ [
				[ ANALYTICS.title, '?page=barrio-analytics' ],
				window.barrioAdmin.canManageOptions && [
					__( 'Settings', 'barrio-suite' ),
					'?page=barrio-analytics&tab=settings',
				],
			].filter( Boolean ) }
		/>
	);
}

// Le proprietà di Google, più quella salvata se Google non la elenca (o non le ha ancora date).
function Property( { email, value, onChange, onNotice } ) {
	const [ options, setOptions ] = useState( null );
	useEffect( () => {
		if ( ! email ) {
			return;
		}
		let current = true;
		apiFetch( { path: '/barrio/v1/analytics/properties' } )
			.then( ( found ) => current && setOptions( found ) )
			.catch( ( error ) =>
				onNotice( { status: 'error', message: errorMessage( error ) } )
			);
		return () => {
			current = false;
		};
	}, [ email ] ); // eslint-disable-line react-hooks/exhaustive-deps -- onNotice cambia a ogni render della pagina.

	let help = __(
		'The properties the service account can read. Changing it shows the data of the new property at once.',
		'barrio-suite'
	);
	if ( ! email ) {
		help = __(
			'Connect the Google service account in Barrio → Settings first.',
			'barrio-suite'
		);
	} else if ( ! options ) {
		help = __( 'Loading from Google…', 'barrio-suite' );
	} else if ( ! options.length ) {
		help = __(
			'Google lists no property: add the service account as a Viewer, as explained below.',
			'barrio-suite'
		);
	}
	const list = options ?? [];
	return (
		<fieldset className="barrio-fixed" disabled={ ! email }>
			<Select
				label={ __( 'Property', 'barrio-suite' ) }
				help={ help }
				value={ value }
				options={ [
					{ value: '', label: '—' },
					...( value &&
					! list.some( ( option ) => option.value === value )
						? [
								{
									value,
									label: value.replace( 'properties/', '' ),
								},
							]
						: [] ),
					...list,
				] }
				onChange={ onChange }
			/>
		</fieldset>
	);
}

// GET e PUT /barrio/v1/analytics, con il Salva della barra (general/General.js): la proprietà e i ruoli.
export const analyticsSection = {
	id: 'analytics',
	load: () => apiFetch( { path: '/barrio/v1/analytics' } ),
	value: ( { property, replace_dashboard: replace, roles } ) => ( {
		property,
		replace_dashboard: replace,
		roles,
	} ),
	save: ( draft ) =>
		apiFetch( {
			path: '/barrio/v1/analytics',
			method: 'PUT',
			data: draft,
		} ),
	Cards: ( { data, draft, set, onNotice } ) => (
		<>
			<Card
				className="barrio-wide"
				icon="chart-area"
				title={ __( 'Google Analytics', 'barrio-suite' ) }
				description={ __(
					'The GA4 property of the site. Analytics reads its reports with the Google service account of Barrio → Settings, only when someone opens them, and keeps them for an hour.',
					'barrio-suite'
				) }
			>
				<Property
					email={ data.email }
					value={ draft.property }
					onChange={ ( property ) => set( { ...draft, property } ) }
					onNotice={ onNotice }
				/>
				<Details title={ __( 'How to connect it', 'barrio-suite' ) }>
					<ol className="barrio-howto">
						<li>
							{ __(
								'In the Google Cloud project of the service account, turn on the Google Analytics Data API and the Google Analytics Admin API.',
								'barrio-suite'
							) }
						</li>
						<li>
							{ __(
								'In Google Analytics, open Admin → Property access management and add the service account with the Viewer role:',
								'barrio-suite'
							) }{ ' ' }
							<code>
								{ data.email ||
									__(
										'the email of the service account',
										'barrio-suite'
									) }
							</code>
						</li>
						<li>
							{ __(
								'Come back here, choose the property and save.',
								'barrio-suite'
							) }
						</li>
					</ol>
				</Details>
			</Card>
			<Card
				className="barrio-wide"
				icon="dashboard"
				title={ __( 'WordPress dashboard', 'barrio-suite' ) }
			>
				<Toggle
					label={ __(
						'Replace the WordPress dashboard',
						'barrio-suite'
					) }
					help={ __(
						'For who can see the analytics, the WordPress dashboard shows the overview of this page, without the settings and without the widgets, those of the other plugins too. The other roles keep the dashboard as it is.',
						'barrio-suite'
					) }
					checked={ draft.replace_dashboard }
					onChange={ ( on ) =>
						set( { ...draft, replace_dashboard: on } )
					}
				/>
			</Card>
			<Permissions
				description={ __(
					'Who can see the analytics, in Barrio → Analytics, in the Barrio dashboard and in the dashboard of WordPress. Administrators can always do everything; only administrators change these settings.',
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
