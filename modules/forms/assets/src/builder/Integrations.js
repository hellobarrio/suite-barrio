// Integrazioni delle estensioni (Integrations.php): la scheda del builder con le impostazioni per
// form, e SettingControl, usato anche per le impostazioni globali e per la config dei campi. Ogni
// controllo viene dallo schema: title, description, enum (etichette in labels), options (una rotta REST
// con le scelte), array, number e i formati del plugin field, field:tipi e field_map; il resto è un
// testo (le password le gestisce Settings.js). In un'altra lingua si cambiano solo le impostazioni
// translatable.
import apiFetch from '@wordpress/api-fetch';
import { useEffect, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

import {
	Card,
	Fixed,
	Icon,
	Menu,
	SectionHead,
	Select,
	Text,
	Toggle,
	errorMessage,
	useScrollIntoView,
} from '../../../../../assets/src/admin/ui.js';
import { formsData } from '../ui.js';
import { Conditions } from './Conditions.js';
import { ruleFields } from './model.js';
import { TextIn } from '../../../../../assets/src/admin/texts.js';

const { languages } = window.barrioAdmin;
const { integrations } = formsData;
const main = languages[ 0 ];

// Le integrazioni con impostazioni per form: le sole che si aggiungono a un form.
export const formIntegrations = Object.entries( integrations ).filter(
	( [ , definition ] ) => Object.keys( definition.form_settings ).length
);

// value: il campo salvato. Se è stato eliminato o rinominato resta in elenco, come nelle regole
// (Conditions.js), invece di sembrare "—".
function fieldOptions( fields, types, value ) {
	const options = fields
		.filter( ( f ) => ! types || types.includes( f.type ) )
		.map( ( f ) => ( { value: f.key, label: f.label } ) );
	if ( value && ! options.some( ( option ) => option.value === value ) ) {
		options.push( {
			value,
			label: sprintf(
				/* translators: %s: field key. */
				__( '%s (missing field)', 'barrio-suite' ),
				value
			),
		} );
	}
	return [ { value: '', label: '—' }, ...options ];
}

export function IntegrationsTab( { form, lang, update } ) {
	const [ added, setAdded ] = useState( null );
	const fields = ruleFields( form.fields, main );
	const used = form.integrations.map( ( integration ) => integration.slug );
	const available = formIntegrations.filter(
		( [ slug ] ) => ! used.includes( slug )
	);
	const set = ( i, integration ) =>
		update( {
			integrations: form.integrations.map( ( x, j ) =>
				i === j ? integration : x
			),
		} );
	const add = ( slug ) => {
		setAdded( form.integrations.length );
		update( {
			integrations: [
				...form.integrations,
				{
					slug,
					enabled: true,
					settings: {},
					conditions: null,
					i18n: { [ main ]: {} },
				},
			],
		} );
	};

	return (
		<div className="barrio-stack">
			<SectionHead
				title={ __(
					'Services that receive each entry',
					'barrio-suite'
				) }
			>
				{ available.length > 0 && (
					<Fixed lang={ lang }>
						<Menu
							label={ __( 'Integrations', 'barrio-suite' ) }
							text={ __( 'Add integration', 'barrio-suite' ) }
							icon="plus-alt2"
							className="is-primary"
							items={ available.map(
								( [ slug, definition ] ) => ( {
									label: definition.label,
									onClick: () => add( slug ),
								} )
							) }
						/>
					</Fixed>
				) }
			</SectionHead>
			{ form.integrations.map( ( integration, i ) => (
				<IntegrationCard
					key={ integration.slug }
					integration={ integration }
					lang={ lang }
					fields={ fields }
					isNew={ i === added }
					open={ i === added || 1 === form.integrations.length }
					onChange={ ( changed ) => set( i, changed ) }
					onRemove={ () => {
						setAdded( null );
						update( {
							integrations: form.integrations.filter(
								( x, j ) => i !== j
							),
						} );
					} }
				/>
			) ) }
			{ ! form.integrations.length && (
				<Card
					description={ __(
						'Entries are not sent to other services. Integrations come with the Barrio Suite extensions, like Mailchimp.',
						'barrio-suite'
					) }
				/>
			) }
		</div>
	);
}

// Senza la sua estensione un'integrazione resta nel form così com'è, ma non riceve le registrazioni.
function IntegrationCard( {
	integration,
	lang,
	fields,
	isNew,
	open,
	onChange,
	onRemove,
} ) {
	const ref = useScrollIntoView( isNew );
	const definition = integrations[ integration.slug ];
	const translating = lang !== main;
	const set = ( changes ) => onChange( { ...integration, ...changes } );
	const setSetting = ( name ) => ( value ) => {
		const settings = { ...integration.settings, [ name ]: value };
		if ( '' === value || ( Array.isArray( value ) && ! value.length ) ) {
			delete settings[ name ];
		}
		set( { settings } );
	};

	return (
		<div ref={ ref } className="barrio-scroll-target">
			<Card
				className="barrio-wide"
				collapsible
				defaultOpen={ open }
				icon="admin-plugins"
				title={ definition?.label ?? integration.slug }
				description={
					definition
						? definition.description
						: __(
								'The extension of this integration is not active: its settings are kept, but entries are not sent. Activate it again to change them.',
								'barrio-suite'
							)
				}
				actions={
					<>
						<Toggle
							label={ __( 'Active', 'barrio-suite' ) }
							disabled={ translating || ! definition }
							checked={ integration.enabled }
							onChange={ ( enabled ) => set( { enabled } ) }
						/>
						<button
							type="button"
							className="barrio-icon-button is-danger"
							aria-label={ __(
								'Remove integration',
								'barrio-suite'
							) }
							disabled={ translating }
							onClick={ onRemove }
						>
							<Icon name="trash" />
						</button>
					</>
				}
			>
				{ definition &&
					Object.entries( definition.form_settings ).map(
						( [ name, schema ] ) =>
							schema.translatable ? (
								<TextIn
									key={ name }
									item={ integration }
									lang={ lang }
									name={ name }
									label={ schema.title ?? name }
									help={ schema.description }
									onChange={ onChange }
								/>
							) : (
								<Fixed key={ name } lang={ lang }>
									<SettingControl
										name={ name }
										schema={ schema }
										value={ integration.settings[ name ] }
										fields={ fields }
										onChange={ setSetting( name ) }
									/>
								</Fixed>
							)
					) }
				{ definition && (
					<Fixed lang={ lang }>
						<Conditions
							value={ integration.conditions }
							fields={ fields }
							actions={ [
								{
									value: 'show',
									label: __(
										'Send the entry',
										'barrio-suite'
									),
								},
								{
									value: 'hide',
									label: __(
										'Do not send it',
										'barrio-suite'
									),
								},
							] }
							empty={ __(
								'Add some fields first.',
								'barrio-suite'
							) }
							onChange={ ( conditions ) => set( { conditions } ) }
						/>
					</Fixed>
				) }
			</Card>
		</div>
	);
}

/**
 * Le scelte che un'estensione legge da un servizio (per esempio le chiavi di reCAPTCHA), da GET path come
 * { value, label }. Il valore salvato resta anche se il servizio non lo elenca più; un errore va sotto il menu.
 *
 * @param {Object}                  props
 * @param {string}                  props.path
 * @param {string}                  props.label
 * @param {string}                  props.help
 * @param {string}                  props.value
 * @param {(value: string) => void} props.onChange
 */
function RemoteSelect( { path, label, help, value, onChange } ) {
	const [ options, setOptions ] = useState( [] );
	const [ error, setError ] = useState( '' );
	useEffect( () => {
		apiFetch( { path } )
			.then( setOptions )
			.catch( ( reason ) => setError( errorMessage( reason ) ) );
	}, [ path ] );
	const saved =
		value && ! options.some( ( option ) => option.value === value )
			? [ { value, label: value } ]
			: [];
	return (
		<Select
			label={ label }
			help={ [ help, error ].filter( Boolean ).join( ' ' ) }
			value={ value }
			options={ [ { value: '', label: '—' }, ...saved, ...options ] }
			onChange={ onChange }
		/>
	);
}

// fields: i campi del form ({ key, type, label }), per i formati field e field_map.
export function SettingControl( {
	name,
	schema,
	value,
	fields = [],
	onChange,
} ) {
	const label = schema.title ?? name;
	const help = schema.description;
	const format = schema.format ?? '';
	if ( 'field_map' === format ) {
		return (
			<FieldMap
				label={ label }
				help={ help }
				value={ value ?? [] }
				fields={ fields }
				onChange={ onChange }
			/>
		);
	}
	if ( 'field' === format || format.startsWith( 'field:' ) ) {
		return (
			<Select
				label={ label }
				help={ help }
				value={ value }
				options={ fieldOptions(
					fields,
					format.split( ':' )[ 1 ]?.split( ',' ),
					value
				) }
				onChange={ onChange }
			/>
		);
	}
	if ( schema.options ) {
		return (
			<RemoteSelect
				path={ schema.options }
				label={ label }
				help={ help }
				value={ value }
				onChange={ onChange }
			/>
		);
	}
	if ( schema.enum ) {
		return (
			<Select
				label={ label }
				help={ help }
				value={ value }
				options={ [
					{ value: '', label: '—' },
					...schema.enum.map( ( v ) => ( {
						value: v,
						label: schema.labels?.[ v ] ?? v,
					} ) ),
				] }
				onChange={ onChange }
			/>
		);
	}
	if ( 'array' === schema.type ) {
		return (
			<ListInput label={ label } value={ value } onChange={ onChange } />
		);
	}
	const number = 'number' === schema.type;
	return (
		<Text
			label={ label }
			help={ help }
			type={ number ? 'number' : 'text' }
			value={ value }
			onChange={ ( v ) =>
				onChange( number && v !== '' ? Number( v ) : v )
			}
		/>
	);
}

// L'unica lista della config sono le estensioni dei file: minuscole e senza punto, come vuole lo
// schema. Il testo resta com'è mentre si scrive, altrimenti la virgola appena digitata sparirebbe.
function ListInput( { label, value, onChange } ) {
	const [ text, setText ] = useState( ( value ?? [] ).join( ', ' ) );
	return (
		<Text
			label={ label }
			help={ __(
				'Separated by commas, for example pdf, jpg, png.',
				'barrio-suite'
			) }
			value={ text }
			onChange={ ( v ) => {
				setText( v );
				onChange( [
					...new Set(
						v
							.split( ',' )
							.map( ( item ) =>
								item.trim().toLowerCase().replace( /^\./, '' )
							)
							.filter( Boolean )
					),
				] );
			} }
		/>
	);
}

// Coppie [nome nel servizio, chiave del campo], come l'editor delle scelte.
function FieldMap( { label, help, value, fields, onChange } ) {
	const set = ( i, pair ) =>
		onChange( value.map( ( p, j ) => ( i === j ? pair : p ) ) );
	return (
		<>
			<SectionHead title={ label } level={ 3 }>
				<button
					type="button"
					className="barrio-button"
					onClick={ () =>
						onChange( [ ...value, [ '', fields[ 0 ]?.key ?? '' ] ] )
					}
				>
					<Icon name="plus-alt2" />
					{ __( 'Add', 'barrio-suite' ) }
				</button>
			</SectionHead>
			{ value.length > 0 && (
				<div className="barrio-choices">
					{ value.map( ( [ remote, key ], i ) => (
						<div key={ i } className="barrio-choice">
							<Text
								label={ __(
									'Name in the service',
									'barrio-suite'
								) }
								hideLabel
								placeholder={ __(
									'Name in the service',
									'barrio-suite'
								) }
								value={ remote }
								onChange={ ( v ) => set( i, [ v, key ] ) }
							/>
							<Select
								label={ __( 'Field', 'barrio-suite' ) }
								hideLabel
								value={ key }
								options={ fieldOptions( fields, null, key ) }
								onChange={ ( v ) => set( i, [ remote, v ] ) }
							/>
							<button
								type="button"
								className="barrio-icon-button is-danger"
								aria-label={ __( 'Remove', 'barrio-suite' ) }
								onClick={ () =>
									onChange(
										value.filter( ( p, j ) => i !== j )
									)
								}
							>
								<Icon name="no-alt" />
							</button>
						</div>
					) ) }
				</div>
			) }
			{ help && <p className="barrio-field__help">{ help }</p> }
		</>
	);
}
