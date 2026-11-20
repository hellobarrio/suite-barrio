// Popup e banner (src/Period.php): l'elenco, lo stato e il periodo, nell'ora del sito, e i testi per lingua, tradotti
// come nel builder dei form (TextIn): nelle altre lingue un testo vuoto vale quello della lingua principale.
import apiFetch from '@wordpress/api-fetch';
import { getSettings, gmdateI18n } from '@wordpress/date';
import { useEffect, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

import {
	setTranslation,
	textIn,
} from '../../../modules/forms/assets/src/builder/model.js';
import { initialQuery, localQuery } from './query.js';
import {
	Card,
	Icon,
	Lang,
	Loading,
	Menu,
	Notice,
	Table,
	Text,
	Toggle,
	errorMessage,
	langOption,
} from './ui.js';

const { languages } = window.barrioAdmin;
const [ main, ...others ] = languages;

// Period::status(): il nome e il colore del badge.
export const STATUS = {
	active: [ __( 'Active', 'barrio-suite' ), 'is-success' ],
	scheduled: [ __( 'Scheduled', 'barrio-suite' ), 'is-warning' ],
	expired: [ __( 'Expired', 'barrio-suite' ), '' ],
	off: [ __( 'Off', 'barrio-suite' ), '' ],
};

// Una data del campo datetime-local (ora del sito) nel formato della lingua, senza passare dal fuso del browser.
const dateText = ( local ) =>
	gmdateI18n( getSettings().formats.datetimeAbbreviated, `${ local }:00Z` );

export function periodText( start, end ) {
	if ( start && end ) {
		return `${ dateText( start ) } – ${ dateText( end ) }`;
	}
	if ( start ) {
		/* translators: %s: date and time. */
		return sprintf( __( 'From %s', 'barrio-suite' ), dateText( start ) );
	}
	if ( end ) {
		/* translators: %s: date and time. */
		return sprintf( __( 'Until %s', 'barrio-suite' ), dateText( end ) );
	}
	return __( 'Always', 'barrio-suite' );
}

/**
 * Acceso, inizio e fine, nell'ora del sito; vuoti, da subito e per sempre.
 *
 * @param {Object}                    props
 * @param {Object}                    props.item        Il popup o il banner.
 * @param {string}                    props.description
 * @param {(changes: Object) => void} props.onChange    { is_enabled }, { starts_at } o { ends_at }.
 */
export const PeriodCard = ( { item, description, onChange } ) => (
	<Card
		icon="calendar-alt"
		title={ __( 'Period', 'barrio-suite' ) }
		description={ description }
	>
		<Toggle
			label={ __( 'On', 'barrio-suite' ) }
			help={
				! item.is_enabled &&
				__( 'Turned off, it never shows up.', 'barrio-suite' )
			}
			checked={ item.is_enabled }
			onChange={ ( on ) => onChange( { is_enabled: on } ) }
		/>
		<Text
			type="datetime-local"
			label={ __( 'Start', 'barrio-suite' ) }
			value={ item.starts_at }
			onChange={ ( value ) => onChange( { starts_at: value || null } ) }
		/>
		<Text
			type="datetime-local"
			label={ __( 'End', 'barrio-suite' ) }
			min={ item.starts_at ?? undefined }
			value={ item.ends_at }
			onChange={ ( value ) => onChange( { ends_at: value || null } ) }
		/>
	</Card>
);

// Al caricamento le altre lingue tengono solo i testi diversi da quelli della lingua principale.
export const trimTexts = ( record ) =>
	others.reduce(
		( item, lang ) =>
			Object.keys( item.i18n[ lang ] ?? {} ).reduce(
				( next, key ) =>
					next.i18n[ lang ]?.[ key ] === next.i18n[ main ]?.[ key ]
						? setTranslation( next, lang, key, '' )
						: next,
				item
			),
		record
	);

// Al salvataggio ogni lingua tradotta ha la riga completa, come vuole il database.
export const fillTexts = ( record ) => ( {
	...record,
	i18n: Object.fromEntries(
		Object.keys( record.i18n ).map( ( lang ) => [
			lang,
			textIn( record, lang, main ),
		] )
	),
} );

// Il più recente per primo. status '' = tutti; lang '' = in qualsiasi lingua.
const INITIAL = { ...initialQuery( 'id', 'desc' ), status: '', lang: '' };

/**
 * L'elenco di Barrio → Popup o Banner, sulla REST del modulo: filtri per stato e lingua, menu ⋯ e azioni di massa.
 * I testi li dà il modulo, perché le traduzioni si leggono nel codice.
 *
 * @param {Object}                   props
 * @param {string}                   props.name  popups o banners: la rotta, la pagina (barrio-…) e il parametro
 *                                               dell'editor (popup, banner).
 * @param {(item: Object) => string} props.label Il nome di una riga nella prima colonna, nella lingua principale.
 * @param {Object}                   props.texts column, title, search, empty, all, add, duplicated e, con il numero,
 *                                               on, off, confirm e deleted.
 */
export function PeriodList( { name, label, texts } ) {
	const [ items, setItems ] = useState( null );
	const [ query, setQuery ] = useState( INITIAL );
	const [ notice, setNotice ] = useState( null );
	const path = `/barrio/v1/${ name }`;
	const editUrl = ( id ) =>
		'?' +
		new URLSearchParams( {
			page: `barrio-${ name }`,
			[ name.slice( 0, -1 ) ]: id,
		} );

	const fail = ( error ) =>
		setNotice( { status: 'error', message: errorMessage( error ) } );
	// Senza return: un effetto non deve restituire la Promise.
	const load = () => {
		apiFetch( { path } ).then( setItems ).catch( fail );
	};
	useEffect( load, [ path ] );

	const act = ( request, message ) => {
		setNotice( null );
		apiFetch( request )
			.then( () => setNotice( { status: 'success', message } ) )
			.catch( fail )
			.finally( load );
	};
	const bulk = ( action, ids, message ) =>
		act(
			{ path: `${ path }/bulk`, method: 'POST', data: { action, ids } },
			message
		);
	const turn = ( on, ids ) =>
		bulk(
			on ? 'enable' : 'disable',
			ids,
			( on ? texts.on : texts.off )( ids.length )
		);
	const remove = ( ids ) => {
		// eslint-disable-next-line no-alert -- conferma prima di un'azione che non si annulla.
		if ( window.confirm( texts.confirm( ids.length ) ) ) {
			bulk( 'delete', ids, texts.deleted( ids.length ) );
		}
	};

	if ( ! items ) {
		return notice ? <Notice notice={ notice } /> : <Loading />;
	}
	const all = items.map( ( item ) => ( {
		...item,
		name: label( item ),
		period: periodText( item.starts_at, item.ends_at ),
		// Per ordinare: gli stati nell'ordine di STATUS, il periodo dall'inizio.
		order: Object.keys( STATUS ).indexOf( item.status ),
		start: item.starts_at ?? '',
		translations: others.filter( ( lang ) => item.i18n[ lang ] ),
	} ) );
	const count = ( status ) =>
		all.filter( ( item ) => item.status === status ).length;
	const visible = all.filter(
		( item ) => ! query.status || item.status === query.status
	);
	// Con la lingua principale quelli non tradotti, con un'altra quelli tradotti in quella.
	const inLanguage = ( lang ) =>
		visible.filter( ( item ) =>
			lang === main
				? ! item.translations.length
				: item.translations.includes( lang )
		);
	const { rows, total } = localQuery(
		( query.lang ? inLanguage( query.lang ) : visible ).map( ( item ) => ( {
			...item,
			count: item.translations.length,
		} ) ),
		query,
		[ 'name', 'period' ]
	);

	const columns = [
		{
			key: 'name',
			label: texts.column,
			sort: 'name',
			render: ( item ) => (
				<a className="barrio-table__title" href={ editUrl( item.id ) }>
					{ item.name }
				</a>
			),
		},
		{
			key: 'status',
			label: __( 'Status', 'barrio-suite' ),
			sort: 'order',
			hideable: true,
			render: ( item ) => (
				<span
					className={ `barrio-badge ${ STATUS[ item.status ][ 1 ] }` }
				>
					{ STATUS[ item.status ][ 0 ] }
				</span>
			),
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
			render: ( item ) =>
				item.translations.length ? (
					<span className="barrio-row is-nowrap">
						{ item.translations.map( ( lang ) => (
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
			render: ( item ) => (
				<Menu
					label={ sprintf(
						/* translators: %s: name of the row: a form, a page, a post or a term. */
						__( 'Actions for "%s"', 'barrio-suite' ),
						item.name
					) }
					items={ [
						{
							label: __( 'Edit', 'barrio-suite' ),
							icon: 'edit',
							href: editUrl( item.id ),
						},
						{
							label: __( 'Duplicate', 'barrio-suite' ),
							icon: 'admin-page',
							onClick: () =>
								act(
									{
										path: `${ path }/${ item.id }/duplicate`,
										method: 'POST',
									},
									texts.duplicated
								),
						},
						item.is_enabled
							? {
									label: __( 'Turn off', 'barrio-suite' ),
									icon: 'hidden',
									onClick: () => turn( false, [ item.id ] ),
								}
							: {
									label: __( 'Turn on', 'barrio-suite' ),
									icon: 'visibility',
									onClick: () => turn( true, [ item.id ] ),
								},
						{
							label: __( 'Delete', 'barrio-suite' ),
							icon: 'trash',
							danger: true,
							onClick: () => remove( [ item.id ] ),
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
				title={ texts.title }
				search={ texts.search }
				empty={ texts.empty }
				filters={ [
					{
						key: 'status',
						label: __( 'Status', 'barrio-suite' ),
						options: [
							{ value: '', label: texts.all, count: all.length },
							...Object.entries( STATUS ).map(
								( [ value, [ text ] ] ) => ( {
									value,
									label: text,
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
						href={ editUrl( 'new' ) }
					>
						<Icon name="plus-alt2" />
						<span className="barrio-button__text">
							{ texts.add }
						</span>
					</a>
				}
			/>
		</div>
	);
}
