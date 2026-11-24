// Scheda Campi: la struttura del form, ordinabile, e le proprietà del campo scelto. Le proprietà
// della config vengono dallo schema del tipo (FieldTypes::schema()); le scelte hanno un editor
// proprio, con i preset. In un'altra lingua (lang) restano solo i testi: la struttura si cambia
// nella lingua principale.
import { arrayMove } from '@dnd-kit/sortable';
import { speak } from '@wordpress/a11y';
import { useEffect, useRef, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

import { Handle, Sortable } from '../../../../../assets/src/admin/sortable.js';
import {
	Card,
	Details,
	Fixed,
	Icon,
	Menu,
	SectionHead,
	Text,
	Toggle,
	useScrollIntoView,
} from '../../../../../assets/src/admin/ui.js';
import { formsData } from '../ui.js';
import {
	choiceRows,
	keyProblem,
	plainLabel,
	presetRows,
	renameChoice,
	ruleFields,
	setTranslation,
	textIn,
	uniqueKey,
	withChoices,
} from './model.js';
import { Conditions } from './Conditions.js';
import { SettingControl } from './Integrations.js';
import { TextIn } from '../../../../../assets/src/admin/texts.js';

const { languages } = window.barrioAdmin;
const { types, presets } = formsData;
const main = languages[ 0 ];

// Tipi senza un controllo da compilare: niente obbligatorio, segnaposto o messaggio di errore.
const NO_INPUT = [ 'hidden', 'page_break' ];
const PLACEHOLDER = [ 'text', 'email', 'tel', 'number', 'textarea', 'select' ];
// Le impostazioni dei tipi che stanno in Avanzate, con la chiave e il messaggio di errore.
const ADVANCED = [ 'autocomplete', 'pattern' ];
const ICONS = {
	text: 'editor-textcolor',
	email: 'email',
	tel: 'phone',
	number: 'calculator',
	textarea: 'editor-paragraph',
	datepicker: 'calendar-alt',
	select: 'arrow-down-alt2',
	radio: 'marker',
	checkbox: 'yes',
	file: 'upload',
	consent: 'privacy',
	hidden: 'hidden',
	page_break: 'editor-insertmore',
};

// Il consenso ha come etichetta il suo testo, l'interruzione di pagina il pulsante: si mostra il tipo.
const fieldTitle = ( field, lang ) =>
	[ 'consent', 'page_break' ].includes( field.type )
		? types[ field.type ].label
		: plainLabel( field, lang, main );

// Le scelte con cui nasce un campo: un preset le sostituisce senza chiedere.
const firstChoices = () => [
	{ value: 'option_1', label: __( 'Option 1', 'barrio-suite' ) },
	{ value: 'option_2', label: __( 'Option 2', 'barrio-suite' ) },
];

// Gli stessi controlli del server sulla chiave, detti prima di salvare.
export const keyError = ( key, others ) =>
	( {
		format: __(
			'A key starts with a lowercase letter and has only lowercase letters, digits and underscores.',
			'barrio-suite'
		),
		taken: __( 'Another field already uses this key.', 'barrio-suite' ),
	} )[ keyProblem( key, others ) ];

function newField( type, keys ) {
	const label =
		'page_break' === type
			? __( 'Next', 'barrio-suite' )
			: types[ type ].label;
	const field = {
		key: uniqueKey( label, keys ),
		type,
		required: 'consent' === type,
		config: 'file' === type ? { extensions: [ 'pdf', 'jpg', 'png' ] } : {},
		conditions: null,
		i18n: {
			[ main ]: {
				label,
				placeholder: null,
				error_message: null,
				options: null,
			},
		},
	};
	return types[ type ].schema.properties.choices
		? withChoices( field, main, firstChoices() )
		: field;
}

// selected sta nel Builder: cambiando scheda questa si smonta, e il campo scelto deve restare.
export function FieldsTab( {
	fields,
	saved,
	lang,
	selected,
	onSelect: setSelected,
	onChange,
} ) {
	const translating = lang !== main;
	const [ added, setAdded ] = useState( null );
	const [ ids, moveIds ] = useStableIds( fields.length );
	const field = fields[ selected ];
	const root = useRef();
	const list = useRef();
	// Verso il pannello delle proprietà, l'ultimo figlio di root: view dopo aver scelto un campo, focus dopo
	// averne aggiunto uno.
	const [ toPanel, setToPanel ] = useState( null );
	useEffect( () => {
		const panel = root.current?.lastElementChild;
		if ( 'focus' === toPanel?.to ) {
			panel.querySelector( 'input, textarea' )?.focus();
		} else if (
			toPanel &&
			// Solo in colonna, dove il pannello sta sotto l'elenco e non è più sticky.
			'static' === window.getComputedStyle( panel ).position
		) {
			panel.scrollIntoView( { block: 'nearest' } );
		}
	}, [ toPanel ] );

	// Il campo nuovo va sotto quello scelto, o in fondo.
	const add = ( type ) => {
		const at = field ? selected + 1 : fields.length;
		const created = newField(
			type,
			fields.map( ( f ) => f.key )
		);
		onChange( [
			...fields.slice( 0, at ),
			created,
			...fields.slice( at ),
		] );
		setSelected( at );
		setAdded( at );
		setToPanel( { to: 'focus' } );
	};
	const remove = ( i ) => {
		onChange( fields.filter( ( f, j ) => i !== j ) );
		// Con un campo in meno le card si rimontano: quella aggiunta prima non deve tornare in vista,
		// e il focus, che era sul cestino, andrebbe perso.
		setAdded( null );
		list.current.focus();
		speak( __( 'Field removed.', 'barrio-suite' ) );
		if ( i === selected ) {
			setSelected( null );
		} else if ( i < selected ) {
			setSelected( selected - 1 );
		}
	};

	let panel = (
		<Card
			className="barrio-panel barrio-scroll-target"
			title={ __( 'Field properties', 'barrio-suite' ) }
			description={ __(
				'Select a field to edit its properties.',
				'barrio-suite'
			) }
		/>
	);
	if ( field ) {
		panel = (
			<FieldPanel
				// Un pannello nuovo per ogni campo: nessuno stato passa da un campo all'altro.
				key={ ids[ selected ] }
				field={ field }
				saved={ saved.find( ( f ) => f.key === field.key ) }
				lang={ lang }
				previous={ fields.slice( 0, selected ) }
				keys={ fields
					.filter( ( f, i ) => i !== selected )
					.map( ( f ) => f.key ) }
				onChange={ ( changed ) =>
					onChange(
						fields.map( ( f, i ) =>
							i === selected ? changed : f
						)
					)
				}
			/>
		);
	}

	return (
		<div className="barrio-fields" ref={ root }>
			<div>
				<SectionHead
					title={ __(
						'Form structure · drag to reorder',
						'barrio-suite'
					) }
				>
					<Fixed lang={ lang }>
						<AddField onAdd={ add } />
					</Fixed>
				</SectionHead>
				<div
					className="barrio-fields__list"
					ref={ list }
					tabIndex={ -1 }
				>
					{ fields.length ? (
						<Sortable
							ids={ ids }
							items={ fields }
							label={ ( f ) => fieldTitle( f, lang ) }
							onMove={ ( from, to ) => {
								moveIds( from, to );
								onChange( arrayMove( fields, from, to ) );
								setSelected( to );
							} }
						>
							{ ( f, i, handle ) => (
								<FieldCard
									field={ f }
									lang={ lang }
									selected={ i === selected }
									isNew={ i === added }
									handle={ handle }
									locked={ translating }
									onSelect={ ( event ) => {
										setSelected( i );
										// Dalla maniglia il campo si sposta, non si apre.
										if (
											! event.target.closest(
												'.barrio-handle'
											)
										) {
											setToPanel( { to: 'view' } );
										}
									} }
									onRemove={ () => remove( i ) }
								/>
							) }
						</Sortable>
					) : (
						<p className="barrio-fields__empty">
							{ __(
								'No fields yet: add the first one.',
								'barrio-suite'
							) }
						</p>
					) }
				</div>
			</div>
			{ panel }
		</div>
	);
}

// I tipi con la loro icona; con la ricerca, perché sono tanti.
const AddField = ( { onAdd } ) => (
	<Menu
		label={ __( 'Field types', 'barrio-suite' ) }
		text={ __( 'Add field', 'barrio-suite' ) }
		icon="plus-alt2"
		className="is-primary"
		items={ Object.entries( types ).map( ( [ type, { label } ] ) => ( {
			label,
			icon: ICONS[ type ] ?? 'admin-generic',
			onClick: () => onAdd( type ),
		} ) ) }
	/>
);

// Una card della struttura. locked: in un'altra lingua la struttura non cambia, maniglia e cestino
// sono disattivati.
function FieldCard( {
	field,
	lang,
	selected,
	isNew,
	handle,
	locked,
	onSelect,
	onRemove,
} ) {
	const ref = useScrollIntoView( isNew );
	return (
		// Il titolo è il bottone accessibile; il clic sul resto della card è una scorciatoia.
		// eslint-disable-next-line jsx-a11y/click-events-have-key-events, jsx-a11y/no-static-element-interactions
		<div
			ref={ ref }
			className={ `barrio-fieldcard barrio-scroll-target${
				selected ? ' is-selected' : ''
			}` }
			onClick={ onSelect }
		>
			<div className="barrio-fieldcard__head">
				<Handle { ...( locked ? { disabled: true } : handle ) } />
				<button
					type="button"
					className="barrio-fieldcard__title"
					aria-pressed={ selected }
					onClick={ onSelect }
				>
					{ fieldTitle( field, lang ) }
					{ field.required && (
						<>
							<span
								className="barrio-required"
								aria-hidden="true"
							>
								*
							</span>
							<span className="screen-reader-text">
								{ __( '(required)', 'barrio-suite' ) }
							</span>
						</>
					) }
				</button>
				{ field.conditions && (
					<span className="barrio-badge is-warning">
						{ __( 'Conditional', 'barrio-suite' ) }
					</span>
				) }
				<span className="barrio-badge">
					{ types[ field.type ]?.label ?? field.type }
				</span>
				<button
					type="button"
					className="barrio-icon-button is-danger"
					aria-label={ sprintf(
						/* translators: %s: name of the field or of the choice to remove. */
						__( 'Remove %s', 'barrio-suite' ),
						fieldTitle( field, lang )
					) }
					disabled={ locked }
					onClick={ ( event ) => {
						event.stopPropagation();
						onRemove();
					} }
				>
					<Icon name="trash" />
				</button>
			</div>
			<div className="barrio-fieldcard__preview" aria-hidden="true">
				<Preview field={ field } lang={ lang } />
			</div>
		</div>
	);
}

// Come apparirà il campo, a grandi linee: il form vero è nell'anteprima.
function Preview( { field, lang } ) {
	const text = textIn( field, lang, main );
	const choices = ( field.config.choices ?? [] ).map( ( value ) => ( {
		value,
		label: text.options?.[ value ] ?? '',
	} ) );
	switch ( field.type ) {
		case 'textarea':
			return (
				<textarea
					className="barrio-input"
					rows={ 2 }
					disabled
					placeholder={ text.placeholder ?? '' }
				/>
			);
		case 'select':
			return (
				<select className="barrio-input" disabled>
					<option>{ text.placeholder || choices[ 0 ]?.label }</option>
				</select>
			);
		case 'radio':
		case 'checkbox':
			return (
				<div className="barrio-preview-choices">
					{ choices.slice( 0, 5 ).map( ( choice, i ) => (
						<span key={ i }>
							<input type={ field.type } disabled />
							{ choice.label || choice.value }
						</span>
					) ) }
					{ choices.length > 5 &&
						sprintf(
							/* translators: %d: number of choices not shown. */
							__( '+ %d more', 'barrio-suite' ),
							choices.length - 5
						) }
				</div>
			);
		case 'consent':
			return (
				<span className="barrio-preview-choices">
					<span>
						<input type="checkbox" disabled />{ ' ' }
						{ plainLabel( field, lang, main ) }
					</span>
				</span>
			);
		case 'file':
			return (
				<div className="barrio-preview-note">
					{ sprintf(
						/* translators: %s: allowed file extensions. */
						__( 'File upload: %s', 'barrio-suite' ),
						( field.config.extensions ?? [] ).join( ', ' )
					) }
				</div>
			);
		case 'hidden':
			return (
				<div className="barrio-preview-note">
					{ field.config.param
						? sprintf(
								/* translators: %s: URL parameter. */
								__(
									'Hidden, from the URL parameter %s',
									'barrio-suite'
								),
								field.config.param
							)
						: __( 'Hidden, with a fixed value', 'barrio-suite' ) }
				</div>
			);
		case 'page_break':
			return (
				<div className="barrio-preview-break">
					{ sprintf(
						/* translators: %s: button text. */
						__( 'Next page · button "%s"', 'barrio-suite' ),
						text.label
					) }
				</div>
			);
		default:
			return (
				<input
					className="barrio-input"
					type={ 'datepicker' === field.type ? 'date' : 'text' }
					disabled
					placeholder={ text.placeholder ?? '' }
				/>
			);
	}
}

function FieldPanel( { field, saved, lang, previous, keys, onChange } ) {
	const translating = lang !== main;
	const text = field.i18n[ main ];
	const schema = types[ field.type ]?.schema ?? { properties: {} };
	const set = ( changes ) => onChange( { ...field, ...changes } );
	// La chiave segue l'etichetta finché nessuno l'ha cambiata a mano e il campo non è salvato:
	// merge tag, condizioni ed export usano quella salvata.
	const setLabel = ( label ) =>
		set( {
			key:
				! saved && field.key === uniqueKey( text.label, keys )
					? uniqueKey( label, keys )
					: field.key,
			i18n: { ...field.i18n, [ main ]: { ...text, label } },
		} );
	const setConfig = ( key ) => ( value ) => {
		const config = { ...field.config, [ key ]: value };
		if ( value === undefined || value === '' || value.length === 0 ) {
			delete config[ key ];
		}
		set( { config } );
	};
	const input = ! NO_INPUT.includes( field.type );
	const badKey = keyError( field.key, keys );
	const setting = ( [ name, prop ] ) =>
		'choices' === name ? (
			<Choices
				key={ name }
				field={ field }
				saved={ saved?.config.choices ?? [] }
				lang={ lang }
				onChange={ onChange }
			/>
		) : (
			<Fixed key={ name } lang={ lang }>
				<SettingControl
					name={ name }
					schema={ prop }
					value={ field.config[ name ] }
					onChange={ setConfig( name ) }
				/>
			</Fixed>
		);

	return (
		<Card
			className="barrio-panel barrio-scroll-target"
			title={ __( 'Field properties', 'barrio-suite' ) }
			actions={
				<span className="barrio-badge">
					{ types[ field.type ]?.label ?? field.type }
				</span>
			}
		>
			<TextIn
				item={ field }
				lang={ lang }
				name="label"
				multiline={ 'consent' === field.type }
				label={
					{
						consent: __( 'Consent text', 'barrio-suite' ),
						page_break: __( 'Next button', 'barrio-suite' ),
						hidden: __( 'Name in the entries', 'barrio-suite' ),
					}[ field.type ] ?? __( 'Label', 'barrio-suite' )
				}
				help={
					'consent' === field.type
						? __(
								'Links are allowed, for example <a href="/privacy-policy/">privacy policy</a>. The text is kept with every entry as proof of consent.',
								'barrio-suite'
							)
						: undefined
				}
				// Nella lingua principale la chiave segue l'etichetta.
				onChange={
					translating
						? onChange
						: ( changed ) => setLabel( changed.i18n[ main ].label )
				}
			/>
			{ PLACEHOLDER.includes( field.type ) && (
				<TextIn
					item={ field }
					lang={ lang }
					name="placeholder"
					optional
					label={
						'select' === field.type
							? __( 'Empty option', 'barrio-suite' )
							: __( 'Placeholder', 'barrio-suite' )
					}
					onChange={ onChange }
				/>
			) }
			{ input && (
				<Toggle
					label={ __( 'Required field', 'barrio-suite' ) }
					disabled={ translating }
					checked={ field.required }
					onChange={ ( required ) => set( { required } ) }
				/>
			) }
			{ Object.entries( schema.properties )
				.filter( ( [ name ] ) => ! ADVANCED.includes( name ) )
				.map( setting ) }
			<Details title={ __( 'Advanced', 'barrio-suite' ) }>
				<Text
					label={ __( 'Key', 'barrio-suite' ) }
					help={
						badKey ? (
							<span className="barrio-field__error">
								{ badKey }
							</span>
						) : (
							__(
								'Generated from the label until you change it or save the form. Used in merge tags, like {{email}}, and in exports: lowercase letters, digits and underscores.',
								'barrio-suite'
							)
						)
					}
					aria-invalid={ !! badKey }
					maxLength={ 64 }
					disabled={ translating }
					value={ field.key }
					onChange={ ( key ) => set( { key } ) }
				/>
				{ Object.entries( schema.properties )
					.filter( ( [ name ] ) => ADVANCED.includes( name ) )
					.map( setting ) }
				{ input && (
					<TextIn
						item={ field }
						lang={ lang }
						name="error_message"
						optional
						label={ __( 'Error message', 'barrio-suite' ) }
						help={ __(
							'Shown when the value is missing or not valid. Leave empty for the standard messages.',
							'barrio-suite'
						) }
						onChange={ onChange }
					/>
				) }
			</Details>
			{ 'page_break' !== field.type && (
				<Fixed lang={ lang }>
					<Conditions
						value={ field.conditions }
						fields={ ruleFields( previous, main ) }
						actions={ [
							{
								value: 'show',
								label: __( 'Show this field', 'barrio-suite' ),
							},
							{
								value: 'hide',
								label: __( 'Hide this field', 'barrio-suite' ),
							},
						] }
						empty={ __(
							'Rules can use only the fields above this one.',
							'barrio-suite'
						) }
						onChange={ ( conditions ) => set( { conditions } ) }
					/>
				</Fixed>
			) }
		</Card>
	);
}

// In un'altra lingua si traducono solo le etichette: l'originale è il segnaposto, e vuota vale quella.
function Choices( { field, saved, lang, onChange } ) {
	const translating = lang !== main;
	const own = field.i18n[ lang ]?.options ?? {};
	// Le righe stanno qui e il campo ne deriva: rilette dal campo, due righe con lo stesso valore
	// (capita mentre si scrive: "1" prima di "10") avrebbero una sola etichetta, l'ultima.
	const [ rows, setRows ] = useState( () => choiceRows( field, main ) );
	const [ ids, moveIds ] = useStableIds( rows.length );
	const box = useRef();
	const set = ( next ) => {
		setRows( next );
		onChange( withChoices( field, main, next ) );
	};
	const applyPreset = ( preset ) => {
		const written =
			rows.some( ( row ) => row.label || row.value ) &&
			JSON.stringify( rows ) !== JSON.stringify( firstChoices() );
		if (
			! written ||
			// eslint-disable-next-line no-alert -- conferma prima di un'azione che non si annulla.
			window.confirm(
				__(
					'Replace the choices you wrote with this preset?',
					'barrio-suite'
				)
			)
		) {
			set( presetRows( preset, main ) );
		}
	};
	const edit = ( i, row ) =>
		set( rows.map( ( r, j ) => ( i === j ? row : r ) ) );

	return (
		<>
			<SectionHead title={ __( 'Choices', 'barrio-suite' ) } level={ 3 }>
				<Fixed lang={ lang }>
					<Menu
						label={ __(
							'Replace the choices with a preset',
							'barrio-suite'
						) }
						text={ __( 'Presets', 'barrio-suite' ) }
						icon="list-view"
						items={ Object.values( presets ).map( ( preset ) => ( {
							label: `${ preset.label } (${ preset.choices.length })`,
							onClick: () => applyPreset( preset ),
						} ) ) }
					/>
					<button
						type="button"
						className="barrio-button"
						onClick={ () =>
							set( [ ...rows, { value: '', label: '' } ] )
						}
					>
						<Icon name="plus-alt2" />
						{ __( 'Add', 'barrio-suite' ) }
					</button>
				</Fixed>
			</SectionHead>
			<div className="barrio-choices" ref={ box } tabIndex={ -1 }>
				<Sortable
					ids={ ids }
					items={ rows }
					label={ ( row ) => row.label || row.value }
					onMove={ ( from, to ) => {
						moveIds( from, to );
						set( arrayMove( rows, from, to ) );
					} }
				>
					{ ( row, i, handle ) => (
						<div className="barrio-choice">
							<Handle
								{ ...( translating
									? { disabled: true }
									: handle ) }
							/>
							<Text
								label={ sprintf(
									/* translators: %d: number of the choice. */
									__( 'Label of choice %d', 'barrio-suite' ),
									i + 1
								) }
								hideLabel
								placeholder={
									translating
										? row.label || row.value
										: __( 'Label', 'barrio-suite' )
								}
								value={
									translating ? own[ row.value ] : row.label
								}
								onChange={ ( label ) =>
									translating
										? onChange(
												setTranslation(
													field,
													lang,
													'options',
													{
														...own,
														[ row.value ]: label,
													}
												)
											)
										: edit(
												i,
												renameChoice(
													row,
													label,
													saved
												)
											)
								}
							/>
							<Text
								label={ sprintf(
									/* translators: %d: number of the choice. */
									__( 'Value of choice %d', 'barrio-suite' ),
									i + 1
								) }
								hideLabel
								placeholder={ __( 'Value', 'barrio-suite' ) }
								disabled={ translating }
								value={ row.value }
								onChange={ ( value ) =>
									edit( i, { ...row, value } )
								}
							/>
							<button
								type="button"
								className="barrio-icon-button is-danger"
								aria-label={ sprintf(
									/* translators: %s: name of the field or of the choice to remove. */
									__( 'Remove %s', 'barrio-suite' ),
									row.label || row.value || i + 1
								) }
								disabled={ translating || rows.length === 1 }
								onClick={ () => {
									set( rows.filter( ( r, j ) => i !== j ) );
									// Le righe si rimontano e il focus, che era su questo bottone, andrebbe perso.
									box.current.focus();
									speak(
										__( 'Choice removed.', 'barrio-suite' )
									);
								} }
							>
								<Icon name="no-alt" />
							</button>
						</div>
					) }
				</Sortable>
			</div>
			<p className="barrio-field__help">
				{ __(
					'The value is what entries and exports save: it stays the same in every language.',
					'barrio-suite'
				) }
			</p>
		</>
	);
}

let lastId = 0;
const newId = () => `sortable-${ ++lastId }`;

// Id stabili per le righe ordinabili: seguono le righe quando si spostano, così React e dnd-kit
// muovono l'elemento giusto (con gli indici, al rilascio la card tornava al suo posto e cambiava
// solo il contenuto). Se cambia il numero di righe gli id ripartono: le righe non hanno stato.
function useStableIds( length ) {
	const [ ids, setIds ] = useState( () => Array.from( { length }, newId ) );
	if ( ids.length !== length ) {
		const fresh = Array.from( { length }, newId );
		setIds( fresh );
		return [ fresh, () => {} ];
	}
	return [ ids, ( from, to ) => setIds( arrayMove( ids, from, to ) ) ];
}
