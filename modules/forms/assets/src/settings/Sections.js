// I campi delle impostazioni globali, usati in Form → Impostazioni e nella guida alla messa online
// (assets/src/launch): ognuno riceve i valori e restituisce quelli nuovi, il salvataggio è di chi li usa.
import apiFetch from '@wordpress/api-fetch';
import { useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { MediaUpload } from '@wordpress/media-utils';

import { Group, Select, Text } from '../admin/ui.js';
import { SettingControl } from '../builder/Integrations.js';
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

// Lo stato di un segreto delle integrazioni (una chiave API), come quello della password SMTP.
const SECRET_HELP = {
	saved: __( 'Saved: leave empty to keep it.', 'barrio-suite' ),
	unreadable: __(
		'The saved value can no longer be read, probably because the WordPress security keys have changed. Enter it again.',
		'barrio-suite'
	),
};

// La password è a parte: non torna mai dal server, se ne conosce solo lo stato.
export function SmtpFields( {
	smtp,
	onChange,
	password,
	onPassword,
	passwordStatus,
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
export function TestEmail( { dirty, onNotice } ) {
	const [ to, setTo ] = useState( '' );
	const [ busy, setBusy ] = useState( false );
	const send = () => {
		setBusy( true );
		onNotice( null );
		apiFetch( {
			path: '/barrio/v1/settings/test-email',
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
		<>
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
			/>
			<div className="barrio-field">
				<div className="barrio-field__control">
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
				</div>
			</div>
		</>
	);
}

export function TemplateFields( { email, onChange } ) {
	const set = ( key ) => ( value ) =>
		onChange( { ...email, [ key ]: value } );
	return (
		<>
			<Group
				label={ __( 'Logo', 'barrio-suite' ) }
				help={ __(
					'Shown at the top of form emails, 48 pixels high. Without a logo the site name is shown.',
					'barrio-suite'
				) }
			>
				{ email.logo && (
					<img className="barrio-logo" src={ email.logo } alt="" />
				) }
				<div className="barrio-row is-fill">
					<MediaUpload
						allowedTypes={ [ 'image' ] }
						onSelect={ ( media ) => set( 'logo' )( media.url ) }
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
							onClick={ () => set( 'logo' )( '' ) }
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
				onChange={ set( 'accent_color' ) }
			/>
			<Text
				label={ __( 'Background color', 'barrio-suite' ) }
				type="color"
				value={ email.background_color }
				onChange={ set( 'background_color' ) }
			/>
			<Text
				multiline
				label={ __( 'Footer', 'barrio-suite' ) }
				help={ __(
					'Shown at the bottom of form emails; links are allowed. Leave empty to show the site name.',
					'barrio-suite'
				) }
				value={ email.footer }
				onChange={ set( 'footer' ) }
			/>
		</>
	);
}

export const RetentionField = ( { privacy, onChange } ) => (
	<Text
		label={ __( 'Delete entries after (days)', 'barrio-suite' ) }
		help={ __(
			'Entries older than this are deleted every day, with their files. Leave 0 to keep them until you delete them.',
			'barrio-suite'
		) }
		type="number"
		min={ 0 }
		max={ 3650 }
		value={ privacy.retention_days }
		onChange={ ( value ) =>
			onChange( { ...privacy, retention_days: Number( value ) } )
		}
	/>
);

/**
 * Le impostazioni globali di un'integrazione (le chiavi API di un'estensione).
 *
 * @param {Object}                   props
 * @param {Object}                   props.definition Da window.barrioAdmin.integrations.
 * @param {Object}                   props.values     I valori; i segreti solo se appena scritti.
 * @param {Object}                   props.secrets    Lo stato di ogni segreto: none, saved, unreadable.
 * @param {(values: Object) => void} props.onChange
 */
export function GlobalFields( {
	definition,
	values = {},
	secrets = {},
	onChange,
} ) {
	// Un segreto vuoto non si invia: resta quello salvato, e la pagina torna senza modifiche.
	const set = ( name, secret ) => ( value ) => {
		const next = { ...values, [ name ]: value };
		if ( secret && '' === value ) {
			delete next[ name ];
		}
		onChange( next );
	};
	return Object.entries( definition.global_settings ).map(
		( [ name, schema ] ) =>
			'password' === schema.format ? (
				<Text
					key={ name }
					label={ schema.title ?? name }
					type="password"
					autoComplete="new-password"
					help={ [
						schema.description,
						SECRET_HELP[ secrets[ name ] ],
					]
						.filter( Boolean )
						.join( ' ' ) }
					placeholder={
						'saved' === secrets[ name ] ? '••••••••' : ''
					}
					value={ values[ name ] ?? '' }
					onChange={ set( name, true ) }
				/>
			) : (
				<SettingControl
					key={ name }
					name={ name }
					schema={ schema }
					value={ values[ name ] }
					onChange={ set( name ) }
				/>
			)
	);
}
