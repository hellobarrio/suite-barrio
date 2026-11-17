// Scheda Campi: la struttura del form, ordinabile, e le proprietà del campo scelto. Le proprietà
// della config vengono dallo schema del tipo (FieldTypes::schema()); le scelte hanno un editor
// proprio, con i preset. In un'altra lingua (lang) restano solo i testi: la struttura si cambia
// nella lingua principale.
import {
	DndContext,
	KeyboardSensor,
	PointerSensor,
	closestCenter,
	useSensor,
	useSensors,
} from '@dnd-kit/core';
import {
	SortableContext,
	arrayMove,
	sortableKeyboardCoordinates,
	useSortable,
	verticalListSortingStrategy,
} from '@dnd-kit/sortable';
import { useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

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
	renameChoice,
	setTranslation,
	textIn,
	uniqueKey,
	withChoices,
} from './model.js';
import { Conditions } from './Conditions.js';
import { SettingControl } from './Integrations.js';
import { TextIn } from './Texts.js';

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

// Etichetta senza HTML (il consenso ha un link), per gli elenchi.
const plain = ( field, lang = main ) =>
	( textIn( field, lang, main ).label ?? '' )
		.replace( /<[^>]*>/g, '' )
		.trim() || field.key;
// Il consenso ha come etichetta il suo testo, l'interruzione di pagina il pulsante: si mostra il tipo.
const fieldTitle = ( field, lang ) =>
	[ 'consent', 'page_break' ].includes( field.type )
		? types[ field.type ].label
		: plain( field, lang );

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
		? withChoices( field, main, [
				{ value: 'option_1', label: __( 'Option 1', 'barrio-suite' ) },
				{ value: 'option_2', label: __( 'Option 2', 'barrio-suite' ) },
			] )
		: field;
}

// Con intl i nomi li dà il browser, nella lingua principale del form.
function presetRows( { choices, intl } ) {
	const date = ( options, time ) =>
		new Intl.DateTimeFormat( main, { ...options, timeZone: 'UTC' } ).format(
			time
		);
	const name = {
		region: ( value ) =>
			new Intl.DisplayNames( main, { type: 'region' } ).of( value ),
		language: ( value ) =>
			new Intl.DisplayNames( main, { type: 'language' } ).of( value ),
		// Il 1° gennaio 2024 era un lunedì.
		weekday: ( value, i ) =>
			date( { weekday: 'long' }, Date.UTC( 2024, 0, 1 + i ) ),
		month: ( value, i ) =>
			date( { month: 'long' }, Date.UTC( 2024, i, 1 ) ),
	}[ intl ];
	const rows = choices.map( ( [ value, label ], i ) => {
		const text = label || name?.( value, i ) || value;
		return {
			value,
			label: text.charAt( 0 ).toLocaleUpperCase( main ) + text.slice( 1 ),
		};
	} );
	return [ 'region', 'language' ].includes( intl )
		? rows.sort( ( x, y ) => x.label.localeCompare( y.label, main ) )
		: rows;
}

// I campi che una regola può usare: per un campo quelli che lo precedono, per una notifica o
// un'integrazione tutti.
export const ruleFields = ( fields ) =>
	fields
		.filter( ( f ) => 'page_break' !== f.type )
		.map( ( f ) => ( {
			key: f.key,
			type: f.type,
			label: plain( f ),
			choices: f.config.choices ? choiceRows( f, main ) : null,
		} ) );

export function FieldsTab( { fields, saved, lang, onChange } ) {
	const translating = lang !== main;
	const [ selected, setSelected ] = useState( fields.length ? 0 : null );
	const [ added, setAdded ] = useState( null );
	const [ ids, moveIds ] = useStableIds( fields.length );
	const field = fields[ selected ];

	// Il campo nuovo va sotto quello scelto, o in fondo.
	const add = ( type ) => {
		const at = null === selected ? fields.length : selected + 1;
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
	};
	const remove = ( i ) => {
		onChange( fields.filter( ( f, j ) => i !== j ) );
		// Con un campo in meno le card si rimontano: quella aggiunta prima non deve tornare in vista.
		setAdded( null );
		if ( i === selected ) {
			setSelected( null );
		} else if ( i < selected ) {
			setSelected( selected - 1 );
		}
	};

	let panel = (
		<Card
			className="barrio-panel"
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
		<div className="barrio-fields">
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
				<div className="barrio-fields__list">
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
									onSelect={ () => setSelected( i ) }
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
					aria-label={ __( 'Remove field', 'barrio-suite' ) }
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
						{ plain( field, lang ) }
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
			className="barrio-panel"
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
					help={ __(
						'Generated from the label until you change it or save the form. Used in merge tags, like {{email}}, and in exports: lowercase letters, digits and underscores.',
						'barrio-suite'
					) }
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
						fields={ ruleFields( previous ) }
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
	const rows = choiceRows( field, main );
	const [ ids, moveIds ] = useStableIds( rows.length );
	const set = ( next ) => onChange( withChoices( field, main, next ) );
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
							onClick: () => set( presetRows( preset ) ),
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
			<div className="barrio-choices">
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
								aria-label={ __(
									'Remove choice',
									'barrio-suite'
								) }
								disabled={ translating || rows.length === 1 }
								onClick={ () =>
									set( rows.filter( ( r, j ) => i !== j ) )
								}
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

const Handle = ( props ) => (
	<button
		type="button"
		className="barrio-handle"
		aria-label={ __( 'Move', 'barrio-suite' ) }
		{ ...props }
	>
		<svg
			width="12"
			height="18"
			viewBox="0 0 12 18"
			fill="currentColor"
			aria-hidden="true"
		>
			<circle cx="3" cy="3" r="1.5" />
			<circle cx="9" cy="3" r="1.5" />
			<circle cx="3" cy="9" r="1.5" />
			<circle cx="9" cy="9" r="1.5" />
			<circle cx="3" cy="15" r="1.5" />
			<circle cx="9" cy="15" r="1.5" />
		</svg>
	</button>
);

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

// Drag & drop accessibile: si trascina dalla maniglia, o la si attiva con spazio e si sposta con
// le frecce. children( item, indice, props della maniglia ); label( item ) è il nome negli annunci.
function Sortable( { ids, items, label, onMove, children } ) {
	const sensors = useSensors(
		useSensor( PointerSensor ),
		useSensor( KeyboardSensor, {
			coordinateGetter: sortableKeyboardCoordinates,
		} )
	);
	const name = ( { id } ) => label( items[ ids.indexOf( id ) ] );
	const moved = ( text, { active, over } ) =>
		over
			? sprintf(
					text,
					name( active ),
					ids.indexOf( over.id ) + 1,
					ids.length
				)
			: null;
	const announcements = {
		onDragStart: ( { active } ) =>
			sprintf(
				/* translators: %s: name of the item. */
				__( 'Picked up %s.', 'barrio-suite' ),
				name( active )
			),
		onDragOver: ( event ) =>
			moved(
				/* translators: 1: name of the item; 2: new position; 3: number of items. */
				__( '%1$s moved to position %2$d of %3$d.', 'barrio-suite' ),
				event
			),
		onDragEnd: ( event ) =>
			moved(
				/* translators: 1: name of the item; 2: new position; 3: number of items. */
				__( '%1$s dropped at position %2$d of %3$d.', 'barrio-suite' ),
				event
			),
		onDragCancel: ( { active } ) =>
			sprintf(
				/* translators: %s: name of the item. */
				__( 'Moving %s was cancelled.', 'barrio-suite' ),
				name( active )
			),
	};
	return (
		<DndContext
			accessibility={ {
				announcements,
				screenReaderInstructions: {
					draggable: __(
						'To move an item, press space or enter, use the arrow keys, then press space or enter again to drop it, or escape to cancel.',
						'barrio-suite'
					),
				},
			} }
			sensors={ sensors }
			collisionDetection={ closestCenter }
			onDragEnd={ ( { active, over } ) =>
				over &&
				active.id !== over.id &&
				onMove( ids.indexOf( active.id ), ids.indexOf( over.id ) )
			}
		>
			<SortableContext
				items={ ids }
				strategy={ verticalListSortingStrategy }
			>
				{ items.map( ( item, i ) => (
					<SortableItem key={ ids[ i ] } id={ ids[ i ] }>
						{ ( handle ) => children( item, i, handle ) }
					</SortableItem>
				) ) }
			</SortableContext>
		</DndContext>
	);
}

function SortableItem( { id, children } ) {
	const {
		attributes,
		listeners,
		setNodeRef,
		transform,
		transition,
		isDragging,
	} = useSortable( { id } );
	return (
		<div
			ref={ setNodeRef }
			style={ {
				position: 'relative',
				zIndex: isDragging ? 1 : undefined,
				transform: transform
					? `translate3d(${ transform.x }px, ${ transform.y }px, 0)`
					: undefined,
				transition,
			} }
		>
			{ children( { ...attributes, ...listeners } ) }
		</div>
	);
}
