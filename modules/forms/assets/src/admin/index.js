// Pagine Barrio: intestazione e schede Form, Registrazioni e Impostazioni. Sotto Form l'elenco, o
// il builder con ?form=ID (o ?form=new); sotto Registrazioni (tab=entries) quelle di un form, con il
// dettaglio in ?entry=ID. Estensioni (page=barrio-extensions) non è dei form: niente schede.
// Ogni vista è un URL: link normali, niente router.
import apiFetch from '@wordpress/api-fetch';
import { createRoot, useEffect, useState } from '@wordpress/element';
import { __, _n, _x, sprintf } from '@wordpress/i18n';

import { Builder } from '../builder/Builder.js';
import { errorMessage } from '../builder/model.js';
import { Entries } from '../entries/Entries.js';
import { Entry } from '../entries/Entry.js';
import { Extensions } from '../extensions/Extensions.js';
import { Settings } from '../settings/Settings.js';
import './admin.css';
import { initialQuery, localQuery } from './query.js';
import {
	Icon,
	Lang,
	Loading,
	Menu,
	Notice,
	Table,
	entriesUrl,
	langOption,
	pageUrl,
} from './ui.js';

const STATUS = {
	active: [ __( 'Active', 'barrio-suite' ), 'is-success' ],
	closed: [ _x( 'Closed', 'form status', 'barrio-suite' ), 'is-warning' ],
	trash: [ __( 'Trash', 'barrio-suite' ), '' ],
};

// I più recenti per primi, come li restituisce il server. status '' = tutti tranne il cestino;
// lang '' = in qualsiasi lingua.
const INITIAL = { ...initialQuery( 'id', 'desc' ), status: '', lang: '' };

// La lingua principale e quelle in cui si traduce.
const [ main, ...translations ] = window.barrioAdmin.languages;

function FormList() {
	const [ forms, setForms ] = useState( null );
	const [ query, setQuery ] = useState( INITIAL );
	const [ notice, setNotice ] = useState( null );

	const fail = ( error ) =>
		setNotice( { status: 'error', message: errorMessage( error ) } );
	// Senza return: un effetto non deve restituire la Promise.
	const load = () => {
		apiFetch( { path: '/barrio/v1/forms' } ).then( setForms ).catch( fail );
	};
	useEffect( load, [] );

	// Una richiesta per form: sono pochi, una rotta per le azioni di massa non serve. Si ricarica
	// anche se qualcuna fallisce, perché le altre sono andate.
	const act = ( ids, { path = '', ...request }, message ) => {
		setNotice( null );
		Promise.all(
			ids.map( ( id ) =>
				apiFetch( {
					path: `/barrio/v1/forms/${ id }${ path }`,
					...request,
				} )
			)
		)
			.then( () => setNotice( { status: 'success', message } ) )
			.catch( fail )
			.finally( load );
	};
	const setStatus = ( ids, status, message ) =>
		act( ids, { method: 'PATCH', data: { status } }, message );
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
				setStatus(
					[ form.id ],
					'trash',
					__( 'Form moved to the trash.', 'barrio-suite' )
				);
			} else {
				act(
					[ form.id ],
					{ method: 'DELETE' },
					__( 'Form deleted.', 'barrio-suite' )
				);
			}
		}
	};
	// Dal cestino; quelli con registrazioni il server li rifiuta e lo dice.
	const removeMany = ( ids ) => {
		if (
			// eslint-disable-next-line no-alert -- conferma prima di un'azione che non si annulla.
			window.confirm(
				sprintf(
					/* translators: %d: form da eliminare. */
					_n(
						'Delete %d form permanently?',
						'Delete %d forms permanently?',
						ids.length,
						'barrio-suite'
					),
					ids.length
				)
			)
		) {
			act(
				ids,
				{ method: 'DELETE' },
				sprintf(
					/* translators: %d: form eliminati. */
					_n(
						'%d form deleted.',
						'%d forms deleted.',
						ids.length,
						'barrio-suite'
					),
					ids.length
				)
			);
		}
	};

	if ( ! forms ) {
		return notice ? <Notice notice={ notice } /> : <Loading />;
	}
	const trash = 'trash' === query.status;
	const count = ( status ) =>
		forms.filter( ( f ) => f.status === status ).length;
	const visible = forms.filter( ( f ) =>
		query.status ? f.status === query.status : 'trash' !== f.status
	);
	// Con un'altra lingua i form che hanno qualche testo in quella lingua (gli altri si mostrano
	// nella principale); con la principale quelli che non sono tradotti in nessuna.
	const inLanguage = ( lang ) =>
		visible.filter( ( f ) =>
			lang === main
				? ! translations.some( ( code ) =>
						f.languages.includes( code )
					)
				: f.languages.includes( lang )
		);
	const { rows, total } = localQuery(
		query.lang ? inLanguage( query.lang ) : visible,
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
			hideable: true,
			render: ( form ) => (
				<a
					className={ `barrio-badge${ form.entries ? ' is-success' : '' }` }
					href={ entriesUrl( { form: form.id } ) }
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
				</a>
			),
		},
		{
			key: 'status',
			label: __( 'Status', 'barrio-suite' ),
			sort: 'status',
			hideable: true,
			render: ( form ) => (
				<span
					className={ `barrio-badge ${ STATUS[ form.status ][ 1 ] }` }
				>
					{ STATUS[ form.status ][ 0 ] }
				</span>
			),
		},
		translations.length > 0 && {
			key: 'languages',
			label: __( 'Translations', 'barrio-suite' ),
			hideable: true,
			render: ( form ) => {
				const done = translations.filter( ( lang ) =>
					form.languages.includes( lang )
				);
				return done.length ? (
					<span className="barrio-row is-nowrap">
						{ done.map( ( lang ) => (
							<span key={ lang } className="barrio-badge">
								<Lang code={ lang } />
							</span>
						) ) }
					</span>
				) : (
					'—'
				);
			},
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
											setStatus(
												[ form.id ],
												'active',
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
												[ form.id ],
												{
													method: 'POST',
													path: '/duplicate',
												},
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
													setStatus(
														[ form.id ],
														'closed',
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
													setStatus(
														[ form.id ],
														'active',
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
				title={ __( 'Forms', 'barrio-suite' ) }
				search={ __( 'Search forms', 'barrio-suite' ) }
				empty={
					trash
						? __( 'The trash is empty.', 'barrio-suite' )
						: __( 'No forms found.', 'barrio-suite' )
				}
				filters={ [
					{
						key: 'status',
						label: __( 'Status', 'barrio-suite' ),
						options: [
							{
								value: '',
								label: __( 'All forms', 'barrio-suite' ),
								count: forms.length - count( 'trash' ),
							},
							...Object.entries( STATUS ).map(
								( [ value, [ label ] ] ) => ( {
									value,
									label,
									count: count( value ),
								} )
							),
						],
					},
					translations.length > 0 && {
						key: 'lang',
						label: __( 'Language', 'barrio-suite' ),
						options: [
							{
								value: '',
								label: __( 'All languages', 'barrio-suite' ),
								count: visible.length,
							},
							...[ main, ...translations ].map( ( lang ) => ( {
								...langOption( lang ),
								count: inLanguage( lang ).length,
							} ) ),
						],
					},
				] }
				bulk={ ( ids ) =>
					trash
						? [
								{
									label: __( 'Restore', 'barrio-suite' ),
									icon: 'undo',
									onClick: () =>
										setStatus(
											ids,
											'active',
											sprintf(
												/* translators: %d: form ripristinati. */
												_n(
													'%d form restored.',
													'%d forms restored.',
													ids.length,
													'barrio-suite'
												),
												ids.length
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
									onClick: () => removeMany( ids ),
								},
							]
						: [
								{
									label: __( 'Reopen', 'barrio-suite' ),
									icon: 'unlock',
									onClick: () =>
										setStatus(
											ids,
											'active',
											sprintf(
												/* translators: %d: form riaperti. */
												_n(
													'%d form reopened.',
													'%d forms reopened.',
													ids.length,
													'barrio-suite'
												),
												ids.length
											)
										),
								},
								{
									label: __(
										'Close to new entries',
										'barrio-suite'
									),
									icon: 'lock',
									onClick: () =>
										setStatus(
											ids,
											'closed',
											sprintf(
												/* translators: %d: form chiusi. */
												_n(
													'%d form closed.',
													'%d forms closed.',
													ids.length,
													'barrio-suite'
												),
												ids.length
											)
										),
								},
								// Nel cestino anche quelli senza registrazioni: si può tornare indietro.
								{
									label: __(
										'Move to trash',
										'barrio-suite'
									),
									icon: 'trash',
									onClick: () =>
										setStatus(
											ids,
											'trash',
											sprintf(
												/* translators: %d: form spostati nel cestino. */
												_n(
													'%d form moved to the trash.',
													'%d forms moved to the trash.',
													ids.length,
													'barrio-suite'
												),
												ids.length
											)
										),
								},
							]
				}
				toolbar={
					<a
						className="barrio-button is-primary"
						href={ pageUrl( { form: 'new' } ) }
					>
						<Icon name="plus-alt2" />
						<span className="barrio-button__text">
							{ __( 'New form', 'barrio-suite' ) }
						</span>
					</a>
				}
			/>
		</div>
	);
}

const { canManageForms, canManageOptions } = window.barrioAdmin;
const params = new URLSearchParams( window.location.search );
const id = ( name ) =>
	/^[1-9]\d*$/.test( params.get( name ) ?? '' )
		? Number( params.get( name ) )
		: null;
const tab = params.get( 'tab' );
const settings = canManageOptions && 'settings' === tab;
// Chi non gestisce i form (gli editor) ha solo le registrazioni, senza schede.
const entries = ! settings && ( 'entries' === tab || ! canManageForms );

let content = <FormList />;
if ( entries ) {
	content = id( 'entry' ) ? (
		<Entry id={ id( 'entry' ) } />
	) : (
		<Entries form={ id( 'form' ) } />
	);
} else if ( settings ) {
	content = <Settings />;
} else if ( 'new' === params.get( 'form' ) || id( 'form' ) ) {
	content = <Builder id={ id( 'form' ) } />;
}

// Le schede dei form; Estensioni è una pagina a sé, per tutta la suite.
const forms = canManageForms && (
	<nav className="barrio-nav" aria-label={ __( 'Sections', 'barrio-suite' ) }>
		<a
			href={ pageUrl() }
			aria-current={ entries || settings ? undefined : 'page' }
		>
			{ __( 'Forms', 'barrio-suite' ) }
		</a>
		<a href={ entriesUrl() } aria-current={ entries ? 'page' : undefined }>
			{ __( 'Entries', 'barrio-suite' ) }
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
);
const extensions = 'barrio-extensions' === params.get( 'page' );

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
					<span>
						/{ ' ' }
						{ extensions
							? __( 'Extensions', 'barrio-suite' )
							: __( 'Forms', 'barrio-suite' ) }
					</span>
				</h1>
				<p>
					{ extensions
						? __(
								'Plugins that connect Barrio Suite to other services.',
								'barrio-suite'
							)
						: __(
								'Contact forms, their notifications and the email of the site.',
								'barrio-suite'
							) }
				</p>
			</div>
		</header>
		{ extensions ? <Extensions /> : forms }
		{ ! extensions && content }
	</div>
);
