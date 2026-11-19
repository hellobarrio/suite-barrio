// Ordinamento (modules/ordering/src/Module.php): la scheda di Barrio → Contenuti che sceglie i tipi di contenuto e le
// tassonomie in ordine manuale, e la pagina "Riordina …" che ognuno ha nel suo menu, dopo Aggiungi. E i suoi numeri
// nella card Contenuti della Bacheca.
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
	Permissions,
	TypeCards,
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

// Module::admin_data(): gli elenchi in ordine manuale e le pagine Riordina di chi guarda. Con il modulo spento manca.
export const orderingData = window.barrioAdmin.ordering;

const CARDS = {
	post_types: {
		icon: 'admin-page',
		title: __( 'Content types', 'barrio-suite' ),
		description: __(
			'In manual order, the menu of the type has a Reorder page after Add. The order applies wherever the site lists the contents, unless another order is chosen (such as by title) or in a search; a new content goes to the top. Turned off, the most recent come first again, and the saved order is kept.',
			'barrio-suite'
		),
	},
	taxonomies: {
		icon: 'tag',
		title: __( 'Taxonomies', 'barrio-suite' ),
		description: __(
			'In manual order, the menu of the content type has a Reorder page for the terms. A new term goes to the end; turned off, the terms are in alphabetical order again, and the saved order is kept.',
			'barrio-suite'
		),
	},
};

// La capability del modulo, nei Permessi (Module::CAP).
const CAPS = { barrio_reorder: __( 'Reorder', 'barrio-suite' ) };

// Per la card Contenuti della Bacheca, dalle impostazioni salvate.
export const orderingStats = () => [
	[
		__( 'Lists in manual order', 'barrio-suite' ),
		Object.keys( CARDS ).reduce(
			( count, group ) =>
				count +
				window.barrioAdmin.contentTypes[ group ].filter( ( { name } ) =>
					orderingData.manual[ group ].includes( name )
				).length,
			0
		),
	],
];

// GET e PUT /barrio/v1/ordering/settings, con il Salva della barra. Cambiano il menu: dopo il salvataggio la pagina
// si ricarica.
export const orderingSection = {
	id: 'ordering',
	reload: true,
	load: () => apiFetch( { path: '/barrio/v1/ordering/settings' } ),
	value: ( { role_names: names, ...settings } ) => settings,
	save: ( draft ) =>
		apiFetch( {
			path: '/barrio/v1/ordering/settings',
			method: 'PUT',
			data: draft,
		} ),
	Cards: ( { data, draft, set } ) => (
		<>
			<TypeCards
				cards={ CARDS }
				checked={ ( group, name ) => draft[ group ].includes( name ) }
				onChange={ ( group, name, on ) =>
					set( {
						...draft,
						[ group ]: on
							? [ ...draft[ group ], name ]
							: draft[ group ].filter(
									( item ) => item !== name
								),
					} )
				}
			/>
			<Permissions
				description={ __(
					'Who can reorder the lists in manual order. They also need to be able to edit the contents of others, or the terms: authors and contributors cannot. Administrators can always do everything; only administrators change these settings.',
					'barrio-suite'
				) }
				caps={ CAPS }
				names={ data.role_names }
				roles={ draft.roles }
				onChange={ ( roles ) => set( { ...draft, roles } ) }
			/>
		</>
	),
};

const title = ( item ) => item.title || __( '(no title)', 'barrio-suite' );

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

// Una pagina Riordina: l'elenco intero (GET /barrio/v1/ordering/post/page) e un Salva.
function Reorder( { list, name } ) {
	const path = `/barrio/v1/ordering/${ list }`;
	// Gli elementi salvati e l'albero della pagina ({ genitore: [ id ] }).
	const [ saved, setSaved ] = useState( null );
	const [ tree, setTree ] = useState( null );
	const [ notice, setNotice ] = useState( null );
	const [ busy, setBusy ] = useState( false );

	const load = ( items ) => {
		setSaved( items );
		setTree( toTree( items ) );
	};
	const fail = ( error ) =>
		setNotice( { status: 'error', message: errorMessage( error ) } );
	useEffect( () => {
		apiFetch( { path } ).then( load ).catch( fail );
	}, [ path ] );

	const dirty =
		!! saved &&
		flatten( tree ).join() !== saved.map( ( { id } ) => id ).join();
	useUnsavedWarning( dirty );

	if ( ! saved ) {
		return notice ? <Notice notice={ notice } /> : <Loading />;
	}

	const save = () => {
		setBusy( true );
		setNotice( null );
		apiFetch( { path, method: 'PUT', data: { ids: flatten( tree ) } } )
			.then( ( items ) => {
				load( items );
				setNotice( {
					status: 'success',
					message: __( 'Order saved.', 'barrio-suite' ),
				} );
			} )
			.catch( fail )
			.finally( () => setBusy( false ) );
	};

	return (
		<div className="barrio-stack">
			<Bar title={ name }>
				<button
					type="button"
					className="barrio-button is-primary"
					disabled={ busy || ! dirty }
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
				description={
					Object.keys( tree ).length > 1 &&
					__(
						'Items move among those with the same parent: the parent is changed in the item itself.',
						'barrio-suite'
					)
				}
			>
				{ tree[ 0 ] ? (
					<Tree
						tree={ tree }
						items={ Object.fromEntries(
							saved.map( ( item ) => [ item.id, item ] )
						) }
						onMove={ ( parent, from, to ) =>
							setTree( ( now ) => ( {
								...now,
								[ parent ]: arrayMove(
									now[ parent ],
									from,
									to
								),
							} ) )
						}
					/>
				) : (
					<p className="barrio-card__description">
						{ __(
							'There is nothing to order yet.',
							'barrio-suite'
						) }
					</p>
				) }
			</Card>
		</div>
	);
}

// Le pagine di src/admin/index.js, per ?page= (Module::pages()).
export const reorderPages = Object.fromEntries(
	Object.entries( orderingData?.pages ?? {} ).map(
		( [ slug, { list, title: name } ] ) => [
			slug,
			{
				title: name,
				description: __(
					'Drag the items by their handle, or press space on it and move them with the arrow keys, then save.',
					'barrio-suite'
				),
				page: <Reorder list={ list } name={ name } />,
			},
		]
	)
);
