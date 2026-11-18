// La scheda Ordinamento di Barrio → Editor (modules/ordering/src/Module.php): per ogni tipo di contenuto e tassonomia
// l'interruttore dell'ordine manuale e l'elenco da trascinare, con un solo Salva. E i suoi numeri nella card Editor
// della Bacheca.
import { arrayMove } from '@dnd-kit/sortable';
import apiFetch from '@wordpress/api-fetch';
import { useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

import { Handle, Sortable } from '../../../../assets/src/admin/sortable.js';
import {
	Bar,
	Card,
	Loading,
	Lucide,
	Notice,
	Picker,
	Toggle,
	errorMessage,
	useUnsavedWarning,
} from '../../../../assets/src/admin/ui.js';
import { flatten, toTree } from './tree.js';

// Anche nella card Moduli di Barrio → Impostazioni.
export const ORDERING = {
	title: __( 'Ordering', 'barrio-suite' ),
	description: __(
		'A manual order for contents and terms, by drag and drop, wherever the site does not ask for another order.',
		'barrio-suite'
	),
};

// Module::admin_data(): gli elenchi in ordine manuale e quelli che chi guarda può riordinare. Con il modulo spento manca.
export const orderingData = window.barrioAdmin.ordering;

const { canManageOptions, contentTypes } = window.barrioAdmin;

// Ogni elenco con il suo percorso nella REST: post/page, term/category. Le tassonomie con i tipi che le usano.
const LISTS = [
	...contentTypes.post_types.map( ( { name, label } ) => ( {
		value: `post/${ name }`,
		label,
		group: 'post_types',
		name,
	} ) ),
	...contentTypes.taxonomies.map( ( { name, label, types } ) => ( {
		value: `term/${ name }`,
		label: `${ label } (${ types.join( ', ' ) })`,
		group: 'taxonomies',
		name,
	} ) ),
];

const isManual = ( manual, list ) => manual[ list.group ].includes( list.name );

const sameSet = ( a, b ) =>
	a.length === b.length && a.every( ( item ) => b.includes( item ) );

const title = ( item ) => item.title || __( '(no title)', 'barrio-suite' );

// Per la card Editor della Bacheca, dalle impostazioni salvate.
export const orderingStats = () => [
	[
		__( 'Lists in manual order', 'barrio-suite' ),
		LISTS.filter( ( list ) => isManual( orderingData.manual, list ) )
			.length,
	],
];

// Un livello dell'albero: si sposta tra fratelli, e ogni elemento porta con sé i suoi figli.
function Tree( { tree, parent = 0, items, onMove } ) {
	return (
		<div className="barrio-order">
			<Sortable
				ids={ tree[ parent ] }
				items={ tree[ parent ] }
				label={ ( id ) => title( items[ id ] ) }
				onMove={ ( from, to ) => onMove( parent, from, to ) }
			>
				{ ( id, index, handle ) => (
					<>
						<div className="barrio-order__row">
							<Handle { ...handle } />
							<span className="barrio-order__title">
								{ title( items[ id ] ) }
							</span>
							{ items[ id ].status && (
								<span className="barrio-badge">
									{ items[ id ].status }
								</span>
							) }
						</div>
						{ tree[ id ] && (
							<Tree
								tree={ tree }
								parent={ id }
								items={ items }
								onMove={ onMove }
							/>
						) }
					</>
				) }
			</Sortable>
		</div>
	);
}

export function Ordering() {
	// Chi gestisce il sito sceglie tra tutti gli elenchi; gli altri riordinano quelli che possono.
	const lists = canManageOptions
		? LISTS
		: LISTS.filter( ( list ) =>
				orderingData.reorderable.includes( list.value )
			);
	// Dal link Riordina delle liste di WordPress, o il primo in ordine manuale.
	const [ value, setValue ] = useState( () => {
		const asked = new URLSearchParams( window.location.search ).get(
			'list'
		);
		return (
			lists.find( ( list ) => list.value === asked ) ??
			lists.find( ( list ) => isManual( orderingData.manual, list ) ) ??
			lists[ 0 ]
		).value;
	} );
	const [ manual, setManual ] = useState( orderingData.manual );
	const [ draftManual, setDraftManual ] = useState( orderingData.manual );
	// Per elenco: gli elementi salvati e l'albero della pagina.
	const [ saved, setSaved ] = useState( {} );
	const [ drafts, setDrafts ] = useState( {} );
	const [ notice, setNotice ] = useState( null );
	const [ busy, setBusy ] = useState( false );

	const list = lists.find( ( item ) => item.value === value );
	const on = isManual( draftManual, list );
	const loaded = !! saved[ value ];

	const load = ( key, items ) => {
		setSaved( ( now ) => ( { ...now, [ key ]: items } ) );
		setDrafts( ( now ) => ( { ...now, [ key ]: toTree( items ) } ) );
	};
	const fail = ( error ) =>
		setNotice( { status: 'error', message: errorMessage( error ) } );

	useEffect( () => {
		if ( on && ! loaded ) {
			apiFetch( { path: `/barrio/v1/ordering/${ value }` } )
				.then( ( items ) => load( value, items ) )
				.catch( fail );
		}
	}, [ on, loaded, value ] );

	const settingsChanged = Object.keys( manual ).some(
		( group ) => ! sameSet( manual[ group ], draftManual[ group ] )
	);
	// Solo gli elenchi che restano in ordine manuale.
	const changed = Object.keys( drafts ).filter(
		( key ) =>
			isManual(
				draftManual,
				LISTS.find( ( item ) => item.value === key )
			) &&
			flatten( drafts[ key ] ).join() !==
				saved[ key ].map( ( { id } ) => id ).join()
	);
	useUnsavedWarning( settingsChanged || changed.length > 0 );

	const save = async () => {
		setBusy( true );
		setNotice( null );
		try {
			if ( settingsChanged ) {
				const next = await apiFetch( {
					path: '/barrio/v1/ordering/settings',
					method: 'PUT',
					data: draftManual,
				} );
				setManual( next );
				setDraftManual( next );
			}
			for ( const key of changed ) {
				load(
					key,
					await apiFetch( {
						path: `/barrio/v1/ordering/${ key }`,
						method: 'PUT',
						data: { ids: flatten( drafts[ key ] ) },
					} )
				);
			}
			setNotice( {
				status: 'success',
				message: __( 'Order saved.', 'barrio-suite' ),
			} );
		} catch ( error ) {
			fail( error );
		} finally {
			setBusy( false );
		}
	};

	const draft = drafts[ value ];
	const move = ( parent, from, to ) =>
		setDrafts( ( now ) => ( {
			...now,
			[ value ]: {
				...now[ value ],
				[ parent ]: arrayMove( now[ value ][ parent ], from, to ),
			},
		} ) );
	const terms = 'taxonomies' === list.group;

	return (
		<div className="barrio-stack">
			<Bar title={ ORDERING.title }>
				<Picker
					label={ __( 'List', 'barrio-suite' ) }
					text={ list.label }
					options={ lists }
					value={ value }
					onChange={ setValue }
				/>
				<button
					type="button"
					className="barrio-button is-primary"
					disabled={ busy || ! ( settingsChanged || changed.length ) }
					onClick={ save }
				>
					<Lucide name="save" />
					<span className="barrio-button__text">
						{ busy
							? __( 'Saving…', 'barrio-suite' )
							: __( 'Save', 'barrio-suite' ) }
					</span>
				</button>
			</Bar>
			<Notice notice={ notice } onClose={ () => setNotice( null ) } />
			<Card
				className="barrio-wide"
				icon={ terms ? 'tag' : 'admin-page' }
				title={ list.label }
				description={
					on &&
					[
						__(
							'Drag the items by their handle, or press space on it and move them with the arrow keys. The order applies wherever the site lists them, unless another order is chosen (such as by title) or in a search.',
							'barrio-suite'
						),
						terms
							? __(
									'A new term goes to the end until you move it.',
									'barrio-suite'
								)
							: __(
									'A new content goes to the top until you move it.',
									'barrio-suite'
								),
						draft &&
							Object.keys( draft ).length > 1 &&
							__(
								'Items move among those with the same parent: the parent is changed in the item itself.',
								'barrio-suite'
							),
					]
						.filter( Boolean )
						.join( ' ' )
				}
			>
				{ canManageOptions && (
					<Toggle
						label={ __( 'Manual order', 'barrio-suite' ) }
						help={
							terms
								? __(
										'Off, the terms are in alphabetical order. Turning it off keeps the order you saved, for when you turn it on again.',
										'barrio-suite'
									)
								: __(
										'Off, the most recent contents come first. Turning it off keeps the order you saved, for when you turn it on again.',
										'barrio-suite'
									)
						}
						checked={ on }
						onChange={ ( checked ) =>
							setDraftManual( {
								...draftManual,
								[ list.group ]: checked
									? [
											...draftManual[ list.group ],
											list.name,
										]
									: draftManual[ list.group ].filter(
											( name ) => name !== list.name
										),
							} )
						}
					/>
				) }
				{ on &&
					( draft ? (
						( draft[ 0 ] && (
							<Tree
								tree={ draft }
								items={ Object.fromEntries(
									saved[ value ].map( ( item ) => [
										item.id,
										item,
									] )
								) }
								onMove={ move }
							/>
						) ) || (
							<p className="barrio-card__description">
								{ __(
									'There is nothing to order yet.',
									'barrio-suite'
								) }
							</p>
						)
					) : (
						<Loading />
					) ) }
			</Card>
		</div>
	);
}
