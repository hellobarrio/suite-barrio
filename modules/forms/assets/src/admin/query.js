// Ricerca, ordinamento e paginazione delle tabelle. Regola di tutte le tabelle del plugin:
// ricerca, pagine e ordinamento a tre stati (crescente → decrescente → ordine di default), con
// una colonna sempre ordinata: quella di default, finché non se ne sceglie un'altra.
// Senza WordPress: le prova tests/js/query.test.js.

export const PER_PAGE = 20;

// La query iniziale di una tabella, con la sua colonna di default.
export const initialQuery = ( sort, order = 'asc' ) => ( {
	search: '',
	sort,
	order,
	page: 1,
} );

// Il clic sull'intestazione di una colonna. Sulla colonna di default il terzo stato coinciderebbe
// con uno dei primi due: lì il clic alterna crescente e decrescente.
export function nextSort( query, field, initial ) {
	if ( query.sort !== field ) {
		return { ...query, sort: field, order: 'asc', page: 1 };
	}
	if ( 'asc' === query.order ) {
		return { ...query, order: 'desc', page: 1 };
	}
	return field === initial.sort && 'desc' === initial.order
		? { ...query, order: 'asc', page: 1 }
		: { ...query, sort: initial.sort, order: initial.order, page: 1 };
}

export const pages = ( total ) => Math.max( 1, Math.ceil( total / PER_PAGE ) );

const compare = ( a, b ) =>
	typeof a === 'number' && typeof b === 'number'
		? a - b
		: String( a ?? '' ).localeCompare( String( b ?? '' ), undefined, {
				numeric: true,
				sensitivity: 'base',
			} );

/**
 * Per le tabelle con pochi dati, tutti nel browser (i form). Le registrazioni passeranno la
 * stessa query al server.
 *
 * @param {Array}    rows
 * @param {Object}   query  { search, sort, order, page }.
 * @param {string[]} fields Le colonne in cui cercare.
 * @return {{rows: Array, total: number}} La pagina richiesta e il numero di righe trovate.
 */
export function localQuery( rows, query, fields ) {
	const search = query.search.trim().toLowerCase();
	let found = search
		? rows.filter( ( row ) =>
				fields.some( ( field ) =>
					String( row[ field ] ).toLowerCase().includes( search )
				)
			)
		: rows;
	const direction = 'desc' === query.order ? -1 : 1;
	// sort() è stabile: a parità di valore resta l'ordine in cui arrivano le righe.
	found = [ ...found ].sort(
		( a, b ) => direction * compare( a[ query.sort ], b[ query.sort ] )
	);
	const start =
		( Math.min( query.page, pages( found.length ) ) - 1 ) * PER_PAGE;
	return {
		rows: found.slice( start, start + PER_PAGE ),
		total: found.length,
	};
}
