// SEO → Contenuti: i contenuti o i termini di un tipo, scelto nel titolo della barra, con titolo SEO,
// descrizione, indicizzazione, punteggio e, con più lingue, la lingua. Ogni riga si valuta con il contesto della
// sua lingua (descrizione predefinita, nome del sito). Filtri e ordinamento nel browser (GET /seo/contents); le modifiche
// passano dalla REST del core, una richiesta per contenuto, nella modale con lo stesso pannello dell'editor.
import apiFetch from '@wordpress/api-fetch';
import { Modal } from '@wordpress/components';
import { useEffect, useMemo, useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';

import {
	initialQuery,
	localQuery,
} from '../../../../../assets/src/admin/query.js';
import {
	Lang,
	Loading,
	Lucide,
	Menu,
	Notice,
	Picker,
	Table,
	errorMessage,
	langOption,
} from '../../../../../assets/src/admin/ui.js';
import { LEVELS, Panel, analyze } from '../editor/Panel.js';
import '../editor/editor.css';
import { level } from '../score.js';
import { text } from '../title.js';
import { pageUrl, seoData } from './ui.js';

const { types, canEditSeo } = seoData;
const { languages } = window.barrioAdmin;

// index, score e lang: il valore scelto; description 'missing': senza una descrizione loro.
const INITIAL = {
	...initialQuery( 'title' ),
	index: '',
	description: '',
	score: '',
	lang: '',
};

const STATUS = {
	future: __( 'Scheduled', 'barrio-suite' ),
	draft: __( 'Draft', 'barrio-suite' ),
	pending: __( 'Pending', 'barrio-suite' ),
	private: __( 'Private', 'barrio-suite' ),
};

const BADGE = { good: 'is-success', ok: 'is-warning', bad: 'is-error' };

// Nell'URL come nelle pagine di WordPress (?type=page, ?taxonomy=category); di default le pagine.
const param = ( type ) => ( 'taxonomies' === type.group ? 'taxonomy' : 'type' );
const key = ( type ) => `${ type.group }/${ type.name }`;
function fromUrl() {
	const params = new URLSearchParams( window.location.search );
	return (
		types.find( ( type ) => params.get( param( type ) ) === type.name ) ??
		types[ 0 ]
	);
}

const seoMeta = ( meta ) =>
	Object.fromEntries(
		Object.entries( meta ).filter( ( [ name ] ) =>
			name.startsWith( '_barrio_seo_' )
		)
	);

function SeoModal( { row, context, onSave, onClose } ) {
	const [ meta, setMeta ] = useState( row.meta );
	const [ busy, setBusy ] = useState( false );
	const [ notice, setNotice ] = useState( null );
	const dirty = JSON.stringify( meta ) !== JSON.stringify( row.meta );
	const close = () => {
		if (
			! dirty ||
			// eslint-disable-next-line no-alert -- le modifiche non salvate non si perdono con Esc o un clic fuori.
			window.confirm( __( 'Discard the changes?', 'barrio-suite' ) )
		) {
			onClose();
		}
	};
	const save = () => {
		setBusy( true );
		setNotice( null );
		onSave( meta )
			.then( onClose )
			.catch( ( error ) => {
				setNotice( {
					status: 'error',
					message: errorMessage( error ),
				} );
				setBusy( false );
			} );
	};
	return (
		<Modal
			className="barrio-seo-modal"
			size="medium"
			title={ sprintf(
				/* translators: %s: title of a page, post or term. */
				__( 'SEO of “%s”', 'barrio-suite' ),
				row.title
			) }
			onRequestClose={ close }
		>
			<Notice notice={ notice } />
			<Panel
				meta={ meta }
				page={ row.page }
				context={ context }
				seo={ analyze( meta, row.page, context ) }
				onChange={ ( changes ) =>
					setMeta( ( now ) => ( { ...now, ...changes } ) )
				}
			/>
			<div className="barrio-row barrio-seo-modal__actions">
				<button
					type="button"
					className="barrio-button"
					onClick={ close }
				>
					{ __( 'Cancel', 'barrio-suite' ) }
				</button>
				<button
					type="button"
					className="barrio-button is-primary"
					disabled={ busy || ! dirty }
					onClick={ save }
				>
					<Lucide name="save" />
					<span className="barrio-button__text">
						{ busy
							? __( 'Saving…', 'barrio-suite' )
							: __( 'Save', 'barrio-suite' ) }
					</span>
				</button>
			</div>
		</Modal>
	);
}

export function Contents() {
	const [ type, setType ] = useState( fromUrl );
	const [ data, setData ] = useState( null );
	const [ query, setQuery ] = useState( INITIAL );
	const [ notice, setNotice ] = useState( null );
	const [ editing, setEditing ] = useState( null );

	const fail = ( error ) =>
		setNotice( { status: 'error', message: errorMessage( error ) } );

	useEffect( () => {
		let current = true;
		// Nell'URL, così Indietro torna a questo tipo.
		window.history.replaceState(
			null,
			'',
			pageUrl( { [ param( type ) ]: type.name } )
		);
		apiFetch( { path: `/barrio/v1/seo/contents/${ key( type ) }` } )
			.then( ( result ) => current && setData( result ) )
			.catch( fail );
		return () => {
			current = false;
		};
	}, [ type ] );

	// Il punteggio di ogni riga si calcola una volta, quando cambiano i dati.
	const rows = useMemo(
		() =>
			data?.rows.map( ( row ) => {
				const context = data.contexts[ row.lang ];
				const seo = analyze( row.meta, row.page, context );
				const own = row.meta._barrio_seo_index;
				return {
					...row,
					title:
						text( row.page.title ) ||
						__( '(no title)', 'barrio-suite' ),
					seo_title: row.meta._barrio_seo_title || '',
					description: row.meta._barrio_seo_description || '',
					index: ( own ? 'index' === own : context.robots.index )
						? 'index'
						: 'noindex',
					inherited: ! own,
					score: seo.result.score,
					level: level( seo.result.score ),
				};
			} ),
		[ data ]
	);

	if ( ! rows ) {
		return notice ? <Notice notice={ notice } /> : <Loading />;
	}

	const editable = canEditSeo && !! type.rest;
	// Una richiesta per contenuto; la riga prende i meta che restituisce la REST del core.
	const save = ( ids, meta ) =>
		Promise.all(
			ids.map( ( id ) =>
				apiFetch( {
					path: `/${ type.rest }/${ id }`,
					method: 'POST',
					data: { meta },
				} ).then( ( response ) =>
					setData( ( now ) => ( {
						...now,
						rows: now.rows.map( ( row ) =>
							row.id === id
								? { ...row, meta: seoMeta( response.meta ) }
								: row
						),
					} ) )
				)
			)
		);
	const setIndex = ( ids, value ) => {
		setNotice( null );
		save( ids, { _barrio_seo_index: value } )
			.then( () =>
				setNotice( {
					status: 'success',
					message: sprintf(
						/* translators: %d: number of pages, posts or terms. */
						_n(
							'%d item updated.',
							'%d items updated.',
							ids.length,
							'barrio-suite'
						),
						ids.length
					),
				} )
			)
			.catch( fail );
	};

	const count = ( field, value ) =>
		rows.filter( ( row ) => row[ field ] === value ).length;
	const filtered = rows.filter(
		( row ) =>
			( ! query.index || row.index === query.index ) &&
			( ! query.description || ! row.description ) &&
			( ! query.score || row.level === query.score ) &&
			( ! query.lang || row.lang === query.lang )
	);
	const page = localQuery( filtered, query, [
		'title',
		'seo_title',
		'description',
	] );
	const yes = __( 'Indexed', 'barrio-suite' );
	const no = __( 'Not indexed', 'barrio-suite' );

	const columns = [
		{
			key: 'title',
			label: __( 'Title', 'barrio-suite' ),
			sort: 'title',
			className: 'is-truncate',
			render: ( row ) => (
				<span className="barrio-row is-nowrap">
					{ editable ? (
						<button
							type="button"
							className="barrio-table__title"
							onClick={ () => setEditing( row ) }
						>
							{ row.title }
						</button>
					) : (
						row.title
					) }
					{ STATUS[ row.status ] && (
						<span className="barrio-badge">
							{ STATUS[ row.status ] }
						</span>
					) }
				</span>
			),
		},
		{
			key: 'seo_title',
			label: __( 'SEO title', 'barrio-suite' ),
			sort: 'seo_title',
			className: 'is-truncate',
			hideable: true,
			render: ( row ) => row.seo_title || '—',
		},
		{
			key: 'description',
			label: __( 'Description', 'barrio-suite' ),
			sort: 'description',
			className: 'is-truncate',
			hideable: true,
			render: ( row ) => row.description || '—',
		},
		{
			key: 'index',
			label: __( 'Indexing', 'barrio-suite' ),
			sort: 'index',
			hideable: true,
			render: ( row ) => (
				<span
					className={ `barrio-badge${
						'index' === row.index ? ' is-success' : ''
					}` }
				>
					{ row.inherited
						? sprintf(
								/* translators: %s: Indexed or Not indexed. */
								__( '%s (default)', 'barrio-suite' ),
								'index' === row.index ? yes : no
							)
						: { index: yes, noindex: no }[ row.index ] }
				</span>
			),
		},
		{
			key: 'score',
			label: __( 'Score', 'barrio-suite' ),
			sort: 'score',
			hideable: true,
			render: ( row ) => (
				<span
					className={ `barrio-badge ${ BADGE[ row.level ] }` }
					title={ LEVELS[ row.level ]() }
				>
					{ row.score }
				</span>
			),
		},
		languages.length > 1 && {
			key: 'lang',
			label: __( 'Language', 'barrio-suite' ),
			sort: 'lang',
			hideable: true,
			render: ( row ) => (
				<span className="barrio-badge">
					<Lang code={ row.lang } />
				</span>
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
			render: ( row ) => (
				<Menu
					label={ sprintf(
						/* translators: %s: name of the row: a form, a page, a post or a term. */
						__( 'Actions for "%s"', 'barrio-suite' ),
						row.title
					) }
					items={ [
						editable && {
							label: __( 'Edit SEO', 'barrio-suite' ),
							icon: 'search',
							onClick: () => setEditing( row ),
						},
						row.edit && {
							label: __( 'Edit', 'barrio-suite' ),
							icon: 'edit',
							href: row.edit,
						},
						row.view && {
							label: __( 'View', 'barrio-suite' ),
							icon: 'visibility',
							href: row.view,
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
				rows={ page.rows }
				total={ page.total }
				query={ query }
				initial={ INITIAL }
				onQuery={ setQuery }
				title={
					<Picker
						className="barrio-bar__select"
						placement="bottom-start"
						label={ __( 'Content type', 'barrio-suite' ) }
						text={ type.label }
						options={ types.map( ( option ) => ( {
							value: key( option ),
							label: option.label,
						} ) ) }
						value={ key( type ) }
						onChange={ ( value ) => {
							setType(
								types.find(
									( option ) => key( option ) === value
								)
							);
							setQuery( INITIAL );
							setData( null );
							setNotice( null );
						} }
					/>
				}
				search={ __( 'Search', 'barrio-suite' ) }
				empty={ __( 'Nothing found.', 'barrio-suite' ) }
				filters={ [
					{
						key: 'index',
						label: __( 'Indexing', 'barrio-suite' ),
						options: [
							{
								value: '',
								label: __( 'All', 'barrio-suite' ),
								count: rows.length,
							},
							{
								value: 'index',
								label: yes,
								count: count( 'index', 'index' ),
							},
							{
								value: 'noindex',
								label: no,
								count: count( 'index', 'noindex' ),
							},
						],
					},
					{
						key: 'description',
						label: __( 'Description', 'barrio-suite' ),
						options: [
							{
								value: '',
								label: __( 'All', 'barrio-suite' ),
								count: rows.length,
							},
							{
								value: 'missing',
								label: __(
									'Without description',
									'barrio-suite'
								),
								count: count( 'description', '' ),
							},
						],
					},
					{
						key: 'score',
						label: __( 'Score', 'barrio-suite' ),
						options: [
							{
								value: '',
								label: __( 'All', 'barrio-suite' ),
								count: rows.length,
							},
							...Object.entries( LEVELS ).map(
								( [ value, label ] ) => ( {
									value,
									label: label(),
									count: count( 'level', value ),
								} )
							),
						],
					},
					languages.length > 1 && {
						key: 'lang',
						label: __( 'Language', 'barrio-suite' ),
						options: [
							{
								value: '',
								label: __( 'All languages', 'barrio-suite' ),
								count: rows.length,
							},
							...languages.map( ( lang ) => ( {
								...langOption( lang ),
								count: count( 'lang', lang ),
							} ) ),
						],
					},
				] }
				bulk={
					editable
						? ( ids ) => [
								{
									label: __( 'Index', 'barrio-suite' ),
									icon: 'visibility',
									onClick: () => setIndex( ids, 'index' ),
								},
								{
									label: __( 'Do not index', 'barrio-suite' ),
									icon: 'hidden',
									onClick: () => setIndex( ids, 'noindex' ),
								},
								{
									label: __(
										'Use the default',
										'barrio-suite'
									),
									icon: 'undo',
									onClick: () => setIndex( ids, null ),
								},
							]
						: undefined
				}
			/>
			{ editing && (
				<SeoModal
					row={ editing }
					context={ data.contexts[ editing.lang ] }
					onSave={ ( meta ) => save( [ editing.id ], meta ) }
					onClose={ () => setEditing( null ) }
				/>
			) }
		</div>
	);
}
