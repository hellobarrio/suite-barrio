// Le registrazioni di un form: ricerca, pagine, ordinamento e filtri sul server
// (GET /forms/{id}/entries), azioni di massa ed export CSV con gli stessi filtri.
import apiFetch from '@wordpress/api-fetch';
import { useEffect, useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';

import { initialQuery } from '../admin/query.js';
import {
	Loading,
	Menu,
	Notice,
	Picker,
	Table,
	entriesUrl,
	restLink,
} from '../admin/ui.js';
import { errorMessage } from '../builder/model.js';

// Le più recenti per prime.
const INITIAL = { ...initialQuery( 'created_at', 'desc' ), read: '', lang: '' };
const { languages } = window.barrioAdmin;

// Senza i filtri vuoti, che il server rifiuterebbe.
const params = ( query ) =>
	Object.fromEntries(
		Object.entries( query ).filter( ( [ , value ] ) => '' !== value )
	);

// Il form da mostrare senza ?form=: il primo con registrazioni da leggere, poi con registrazioni.
const pick = ( forms ) =>
	(
		forms.find( ( f ) => f.unread ) ??
		forms.find( ( f ) => f.entries ) ??
		forms[ 0 ]
	)?.id;

// Il form nel titolo: i form nel cestino hanno ancora le loro registrazioni.
const formLabel = ( form ) =>
	'trash' === form.status
		? sprintf(
				/* translators: %s: nome del form. */
				__( '%s (trash)', 'barrio-suite' ),
				form.title
			)
		: form.title;

export function Entries( { form: requested } ) {
	const [ forms, setForms ] = useState( null );
	const [ formId, setFormId ] = useState( requested );
	const [ query, setQuery ] = useState( INITIAL );
	const [ data, setData ] = useState( null );
	const [ notice, setNotice ] = useState( null );
	// Cambia dopo ogni azione: ricarica l'elenco e i conteggi dei form.
	const [ reload, setReload ] = useState( 0 );

	const fail = ( error ) =>
		setNotice( { status: 'error', message: errorMessage( error ) } );

	useEffect( () => {
		apiFetch( { path: '/barrio/v1/forms' } )
			.then( ( list ) => {
				setForms( list );
				setFormId( ( id ) => id ?? pick( list ) );
			} )
			.catch( fail );
	}, [ reload ] );

	useEffect( () => {
		let current = true;
		// Mentre si scrive nella ricerca parte una sola richiesta.
		const timer = setTimeout( () => {
			if ( formId ) {
				apiFetch( {
					path: `/barrio/v1/forms/${ formId }/entries?${ new URLSearchParams(
						params( query )
					) }`,
				} )
					.then( ( result ) => {
						if ( current ) {
							setData( result );
						}
					} )
					.catch( fail );
			}
		}, 250 );
		return () => {
			current = false;
			clearTimeout( timer );
		};
	}, [ formId, query, reload ] );

	if ( forms && ! forms.length ) {
		return (
			<Notice
				notice={ {
					status: 'success',
					message: __( 'There are no forms yet.', 'barrio-suite' ),
				} }
			/>
		);
	}
	if ( ! forms || ! data ) {
		return notice ? <Notice notice={ notice } /> : <Loading />;
	}

	const form = forms.find( ( f ) => f.id === formId );

	const switchForm = ( id ) => {
		setFormId( id );
		setQuery( INITIAL );
		setData( null );
		window.history.replaceState( null, '', entriesUrl( { form: id } ) );
	};

	// ids null = tutte le registrazioni del form, non solo quelle filtrate.
	const bulk = ( action, entries, message ) => {
		setNotice( null );
		apiFetch( {
			path: `/barrio/v1/forms/${ formId }/entries/bulk`,
			method: 'POST',
			data: entries ? { action, ids: entries } : { action, all: true },
		} )
			.then( ( result ) => {
				setReload( ( n ) => n + 1 );
				setNotice( { status: 'success', message: message( result ) } );
			} )
			.catch( fail );
	};
	const read = ( entries, isRead ) =>
		bulk( isRead ? 'read' : 'unread', entries, () =>
			isRead
				? __( 'Marked as read.', 'barrio-suite' )
				: __( 'Marked as unread.', 'barrio-suite' )
		);
	const remove = ( entries ) => {
		const count = entries ? entries.length : form.entries;
		const question = entries
			? sprintf(
					/* translators: %d: registrazioni da eliminare. */
					_n(
						'Delete %d entry permanently, with its files?',
						'Delete %d entries permanently, with their files?',
						count,
						'barrio-suite'
					),
					count
				)
			: sprintf(
					/* translators: 1: tutte le registrazioni del form; 2: nome del form. */
					__(
						'Delete all %1$d entries of "%2$s" permanently, with their files?',
						'barrio-suite'
					),
					count,
					form.title
				);
		// eslint-disable-next-line no-alert -- conferma prima di un'azione che non si annulla.
		if ( window.confirm( question ) ) {
			bulk( 'delete', entries, ( result ) =>
				sprintf(
					/* translators: %d: registrazioni eliminate. */
					_n(
						'%d entry deleted.',
						'%d entries deleted.',
						result.deleted,
						'barrio-suite'
					),
					result.deleted
				)
			);
		}
	};

	const columns = [
		{
			key: 'id',
			label: '#',
			sort: 'id',
			className: 'is-narrow',
			render: ( row ) => (
				<span className="barrio-row is-nowrap">
					<a
						className={ `barrio-table__title${
							row.is_read ? '' : ' is-unread'
						}` }
						href={ entriesUrl( { entry: row.id } ) }
					>
						#{ row.id }
					</a>
					{ ! row.is_read && (
						<span className="barrio-badge is-warning">
							{ __( 'New', 'barrio-suite' ) }
						</span>
					) }
				</span>
			),
		},
		...data.columns.map( ( column, index ) => ( {
			key: `field:${ column.key }`,
			label: column.label,
			// I file non hanno un valore su cui ordinare.
			sort: 'file' === column.type ? null : `field:${ column.key }`,
			className: 'is-truncate',
			// Di default le prime tre, come nel prototipo.
			hideable: true,
			hidden: index > 2,
			render: ( row ) => row.values[ column.key ] || '—',
		} ) ),
		languages.length > 1 && {
			key: 'lang',
			label: __( 'Language', 'barrio-suite' ),
			sort: 'lang',
			render: ( row ) => (
				<span className="barrio-badge">{ row.lang.toUpperCase() }</span>
			),
		},
		{
			key: 'date',
			label: __( 'Date', 'barrio-suite' ),
			sort: 'created_at',
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
						/* translators: %d: numero della registrazione. */
						__( 'Actions for entry #%d', 'barrio-suite' ),
						row.id
					) }
					items={ [
						{
							label: __( 'View', 'barrio-suite' ),
							icon: 'visibility',
							href: entriesUrl( { entry: row.id } ),
						},
						row.is_read
							? {
									label: __(
										'Mark as unread',
										'barrio-suite'
									),
									icon: 'hidden',
									onClick: () => read( [ row.id ], false ),
								}
							: {
									label: __( 'Mark as read', 'barrio-suite' ),
									icon: 'yes',
									onClick: () => read( [ row.id ], true ),
								},
						{
							label: __( 'Delete', 'barrio-suite' ),
							icon: 'trash',
							danger: true,
							onClick: () => remove( [ row.id ] ),
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
				rows={ data.rows }
				total={ data.total }
				query={ query }
				initial={ INITIAL }
				onQuery={ setQuery }
				title={
					<Picker
						className="barrio-bar__select"
						placement="bottom-start"
						label={ __( 'Form', 'barrio-suite' ) }
						text={ formLabel( form ) }
						options={ forms.map( ( f ) => ( {
							value: f.id,
							label: formLabel( f ),
						} ) ) }
						value={ formId }
						onChange={ switchForm }
					/>
				}
				search={ __( 'Search entries', 'barrio-suite' ) }
				empty={ __( 'No entries found.', 'barrio-suite' ) }
				filters={ [
					{
						key: 'read',
						label: __( 'Status', 'barrio-suite' ),
						options: [
							{
								value: '',
								label: __( 'All entries', 'barrio-suite' ),
								count: form.entries,
							},
							{
								value: '0',
								label: __( 'Unread', 'barrio-suite' ),
								count: form.unread,
							},
							{
								value: '1',
								label: __( 'Read', 'barrio-suite' ),
								count: form.entries - form.unread,
							},
						],
					},
					languages.length > 1 && {
						key: 'lang',
						label: __( 'Language', 'barrio-suite' ),
						icon: 'translation',
						options: [
							{
								value: '',
								label: __( 'All languages', 'barrio-suite' ),
							},
							...languages.map( ( lang ) => ( {
								value: lang,
								label: lang.toUpperCase(),
							} ) ),
						],
					},
				] }
				bulk={ ( ids ) => [
					{
						label: __( 'Mark as read', 'barrio-suite' ),
						icon: 'yes',
						onClick: () => read( ids, true ),
					},
					{
						label: __( 'Mark as unread', 'barrio-suite' ),
						icon: 'hidden',
						onClick: () => read( ids, false ),
					},
					{
						label: __( 'Delete', 'barrio-suite' ),
						icon: 'trash',
						danger: true,
						onClick: () => remove( ids ),
					},
				] }
				toolbar={
					<Menu
						label={ __( 'More actions', 'barrio-suite' ) }
						items={ [
							{
								// Con i filtri correnti.
								label: __( 'Export CSV', 'barrio-suite' ),
								icon: 'download',
								href: restLink(
									`forms/${ formId }/entries/export`,
									params( {
										search: query.search,
										read: query.read,
										lang: query.lang,
									} )
								),
							},
							{
								label: __( 'Mark all as read', 'barrio-suite' ),
								icon: 'yes',
								onClick: () => read( null, true ),
							},
							form.entries > 0 && {
								label: __(
									'Delete all entries',
									'barrio-suite'
								),
								icon: 'trash',
								danger: true,
								onClick: () => remove( null ),
							},
						] }
					/>
				}
			/>
		</div>
	);
}
