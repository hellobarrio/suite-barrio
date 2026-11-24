// Scheda Impostazioni: template delle email, privacy (con l'eliminazione di tutte le registrazioni,
// subito e senza Salva), cosa può fare ogni ruolo e le impostazioni globali delle integrazioni (i campi
// sono in Sections.js). Legge e salva con GET/PUT /barrio/v1/settings; i segreti delle integrazioni non
// tornano mai dal server, se ne conosce solo lo stato. L'SMTP è in Barrio → Impostazioni.
import apiFetch from '@wordpress/api-fetch';
import { useEffect, useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';

import {
	Bar,
	Card,
	Group,
	Loading,
	Notice,
	Permissions,
	SaveButton,
	errorMessage,
	useUnsavedWarning,
} from '../../../../../assets/src/admin/ui.js';
import { formsData } from '../ui.js';
import { GlobalFields, RetentionField, TemplateFields } from './Sections.js';

// Le integrazioni delle estensioni con impostazioni globali, una card ciascuna.
const GLOBAL = Object.entries( formsData.integrations ).filter(
	( [ , definition ] ) => Object.keys( definition.global_settings ).length
);

// Le capability del modulo, nei Permessi (RestAdmin::CAPS).
const CAPS = {
	barrio_manage_forms: __( 'Create and edit forms', 'barrio-suite' ),
	barrio_manage_entries: __( 'See and manage entries', 'barrio-suite' ),
};

// integrations: le impostazioni globali per slug (i segreti solo se appena scritti); roles: per
// ruolo le capability (gli amministratori hanno sempre tutto).
const editable = ( { email, privacy, integrations, roles } ) => ( {
	email,
	privacy,
	integrations,
	roles,
} );

export function Settings() {
	const [ saved, setSaved ] = useState( null );
	const [ draft, setDraft ] = useState( null );
	const [ notice, setNotice ] = useState( null );
	const [ busy, setBusy ] = useState( null );
	const fail = ( error ) =>
		setNotice( { status: 'error', message: errorMessage( error ) } );

	useEffect( () => {
		apiFetch( { path: '/barrio/v1/settings' } )
			.then( ( data ) => {
				setSaved( data );
				setDraft( editable( data ) );
			} )
			.catch( fail );
	}, [] );

	const dirty =
		!! saved &&
		JSON.stringify( draft ) !== JSON.stringify( editable( saved ) );
	useUnsavedWarning( dirty );

	if ( ! saved ) {
		return notice ? <Notice notice={ notice } /> : <Loading />;
	}

	const { roles, integrations } = draft;
	const set = ( key ) => ( value ) =>
		setDraft( { ...draft, [ key ]: value } );
	const request = ( kind, options, then ) => {
		setBusy( kind );
		setNotice( null );
		apiFetch( options )
			.then( ( data ) =>
				setNotice( { status: 'success', message: then( data ) } )
			)
			.catch( fail )
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
			.catch( fail )
			.finally( () => setBusy( null ) );
	};
	// Ciò che si scrive durante il salvataggio resta, da salvare.
	const save = () =>
		request(
			'save',
			{
				path: '/barrio/v1/settings',
				method: 'PUT',
				data: draft,
			},
			( response ) => {
				setSaved( response );
				setDraft( ( now ) =>
					now === draft ? editable( response ) : now
				);
				return __( 'Settings saved.', 'barrio-suite' );
			}
		);

	return (
		<div className="barrio-stack">
			<Bar title={ __( 'Settings', 'barrio-suite' ) }>
				<SaveButton
					busy={ 'save' === busy }
					disabled={ !! busy || ! dirty }
					onClick={ save }
				/>
			</Bar>
			<Notice notice={ notice } onClose={ () => setNotice( null ) } />
			<Card
				className="barrio-wide"
				icon="art"
				title={ __( 'Email template', 'barrio-suite' ) }
				description={ __(
					'Logo, colors and footer of the form notifications.',
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

			<Permissions
				description={ __(
					'What each role can do in Forms. Administrators can always do everything; only administrators change these settings.',
					'barrio-suite'
				) }
				caps={ CAPS }
				names={ saved.role_names }
				roles={ roles }
				onChange={ set( 'roles' ) }
			/>

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
