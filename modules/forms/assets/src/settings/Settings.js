// Scheda Impostazioni: SMTP, template delle email, email di test, privacy (con l'eliminazione di
// tutte le registrazioni, subito e senza Salva), cosa può fare ogni ruolo e le impostazioni globali
// delle integrazioni (i campi sono in Sections.js). Legge e salva con GET/PUT /barrio/v1/settings;
// la password SMTP e i segreti delle integrazioni non tornano mai dal server, se ne conosce solo lo stato.
import apiFetch from '@wordpress/api-fetch';
import { useEffect, useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';

import {
	Bar,
	Card,
	Check,
	Group,
	Loading,
	Lucide,
	Notice,
	useUnsavedWarning,
} from '../admin/ui.js';
import { errorMessage } from '../builder/model.js';
import {
	GlobalFields,
	RetentionField,
	SmtpFields,
	TemplateFields,
	TestEmail,
} from './Sections.js';

// Le integrazioni delle estensioni con impostazioni globali, una card ciascuna.
const GLOBAL = Object.entries( window.barrioAdmin.integrations ).filter(
	( [ , definition ] ) => Object.keys( definition.global_settings ).length
);

// Le capability del modulo, nei Permessi (RestAdmin::CAPS).
const CAPS = {
	barrio_manage_forms: __( 'Create and edit forms', 'barrio-suite' ),
	barrio_manage_entries: __( 'See and manage entries', 'barrio-suite' ),
};

// integrations: le impostazioni globali per slug (i segreti solo se appena scritti); roles: per
// ruolo le capability (gli amministratori hanno sempre tutto).
const editable = ( { smtp, email, privacy, integrations, roles } ) => ( {
	smtp,
	email,
	privacy,
	integrations,
	roles,
} );

export function Settings() {
	const [ saved, setSaved ] = useState( null );
	const [ draft, setDraft ] = useState( null );
	const [ password, setPassword ] = useState( '' );
	const [ notice, setNotice ] = useState( null );
	const [ busy, setBusy ] = useState( null );

	useEffect( () => {
		apiFetch( { path: '/barrio/v1/settings' } )
			.then( ( data ) => {
				setSaved( data );
				setDraft( editable( data ) );
			} )
			.catch( ( error ) =>
				setNotice( { status: 'error', message: errorMessage( error ) } )
			);
	}, [] );

	const dirty =
		!! saved &&
		( '' !== password ||
			JSON.stringify( draft ) !== JSON.stringify( editable( saved ) ) );
	useUnsavedWarning( dirty );

	if ( ! saved ) {
		return notice ? <Notice notice={ notice } /> : <Loading />;
	}

	const { roles, integrations } = draft;
	const set = ( key ) => ( value ) =>
		setDraft( { ...draft, [ key ]: value } );
	// Nell'ordine di CAPS, così il confronto con quelle salvate non dipende dall'ordine dei clic.
	const setCap = ( role, cap ) => ( on ) =>
		set( 'roles' )( {
			...roles,
			[ role ]: Object.keys( CAPS ).filter( ( key ) =>
				key === cap ? on : roles[ role ].includes( key )
			),
		} );
	const request = ( kind, options, then ) => {
		setBusy( kind );
		setNotice( null );
		apiFetch( options )
			.then( ( data ) =>
				setNotice( { status: 'success', message: then( data ) } )
			)
			.catch( ( error ) =>
				setNotice( { status: 'error', message: errorMessage( error ) } )
			)
			.finally( () => setBusy( null ) );
	};
	// Le registrazioni di tutti i form, con la rotta di massa di ognuno: elimina a blocchi, con i file.
	const deleteAll = () => {
		setBusy( 'delete' );
		setNotice( null );
		apiFetch( { path: '/barrio/v1/forms' } )
			.then( ( forms ) => {
				const full = forms.filter( ( form ) => form.entries > 0 );
				const total = full.reduce( ( n, form ) => n + form.entries, 0 );
				if ( ! total ) {
					return __(
						'There are no entries to delete.',
						'barrio-suite'
					);
				}
				if (
					// eslint-disable-next-line no-alert -- conferma prima di un'azione che non si annulla.
					! window.confirm(
						sprintf(
							/* translators: %d: number of entries of every form. */
							_n(
								'Delete %d entry permanently, with its files? This cannot be undone.',
								'Delete all %d entries of every form permanently, with their files? This cannot be undone.',
								total,
								'barrio-suite'
							),
							total
						)
					)
				) {
					return null;
				}
				return Promise.all(
					full.map( ( form ) =>
						apiFetch( {
							path: `/barrio/v1/forms/${ form.id }/entries/bulk`,
							method: 'POST',
							data: { action: 'delete', all: true },
						} )
					)
				).then( ( results ) => {
					const deleted = results.reduce(
						( n, result ) => n + result.deleted,
						0
					);
					return sprintf(
						/* translators: %d: number of entries deleted. */
						_n(
							'%d entry deleted.',
							'%d entries deleted.',
							deleted,
							'barrio-suite'
						),
						deleted
					);
				} );
			} )
			.then(
				( message ) =>
					message && setNotice( { status: 'success', message } )
			)
			.catch( ( error ) =>
				setNotice( { status: 'error', message: errorMessage( error ) } )
			)
			.finally( () => setBusy( null ) );
	};
	// Ciò che si scrive durante il salvataggio resta, da salvare.
	const save = () =>
		request(
			'save',
			{
				path: '/barrio/v1/settings',
				method: 'PUT',
				data: { ...draft, ...( password && { password } ) },
			},
			( response ) => {
				setSaved( response );
				setDraft( ( now ) =>
					now === draft ? editable( response ) : now
				);
				setPassword( ( now ) => ( now === password ? '' : now ) );
				return __( 'Settings saved.', 'barrio-suite' );
			}
		);

	return (
		<div className="barrio-stack">
			<Bar title={ __( 'Settings', 'barrio-suite' ) }>
				<button
					type="button"
					className="barrio-button is-primary"
					disabled={ !! busy || ! dirty }
					onClick={ save }
				>
					<Lucide name="save" />
					<span className="barrio-button__text">
						{ 'save' === busy
							? __( 'Saving…', 'barrio-suite' )
							: __( 'Save', 'barrio-suite' ) }
					</span>
				</button>
			</Bar>
			<Notice notice={ notice } onClose={ () => setNotice( null ) } />
			<Card
				className="barrio-wide"
				icon="email-alt"
				title="SMTP"
				description={ __(
					'Used for all the email the site sends, not only form notifications. Leave the host empty to send with the default WordPress mail.',
					'barrio-suite'
				) }
			>
				<SmtpFields
					smtp={ draft.smtp }
					onChange={ set( 'smtp' ) }
					password={ password }
					onPassword={ setPassword }
					passwordStatus={ saved.password_status }
				/>
			</Card>

			<Card
				className="barrio-wide"
				icon="art"
				title={ __( 'Email template', 'barrio-suite' ) }
				description={ __(
					'Logo, colors and footer of the form notifications and of the test email.',
					'barrio-suite'
				) }
			>
				<TemplateFields
					email={ draft.email }
					onChange={ set( 'email' ) }
				/>
			</Card>

			<Card
				className="barrio-wide"
				icon="email"
				title={ __( 'Test email', 'barrio-suite' ) }
				description={ __(
					'Sends an email with the saved settings and the template of the notifications.',
					'barrio-suite'
				) }
			>
				<TestEmail dirty={ dirty } onNotice={ setNotice } />
			</Card>

			<Card
				className="barrio-wide"
				icon="privacy"
				title={ __( 'Privacy', 'barrio-suite' ) }
				description={ __(
					'Requests to export or erase personal data, from Tools in WordPress, include the form entries that contain the email address in an email field.',
					'barrio-suite'
				) }
			>
				<RetentionField
					privacy={ draft.privacy }
					onChange={ set( 'privacy' ) }
				/>
				<Group
					label={ __( 'All entries', 'barrio-suite' ) }
					help={ __(
						'Deletes the entries of every form, with their files, right away. It cannot be undone. To delete only some, select them in Entries.',
						'barrio-suite'
					) }
				>
					<button
						type="button"
						className="barrio-button is-danger"
						disabled={ !! busy }
						onClick={ deleteAll }
					>
						{ 'delete' === busy
							? __( 'Deleting…', 'barrio-suite' )
							: __( 'Delete all entries', 'barrio-suite' ) }
					</button>
				</Group>
			</Card>

			<Card
				className="barrio-wide"
				icon="groups"
				title={ __( 'Permissions', 'barrio-suite' ) }
				description={ __(
					'What each role can do in Forms. Administrators can always do everything; only administrators change these settings.',
					'barrio-suite'
				) }
			>
				{ /* Gli amministratori non sono in roles: tutto spuntato e fisso. */ }
				{ Object.entries( saved.role_names ).map(
					( [ role, name ] ) => (
						<Group key={ role } label={ name }>
							<div className="barrio-checks">
								{ Object.entries( CAPS ).map(
									( [ cap, label ] ) => (
										<Check
											key={ cap }
											label={ label }
											disabled={ ! roles[ role ] }
											checked={
												! roles[ role ] ||
												roles[ role ].includes( cap )
											}
											onChange={ setCap( role, cap ) }
										/>
									)
								) }
							</div>
						</Group>
					)
				) }
			</Card>

			{ GLOBAL.map( ( [ slug, definition ] ) => (
				<Card
					key={ slug }
					className="barrio-wide"
					icon="admin-plugins"
					title={ definition.label }
					description={ definition.description }
				>
					<GlobalFields
						definition={ definition }
						values={ integrations[ slug ] }
						secrets={ saved.secrets[ slug ] }
						onChange={ ( values ) =>
							set( 'integrations' )( {
								...integrations,
								[ slug ]: values,
							} )
						}
					/>
				</Card>
			) ) }
		</div>
	);
}
