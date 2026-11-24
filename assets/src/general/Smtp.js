// SMTP ed email di prova (src/Mail/Smtp.php), in Barrio → Impostazioni e nella guida alla messa
// online. La password non torna mai dal server, se ne conosce solo lo stato: vuota vuol dire "resta
// quella salvata", e per toglierla c'è il suo bottone (solo nelle Impostazioni).
import apiFetch from '@wordpress/api-fetch';
import { useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

import { Card, Details, Select, Text, errorMessage } from '../admin/ui.js';

const PASSWORD_HELP = {
	none: '',
	saved: __( 'Leave empty to keep the saved password.', 'barrio-suite' ),
	constant: __(
		'Defined in wp-config.php with the BARRIO_SMTP_PASSWORD constant.',
		'barrio-suite'
	),
	unreadable: __(
		'The saved password can no longer be read, probably because the WordPress security keys have changed. Enter it again.',
		'barrio-suite'
	),
};

// La password è a parte: non torna mai dal server, se ne conosce solo lo stato.
function SmtpFields( {
	smtp,
	onChange,
	password,
	onPassword,
	passwordStatus,
	onRemovePassword,
} ) {
	const [ showPassword, setShowPassword ] = useState( false );
	const set = ( key ) => ( value ) => onChange( { ...smtp, [ key ]: value } );
	return (
		<>
			<Text
				label={ __( 'Host', 'barrio-suite' ) }
				placeholder="smtp.example.com"
				value={ smtp.host }
				onChange={ set( 'host' ) }
			/>
			<Text
				label={ __( 'Port', 'barrio-suite' ) }
				type="number"
				min={ 1 }
				max={ 65535 }
				value={ smtp.port }
				onChange={ ( value ) => set( 'port' )( Number( value ) ) }
			/>
			<Select
				label={ __( 'Encryption', 'barrio-suite' ) }
				value={ smtp.encryption }
				options={ [
					{ label: 'TLS (STARTTLS)', value: 'tls' },
					{ label: 'SSL', value: 'ssl' },
					{ label: __( 'None', 'barrio-suite' ), value: 'none' },
				] }
				onChange={ set( 'encryption' ) }
			/>
			<Text
				label={ __( 'Username', 'barrio-suite' ) }
				help={ __(
					'Leave empty if the server does not require authentication.',
					'barrio-suite'
				) }
				autoComplete="off"
				value={ smtp.username }
				onChange={ set( 'username' ) }
			/>
			<Text
				label={ __( 'Password', 'barrio-suite' ) }
				type={ showPassword ? 'text' : 'password' }
				autoComplete="new-password"
				help={ PASSWORD_HELP[ passwordStatus ] }
				placeholder={ 'saved' === passwordStatus ? '••••••••' : '' }
				disabled={ 'constant' === passwordStatus }
				value={ password }
				onChange={ onPassword }
				addon={
					<button
						type="button"
						className="barrio-button"
						onClick={ () => setShowPassword( ! showPassword ) }
					>
						{ showPassword
							? __( 'Hide', 'barrio-suite' )
							: __( 'Show', 'barrio-suite' ) }
					</button>
				}
				actions={
					onRemovePassword &&
					[ 'saved', 'unreadable' ].includes( passwordStatus ) && (
						<button
							type="button"
							className="barrio-button is-danger"
							onClick={ onRemovePassword }
						>
							{ __( 'Remove password', 'barrio-suite' ) }
						</button>
					)
				}
			/>
			<Text
				label={ __( 'From email', 'barrio-suite' ) }
				help={ __(
					'The sender of every email the site sends. SMTP servers usually accept only the addresses of their own domain.',
					'barrio-suite'
				) }
				type="email"
				value={ smtp.from_email }
				onChange={ set( 'from_email' ) }
			/>
			<Text
				label={ __( 'From name', 'barrio-suite' ) }
				value={ smtp.from_name }
				onChange={ set( 'from_name' ) }
			/>
		</>
	);
}

/**
 * Con le impostazioni salvate: con modifiche in sospeso è spenta.
 *
 * @param {Object}                   props
 * @param {boolean}                  props.dirty    Ci sono modifiche non salvate.
 * @param {(notice: Object) => void} props.onNotice L'esito, come avviso della pagina.
 */
function TestEmail( { dirty, onNotice } ) {
	const [ to, setTo ] = useState( '' );
	const [ busy, setBusy ] = useState( false );
	const send = () => {
		setBusy( true );
		onNotice( null );
		apiFetch( {
			path: '/barrio/v1/smtp/test-email',
			method: 'POST',
			data: to ? { to } : {},
		} )
			.then( ( data ) =>
				onNotice( { status: 'success', message: data.message } )
			)
			.catch( ( error ) =>
				onNotice( { status: 'error', message: errorMessage( error ) } )
			)
			.finally( () => setBusy( false ) );
	};
	return (
		<Text
			label={ __( 'Send to', 'barrio-suite' ) }
			help={
				dirty
					? __(
							'Save your changes first: the test uses the saved settings.',
							'barrio-suite'
						)
					: __(
							'Leave empty to send it to your own address.',
							'barrio-suite'
						)
			}
			type="email"
			value={ to }
			onChange={ setTo }
			addon={
				<button
					type="button"
					className="barrio-button"
					disabled={ busy || dirty }
					onClick={ send }
				>
					{ busy
						? __( 'Sending…', 'barrio-suite' )
						: __( 'Send test email', 'barrio-suite' ) }
				</button>
			}
		/>
	);
}

const smtp = {
	id: 'smtp',
	load: () => apiFetch( { path: '/barrio/v1/smtp' } ),
	value: ( data ) => ( { smtp: data.smtp, password: '' } ),
	save: ( draft ) =>
		apiFetch( {
			path: '/barrio/v1/smtp',
			method: 'PUT',
			data: {
				smtp: draft.smtp,
				...( draft.password && { password: draft.password } ),
			},
		} ),
};

// I campi e, chiusa, l'email di prova. onData c'è solo nelle Impostazioni: lì la password si può togliere, subito
// e non con Salva.
const Fields = ( { draft, set, data, dirty, onData, onNotice } ) => (
	<>
		<SmtpFields
			smtp={ draft.smtp }
			onChange={ ( value ) => set( { ...draft, smtp: value } ) }
			password={ draft.password }
			onPassword={ ( password ) => set( { ...draft, password } ) }
			passwordStatus={ data.password_status }
			onRemovePassword={
				onData &&
				( () =>
					// eslint-disable-next-line no-alert -- conferma prima di un'azione che non si annulla.
					window.confirm(
						__(
							'Remove the saved SMTP password from this site?',
							'barrio-suite'
						)
					) &&
					apiFetch( {
						path: '/barrio/v1/smtp/password',
						method: 'DELETE',
					} )
						.then( onData )
						.catch( ( error ) =>
							onNotice( {
								status: 'error',
								message: errorMessage( error ),
							} )
						) )
			}
		/>
		<Details title={ __( 'Test email', 'barrio-suite' ) }>
			<TestEmail dirty={ dirty } onNotice={ onNotice } />
		</Details>
	</>
);

// Nella guida alla messa online (assets/src/launch): fatto con host, mittente e, se il server la
// chiede, la password (Smtp::is_configured()).
export const smtpStep = {
	...smtp,
	icon: 'email-alt',
	title: 'SMTP',
	description: __(
		'The emails of the site leave through an SMTP server, so they do not end up in spam.',
		'barrio-suite'
	),
	Fields,
};

// In Barrio → Impostazioni (assets/src/general).
export const smtpSection = {
	...smtp,
	Cards: ( props ) => (
		<Card
			className="barrio-wide"
			icon="email-alt"
			title="SMTP"
			description={ __(
				'Used for all the email the site sends, not only form notifications. Leave the host empty to send with the default WordPress mail.',
				'barrio-suite'
			) }
		>
			<Fields { ...props } />
		</Card>
	),
};
