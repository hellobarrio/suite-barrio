// Pagina Barrio: intestazione e schede Form e Impostazioni. Sotto Form l'elenco, o il builder con
// ?form=ID (o ?form=new). Ogni vista è un URL: link normali, niente router.
import apiFetch from '@wordpress/api-fetch';
import { createRoot, useEffect, useState } from '@wordpress/element';
import { __, _n, _x, sprintf } from '@wordpress/i18n';

import { Builder } from '../builder/Builder.js';
import { errorMessage } from '../builder/model.js';
import { Settings } from '../settings/Settings.js';
import './admin.css';
import { initialQuery, localQuery } from './query.js';
import { Icon, Loading, Menu, Notice, Table, pageUrl } from './ui.js';

const STATUS = {
	active: [ __( 'Active', 'barrio-suite' ), 'is-success' ],
	closed: [ _x( 'Closed', 'form status', 'barrio-suite' ), 'is-warning' ],
	trash: [ __( 'Trash', 'barrio-suite' ), '' ],
};

// I più recenti per primi, come li restituisce il server.
const INITIAL = initialQuery( 'id', 'desc' );

function FormList() {
	const [ forms, setForms ] = useState( null );
	const [ trash, setTrash ] = useState( false );
	const [ query, setQuery ] = useState( INITIAL );
	const [ notice, setNotice ] = useState( null );

	const fail = ( error ) =>
		setNotice( { status: 'error', message: errorMessage( error ) } );
	// Senza return: un effetto non deve restituire la Promise.
	const load = () => {
		apiFetch( { path: '/barrio/v1/forms' } ).then( setForms ).catch( fail );
	};
	useEffect( load, [] );

	const act = ( path, method, data, message ) => {
		setNotice( null );
		apiFetch( { path: `/barrio/v1/forms/${ path }`, method, data } )
			.then( () => {
				load();
				setNotice( { status: 'success', message } );
			} )
			.catch( fail );
	};
	const copied = () =>
		setNotice( {
			status: 'success',
			message: __( 'Shortcode copied.', 'barrio-suite' ),
		} );
	// Un form con registrazioni non si può eliminare (le registrazioni sono la prova del consenso):
	// va nel cestino. Dal cestino si elimina quando non ne ha più.
	const remove = ( form ) => {
		const toTrash = form.entries > 0 && 'trash' !== form.status;
		const question = toTrash
			? sprintf(
					/* translators: 1: nome del form; 2: numero di registrazioni. */
					__(
						'"%1$s" has %2$d entries, so it cannot be deleted: move it to the trash? The entries are kept.',
						'barrio-suite'
					),
					form.title,
					form.entries
				)
			: sprintf(
					/* translators: %s: nome del form. */
					__( 'Delete "%s" permanently?', 'barrio-suite' ),
					form.title
				);
		// eslint-disable-next-line no-alert -- conferma prima di un'azione che non si annulla.
		if ( window.confirm( question ) ) {
			if ( toTrash ) {
				act(
					form.id,
					'PATCH',
					{ status: 'trash' },
					__( 'Form moved to the trash.', 'barrio-suite' )
				);
			} else {
				act(
					form.id,
					'DELETE',
					undefined,
					__( 'Form deleted.', 'barrio-suite' )
				);
			}
		}
	};

	if ( ! forms ) {
		return notice ? <Notice notice={ notice } /> : <Loading />;
	}
	const trashed = forms.filter( ( f ) => 'trash' === f.status );
	const { rows, total } = localQuery(
		trash ? trashed : forms.filter( ( f ) => 'trash' !== f.status ),
		query,
		[ 'id', 'title' ]
	);

	const columns = [
		{ key: 'id', label: '#', sort: 'id', className: 'is-narrow' },
		{
			key: 'title',
			label: __( 'Name', 'barrio-suite' ),
			sort: 'title',
			render: ( form ) =>
				'trash' === form.status ? (
					form.title
				) : (
					<a
						className="barrio-table__title"
						href={ pageUrl( { form: form.id } ) }
					>
						{ form.title }
					</a>
				),
		},
		{
			key: 'entries',
			label: __( 'Entries', 'barrio-suite' ),
			sort: 'entries',
			render: ( form ) => (
				<span
					className={ `barrio-badge${ form.entries ? ' is-success' : '' }` }
				>
					{ sprintf(
						/* translators: %d: numero di registrazioni. */
						_n(
							'%d entry',
							'%d entries',
							form.entries,
							'barrio-suite'
						),
						form.entries
					) }
				</span>
			),
		},
		{
			key: 'status',
			label: __( 'Status', 'barrio-suite' ),
			sort: 'status',
			render: ( form ) => (
				<span
					className={ `barrio-badge ${ STATUS[ form.status ][ 1 ] }` }
				>
					{ STATUS[ form.status ][ 0 ] }
				</span>
			),
		},
		{
			key: 'modified',
			label: __( 'Last modified', 'barrio-suite' ),
			sort: 'updated_at',
		},
		{
			key: 'actions',
			label: (
				<span className="screen-reader-text">
					{ __( 'Actions', 'barrio-suite' ) }
				</span>
			),
			className: 'is-narrow is-end',
			render: ( form ) => (
				<Menu
					label={ sprintf(
						/* translators: %s: nome del form. */
						__( 'Actions for "%s"', 'barrio-suite' ),
						form.title
					) }
					items={
						'trash' === form.status
							? [
									{
										label: __( 'Restore', 'barrio-suite' ),
										icon: 'undo',
										onClick: () =>
											act(
												form.id,
												'PATCH',
												{ status: 'active' },
												__(
													'Form restored.',
													'barrio-suite'
												)
											),
									},
									{
										label: __(
											'Delete permanently',
											'barrio-suite'
										),
										icon: 'trash',
										danger: true,
										onClick: () => remove( form ),
									},
								]
							: [
									{
										label: __( 'Edit', 'barrio-suite' ),
										icon: 'edit',
										href: pageUrl( { form: form.id } ),
									},
									{
										label: __(
											'Duplicate',
											'barrio-suite'
										),
										icon: 'admin-page',
										onClick: () =>
											act(
												`${ form.id }/duplicate`,
												'POST',
												undefined,
												__(
													'Form duplicated.',
													'barrio-suite'
												)
											),
									},
									window.navigator.clipboard && {
										label: __(
											'Copy shortcode',
											'barrio-suite'
										),
										icon: 'shortcode',
										onClick: () =>
											window.navigator.clipboard
												.writeText(
													`[barrio_form id="${ form.id }"]`
												)
												.then( copied ),
									},
									'active' === form.status
										? {
												label: __(
													'Close to new entries',
													'barrio-suite'
												),
												icon: 'lock',
												onClick: () =>
													act(
														form.id,
														'PATCH',
														{ status: 'closed' },
														__(
															'Form closed.',
															'barrio-suite'
														)
													),
											}
										: {
												label: __(
													'Reopen',
													'barrio-suite'
												),
												icon: 'unlock',
												onClick: () =>
													act(
														form.id,
														'PATCH',
														{ status: 'active' },
														__(
															'Form reopened.',
															'barrio-suite'
														)
													),
											},
									{
										label: __( 'Delete', 'barrio-suite' ),
										icon: 'trash',
										danger: true,
										onClick: () => remove( form ),
									},
								]
					}
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
				title={
					trash
						? __( 'Trash', 'barrio-suite' )
						: __( 'All forms', 'barrio-suite' )
				}
				search={ __( 'Search forms', 'barrio-suite' ) }
				empty={
					trash
						? __( 'The trash is empty.', 'barrio-suite' )
						: __( 'No forms found.', 'barrio-suite' )
				}
				toolbar={
					<>
						{ ( trash || trashed.length > 0 ) && (
							<button
								type="button"
								className="barrio-button"
								onClick={ () => {
									setTrash( ! trash );
									setQuery( { ...query, page: 1 } );
								} }
							>
								<Icon
									name={ trash ? 'arrow-left-alt2' : 'trash' }
								/>
								{ trash
									? __( 'All forms', 'barrio-suite' )
									: sprintf(
											/* translators: %d: form nel cestino. */
											__( 'Trash (%d)', 'barrio-suite' ),
											trashed.length
										) }
							</button>
						) }
						<a
							className="barrio-button is-primary"
							href={ pageUrl( { form: 'new' } ) }
						>
							<Icon name="plus-alt2" />
							{ __( 'New form', 'barrio-suite' ) }
						</a>
					</>
				}
			/>
		</div>
	);
}

const { canManageOptions } = window.barrioAdmin;
const params = new URLSearchParams( window.location.search );
const settings = canManageOptions && 'settings' === params.get( 'tab' );
const form = params.get( 'form' );

let content = <FormList />;
if ( settings ) {
	content = <Settings />;
} else if ( 'new' === form || /^[1-9]\d*$/.test( form ?? '' ) ) {
	content = <Builder id={ 'new' === form ? null : Number( form ) } />;
}

createRoot( document.getElementById( 'barrio-forms-admin' ) ).render(
	<div className="barrio-admin">
		<header className="barrio-header">
			<span className="barrio-header__logo">
				<svg
					width="28"
					height="28"
					viewBox="0 0 24 24"
					fill="none"
					stroke="currentColor"
					strokeWidth="2"
					strokeLinecap="round"
					aria-hidden="true"
				>
					<rect x="3" y="4" width="18" height="16" rx="2" />
					<path d="M7 9h10M7 13h6M7 17h4" />
				</svg>
			</span>
			<div>
				<h1>
					Barrio Suite{ ' ' }
					<span>/ { __( 'Forms', 'barrio-suite' ) }</span>
				</h1>
				<p>
					{ __(
						'Contact forms, their notifications and the email of the site.',
						'barrio-suite'
					) }
				</p>
			</div>
		</header>
		<nav
			className="barrio-nav"
			aria-label={ __( 'Sections', 'barrio-suite' ) }
		>
			<a
				href={ pageUrl() }
				aria-current={ settings ? undefined : 'page' }
			>
				{ __( 'Forms', 'barrio-suite' ) }
			</a>
			{ canManageOptions && (
				<a
					href={ pageUrl( { tab: 'settings' } ) }
					aria-current={ settings ? 'page' : undefined }
				>
					{ __( 'Settings', 'barrio-suite' ) }
				</a>
			) }
		</nav>
		{ content }
	</div>
);
