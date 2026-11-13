// Scheda Impostazioni: SMTP, template delle email, email di test e privacy. Legge e salva con
// GET/PUT /barrio/v1/settings; la password SMTP non torna mai dal server, se ne conosce solo lo stato.
import apiFetch from '@wordpress/api-fetch';
import { useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { MediaUpload } from '@wordpress/media-utils';

import {
	Bar,
	Card,
	Group,
	Loading,
	Lucide,
	Notice,
	Select,
	Text,
	useUnsavedWarning,
} from '../admin/ui.js';
import { errorMessage } from '../builder/model.js';

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

export function Settings() {
	const [ saved, setSaved ] = useState( null );
	const [ smtp, setSmtp ] = useState( null );
	const [ email, setEmail ] = useState( null );
	const [ privacy, setPrivacy ] = useState( null );
	const [ password, setPassword ] = useState( '' );
	const [ showPassword, setShowPassword ] = useState( false );
	const [ testTo, setTestTo ] = useState( '' );
	const [ notice, setNotice ] = useState( null );
	const [ busy, setBusy ] = useState( null );

	useEffect( () => {
		apiFetch( { path: '/barrio/v1/settings' } )
			.then( ( data ) => {
				setSaved( data );
				setSmtp( data.smtp );
				setEmail( data.email );
				setPrivacy( data.privacy );
			} )
			.catch( ( error ) =>
				setNotice( { status: 'error', message: errorMessage( error ) } )
			);
	}, [] );

	const dirty =
		'' !== password ||
		JSON.stringify( { smtp, email, privacy } ) !==
			JSON.stringify( {
				smtp: saved?.smtp,
				email: saved?.email,
				privacy: saved?.privacy,
			} );
	useUnsavedWarning( dirty );

	if ( ! saved ) {
		return notice ? <Notice notice={ notice } /> : <Loading />;
	}

	const setSmtpField = ( key ) => ( value ) =>
		setSmtp( { ...smtp, [ key ]: value } );
	const setEmailField = ( key ) => ( value ) =>
		setEmail( { ...email, [ key ]: value } );
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
	const save = () =>
		request(
			'save',
			{
				path: '/barrio/v1/settings',
				method: 'PUT',
				data: { smtp, email, privacy, ...( password && { password } ) },
			},
			( response ) => {
				setSaved( response );
				setSmtp( response.smtp );
				setEmail( response.email );
				setPrivacy( response.privacy );
				setPassword( '' );
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
				<Text
					label={ __( 'Host', 'barrio-suite' ) }
					placeholder="smtp.example.com"
					value={ smtp.host }
					onChange={ setSmtpField( 'host' ) }
				/>
				<Text
					label={ __( 'Port', 'barrio-suite' ) }
					type="number"
					min={ 1 }
					max={ 65535 }
					value={ smtp.port }
					onChange={ ( value ) =>
						setSmtpField( 'port' )( Number( value ) )
					}
				/>
				<Select
					label={ __( 'Encryption', 'barrio-suite' ) }
					value={ smtp.encryption }
					options={ [
						{ label: 'TLS (STARTTLS)', value: 'tls' },
						{ label: 'SSL', value: 'ssl' },
						{ label: __( 'None', 'barrio-suite' ), value: 'none' },
					] }
					onChange={ setSmtpField( 'encryption' ) }
				/>
				<Text
					label={ __( 'Username', 'barrio-suite' ) }
					help={ __(
						'Leave empty if the server does not require authentication.',
						'barrio-suite'
					) }
					autoComplete="off"
					value={ smtp.username }
					onChange={ setSmtpField( 'username' ) }
				/>
				<Text
					label={ __( 'Password', 'barrio-suite' ) }
					type={ showPassword ? 'text' : 'password' }
					autoComplete="new-password"
					help={ PASSWORD_HELP[ saved.password_status ] }
					placeholder={
						'saved' === saved.password_status ? '••••••••' : ''
					}
					disabled={ 'constant' === saved.password_status }
					value={ password }
					onChange={ setPassword }
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
				/>
				<Text
					label={ __( 'From email', 'barrio-suite' ) }
					help={ __(
						'The sender of every email the site sends. SMTP servers usually accept only the addresses of their own domain.',
						'barrio-suite'
					) }
					type="email"
					value={ smtp.from_email }
					onChange={ setSmtpField( 'from_email' ) }
				/>
				<Text
					label={ __( 'From name', 'barrio-suite' ) }
					value={ smtp.from_name }
					onChange={ setSmtpField( 'from_name' ) }
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
				<Group
					label={ __( 'Logo', 'barrio-suite' ) }
					help={ __(
						'Shown at the top of form emails, 48 pixels high. Without a logo the site name is shown.',
						'barrio-suite'
					) }
				>
					{ email.logo && (
						<img
							className="barrio-logo"
							src={ email.logo }
							alt=""
						/>
					) }
					<div className="barrio-row is-fill">
						<MediaUpload
							allowedTypes={ [ 'image' ] }
							onSelect={ ( media ) =>
								setEmailField( 'logo' )( media.url )
							}
							render={ ( { open } ) => (
								<button
									type="button"
									className="barrio-button"
									onClick={ open }
								>
									{ email.logo
										? __( 'Replace logo', 'barrio-suite' )
										: __( 'Choose logo', 'barrio-suite' ) }
								</button>
							) }
						/>
						{ email.logo && (
							<button
								type="button"
								className="barrio-button is-danger"
								onClick={ () => setEmailField( 'logo' )( '' ) }
							>
								{ __( 'Remove', 'barrio-suite' ) }
							</button>
						) }
					</div>
				</Group>
				<Text
					label={ __( 'Accent color', 'barrio-suite' ) }
					type="color"
					value={ email.accent_color }
					onChange={ setEmailField( 'accent_color' ) }
				/>
				<Text
					label={ __( 'Background color', 'barrio-suite' ) }
					type="color"
					value={ email.background_color }
					onChange={ setEmailField( 'background_color' ) }
				/>
				<Text
					multiline
					label={ __( 'Footer', 'barrio-suite' ) }
					help={ __(
						'Shown at the bottom of form emails; links are allowed. Leave empty to show the site name.',
						'barrio-suite'
					) }
					value={ email.footer }
					onChange={ setEmailField( 'footer' ) }
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
					value={ testTo }
					onChange={ setTestTo }
				/>
				<div className="barrio-field">
					<div className="barrio-field__control">
						<button
							type="button"
							className="barrio-button"
							disabled={ !! busy || dirty }
							onClick={ () =>
								request(
									'test',
									{
										path: '/barrio/v1/settings/test-email',
										method: 'POST',
										data: testTo ? { to: testTo } : {},
									},
									( data ) => data.message
								)
							}
						>
							{ __( 'Send test email', 'barrio-suite' ) }
						</button>
					</div>
				</div>
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
				<Text
					label={ __(
						'Delete entries after (days)',
						'barrio-suite'
					) }
					help={ __(
						'Entries older than this are deleted every day, with their files. Leave 0 to keep them until you delete them.',
						'barrio-suite'
					) }
					type="number"
					min={ 0 }
					max={ 3650 }
					value={ privacy.retention_days }
					onChange={ ( value ) =>
						setPrivacy( {
							...privacy,
							retention_days: Number( value ),
						} )
					}
				/>
			</Card>
		</div>
	);
}
