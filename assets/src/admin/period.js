// Popup e banner (src/Period.php): l'elenco, l'editor, lo stato e il periodo, nell'ora del sito, e i testi per lingua,
// tradotti come nel builder dei form (TextIn): nelle altre lingue un testo vuoto vale quello della lingua principale.
import apiFetch from '@wordpress/api-fetch';
import { TabPanel } from '@wordpress/components';
import { getSettings, gmdateI18n } from '@wordpress/date';
import { useEffect, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

import { setTranslation, textIn } from './i18n.js';
import { TextIn } from './texts.js';
import { initialQuery, localQuery } from './query.js';
import {
	ApplyButton,
	Bar,
	CancelButton,
	Card,
	Dialog,
	Fixed,
	Group,
	Icon,
	Lang,
	LangNotice,
	LangPicker,
	Loading,
	Menu,
	Notice,
	RemoveButton,
	SaveButton,
	Table,
	Text,
	Toggle,
	errorMessage,
	langOption,
	useUnsavedWarning,
} from './ui.js';

const { languages } = window.barrioAdmin;
const [ main, ...others ] = languages;
const MAIN = main.toUpperCase();

// Period::status(): il nome e il colore del badge.
const STATUS = {
	active: [ __( 'Active', 'barrio-suite' ), 'is-success' ],
	scheduled: [ __( 'Scheduled', 'barrio-suite' ), 'is-warning' ],
	expired: [ __( 'Expired', 'barrio-suite' ), '' ],
	off: [ __( 'Off', 'barrio-suite' ), '' ],
};

// La pagina del modulo (popups o banners) e, con l'ID o new, l'editor: ?page=barrio-popups&popup=12.
export const pageUrl = ( name, id ) =>
	'?' +
	new URLSearchParams( {
		page: `barrio-${ name }`,
		...( id && { [ name.slice( 0, -1 ) ]: id } ),
	} );

// Una data del campo datetime-local (ora del sito) nel formato della lingua, senza passare dal fuso del browser.
const dateText = ( local ) =>
	gmdateI18n( getSettings().formats.datetimeAbbreviated, `${ local }:00Z` );

function periodText( start, end ) {
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
const PeriodCard = ( { item, description, onChange } ) => (
	<Card
		className="barrio-wide"
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

/**
 * Il link di una CTA, come il campo Link di ACF: nella card il testo, l'indirizzo e la nuova scheda, con Modifica e
 * Rimuovi; si scrive in una modale e si applica alla bozza, salvata con la barra. Indirizzo e testo si traducono come
 * TextIn (in un'altra lingua vuoto vale quello principale); la nuova scheda vale per tutte le lingue.
 *
 * @param {Object}                                props
 * @param {Object}                                props.item        Il popup o il banner, con link_url nei testi.
 * @param {string}                                props.lang
 * @param {string}                                props.label
 * @param {string}                                props.help
 * @param {string}                                [props.text]      Il testo del link nei testi (button_label), se c'è.
 * @param {string}                                [props.textLabel]
 * @param {string}                                [props.textHelp]
 * @param {string}                                [props.fallback]  Il testo mostrato senza testo, come "Learn more".
 * @param {(item: Object) => boolean}             props.newTab      Se il link dell'elemento apre una nuova scheda.
 * @param {(item: Object, on: boolean) => Object} props.withNewTab  L'elemento con la nuova scheda cambiata.
 * @param {(item: Object) => void}                props.onChange
 */
export function LinkField( {
	item,
	lang,
	label,
	help,
	text,
	textLabel,
	textHelp,
	fallback,
	newTab,
	withNewTab,
	onChange,
} ) {
	const [ draft, setDraft ] = useState( null );
	const shown = textIn( item, lang, main );
	const keys = [ 'link_url', text ].filter( Boolean );
	// Toglie il link in tutte le lingue: le altre senza il principale non avrebbero niente da tradurre.
	const remove = () =>
		onChange(
			others.reduce(
				( next, code ) =>
					keys.reduce(
						( row, key ) => setTranslation( row, code, key, '' ),
						next
					),
				{
					...item,
					i18n: {
						...item.i18n,
						[ main ]: {
							...item.i18n[ main ],
							...Object.fromEntries(
								keys.map( ( key ) => [ key, null ] )
							),
						},
					},
				}
			)
		);
	let field;
	if ( shown.link_url ) {
		field = (
			<div className="barrio-link">
				<Icon name="admin-links" />
				<span className="barrio-link__text">
					{ text && <strong>{ shown[ text ] || fallback }</strong> }
					<span>{ shown.link_url }</span>
				</span>
				{ newTab( item ) && (
					<span
						className="barrio-link__tab"
						title={ __(
							'Open the link in a new tab',
							'barrio-suite'
						) }
					>
						<Icon name="external" />
					</span>
				) }
				<button
					type="button"
					className="barrio-icon-button"
					aria-label={ __( 'Edit link', 'barrio-suite' ) }
					onClick={ () => setDraft( item ) }
				>
					<Icon name="edit" />
				</button>
				{ lang === main && (
					<RemoveButton
						label={ __( 'Remove link', 'barrio-suite' ) }
						onClick={ remove }
					/>
				) }
			</div>
		);
	} else if ( lang === main ) {
		field = (
			<button
				type="button"
				className="barrio-button"
				onClick={ () => setDraft( item ) }
			>
				<Icon name="plus-alt2" />
				<span className="barrio-button__text">
					{ __( 'Add link', 'barrio-suite' ) }
				</span>
			</button>
		);
	} else {
		field = (
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
		);
	}
	const texts = { item: draft, lang, onChange: setDraft };
	return (
		<Group label={ label } help={ help }>
			{ field }
			{ draft && (
				<Dialog
					size="medium"
					title={ label }
					onRequestClose={ () => setDraft( null ) }
					footer={
						<>
							<CancelButton onClick={ () => setDraft( null ) } />
							<ApplyButton
								disabled={
									! textIn( draft, lang, main ).link_url
								}
								onClick={ () => {
									onChange( draft );
									setDraft( null );
								} }
							/>
						</>
					}
				>
					<TextIn
						{ ...texts }
						name="link_url"
						optional
						label={ __( 'Link', 'barrio-suite' ) }
						help={ __(
							'A path like /contacts/ or a full address.',
							'barrio-suite'
						) }
						spellCheck={ false }
					/>
					{ text && (
						<TextIn
							{ ...texts }
							name={ text }
							optional
							label={ textLabel }
							help={ textHelp }
							maxLength={ 255 }
						/>
					) }
					<Toggle
						label={ __(
							'Open the link in a new tab',
							'barrio-suite'
						) }
						help={
							others.length > 0 &&
							__( 'In every language.', 'barrio-suite' )
						}
						checked={ newTab( draft ) }
						onChange={ ( on ) =>
							setDraft( withNewTab( draft, on ) )
						}
					/>
				</Dialog>
			) }
		</Group>
	);
}

// Al caricamento le altre lingue tengono solo i testi diversi da quelli della lingua principale.
const trimTexts = ( record ) =>
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
const fillTexts = ( record ) => ( {
	...record,
	i18n: Object.fromEntries(
		Object.keys( record.i18n ).map( ( lang ) => [
			lang,
			textIn( record, lang, main ),
		] )
	),
} );

/**
 * L'editor di un popup o di un banner: la barra con la lingua e Salva, la scheda Contenuto con l'anteprima a fianco e
 * la scheda Opzioni con il periodo. I campi li dà il modulo, con { item, lang, set, setItem }.
 *
 * @param {Object}                     props
 * @param {string}                     props.name      popups o banners, come in PeriodList.
 * @param {number}                     [props.id]      Senza, uno nuovo.
 * @param {() => Object}               props.blank     Quello nuovo.
 * @param {(data: Object) => ?string}  props.check     Con i testi completi, l'errore che impedisce di salvare.
 * @param {Object}                     props.texts     Quelli di PeriodList, più edit, saved e period (la descrizione
 *                                                     della card Periodo).
 * @param {(editor: Object) => Object} props.content   I campi del Contenuto.
 * @param {(editor: Object) => Object} props.preview
 * @param {(editor: Object) => Object} [props.options] Le card delle Opzioni prima del periodo.
 */
export function PeriodEditor( {
	name,
	id,
	blank,
	check,
	texts,
	content,
	preview,
	options,
} ) {
	const [ item, setItem ] = useState( id ? null : blank );
	const [ saved, setSaved ] = useState( item );
	const [ lang, setLang ] = useState( main );
	const [ notice, setNotice ] = useState( null );
	const [ busy, setBusy ] = useState( false );
	const path = `/barrio/v1/${ name }`;

	const fail = ( error ) =>
		setNotice( { status: 'error', message: errorMessage( error ) } );
	const load = ( { status, ...data } ) => {
		const loaded = trimTexts( data );
		setSaved( loaded );
		setItem( loaded );
	};
	useEffect( () => {
		if ( id ) {
			apiFetch( { path: `${ path }/${ id }` } )
				.then( load )
				.catch( fail );
		}
	}, [ id, path ] );

	const dirty = JSON.stringify( item ) !== JSON.stringify( saved );
	useUnsavedWarning( dirty );

	if ( ! item ) {
		return notice ? <Notice notice={ notice } /> : <Loading />;
	}
	const set = ( changes ) => setItem( { ...item, ...changes } );
	const save = () => {
		const { id: current, ...data } = fillTexts( item );
		const error = check( data );
		if ( error ) {
			setNotice( { status: 'error', message: error } );
			return;
		}
		setBusy( true );
		setNotice( null );
		apiFetch(
			current
				? { path: `${ path }/${ current }`, method: 'PUT', data }
				: { path, method: 'POST', data }
		)
			.then( ( result ) => {
				load( result );
				if ( ! current ) {
					window.history.replaceState(
						null,
						'',
						pageUrl( name, result.id )
					);
				}
				setNotice( { status: 'success', message: texts.saved } );
			} )
			.catch( fail )
			.finally( () => setBusy( false ) );
	};
	const editor = { item, lang, set, setItem };

	return (
		<div className="barrio-stack">
			<Bar
				title={
					<>
						<a
							className="barrio-icon-button"
							href={ pageUrl( name ) }
							aria-label={ texts.all }
						>
							<Icon name="arrow-left-alt2" />
						</a>
						<h2 className="barrio-bar__title">
							{ item.id ? texts.edit : texts.add }
						</h2>
					</>
				}
			>
				<LangPicker value={ lang } onChange={ setLang } />
				<SaveButton
					busy={ busy }
					disabled={ item.id && ! dirty }
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
				{ ( tab ) =>
					// L'anteprima solo nel contenuto, come i campi del builder; le opzioni a tutta larghezza.
					'content' === tab.name ? (
						<div className="barrio-fields">
							<div className="barrio-stack">
								{ content( editor ) }
							</div>
							<div className="barrio-panel">
								{ preview( editor ) }
							</div>
						</div>
					) : (
						<div className="barrio-stack">
							<Fixed lang={ lang }>
								{ options?.( editor ) }
								<PeriodCard
									item={ item }
									description={ texts.period }
									onChange={ set }
								/>
							</Fixed>
						</div>
					)
				}
			</TabPanel>
		</div>
	);
}

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
	const editUrl = ( id ) => pageUrl( name, id );

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
	// Tra gli attivi il sito mostra solo il più recente (PeriodRepository::shown()).
	const shown = Math.max(
		...items
			.filter( ( item ) => 'active' === item.status )
			.map( ( item ) => item.id )
	);
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
			render: ( item ) =>
				'active' === item.status && item.id !== shown ? (
					<span className="barrio-badge">
						{ __( 'Active, behind a newer one', 'barrio-suite' ) }
					</span>
				) : (
					<span
						className={ `barrio-badge ${
							STATUS[ item.status ][ 1 ]
						}` }
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
