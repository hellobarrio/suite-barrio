// L'elenco della REST (in ordine, con il genitore) come { genitore: [ id ] }, e di nuovo piatto per salvarlo, con i
// genitori prima dei figli come le posizioni che scrive OrderRepository.
export function toTree( items ) {
	const tree = {};
	for ( const { id, parent } of items ) {
		( tree[ parent ] ??= [] ).push( id );
	}
	return tree;
}

export const flatten = ( tree, parent = 0 ) =>
	( tree[ parent ] ?? [] ).flatMap( ( id ) => [
		id,
		...flatten( tree, id ),
	] );
