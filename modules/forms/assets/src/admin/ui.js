// Componenti di base delle pagine admin: HTML nativo con lo stile di admin.css. Da
// @wordpress/components si usa solo ciò che l'HTML non ha (modale, popover e menu, schede).
import {
	Dropdown,
	MenuItem,
	NavigableMenu,
	Spinner,
} from '@wordpress/components';
import {
	cloneElement,
	useEffect,
	useId,
	useLayoutEffect,
	useRef,
	useState,
} from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

import { PER_PAGE, nextSort, pages } from './query.js';

export const pageUrl = ( params = {} ) =>
	'?' + new URLSearchParams( { page: 'barrio-forms', ...params } );

export const entriesUrl = ( params ) =>
	pageUrl( { tab: 'entries', ...params } );

// Un download (file, CSV) è un link e non passa da apiFetch: il nonce va nell'URL. Con i
// permalink semplici l'URL della REST ha già un "?" (?rest_route=).
export function restLink( path, args = {} ) {
	const url = window.barrioAdmin.rest.url + path;
	const query = new URLSearchParams( {
		...args,
		_wpnonce: window.barrioAdmin.rest.nonce,
	} );
	return `${ url }${ url.includes( '?' ) ? '&' : '?' }${ query }`;
}

export const Icon = ( { name } ) => (
	<span className={ `dashicons dashicons-${ name }` } aria-hidden="true" />
);

// Icone di Lucide (licenza ISC), dove le dashicons non bastano.
const LUCIDE = {
	'arrow-up': [ 'm5 12 7-7 7 7', 'M12 19V5' ],
	'arrow-down': [ 'M12 5v14', 'm19 12-7 7-7-7' ],
	'arrow-up-down': [
		'm21 16-4 4-4-4',
		'M17 20V4',
		'm3 8 4-4 4 4',
		'M7 4v16',
	],
	save: [
		'M15.2 3a2 2 0 0 1 1.4.6l3.8 3.8a2 2 0 0 1 .6 1.4V19a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2z',
		'M17 21v-7a1 1 0 0 0-1-1H8a1 1 0 0 0-1 1v7',
		'M7 3v4a1 1 0 0 0 1 1h7',
	],
};

export const Lucide = ( { name, size = 16 } ) => (
	<svg
		viewBox="0 0 24 24"
		width={ size }
		height={ size }
		fill="none"
		stroke="currentColor"
		strokeWidth="2"
		strokeLinecap="round"
		strokeLinejoin="round"
		aria-hidden="true"
	>
		{ LUCIDE[ name ].map( ( d ) => (
			<path key={ d } d={ d } />
		) ) }
	</svg>
);

// Le modifiche non salvate non si perdono chiudendo o ricaricando la pagina per sbaglio.
export function useUnsavedWarning( dirty ) {
	useEffect( () => {
		const warn = ( event ) => {
			event.preventDefault();
			event.returnValue = '';
		};
		if ( dirty ) {
			window.addEventListener( 'beforeunload', warn );
		}
		return () => window.removeEventListener( 'beforeunload', warn );
	}, [ dirty ] );
}

// Porta in vista un elemento appena aggiunto (per esempio in fondo a un elenco lungo).
export function useScrollIntoView( active ) {
	const ref = useRef();
	useEffect( () => {
		if ( active ) {
			ref.current?.scrollIntoView( {
				behavior: 'smooth',
				block: 'nearest',
			} );
		}
	}, [ active ] );
	return ref;
}

export const Loading = () => (
	<div className="barrio-loading">
		<Spinner />
	</div>
);

// Il codice di una lingua (EN-US) con la sua bandiera, se la fonte delle lingue ne ha una (WPML).
export const Lang = ( { code } ) => (
	<span className="barrio-lang">
		{ window.barrioAdmin.flags[ code ] && (
			<img
				className="barrio-flag"
				src={ window.barrioAdmin.flags[ code ] }
				alt=""
			/>
		) }
		{ code.toUpperCase() }
	</span>
);

// Una scelta di un filtro per lingua, con la bandiera.
export const langOption = ( code, label = code.toUpperCase() ) => ( {
	value: code,
	label,
	image: window.barrioAdmin.flags[ code ],
} );

// addon: un bottone accanto al controllo (per esempio "Mostra" della password).
export function Field( { label, help, addon, children } ) {
	const id = useId();
	const control = cloneElement( children, {
		id,
		'aria-describedby': help ? `${ id }-help` : undefined,
	} );
	return (
		<div className="barrio-field">
			<label className="barrio-field__label" htmlFor={ id }>
				{ label }
			</label>
			<div className="barrio-field__control">
				{ addon ? (
					<div className="barrio-row is-nowrap">
						{ control }
						{ addon }
					</div>
				) : (
					control
				) }
				{ help && (
					<p className="barrio-field__help" id={ `${ id }-help` }>
						{ help }
					</p>
				) }
			</div>
		</div>
	);
}

// Come Field, per ciò che non è un singolo controllo (un'etichetta <label> non avrebbe a chi riferirsi).
export const Group = ( { label, help, children } ) => (
	<div className="barrio-field">
		<span className="barrio-field__label">{ label }</span>
		<div className="barrio-field__control">
			{ children }
			{ help && <p className="barrio-field__help">{ help }</p> }
		</div>
	</div>
);

// Un testo facoltativo vuoto lo trasforma in null chi lo usa (docs/database.md: NULL = standard).
export function Text( {
	label,
	help,
	addon,
	value,
	onChange,
	multiline,
	hideLabel,
	...props
} ) {
	const Tag = multiline ? 'textarea' : 'input';
	const input = (
		<Tag
			className="barrio-input"
			value={ value ?? '' }
			onChange={ ( event ) => onChange( event.target.value ) }
			aria-label={ hideLabel ? label : undefined }
			{ ...props }
		/>
	);
	return hideLabel ? (
		input
	) : (
		<Field label={ label } help={ help } addon={ addon }>
			{ input }
		</Field>
	);
}

export function Select( { label, help, value, options, onChange, hideLabel } ) {
	const select = (
		<select
			className="barrio-input"
			value={ value ?? '' }
			onChange={ ( event ) => onChange( event.target.value ) }
			aria-label={ hideLabel ? label : undefined }
		>
			{ options.map( ( option ) => (
				<option key={ option.value } value={ option.value }>
					{ option.label }
				</option>
			) ) }
		</select>
	);
	return hideLabel ? (
		select
	) : (
		<Field label={ label } help={ help }>
			{ select }
		</Field>
	);
}

export function Check( { label, help, checked, onChange, disabled } ) {
	const id = useId();
	return (
		<div className="barrio-check">
			<input
				type="checkbox"
				id={ id }
				aria-describedby={ help ? `${ id }-help` : undefined }
				disabled={ disabled }
				checked={ !! checked }
				onChange={ ( event ) => onChange( event.target.checked ) }
			/>
			<div>
				<label htmlFor={ id }>{ label }</label>
				{ help && (
					<p className="barrio-field__help" id={ `${ id }-help` }>
						{ help }
					</p>
				) }
			</div>
		</div>
	);
}

export const Card = ( {
	icon,
	title,
	description,
	actions,
	children,
	className = '',
} ) => (
	<section className={ `barrio-card ${ className }` }>
		{ title && (
			<header className="barrio-card__header">
				{ icon && (
					<span className="barrio-card__icon">
						<Icon name={ icon } />
					</span>
				) }
				<h2 className="barrio-card__title">{ title }</h2>
				{ actions && (
					<div className="barrio-card__actions">{ actions }</div>
				) }
			</header>
		) }
		<div className="barrio-card__body">
			{ description && (
				<p className="barrio-card__description">{ description }</p>
			) }
			{ children }
		</div>
	</section>
);

// Si copia con un clic dove il browser lo permette (HTTPS o localhost); altrimenti si seleziona.
export function Shortcode( { id, onCopy } ) {
	const code = `[barrio_form id="${ id }"]`;
	if ( ! window.navigator.clipboard ) {
		return (
			<code className="barrio-chip">
				<span className="barrio-chip__text">{ code }</span>
			</code>
		);
	}
	return (
		<button
			type="button"
			className="barrio-chip"
			aria-label={ sprintf(
				/* translators: %s: shortcode del form. */
				__( 'Copy %s', 'barrio-suite' ),
				code
			) }
			onClick={ () =>
				window.navigator.clipboard.writeText( code ).then( onCopy )
			}
		>
			<span className="barrio-button__text barrio-chip__text">
				{ code }
			</span>
			<Icon name="admin-page" />
		</button>
	);
}

// Titolo di una sezione con le sue azioni a destra: ogni elenco ha "Aggiungi" qui, in alto.
export function SectionHead( { title, level = 2, children } ) {
	const Heading = `h${ level }`;
	return (
		<div className="barrio-section-head">
			<Heading>{ title }</Heading>
			{ children && (
				<div className="barrio-row is-nowrap">{ children }</div>
			) }
		</div>
	);
}

// Oltre quante voci compare la ricerca, nelle scelte e nei menu.
const SEARCH_ABOVE = 5;

// La ricerca sopra le liste lunghe: chi la usa sta in un componente a parte, che si monta a ogni
// apertura, così riparte vuota.
function useSearch( items ) {
	const [ search, setSearch ] = useState( '' );
	const term = search.trim().toLowerCase();
	return [
		items.filter( ( item ) => item.label.toLowerCase().includes( term ) ),
		items.length > SEARCH_ABOVE && (
			<input
				type="search"
				className="barrio-picker__search"
				placeholder={ __( 'Search', 'barrio-suite' ) }
				aria-label={ __( 'Search', 'barrio-suite' ) }
				value={ search }
				onChange={ ( event ) => setSearch( event.target.value ) }
			/>
		),
	];
}

/**
 * Menu a tendina: le azioni di una riga (senza text, il bottone "⋯") o una scelta (con text).
 * Con tante voci ha la ricerca, come Picker.
 *
 * @param {Object}  props
 * @param {string}  props.label     Il nome del menu, per chi usa uno screen reader.
 * @param {string}  props.text      Il testo del bottone; senza, solo l'icona.
 * @param {string}  props.icon
 * @param {string}  props.className Del bottone con il testo.
 * @param {boolean} props.inBar     Aperto dalla barra sticky. Gli altri, scorrendo, passano sotto
 *                                  la barra e sotto quella di WordPress (i popover sono nel body).
 * @param {Array}   props.items     { label, icon?, onClick?, href?, danger? }; i falsy si saltano.
 */
export function Menu( {
	label,
	text,
	icon = 'ellipsis',
	className = '',
	inBar,
	items,
} ) {
	return (
		<Dropdown
			popoverProps={ {
				placement: 'bottom-end',
				className: inBar ? undefined : 'barrio-popover-below',
			} }
			renderToggle={ ( { isOpen, onToggle } ) => (
				<button
					type="button"
					className={
						text
							? `barrio-button ${ className }`
							: 'barrio-icon-button'
					}
					aria-label={ text ? undefined : label }
					aria-haspopup="true"
					aria-expanded={ isOpen }
					onClick={ onToggle }
				>
					<Icon name={ icon } />
					{ text && (
						<span className="barrio-button__text">{ text }</span>
					) }
				</button>
			) }
			renderContent={ ( { onClose } ) => (
				<MenuItems
					label={ label }
					items={ items.filter( Boolean ) }
					onClose={ onClose }
				/>
			) }
		/>
	);
}

function MenuItems( { label, items, onClose } ) {
	const [ found, search ] = useSearch( items );
	return (
		<div className="barrio-menu">
			{ search }
			<NavigableMenu role="menu" aria-label={ label }>
				{ found.map( ( item ) => (
					<MenuItem
						key={ item.label }
						icon={ item.icon }
						iconPosition="left"
						href={ item.href }
						isDestructive={ item.danger }
						onClick={ () => {
							onClose();
							item.onClick?.();
						} }
					>
						{ item.label }
					</MenuItem>
				) ) }
			</NavigableMenu>
			{ ! found.length && (
				<p className="barrio-picker__empty">
					{ __( 'No results.', 'barrio-suite' ) }
				</p>
			) }
		</div>
	);
}

// notice: { status: success|error, message, action? }.
export function Notice( { notice, onClose } ) {
	if ( ! notice ) {
		return null;
	}
	return (
		<div
			className={ `barrio-notice is-${ notice.status }` }
			role={ 'error' === notice.status ? 'alert' : 'status' }
		>
			<p>{ notice.message }</p>
			{ notice.action }
			{ onClose && (
				<button
					type="button"
					className="barrio-icon-button"
					aria-label={ __( 'Dismiss', 'barrio-suite' ) }
					onClick={ onClose }
				>
					<Icon name="no-alt" />
				</button>
			) }
		</div>
	);
}

const SORT_ICON = { asc: 'arrow-up', desc: 'arrow-down' };

// Il primo assetto in cui tutto sta su una riga: con i testi; con i bottoni senza testo (il nome
// del form può accorciarsi); anche con la ricerca ridotta a un bottone. Solo se non basta, il
// titolo va sopra e il resto sotto.
const LAYOUTS = [
	[],
	[ 'is-compact' ],
	[ 'is-compact', 'is-searchless' ],
	[ 'is-compact', 'is-stacked' ],
];

function fit( bar ) {
	for ( const classes of LAYOUTS ) {
		bar.classList.remove( 'is-compact', 'is-searchless', 'is-stacked' );
		bar.classList.add( ...classes );
		// Su una riga niente va a capo (admin.css): ci sta se niente esce dalla barra.
		if ( bar.scrollWidth <= bar.clientWidth ) {
			return;
		}
	}
}

/**
 * La barra sticky in alto di ogni pagina, sempre uguale: titolo a sinistra (con il
 * ritorno, se c'è), azioni a destra con la principale per ultima. Non va mai a capo a caso:
 * vedi fit().
 *
 * @param {Object} props
 * @param {Object} props.title    Testo, o un nodo (il nome modificabile del form).
 * @param {Object} props.children Le azioni.
 */
export function Bar( { title, children } ) {
	const ref = useRef();
	// A ogni render: compare "N selezionate", cambia il testo di un filtro...
	useLayoutEffect( () => fit( ref.current ) );
	// ...e quando cambia la larghezza. Al frame dopo: cambiare l'altezza dentro il
	// callback farebbe ripartire l'observer.
	useEffect( () => {
		const bar = ref.current;
		let width;
		let frame;
		const observer = new window.ResizeObserver( ( [ entry ] ) => {
			if ( entry.contentRect.width !== width ) {
				width = entry.contentRect.width;
				window.cancelAnimationFrame( frame );
				frame = window.requestAnimationFrame( () => fit( bar ) );
			}
		} );
		observer.observe( bar );
		return () => {
			observer.disconnect();
			window.cancelAnimationFrame( frame );
		};
	}, [] );
	return (
		<div className="barrio-card barrio-bar" ref={ ref }>
			{ 'string' === typeof title ? (
				<h2 className="barrio-bar__title">{ title }</h2>
			) : (
				title
			) }
			<div className="barrio-bar__end">{ children }</div>
		</div>
	);
}

/**
 * Un pulsante della barra con una scelta e la ricerca quando le opzioni sono tante: il form delle
 * registrazioni, la lingua del builder. La scelta si applica subito e l'elenco resta aperto, così
 * con le frecce si scorrono le opzioni; si chiude con Esc o fuori.
 *
 * @param {Object}                  props
 * @param {string}                  props.label     Il nome dell'elenco.
 * @param {string}                  props.text      Sul pulsante (di default label): l'opzione scelta.
 * @param {string}                  props.image     Prima del testo: la bandiera della lingua scelta.
 * @param {boolean}                 props.active    Diverso dal default.
 * @param {string}                  props.className Del pulsante, oltre a barrio-button.
 * @param {string}                  props.placement Del menu.
 * @param {Array}                   props.options   { value, label, count?, image? }.
 * @param {string}                  props.value
 * @param {(value: string) => void} props.onChange
 */
export function Picker( {
	label,
	text = label,
	image,
	active,
	className = '',
	placement = 'bottom-end',
	options,
	value,
	onChange,
} ) {
	return (
		<Dropdown
			popoverProps={ { placement } }
			renderToggle={ ( { isOpen, onToggle } ) => (
				<button
					type="button"
					className={ `barrio-button ${ className }${
						active ? ' is-active' : ''
					}` }
					aria-expanded={ isOpen }
					onClick={ onToggle }
				>
					{ image && (
						<img className="barrio-flag" src={ image } alt="" />
					) }
					<span className="barrio-button__text">
						{ text !== label && (
							<span className="screen-reader-text">
								{ label }:{ ' ' }
							</span>
						) }
						{ text }
					</span>
					<Icon name="arrow-down-alt2" />
				</button>
			) }
			renderContent={ () => (
				<Choices
					label={ label }
					options={ options }
					value={ value }
					onChange={ onChange }
				/>
			) }
		/>
	);
}

// heading: il nome si vede (le sezioni del pannello dei filtri); da solo lo dice già il pulsante.
function Choices( { label, heading, options, value, multiple, onChange } ) {
	const name = useId();
	const [ found, search ] = useSearch( options );
	return (
		<div className="barrio-picker">
			{ heading && (
				<div className="barrio-picker__heading" aria-hidden="true">
					{ label }
				</div>
			) }
			{ search }
			<fieldset className="barrio-picker__list">
				<legend className="screen-reader-text">{ label }</legend>
				{ found.map( ( option ) => (
					<label
						key={ option.value }
						className="barrio-picker__option"
						htmlFor={ `${ name }-${ option.value }` }
					>
						<input
							type={ multiple ? 'checkbox' : 'radio' }
							id={ `${ name }-${ option.value }` }
							name={ name }
							checked={
								multiple
									? value.includes( option.value )
									: value === option.value
							}
							onChange={ ( event ) =>
								onChange( option.value, event.target.checked )
							}
						/>
						{ option.image && (
							<img
								className="barrio-flag"
								src={ option.image }
								alt=""
							/>
						) }
						{ option.label }
						{ undefined !== option.count && (
							<span className="barrio-picker__count">
								{ option.count }
							</span>
						) }
					</label>
				) ) }
				{ ! found.length && (
					<p className="barrio-picker__empty">
						{ __( 'No results.', 'barrio-suite' ) }
					</p>
				) }
			</fieldset>
		</div>
	);
}

// I filtri di una tabella e le colonne da mostrare in un solo pannello. Sul pulsante il numero
// dei filtri diversi da quelli iniziali; onClear riporta tutta la vista all'inizio (changed: se
// qualcosa è cambiato, anche solo colonne, ordinamento o ricerca).
function Filters( {
	filters,
	query,
	initial,
	onQuery,
	columns,
	shown,
	onColumn,
	changed,
	onClear,
} ) {
	const active = filters.filter(
		( filter ) => query[ filter.key ] !== initial[ filter.key ]
	);
	const label = __( 'Filters', 'barrio-suite' );
	return (
		<Dropdown
			popoverProps={ { placement: 'bottom-end' } }
			renderToggle={ ( { isOpen, onToggle } ) => (
				<button
					type="button"
					className={ `barrio-button${
						active.length ? ' is-active' : ''
					}` }
					aria-expanded={ isOpen }
					onClick={ onToggle }
				>
					<Icon name="filter" />
					<span className="barrio-button__text">{ label }</span>
					{ active.length > 0 && (
						<span className="barrio-button__count">
							<span className="screen-reader-text">
								{ __(
									'Active filters:',
									'barrio-suite'
								) }{ ' ' }
							</span>
							{ active.length }
						</span>
					) }
					<Icon name="arrow-down-alt2" />
				</button>
			) }
			renderContent={ () => (
				<div className="barrio-filters">
					{ filters.map( ( filter ) => (
						<Choices
							key={ filter.key }
							heading
							label={ filter.label }
							options={ filter.options }
							value={ query[ filter.key ] }
							onChange={ ( value ) =>
								onQuery( {
									...query,
									[ filter.key ]: value,
									page: 1,
								} )
							}
						/>
					) ) }
					{ columns.length > 0 && (
						<Choices
							heading
							multiple
							label={ __( 'Columns', 'barrio-suite' ) }
							options={ columns }
							value={ shown }
							onChange={ onColumn }
						/>
					) }
					{ /* Sempre presente: se sparisse al clic, il focus uscirebbe dal pannello e
					non si potrebbe più chiuderlo con Esc o cliccando fuori. */ }
					<button
						type="button"
						className="barrio-button barrio-filters__clear"
						aria-disabled={ ! changed }
						onClick={ () => changed && onClear() }
					>
						{ __( 'Clear filters', 'barrio-suite' ) }
					</button>
				</div>
			) }
		/>
	);
}

/**
 * La tabella di tutte le pagine del plugin, uguale ovunque. Nella barra: ricerca, un pannello
 * Filtri (i filtri, per esempio gli stati se le righe ne hanno, e le colonne da mostrare) e le
 * azioni sulle righe selezionate. Pagine e
 * ordinamento a tre stati, con una colonna sempre ordinata. È controllata: rows è già la pagina
 * da mostrare (localQuery() o il server).
 *
 * @param {Object}                   props
 * @param {Array}                    props.columns { key, label, sort?: campo su cui ordinare, render?( row ), className?, hideable?: nelle colonne da scegliere, hidden?: nascosta all'inizio }.
 * @param {Array}                    props.rows
 * @param {number}                   props.total   Righe trovate, su tutte le pagine.
 * @param {Object}                   props.query   { search, sort, order, page } e i filtri.
 * @param {Object}                   props.initial La query iniziale, con la colonna di default.
 * @param {(query: Object) => void}  props.onQuery
 * @param {string}                   props.title   Il titolo della barra.
 * @param {string}                   props.search  Il segnaposto della ricerca.
 * @param {Array}                    props.filters { key: chiave della query, label, options: [ { value, label, count?, image? } ] }.
 * @param {(ids: number[]) => Array} props.bulk    Le voci del menu per le righe selezionate.
 * @param {string}                   props.empty   Il testo senza righe.
 * @param {Object}                   props.toolbar In fondo alla barra.
 */
export function Table( {
	columns,
	rows,
	total,
	query,
	initial,
	onQuery,
	title,
	search,
	filters: allFilters = [],
	bulk,
	empty,
	toolbar,
} ) {
	// La selezione vale nella query in cui è nata e solo per le righe ancora mostrate: cambiando
	// pagina, filtro o ordine si azzera, e le righe eliminate ne escono da sole.
	const [ selection, setSelection ] = useState( { query, ids: [] } );
	// Le colonne mostrate o nascoste a mano; le altre seguono hidden.
	const [ shown, setShown ] = useState( {} );

	const last = pages( total );
	const page = Math.min( query.page, last );
	const first = total ? ( page - 1 ) * PER_PAGE + 1 : 0;
	const go = ( to ) => onQuery( { ...query, page: to } );
	const searchFor = ( event ) =>
		onQuery( { ...query, search: event.target.value, page: 1 } );

	const ids = rows.map( ( row ) => row.id );
	const selected =
		selection.query === query
			? selection.ids.filter( ( id ) => ids.includes( id ) )
			: [];
	const select = ( list ) => setSelection( { query, ids: list } );
	const all = ids.length > 0 && selected.length === ids.length;
	const filters = allFilters.filter( Boolean );
	const hideable = columns.filter( ( column ) => column?.hideable );
	const isShown = ( column ) => shown[ column.key ] ?? ! column.hidden;
	// La vista è diversa da quella iniziale (la pagina non conta).
	const changed =
		Object.keys( initial ).some(
			( key ) => 'page' !== key && query[ key ] !== initial[ key ]
		) ||
		hideable.some( ( column ) => isShown( column ) === !! column.hidden );
	const toggle = ( column, on ) => {
		setShown( { ...shown, [ column.key ]: on } );
		// Una colonna nascosta non può restare quella ordinata.
		if ( ! on && column.sort === query.sort ) {
			onQuery( {
				...query,
				sort: initial.sort,
				order: initial.order,
				page: 1,
			} );
		}
	};

	const visible = [
		bulk && {
			key: 'select',
			className: 'is-narrow',
			label: (
				<input
					type="checkbox"
					aria-label={ __(
						'Select all on this page',
						'barrio-suite'
					) }
					checked={ all }
					ref={ ( input ) => {
						if ( input ) {
							input.indeterminate = selected.length > 0 && ! all;
						}
					} }
					onChange={ () => select( all ? [] : ids ) }
				/>
			),
			render: ( row ) => (
				<input
					type="checkbox"
					aria-label={ sprintf(
						/* translators: %d: numero della riga (la colonna #). */
						__( 'Select #%d', 'barrio-suite' ),
						row.id
					) }
					checked={ selected.includes( row.id ) }
					onChange={ () =>
						select(
							selected.includes( row.id )
								? selected.filter( ( id ) => id !== row.id )
								: [ ...selected, row.id ]
						)
					}
				/>
			),
		},
		...columns.filter( ( column ) => column && isShown( column ) ),
	].filter( Boolean );

	return (
		<div className="barrio-stack">
			<Bar title={ title }>
				<div className="barrio-bar__tools">
					<div className="barrio-search">
						<Icon name="search" />
						<input
							type="search"
							className="barrio-input"
							placeholder={ search }
							aria-label={ search }
							value={ query.search }
							onChange={ searchFor }
						/>
					</div>
					{ /* Al posto della ricerca quando la barra è stretta: vedi fit(). */ }
					<Dropdown
						className="barrio-search-toggle"
						popoverProps={ { placement: 'bottom-start' } }
						renderToggle={ ( { isOpen, onToggle } ) => (
							<button
								type="button"
								className={ `barrio-button${
									query.search ? ' is-active' : ''
								}` }
								aria-label={ search }
								aria-expanded={ isOpen }
								onClick={ onToggle }
							>
								<Icon name="search" />
							</button>
						) }
						renderContent={ () => (
							<div className="barrio-picker">
								<input
									type="search"
									className="barrio-picker__search"
									placeholder={ search }
									aria-label={ search }
									value={ query.search }
									onChange={ searchFor }
								/>
							</div>
						) }
					/>
					{ ( filters.length > 0 || hideable.length > 0 ) && (
						<Filters
							filters={ filters }
							query={ query }
							initial={ initial }
							onQuery={ onQuery }
							columns={ hideable.map( ( column ) => ( {
								value: column.key,
								label: column.label,
							} ) ) }
							shown={ hideable
								.filter( isShown )
								.map( ( column ) => column.key ) }
							changed={ changed }
							onClear={ () => {
								setShown( {} );
								onQuery( initial );
							} }
							onColumn={ ( key, on ) =>
								toggle(
									hideable.find(
										( column ) => column.key === key
									),
									on
								)
							}
						/>
					) }
					{ selected.length > 0 && (
						<Menu
							inBar
							text={ sprintf(
								/* translators: %d: righe selezionate. */
								__( '%d selected', 'barrio-suite' ),
								selected.length
							) }
							icon="yes-alt"
							label={ __(
								'Actions for the selected rows',
								'barrio-suite'
							) }
							items={ bulk( selected ) }
						/>
					) }
				</div>
				{ toolbar }
			</Bar>
			<div className="barrio-card">
				{ /* Scorre solo la tabella: la paginazione resta larga quanto la card. */ }
				<div className="barrio-table__scroll">
					<table className="barrio-table">
						<thead>
							<tr>
								{ visible.map( ( column ) => {
									const active = query.sort === column.sort;
									let ariaSort;
									if ( active ) {
										ariaSort =
											'asc' === query.order
												? 'ascending'
												: 'descending';
									}
									return (
										<th
											key={ column.key }
											scope="col"
											className={ column.className }
											aria-sort={ ariaSort }
										>
											{ column.sort ? (
												<button
													type="button"
													onClick={ () =>
														onQuery(
															nextSort(
																query,
																column.sort,
																initial
															)
														)
													}
												>
													{ column.label }
													<Lucide
														size={ 14 }
														name={
															active
																? SORT_ICON[
																		query
																			.order
																	]
																: 'arrow-up-down'
														}
													/>
												</button>
											) : (
												column.label
											) }
										</th>
									);
								} ) }
							</tr>
						</thead>
						<tbody>
							{ rows.map( ( row ) => (
								<tr key={ row.id }>
									{ visible.map( ( column ) => (
										<td
											key={ column.key }
											className={ column.className }
										>
											{ column.render
												? column.render( row )
												: row[ column.key ] }
										</td>
									) ) }
								</tr>
							) ) }
							{ ! rows.length && (
								<tr>
									<td
										colSpan={ visible.length }
										className="barrio-table__empty"
									>
										{ empty }
									</td>
								</tr>
							) }
						</tbody>
					</table>
				</div>
				<div className="barrio-table__footer">
					<span>
						{ sprintf(
							/* translators: 1: prima riga mostrata; 2: ultima riga mostrata; 3: righe trovate. */
							__( '%1$d–%2$d of %3$d', 'barrio-suite' ),
							first,
							first + rows.length - ( total ? 1 : 0 ),
							total
						) }
					</span>
					<button
						type="button"
						className="barrio-icon-button"
						aria-label={ __( 'Previous page', 'barrio-suite' ) }
						disabled={ page <= 1 }
						onClick={ () => go( page - 1 ) }
					>
						<Icon name="arrow-left-alt2" />
					</button>
					<span>
						{ sprintf(
							/* translators: 1: pagina corrente; 2: numero di pagine. */
							__( 'Page %1$d of %2$d', 'barrio-suite' ),
							page,
							last
						) }
					</span>
					<button
						type="button"
						className="barrio-icon-button"
						aria-label={ __( 'Next page', 'barrio-suite' ) }
						disabled={ page >= last }
						onClick={ () => go( page + 1 ) }
					>
						<Icon name="arrow-right-alt2" />
					</button>
				</div>
			</div>
		</div>
	);
}
