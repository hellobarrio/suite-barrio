// I campi delle impostazioni globali, usati in Form → Impostazioni e nel passaggio dei form della
// guida alla messa online: ognuno riceve i valori e restituisce quelli nuovi, il salvataggio è di chi li usa.
import apiFetch from '@wordpress/api-fetch';
import { __ } from '@wordpress/i18n';
import { MediaUpload } from '@wordpress/media-utils';

import {
	Group,
	SectionHead,
	Text,
} from '../../../../../assets/src/admin/ui.js';
import { SettingControl } from '../builder/Integrations.js';

// Lo stato di un segreto delle integrazioni (una chiave API), come quello della password SMTP
// (assets/src/general/Smtp.js).
const SECRET_HELP = {
	saved: __( 'Saved: leave empty to keep it.', 'barrio-suite' ),
	unreadable: __(
		'The saved value can no longer be read, probably because the WordPress security keys have changed. Enter it again.',
		'barrio-suite'
	),
};

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
 * @param {Object}                   props.definition Da window.barrioAdmin.forms.integrations.
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

// Nella guida alla messa online (assets/src/launch): fatto con il logo delle email e una durata delle
// registrazioni (Forms\Module::launch_steps()).
export const formsStep = {
	id: 'forms',
	icon: 'feedback',
	title: __( 'Forms', 'barrio-suite' ),
	description: __(
		'The form emails carry the logo of the site, and the entries are kept only as long as needed, as the GDPR asks.',
		'barrio-suite'
	),
	load: () => apiFetch( { path: '/barrio/v1/settings' } ),
	value: ( { email, privacy } ) => ( { email, privacy } ),
	save: ( draft ) =>
		apiFetch( { path: '/barrio/v1/settings', method: 'PUT', data: draft } ),
	Fields: ( { draft, set } ) => (
		<>
			<SectionHead
				title={ __( 'Email template', 'barrio-suite' ) }
				level={ 3 }
			/>
			<TemplateFields
				email={ draft.email }
				onChange={ ( email ) => set( { ...draft, email } ) }
			/>
			<SectionHead
				title={ __( 'Privacy', 'barrio-suite' ) }
				level={ 3 }
			/>
			<RetentionField
				privacy={ draft.privacy }
				onChange={ ( privacy ) => set( { ...draft, privacy } ) }
			/>
		</>
	),
};
