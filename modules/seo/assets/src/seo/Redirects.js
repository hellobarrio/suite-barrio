// SEO → Redirect: ricerca, filtri, ordinamento e pagine sul server (GET /seo/redirects); aggiunta e modifica in
// una modale, eliminazione dal menu della riga o sulle righe selezionate.
import apiFetch from '@wordpress/api-fetch';
import { useEffect, useId, useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';

import { initialQuery } from '../../../../../assets/src/admin/query.js';
import {
	Dialog,
	Icon,
	Loading,
	Lucide,
	Menu,
	Notice,
	Select,
	Table,
	Text,
	errorMessage,
} from '../../../../../assets/src/admin/ui.js';

// I più recenti per primi. code '' = tutti; auto '' = automatici e a mano.
const INITIAL = { ...initialQuery( 'updated_at', 'desc' ), code: '', auto: '' };

const CODES = {
	301: __( '301 Moved permanently', 'barrio-suite' ),
	302: __( '302 Found', 'barrio-suite' ),
	307: __( '307 Temporary redirect', 'barrio-suite' ),
	308: __( '308 Permanent redirect', 'barrio-suite' ),
	410: __( '410 Content deleted', 'barrio-suite' ),
};

// Senza i filtri vuoti, che il server rifiuterebbe.
const params = ( query ) =>
	Object.fromEntries(
		Object.entries( query ).filter( ( [ , value ] ) => '' !== value )
	);

const EMPTY = { source: '', target: '', code: 301, note: '' };

function RedirectModal( { redirect, onSaved, onClose } ) {
	const [ draft, setDraft ] = useState( {
		...redirect,
		target: redirect.target ?? '',
		note: redirect.note ?? '',
	} );
	const [ busy, setBusy ] = useState( false );
	const [ notice, setNotice ] = useState( null );
	// Il Salva sta nel piede della modale, fuori dal form: lo invia con l'attributo form.
	const form = useId();
	const gone = 410 === draft.code;
	const set = ( name ) => ( value ) =>
		setDraft( ( now ) => ( { ...now, [ name ]: value } ) );
	const save = ( event ) => {
		event.preventDefault();
		setBusy( true );
		setNotice( null );
		apiFetch( {
			path: `/barrio/v1/seo/redirects${
				redirect.id ? `/${ redirect.id }` : ''
			}`,
			method: redirect.id ? 'PUT' : 'POST',
			data: {
				source: draft.source,
				target: gone ? null : draft.target,
				code: draft.code,
				note: draft.note,
			},
		} )
			.then( onSaved )
			.catch( ( error ) => {
				setNotice( {
					status: 'error',
					message: errorMessage( error ),
				} );
				setBusy( false );
			} );
	};
	return (
		<Dialog
			className="barrio-seo-modal"
			footer={
				<>
					<button
						type="button"
						className="barrio-button"
						onClick={ onClose }
					>
						{ __( 'Cancel', 'barrio-suite' ) }
					</button>
					<button
						type="submit"
						form={ form }
						className="barrio-button is-primary"
						disabled={ busy }
					>
						<Lucide name="save" />
						<span className="barrio-button__text">
							{ busy
								? __( 'Saving…', 'barrio-suite' )
								: __( 'Save', 'barrio-suite' ) }
						</span>
					</button>
				</>
			}
			size="medium"
			title={
				redirect.id
					? __( 'Edit redirect', 'barrio-suite' )
					: __( 'Add redirect', 'barrio-suite' )
			}
			onRequestClose={ onClose }
		>
			<form id={ form } className="barrio-stack" onSubmit={ save }>
				<Notice notice={ notice } />
				<Text
					label={ __( 'From', 'barrio-suite' ) }
					help={ __(
						'The old address, such as /old-page. A full URL works too: only the path is kept.',
						'barrio-suite'
					) }
					required
					value={ draft.source }
					onChange={ set( 'source' ) }
				/>
				<Select
					label={ __( 'Type', 'barrio-suite' ) }
					help={
						gone
							? __(
									'Tells search engines that the page was removed on purpose and has no replacement.',
									'barrio-suite'
								)
							: __(
									'301 for a page that moved for good, the usual choice; 302 or 307 for a temporary move.',
									'barrio-suite'
								)
					}
					value={ draft.code }
					options={ Object.entries( CODES ).map(
						( [ value, label ] ) => ( { value, label } )
					) }
					onChange={ ( value ) => set( 'code' )( Number( value ) ) }
				/>
				<Text
					label={ __( 'To', 'barrio-suite' ) }
					help={ __(
						'A path of this site, such as /new-page/, or the full URL of another site.',
						'barrio-suite'
					) }
					required={ ! gone }
					disabled={ gone }
					value={ gone ? '' : draft.target }
					onChange={ set( 'target' ) }
				/>
				<Text
					label={ __( 'Note', 'barrio-suite' ) }
					maxLength={ 255 }
					value={ draft.note }
					onChange={ set( 'note' ) }
				/>
			</form>
		</Dialog>
	);
}

export function Redirects() {
	const [ query, setQuery ] = useState( INITIAL );
	const [ data, setData ] = useState( null );
	const [ notice, setNotice ] = useState( null );
	const [ editing, setEditing ] = useState( null );
	// Cambia dopo ogni modifica: ricarica la pagina della tabella.
	const [ reload, setReload ] = useState( 0 );

	const fail = ( error ) =>
		setNotice( { status: 'error', message: errorMessage( error ) } );

	useEffect( () => {
		let current = true;
		// Mentre si scrive nella ricerca parte una sola richiesta.
		const timer = setTimeout(
			() =>
				apiFetch( {
					path: `/barrio/v1/seo/redirects?${ new URLSearchParams(
						params( query )
					) }`,
				} )
					.then( ( result ) => current && setData( result ) )
					.catch( fail ),
			250
		);
		return () => {
			current = false;
			clearTimeout( timer );
		};
	}, [ query, reload ] );

	if ( ! data ) {
		return notice ? <Notice notice={ notice } /> : <Loading />;
	}

	const done = ( message ) => {
		setReload( ( n ) => n + 1 );
		setNotice( { status: 'success', message } );
	};
	const remove = ( ids ) => {
		if (
			// eslint-disable-next-line no-alert -- conferma prima di un'azione che non si annulla.
			window.confirm(
				sprintf(
					/* translators: %d: number of redirects to delete. */
					_n(
						'Delete %d redirect?',
						'Delete %d redirects?',
						ids.length,
						'barrio-suite'
					),
					ids.length
				)
			)
		) {
			setNotice( null );
			apiFetch( {
				path: '/barrio/v1/seo/redirects/bulk',
				method: 'POST',
				data: { action: 'delete', ids },
			} )
				.then( ( result ) =>
					done(
						sprintf(
							/* translators: %d: number of redirects deleted. */
							_n(
								'%d redirect deleted.',
								'%d redirects deleted.',
								result.deleted,
								'barrio-suite'
							),
							result.deleted
						)
					)
				)
				.catch( fail );
		}
	};

	const columns = [
		{
			key: 'source',
			label: __( 'From', 'barrio-suite' ),
			sort: 'source',
			className: 'is-truncate',
			render: ( row ) => (
				<span className="barrio-row is-nowrap">
					<button
						type="button"
						className="barrio-table__title"
						onClick={ () => setEditing( row ) }
					>
						{ row.source }
					</button>
					{ row.is_auto && (
						<span className="barrio-badge">
							{ __( 'Automatic', 'barrio-suite' ) }
						</span>
					) }
				</span>
			),
		},
		{
			key: 'target',
			label: __( 'To', 'barrio-suite' ),
			sort: 'target',
			className: 'is-truncate',
			render: ( row ) => row.target ?? '—',
		},
		{
			key: 'code',
			label: __( 'Type', 'barrio-suite' ),
			sort: 'code',
			hideable: true,
			render: ( row ) => (
				<span
					className={ `barrio-badge${
						410 === row.code ? ' is-warning' : ''
					}` }
				>
					{ CODES[ row.code ] }
				</span>
			),
		},
		{
			key: 'note',
			label: __( 'Note', 'barrio-suite' ),
			className: 'is-truncate',
			hideable: true,
			render: ( row ) => row.note || '—',
		},
		{
			key: 'modified',
			label: __( 'Last modified', 'barrio-suite' ),
			sort: 'updated_at',
			hideable: true,
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
						/* translators: %s: the source address of a redirect, such as /old-page. */
						__( 'Actions for %s', 'barrio-suite' ),
						row.source
					) }
					items={ [
						{
							label: __( 'Edit', 'barrio-suite' ),
							icon: 'edit',
							onClick: () => setEditing( row ),
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
				title={ __( 'Redirects', 'barrio-suite' ) }
				search={ __( 'Search redirects', 'barrio-suite' ) }
				empty={ __( 'No redirects found.', 'barrio-suite' ) }
				filters={ [
					{
						key: 'code',
						label: __( 'Type', 'barrio-suite' ),
						options: [
							{ value: '', label: __( 'All', 'barrio-suite' ) },
							...Object.entries( CODES ).map(
								( [ value, label ] ) => ( { value, label } )
							),
						],
					},
					{
						key: 'auto',
						label: __( 'Created', 'barrio-suite' ),
						options: [
							{ value: '', label: __( 'All', 'barrio-suite' ) },
							{
								value: '1',
								label: __(
									'Automatically, when an address changed',
									'barrio-suite'
								),
							},
							{
								value: '0',
								label: __( 'By hand', 'barrio-suite' ),
							},
						],
					},
				] }
				bulk={ ( ids ) => [
					{
						label: __( 'Delete', 'barrio-suite' ),
						icon: 'trash',
						danger: true,
						onClick: () => remove( ids ),
					},
				] }
				toolbar={
					<button
						type="button"
						className="barrio-button is-primary"
						onClick={ () => setEditing( EMPTY ) }
					>
						<Icon name="plus-alt2" />
						<span className="barrio-button__text">
							{ __( 'Add redirect', 'barrio-suite' ) }
						</span>
					</button>
				}
			/>
			{ editing && (
				<RedirectModal
					redirect={ editing }
					onSaved={ () => {
						setEditing( null );
						done(
							editing.id
								? __( 'Redirect saved.', 'barrio-suite' )
								: __( 'Redirect added.', 'barrio-suite' )
						);
					} }
					onClose={ () => setEditing( null ) }
				/>
			) }
		</div>
	);
}
