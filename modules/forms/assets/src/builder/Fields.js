// Scheda Campi: la struttura del form, ordinabile, e le proprietà del campo scelto. Le proprietà
// della config vengono dallo schema del tipo (FieldTypes::schema()); le scelte hanno un editor
// proprio, con i preset.
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
import { Dropdown } from '@wordpress/components';
import { useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

import {
	Card,
	Check,
	Icon,
	Menu,
	SectionHead,
	Text,
	useScrollIntoView,
} from '../admin/ui.js';
import { Conditions } from './Conditions.js';
import { choiceRows, renameChoice, uniqueKey, withChoices } from './model.js';

const { languages, types, presets } = window.barrioAdmin;
const lang = languages[ 0 ];

// Tipi senza un controllo da compilare: niente obbligatorio, segnaposto o messaggio di errore.
const NO_INPUT = [ 'hidden', 'page_break' ];
const PLACEHOLDER = [ 'text', 'email', 'tel', 'number', 'textarea', 'select' ];
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
const plain = ( field ) =>
	( field.i18n[ lang ]?.label ?? '' ).replace( /<[^>]*>/g, '' ).trim() ||
	field.key;
// Il consenso ha come etichetta il suo testo, l'interruzione di pagina il pulsante: si mostra il tipo.
const title = ( field ) =>
	[ 'consent', 'page_break' ].includes( field.type )
		? types[ field.type ].label
		: plain( field );

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
			[ lang ]: {
				label,
				placeholder: null,
				error_message: null,
				options: null,
			},
		},
	};
	return types[ type ].schema.properties.choices
		? withChoices( field, lang, [
				{ value: 'option_1', label: __( 'Option 1', 'barrio-suite' ) },
				{ value: 'option_2', label: __( 'Option 2', 'barrio-suite' ) },
			] )
		: field;
}

// I campi che una regola può usare: per un campo quelli che lo precedono, per una notifica tutti.
export const ruleFields = ( fields ) =>
	fields
		.filter( ( f ) => 'page_break' !== f.type )
		.map( ( f ) => ( {
			key: f.key,
			label: plain( f ),
			choices: f.config.choices ? choiceRows( f, lang ) : null,
		} ) );

export function FieldsTab( { fields, onChange } ) {
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

	return (
		<div className="barrio-fields">
			<div>
				<SectionHead
					title={ __(
						'Form structure · drag to reorder',
						'barrio-suite'
					) }
				>
					<AddField onAdd={ add } />
				</SectionHead>
				<div className="barrio-fields__list">
					{ fields.length ? (
						<Sortable
							ids={ ids }
							items={ fields }
							onMove={ ( from, to ) => {
								moveIds( from, to );
								onChange( arrayMove( fields, from, to ) );
								setSelected( to );
							} }
						>
							{ ( f, i, handle ) => (
								<FieldCard
									field={ f }
									selected={ i === selected }
									isNew={ i === added }
									handle={ handle }
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
			{ field ? (
				<FieldPanel
					// Un pannello nuovo per ogni campo: nessuno stato passa da un campo all'altro.
					key={ ids[ selected ] }
					field={ field }
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
			) : (
				<Card
					className="barrio-panel"
					title={ __( 'Field properties', 'barrio-suite' ) }
					description={ __(
						'Select a field to edit its properties.',
						'barrio-suite'
					) }
				/>
			) }
		</div>
	);
}

function AddField( { onAdd } ) {
	return (
		<Dropdown
			popoverProps={ { placement: 'bottom-end' } }
			renderToggle={ ( { isOpen, onToggle } ) => (
				<button
					type="button"
					className="barrio-button is-primary"
					aria-expanded={ isOpen }
					onClick={ onToggle }
				>
					<Icon name="plus-alt2" />
					{ __( 'Add field', 'barrio-suite' ) }
				</button>
			) }
			renderContent={ ( { onClose } ) => (
				<div className="barrio-typegrid">
					{ Object.entries( types ).map( ( [ type, { label } ] ) => (
						<button
							key={ type }
							type="button"
							onClick={ () => {
								onAdd( type );
								onClose();
							} }
						>
							<Icon name={ ICONS[ type ] ?? 'admin-generic' } />
							{ label }
						</button>
					) ) }
				</div>
			) }
		/>
	);
}

function FieldCard( { field, selected, isNew, handle, onSelect, onRemove } ) {
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
				<Handle { ...handle } />
				<button
					type="button"
					className="barrio-fieldcard__title"
					aria-pressed={ selected }
					onClick={ onSelect }
				>
					{ title( field ) }
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
					onClick={ ( event ) => {
						event.stopPropagation();
						onRemove();
					} }
				>
					<Icon name="trash" />
				</button>
			</div>
			<div className="barrio-fieldcard__preview" aria-hidden="true">
				<Preview field={ field } />
			</div>
		</div>
	);
}

// Come apparirà il campo, a grandi linee: il form vero è nell'anteprima.
function Preview( { field } ) {
	const text = field.i18n[ lang ] ?? {};
	const choices = choiceRows( field, lang );
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
							/* translators: %d: altre scelte non mostrate. */
							__( '+ %d more', 'barrio-suite' ),
							choices.length - 5
						) }
				</div>
			);
		case 'consent':
			return (
				<span className="barrio-preview-choices">
					<span>
						<input type="checkbox" disabled /> { plain( field ) }
					</span>
				</span>
			);
		case 'file':
			return (
				<div className="barrio-preview-note">
					{ sprintf(
						/* translators: %s: estensioni ammesse. */
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
								/* translators: %s: parametro dell'URL. */
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
						/* translators: %s: testo del pulsante. */
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

function FieldPanel( { field, previous, keys, onChange } ) {
	const text = field.i18n[ lang ];
	const schema = types[ field.type ]?.schema ?? { properties: {} };
	const set = ( changes ) => onChange( { ...field, ...changes } );
	// Un testo facoltativo vuoto è null: il valore standard (docs/database.md).
	const setText = ( key, optional ) => ( value ) =>
		set( {
			i18n: {
				...field.i18n,
				[ lang ]: {
					...text,
					[ key ]: optional && ! value ? null : value,
				},
			},
		} );
	// La chiave segue l'etichetta finché nessuno l'ha cambiata a mano.
	const setLabel = ( label ) =>
		set( {
			key:
				field.key === uniqueKey( text.label, keys )
					? uniqueKey( label, keys )
					: field.key,
			i18n: { ...field.i18n, [ lang ]: { ...text, label } },
		} );
	const setConfig = ( key ) => ( value ) => {
		const config = { ...field.config, [ key ]: value };
		if ( value === undefined || value === '' || value.length === 0 ) {
			delete config[ key ];
		}
		set( { config } );
	};
	const input = ! NO_INPUT.includes( field.type );

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
			<Text
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
				value={ text.label }
				onChange={ setLabel }
			/>
			<Text
				label={ __( 'Key', 'barrio-suite' ) }
				help={ __(
					'Generated from the label until you change it. Used in merge tags, like {{email}}, and in exports: lowercase letters, digits and underscores.',
					'barrio-suite'
				) }
				value={ field.key }
				onChange={ ( key ) => set( { key } ) }
			/>
			{ PLACEHOLDER.includes( field.type ) && (
				<Text
					label={
						'select' === field.type
							? __( 'Empty option', 'barrio-suite' )
							: __( 'Placeholder', 'barrio-suite' )
					}
					value={ text.placeholder }
					onChange={ setText( 'placeholder', true ) }
				/>
			) }
			{ input && (
				<Check
					label={ __( 'Required field', 'barrio-suite' ) }
					checked={ field.required }
					onChange={ ( required ) => set( { required } ) }
				/>
			) }
			{ Object.entries( schema.properties ).map( ( [ name, prop ] ) =>
				'choices' === name ? (
					<Choices
						key={ name }
						field={ field }
						onChange={ onChange }
					/>
				) : (
					<ConfigControl
						key={ name }
						prop={ prop }
						value={ field.config[ name ] }
						onChange={ setConfig( name ) }
					/>
				)
			) }
			{ input && (
				<Text
					label={ __( 'Error message', 'barrio-suite' ) }
					help={ __(
						'Shown when the value is missing or not valid. Leave empty for the standard messages.',
						'barrio-suite'
					) }
					value={ text.error_message }
					onChange={ setText( 'error_message', true ) }
				/>
			) }
			{ 'page_break' !== field.type && (
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
			) }
		</Card>
	);
}

// Un controllo per proprietà dello schema: testo, numero o lista.
function ConfigControl( { prop, value, onChange } ) {
	if ( 'array' === prop.type ) {
		return (
			<ListInput prop={ prop } value={ value } onChange={ onChange } />
		);
	}
	const number = 'number' === prop.type;
	return (
		<Text
			label={ prop.title }
			help={ prop.description }
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
function ListInput( { prop, value, onChange } ) {
	const [ text, setText ] = useState( ( value ?? [] ).join( ', ' ) );
	return (
		<Text
			label={ prop.title }
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

function Choices( { field, onChange } ) {
	const rows = choiceRows( field, lang );
	const [ ids, moveIds ] = useStableIds( rows.length );
	const set = ( next ) => onChange( withChoices( field, lang, next ) );
	const edit = ( i, row ) =>
		set( rows.map( ( r, j ) => ( i === j ? row : r ) ) );

	return (
		<>
			<SectionHead title={ __( 'Choices', 'barrio-suite' ) } level={ 3 }>
				<Menu
					label={ __(
						'Replace the choices with a preset',
						'barrio-suite'
					) }
					text={ __( 'Presets', 'barrio-suite' ) }
					icon="list-view"
					items={ Object.entries( presets ).map(
						( [ id, preset ] ) => ( {
							label: `${ preset.label } (${ preset.choices.length })`,
							onClick: () =>
								set(
									presets[ id ].choices.map(
										( [ value, label ] ) => ( {
											value,
											label,
										} )
									)
								),
						} )
					) }
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
			</SectionHead>
			<div className="barrio-choices">
				<Sortable
					ids={ ids }
					items={ rows }
					onMove={ ( from, to ) => {
						moveIds( from, to );
						set( arrayMove( rows, from, to ) );
					} }
				>
					{ ( row, i, handle ) => (
						<div className="barrio-choice">
							<Handle { ...handle } />
							<Text
								label={ sprintf(
									/* translators: %d: numero della scelta. */
									__( 'Label of choice %d', 'barrio-suite' ),
									i + 1
								) }
								hideLabel
								placeholder={ __( 'Label', 'barrio-suite' ) }
								value={ row.label }
								onChange={ ( label ) =>
									edit( i, renameChoice( row, label ) )
								}
							/>
							<Text
								label={ sprintf(
									/* translators: %d: numero della scelta. */
									__( 'Value of choice %d', 'barrio-suite' ),
									i + 1
								) }
								hideLabel
								placeholder={ __( 'Value', 'barrio-suite' ) }
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
								disabled={ rows.length === 1 }
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
// le frecce. children( item, indice, props della maniglia ).
function Sortable( { ids, items, onMove, children } ) {
	const sensors = useSensors(
		useSensor( PointerSensor ),
		useSensor( KeyboardSensor, {
			coordinateGetter: sortableKeyboardCoordinates,
		} )
	);
	return (
		<DndContext
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
