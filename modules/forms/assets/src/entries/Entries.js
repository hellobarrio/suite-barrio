// Le registrazioni di un form: ricerca, pagine, ordinamento e filtri sul server
// (GET /forms/{id}/entries), azioni di massa ed export CSV con gli stessi filtri.
import apiFetch from '@wordpress/api-fetch';
import { useEffect, useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';

import { initialQuery } from '../../../../../assets/src/admin/query.js';
import {
	Icon,
	Lang,
	Loading,
	Menu,
	Notice,
	Picker,
	Table,
	errorMessage,
	langOption,
	restLink,
} from '../../../../../assets/src/admin/ui.js';
import { entriesUrl } from '../ui.js';

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
				/* translators: %s: form name. */
				__( '%s (trash)', 'barrio-suite' ),
				form.title
			)
		: form.title;

// La vista dell'elenco resta per la sessione: tornando dal dettaglio di una registrazione si ritrova.
const VIEW = 'barrio-entries-view';
function storedView( form ) {
	try {
		const view = JSON.parse( window.sessionStorage.getItem( VIEW ) );
		if ( form && view?.form === form ) {
			return {
				query: { ...INITIAL, ...view.query },
				shown: view.shown ?? {},
			};
		}
	} catch {}
	return { query: INITIAL, shown: {} };
}

export function Entries( { form: requested } ) {
	const [ forms, setForms ] = useState( null );
	const [ formId, setFormId ] = useState( requested );
	const [ query, setQuery ] = useState( () => storedView( requested ).query );
	const [ shown, setShown ] = useState( () => storedView( requested ).shown );
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
		if ( formId ) {
			// Nell'URL, così Indietro torna a questo form.
			window.history.replaceState(
				null,
				'',
				entriesUrl( { form: formId } )
			);
			try {
				window.sessionStorage.setItem(
					VIEW,
					JSON.stringify( { form: formId, query, shown } )
				);
			} catch {}
		}
	}, [ formId, query, shown ] );

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
		setShown( {} );
		setData( null );
	};

	const bulk = ( action, ids, message ) => {
		setNotice( null );
		apiFetch( {
			path: `/barrio/v1/forms/${ formId }/entries/bulk`,
			method: 'POST',
			data: { action, ids },
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
		const question = sprintf(
			/* translators: %d: number of entries to delete. */
			_n(
				'Delete %d entry permanently, with its files?',
				'Delete %d entries permanently, with their files?',
				entries.length,
				'barrio-suite'
			),
			entries.length
		);
		// eslint-disable-next-line no-alert -- conferma prima di un'azione che non si annulla.
		if ( window.confirm( question ) ) {
			bulk( 'delete', entries, ( result ) =>
				sprintf(
					/* translators: %d: number of entries deleted. */
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
				<span className="barrio-badge">
					<Lang code={ row.lang } />
				</span>
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
						/* translators: %d: entry number. */
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
				shown={ shown }
				onShown={ setShown }
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
						options: [
							{
								value: '',
								label: __( 'All languages', 'barrio-suite' ),
							},
							...languages.map( ( lang ) => langOption( lang ) ),
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
					// Con i filtri correnti. Le altre azioni sono sulle righe selezionate; eliminare
					// tutte le registrazioni del sito è in Impostazioni → Privacy.
					<a
						className="barrio-button is-primary"
						href={ restLink(
							`forms/${ formId }/entries/export`,
							params( {
								search: query.search,
								read: query.read,
								lang: query.lang,
							} )
						) }
					>
						<Icon name="download" />
						<span className="barrio-button__text">
							{ __( 'Export CSV', 'barrio-suite' ) }
						</span>
					</a>
				}
			/>
		</div>
	);
}
