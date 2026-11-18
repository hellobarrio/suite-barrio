// Drag & drop accessibile, uguale in tutte le pagine: i campi e le scelte del builder dei form, l'albero di
// Ordinamento.
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
	sortableKeyboardCoordinates,
	useSortable,
	verticalListSortingStrategy,
} from '@dnd-kit/sortable';
import { __, sprintf } from '@wordpress/i18n';

export const Handle = ( props ) => (
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

// Drag & drop accessibile: si trascina dalla maniglia, o la si attiva con spazio e si sposta con
// le frecce. children( item, indice, props della maniglia ); label( item ) è il nome negli annunci.
export function Sortable( { ids, items, label, onMove, children } ) {
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
