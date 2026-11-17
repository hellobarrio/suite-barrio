// Editor della logica condizionale di campi e notifiche, nel formato di Conditions.php:
// { action: show|hide, logic: all|any, rules: [ { field, operator, value } ] }; null = nessuna regola.
import { __, sprintf } from '@wordpress/i18n';

import {
	Icon,
	SectionHead,
	Select,
	Text,
	Toggle,
} from '../../../../../assets/src/admin/ui.js';

const operators = () => [
	{ value: '=', label: __( 'is', 'barrio-suite' ) },
	{ value: '!=', label: __( 'is not', 'barrio-suite' ) },
	{ value: '>', label: __( 'is greater than', 'barrio-suite' ) },
	{ value: '<', label: __( 'is less than', 'barrio-suite' ) },
	{ value: 'contains', label: __( 'contains', 'barrio-suite' ) },
	{ value: 'starts_with', label: __( 'starts with', 'barrio-suite' ) },
	{ value: 'ends_with', label: __( 'ends with', 'barrio-suite' ) },
	{ value: 'empty', label: __( 'is empty', 'barrio-suite' ) },
	{ value: 'not_empty', label: __( 'is not empty', 'barrio-suite' ) },
];

// fields: i campi che le regole possono usare ({ key, label, choices }); actions: le etichette di
// show e hide; empty: perché non si può attivare senza campi.
export function Conditions( { value, fields, actions, empty, onChange } ) {
	const rule = () => ( {
		field: fields[ 0 ]?.key,
		operator: '=',
		value: '',
	} );
	const set = ( changes ) => onChange( { ...value, ...changes } );

	return (
		<>
			<SectionHead
				title={ __( 'Conditional logic', 'barrio-suite' ) }
				level={ 3 }
			>
				{ value && (
					<button
						type="button"
						className="barrio-button"
						onClick={ () =>
							set( { rules: [ ...value.rules, rule() ] } )
						}
					>
						<Icon name="plus-alt2" />
						{ __( 'Add rule', 'barrio-suite' ) }
					</button>
				) }
			</SectionHead>
			<Toggle
				label={ __( 'Enable conditional logic', 'barrio-suite' ) }
				help={ ! value && ! fields.length ? empty : undefined }
				disabled={ ! value && ! fields.length }
				checked={ !! value }
				onChange={ ( on ) =>
					onChange(
						on
							? {
									action: 'show',
									logic: 'all',
									rules: [ rule() ],
								}
							: null
					)
				}
			/>
			{ value && (
				<>
					<div className="barrio-row is-nowrap">
						<Select
							label={ __( 'Action', 'barrio-suite' ) }
							hideLabel
							value={ value.action }
							options={ actions }
							onChange={ ( action ) => set( { action } ) }
						/>
						<Select
							label={ __( 'Logic', 'barrio-suite' ) }
							hideLabel
							value={ value.logic }
							options={ [
								{
									value: 'all',
									label: __(
										'if all rules match',
										'barrio-suite'
									),
								},
								{
									value: 'any',
									label: __(
										'if any rule matches',
										'barrio-suite'
									),
								},
							] }
							onChange={ ( logic ) => set( { logic } ) }
						/>
					</div>
					{ value.rules.map( ( r, i ) => (
						<Rule
							key={ i }
							rule={ r }
							fields={ fields }
							onChange={ ( changes ) =>
								set( {
									rules: value.rules.map( ( x, j ) =>
										i === j ? { ...x, ...changes } : x
									),
								} )
							}
							// Le regole sono almeno una: togliere l'ultima toglie la logica condizionale.
							onRemove={ () =>
								value.rules.length > 1
									? set( {
											rules: value.rules.filter(
												( x, j ) => i !== j
											),
										} )
									: onChange( null )
							}
						/>
					) ) }
				</>
			) }
		</>
	);
}

function Rule( { rule, fields, onChange, onRemove } ) {
	const source = fields.find( ( f ) => f.key === rule.field );
	const options = fields.map( ( f ) => ( { value: f.key, label: f.label } ) );
	// Un campo eliminato o rinominato resta visibile, invece di sembrare il primo dell'elenco.
	if ( ! source ) {
		options.push( {
			value: rule.field,
			label: sprintf(
				/* translators: %s: field key. */
				__( '%s (missing field)', 'barrio-suite' ),
				rule.field
			),
		} );
	}
	const valueProps = {
		label: __( 'Value', 'barrio-suite' ),
		hideLabel: true,
		value: rule.value,
		onChange: ( value ) => onChange( { value } ),
	};
	let valueControl = <Text { ...valueProps } />;
	if ( [ 'empty', 'not_empty' ].includes( rule.operator ) ) {
		valueControl = null;
	} else if ( source?.choices && [ '=', '!=' ].includes( rule.operator ) ) {
		valueControl = (
			<Select
				{ ...valueProps }
				options={ [
					{ value: '', label: '—' },
					...source.choices.map( ( c ) => ( {
						value: c.value,
						label: c.label || c.value,
					} ) ),
				] }
			/>
		);
	}

	return (
		<div className="barrio-rule">
			<Select
				label={ __( 'Field', 'barrio-suite' ) }
				hideLabel
				value={ rule.field }
				options={ options }
				onChange={ ( field ) => onChange( { field } ) }
			/>
			<button
				type="button"
				className="barrio-icon-button is-danger"
				aria-label={ __( 'Remove rule', 'barrio-suite' ) }
				onClick={ onRemove }
			>
				<Icon name="no-alt" />
			</button>
			<Select
				label={ __( 'Operator', 'barrio-suite' ) }
				hideLabel
				value={ rule.operator }
				options={ operators() }
				onChange={ ( operator ) => onChange( { operator } ) }
			/>
			{ valueControl }
		</div>
	);
}
