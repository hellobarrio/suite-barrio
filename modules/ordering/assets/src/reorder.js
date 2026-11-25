// Riordina nella lista di un elenco in ordine manuale (Module::list_screen() in modules/ordering/src/Module.php): il
// pulsante accanto ad Aggiungi apre l'elenco intero (GET /barrio/v1/ordering/post/page) da trascinare. Salvato, la lista
// si ricarica nel nuovo ordine.
import { arrayMove } from '@dnd-kit/sortable';
import apiFetch from '@wordpress/api-fetch';
import { createRoot, useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

import { Handle, Sortable } from '../../../../assets/src/admin/sortable.js';
import {
	CancelButton,
	Dialog,
	Loading,
	Notice,
	SaveButton,
	discard,
	errorMessage,
	useUnsavedWarning,
} from '../../../../assets/src/admin/ui.js';
import { flatten, toTree } from './tree.js';

const { list, title: name } = window.barrioReorder;

// ponytail: la modale ha tutto l'elenco; oltre qualche centinaio di voci il trascinamento va a scatti (67 ms a passo
// con 1.011 pagine), con le pagine quando un sito lo chiede.
const MANY = 300;

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

function ReorderModal( { onClose } ) {
	const path = `/barrio/v1/ordering/${ list }`;
	// Gli elementi salvati e l'albero della modale ({ genitore: [ id ] }).
	const [ saved, setSaved ] = useState( null );
	const [ tree, setTree ] = useState( null );
	const [ notice, setNotice ] = useState( null );
	const [ busy, setBusy ] = useState( false );

	const fail = ( error ) =>
		setNotice( { status: 'error', message: errorMessage( error ) } );
	useEffect( () => {
		apiFetch( { path } )
			.then( ( items ) => {
				setSaved( items );
				setTree( toTree( items ) );
			} )
			.catch( fail );
	}, [ path ] );

	// Con l'albero al caricamento: l'elenco della REST non ha sempre i genitori prima dei figli.
	const dirty =
		!! saved &&
		flatten( tree ).join() !== flatten( toTree( saved ) ).join();
	// Salvando no: la lista si ricarica.
	useUnsavedWarning( dirty && ! busy );

	const close = () => discard( dirty ) && onClose();
	const save = () => {
		setBusy( true );
		setNotice( null );
		apiFetch( { path, method: 'PUT', data: { ids: flatten( tree ) } } )
			.then( () => window.location.reload() )
			.catch( ( error ) => {
				fail( error );
				setBusy( false );
			} );
	};

	let content = notice ? null : <Loading />;
	if ( saved ) {
		content = tree[ 0 ] ? (
			<Tree
				tree={ tree }
				items={ Object.fromEntries(
					saved.map( ( item ) => [ item.id, item ] )
				) }
				onMove={ ( parent, from, to ) =>
					setTree( ( now ) => ( {
						...now,
						[ parent ]: arrayMove( now[ parent ], from, to ),
					} ) )
				}
			/>
		) : (
			<p>{ __( 'There is nothing to order yet.', 'barrio-suite' ) }</p>
		);
	}

	return (
		<Dialog
			footer={
				<>
					<CancelButton onClick={ close } />
					<SaveButton
						busy={ busy }
						disabled={ ! dirty }
						onClick={ save }
					/>
				</>
			}
			size="medium"
			title={ name }
			onRequestClose={ close }
		>
			<div className="barrio-stack">
				<p className="barrio-field__help">
					{ __(
						'Drag the items by their handle, or press space on it and move them with the arrow keys, then save.',
						'barrio-suite'
					) }{ ' ' }
					{ tree &&
						Object.keys( tree ).length > 1 &&
						__(
							'Items move among those with the same parent: the parent is changed in the item itself.',
							'barrio-suite'
						) }{ ' ' }
					{ saved?.length > MANY &&
						__(
							'With this many items, moving them can be slow.',
							'barrio-suite'
						) }
				</p>
				<Notice notice={ notice } />
				{ content }
			</div>
		</Dialog>
	);
}

function Reorder() {
	const [ open, setOpen ] = useState( false );
	return (
		<>
			<button
				type="button"
				className="page-title-action"
				onClick={ () => setOpen( true ) }
			>
				{ __( 'Reorder', 'barrio-suite' ) }
			</button>
			{ open && <ReorderModal onClose={ () => setOpen( false ) } /> }
		</>
	);
}

// Accanto ad Aggiungi, che nelle liste dei termini non c'è: prima della linea che chiude l'intestazione.
const end = document.querySelector( '.wrap > .wp-header-end' );
if ( end ) {
	const root = document.createElement( 'span' );
	end.before( root );
	createRoot( root ).render( <Reorder /> );
}
