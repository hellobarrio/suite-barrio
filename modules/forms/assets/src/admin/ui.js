// Componenti di base delle pagine admin: HTML nativo con lo stile di admin.css. Da
// @wordpress/components si usa solo ciò che l'HTML non ha (modale, popover e menu, schede).
import {
	Dropdown,
	MenuItem,
	NavigableMenu,
	Spinner,
} from '@wordpress/components';
import { cloneElement, useEffect, useId, useRef } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

import { PER_PAGE, nextSort, pages } from './query.js';

export const pageUrl = ( params = {} ) =>
	'?' + new URLSearchParams( { page: 'barrio-forms', ...params } );

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
		return <code className="barrio-chip">{ code }</code>;
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
			{ code }
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

/**
 * Menu a tendina: le azioni di una riga (senza text, il bottone "⋯") o una scelta (con text).
 *
 * @param {Object} props
 * @param {string} props.label Il nome del menu, per chi usa uno screen reader.
 * @param {string} props.text  Il testo del bottone; senza, solo l'icona.
 * @param {string} props.icon
 * @param {Array}  props.items { label, icon?, onClick?, href?, danger? }; i falsy si saltano.
 */
export function Menu( { label, text, icon = 'ellipsis', items } ) {
	return (
		<Dropdown
			popoverProps={ { placement: 'bottom-end' } }
			renderToggle={ ( { isOpen, onToggle } ) => (
				<button
					type="button"
					className={ text ? 'barrio-button' : 'barrio-icon-button' }
					aria-label={ text ? undefined : label }
					aria-haspopup="true"
					aria-expanded={ isOpen }
					onClick={ onToggle }
				>
					<Icon name={ icon } />
					{ text }
				</button>
			) }
			renderContent={ ( { onClose } ) => (
				<NavigableMenu
					role="menu"
					aria-label={ label }
					className="barrio-menu"
				>
					{ items.filter( Boolean ).map( ( item ) => (
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
			) }
		/>
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

/**
 * La barra sticky in alto di ogni pagina, sempre uguale: titolo a sinistra (con il
 * ritorno, se c'è), azioni a destra con la principale per ultima.
 *
 * @param {Object} props
 * @param {Object} props.title    Testo, o un nodo (il nome modificabile del form).
 * @param {Object} props.children Le azioni.
 */
export function Bar( { title, children } ) {
	return (
		<div className="barrio-card barrio-bar">
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
 * La tabella di tutte le pagine del plugin: ricerca, pagine e ordinamento a tre stati, con
 * una colonna sempre ordinata. È controllata: rows è già la pagina da mostrare (localQuery()
 * o il server).
 *
 * @param {Object}                  props
 * @param {Array}                   props.columns { key, label, sort?: campo su cui ordinare, render?( row ), className? }.
 * @param {Array}                   props.rows
 * @param {number}                  props.total   Righe trovate, su tutte le pagine.
 * @param {Object}                  props.query   { search, sort, order, page }.
 * @param {Object}                  props.initial La query iniziale, con la colonna di default.
 * @param {(query: Object) => void} props.onQuery
 * @param {string}                  props.title   Il titolo della barra.
 * @param {string}                  props.search  Il segnaposto della ricerca.
 * @param {string}                  props.empty   Il testo senza righe.
 * @param {Object}                  props.toolbar Nella barra, dopo la ricerca.
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
	empty,
	toolbar,
} ) {
	const last = pages( total );
	const page = Math.min( query.page, last );
	const first = total ? ( page - 1 ) * PER_PAGE + 1 : 0;
	const go = ( to ) => onQuery( { ...query, page: to } );

	return (
		<div className="barrio-stack">
			<Bar title={ title }>
				<div className="barrio-search">
					<Icon name="search" />
					<input
						type="search"
						className="barrio-input"
						placeholder={ search }
						aria-label={ search }
						value={ query.search }
						onChange={ ( event ) =>
							onQuery( {
								...query,
								search: event.target.value,
								page: 1,
							} )
						}
					/>
				</div>
				{ toolbar }
			</Bar>
			<div className="barrio-card barrio-table__card">
				<table className="barrio-table">
					<thead>
						<tr>
							{ columns.map( ( column ) => {
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
																	query.order
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
								{ columns.map( ( column ) => (
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
									colSpan={ columns.length }
									className="barrio-table__empty"
								>
									{ empty }
								</td>
							</tr>
						) }
					</tbody>
				</table>
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
