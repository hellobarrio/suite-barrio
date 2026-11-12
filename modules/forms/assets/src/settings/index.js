// Pagina Impostazioni: SMTP, template delle email ed email di test. Legge e salva con
// GET/PUT /barrio/v1/settings; la password SMTP non torna mai dal server, se ne conosce solo lo stato.
import apiFetch from '@wordpress/api-fetch';
import {
	BaseControl,
	Button,
	Card,
	CardBody,
	CardHeader,
	ColorPalette,
	Notice,
	SelectControl,
	Spinner,
	TextControl,
	TextareaControl,
} from '@wordpress/components';
import { createRoot, useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { MediaUpload } from '@wordpress/media-utils';

const STACK = { display: 'grid', gap: '16px' };

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

// Le props che tolgono gli stili deprecati dei controlli di @wordpress/components.
const control = { __nextHasNoMarginBottom: true, __next40pxDefaultSize: true };

function Section( { title, children } ) {
	return (
		<Card>
			<CardHeader>
				<h2 style={ { margin: 0 } }>{ title }</h2>
			</CardHeader>
			<CardBody>
				<div style={ STACK }>{ children }</div>
			</CardBody>
		</Card>
	);
}

function Color( { label, value, onChange } ) {
	return (
		<BaseControl __nextHasNoMarginBottom>
			<BaseControl.VisualLabel>{ label }</BaseControl.VisualLabel>
			<ColorPalette
				colors={ [] }
				value={ value }
				onChange={ ( color ) => color && onChange( color ) }
				clearable={ false }
			/>
		</BaseControl>
	);
}

function SettingsPage() {
	const [ saved, setSaved ] = useState( null );
	const [ smtp, setSmtp ] = useState( null );
	const [ email, setEmail ] = useState( null );
	const [ password, setPassword ] = useState( '' );
	const [ testTo, setTestTo ] = useState( '' );
	const [ notice, setNotice ] = useState( null );
	const [ busy, setBusy ] = useState( false );

	const load = ( data ) => {
		setSaved( data );
		setSmtp( data.smtp );
		setEmail( data.email );
		setPassword( '' );
	};
	const fail = ( error ) =>
		setNotice( { status: 'error', message: error.message } );

	useEffect( () => {
		apiFetch( { path: '/barrio/v1/settings' } ).then( load ).catch( fail );
	}, [] );

	if ( ! saved ) {
		return notice ? (
			<Notice status="error" isDismissible={ false }>
				{ notice.message }
			</Notice>
		) : (
			<Spinner />
		);
	}

	// L'email di test usa le impostazioni salvate: con modifiche in sospeso sarebbe fuorviante.
	const dirty =
		'' !== password ||
		JSON.stringify( { smtp, email } ) !==
			JSON.stringify( { smtp: saved.smtp, email: saved.email } );
	const setSmtpField = ( key ) => ( value ) =>
		setSmtp( { ...smtp, [ key ]: value } );
	const setEmailField = ( key ) => ( value ) =>
		setEmail( { ...email, [ key ]: value } );

	const request = ( options, onSuccess ) => {
		setBusy( true );
		setNotice( null );
		apiFetch( options )
			.then( ( data ) =>
				setNotice( { status: 'success', message: onSuccess( data ) } )
			)
			.catch( fail )
			.finally( () => setBusy( false ) );
	};
	const save = () =>
		request(
			{
				path: '/barrio/v1/settings',
				method: 'PUT',
				data: { smtp, email, ...( password && { password } ) },
			},
			( data ) => {
				load( data );
				return __( 'Settings saved.', 'barrio-suite' );
			}
		);
	const sendTest = () =>
		request(
			{
				path: '/barrio/v1/settings/test-email',
				method: 'POST',
				data: testTo ? { to: testTo } : {},
			},
			( data ) => data.message
		);

	return (
		<div style={ { ...STACK, gap: '24px', maxWidth: '720px' } }>
			{ notice && (
				<Notice
					status={ notice.status }
					onRemove={ () => setNotice( null ) }
				>
					{ notice.message }
				</Notice>
			) }

			<Section title={ __( 'SMTP', 'barrio-suite' ) }>
				<p style={ { margin: 0 } }>
					{ __(
						'Used for all the email the site sends, not only form notifications. Leave the host empty to send with the default WordPress mail.',
						'barrio-suite'
					) }
				</p>
				<TextControl
					{ ...control }
					label={ __( 'Host', 'barrio-suite' ) }
					placeholder="smtp.example.com"
					value={ smtp.host }
					onChange={ setSmtpField( 'host' ) }
				/>
				<TextControl
					{ ...control }
					label={ __( 'Port', 'barrio-suite' ) }
					type="number"
					min={ 1 }
					max={ 65535 }
					value={ smtp.port }
					onChange={ ( value ) =>
						setSmtpField( 'port' )( Number( value ) )
					}
				/>
				<SelectControl
					{ ...control }
					label={ __( 'Encryption', 'barrio-suite' ) }
					value={ smtp.encryption }
					options={ [
						{ label: 'TLS (STARTTLS)', value: 'tls' },
						{ label: 'SSL', value: 'ssl' },
						{ label: __( 'None', 'barrio-suite' ), value: 'none' },
					] }
					onChange={ setSmtpField( 'encryption' ) }
				/>
				<TextControl
					{ ...control }
					label={ __( 'Username', 'barrio-suite' ) }
					help={ __(
						'Leave empty if the server does not require authentication.',
						'barrio-suite'
					) }
					autoComplete="off"
					value={ smtp.username }
					onChange={ setSmtpField( 'username' ) }
				/>
				<TextControl
					{ ...control }
					label={ __( 'Password', 'barrio-suite' ) }
					type="password"
					autoComplete="new-password"
					help={ PASSWORD_HELP[ saved.password_status ] }
					placeholder={
						'saved' === saved.password_status ? '••••••••' : ''
					}
					disabled={ 'constant' === saved.password_status }
					value={ password }
					onChange={ setPassword }
				/>
				<TextControl
					{ ...control }
					label={ __( 'From email', 'barrio-suite' ) }
					help={ __(
						'The sender of every email the site sends. SMTP servers usually accept only the addresses of their own domain.',
						'barrio-suite'
					) }
					type="email"
					value={ smtp.from_email }
					onChange={ setSmtpField( 'from_email' ) }
				/>
				<TextControl
					{ ...control }
					label={ __( 'From name', 'barrio-suite' ) }
					value={ smtp.from_name }
					onChange={ setSmtpField( 'from_name' ) }
				/>
			</Section>

			<Section title={ __( 'Email template', 'barrio-suite' ) }>
				<BaseControl
					help={ __(
						'Shown at the top of form emails, 48 pixels high. Without a logo the site name is shown.',
						'barrio-suite'
					) }
					__nextHasNoMarginBottom
				>
					<BaseControl.VisualLabel>
						{ __( 'Logo', 'barrio-suite' ) }
					</BaseControl.VisualLabel>
					{ email.logo && (
						<img
							src={ email.logo }
							alt=""
							style={ {
								display: 'block',
								height: '48px',
								marginBottom: '8px',
							} }
						/>
					) }
					<div style={ { display: 'flex', gap: '8px' } }>
						<MediaUpload
							allowedTypes={ [ 'image' ] }
							onSelect={ ( media ) =>
								setEmailField( 'logo' )( media.url )
							}
							render={ ( { open } ) => (
								<Button
									variant="secondary"
									onClick={ open }
									__next40pxDefaultSize
								>
									{ email.logo
										? __( 'Replace logo', 'barrio-suite' )
										: __( 'Choose logo', 'barrio-suite' ) }
								</Button>
							) }
						/>
						{ email.logo && (
							<Button
								variant="tertiary"
								isDestructive
								onClick={ () => setEmailField( 'logo' )( '' ) }
								__next40pxDefaultSize
							>
								{ __( 'Remove', 'barrio-suite' ) }
							</Button>
						) }
					</div>
				</BaseControl>
				<Color
					label={ __( 'Accent color', 'barrio-suite' ) }
					value={ email.accent_color }
					onChange={ setEmailField( 'accent_color' ) }
				/>
				<Color
					label={ __( 'Background color', 'barrio-suite' ) }
					value={ email.background_color }
					onChange={ setEmailField( 'background_color' ) }
				/>
				<TextareaControl
					__nextHasNoMarginBottom
					label={ __( 'Footer', 'barrio-suite' ) }
					help={ __(
						'Shown at the bottom of form emails; links are allowed. Leave empty to show the site name.',
						'barrio-suite'
					) }
					value={ email.footer }
					onChange={ setEmailField( 'footer' ) }
				/>
			</Section>

			<div>
				<Button
					variant="primary"
					onClick={ save }
					isBusy={ busy }
					disabled={ busy || ! dirty }
					__next40pxDefaultSize
				>
					{ __( 'Save', 'barrio-suite' ) }
				</Button>
			</div>

			<Section title={ __( 'Test email', 'barrio-suite' ) }>
				<TextControl
					{ ...control }
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
				<div>
					<Button
						variant="secondary"
						onClick={ sendTest }
						disabled={ busy || dirty }
						__next40pxDefaultSize
					>
						{ __( 'Send test email', 'barrio-suite' ) }
					</Button>
				</div>
			</Section>
		</div>
	);
}

createRoot( document.getElementById( 'barrio-settings' ) ).render(
	<SettingsPage />
);
