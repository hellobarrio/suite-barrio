// La vista dell'elenco delle registrazioni, senza WordPress: la prova tests/js/entries.test.js.
import { initialQuery } from '../../../../../assets/src/admin/query.js';

// Le più recenti per prime.
export const INITIAL = {
	...initialQuery( 'created_at', 'desc' ),
	read: '',
	lang: '',
};

// Senza i filtri vuoti, che il server rifiuterebbe.
export const params = ( query ) =>
	Object.fromEntries(
		Object.entries( query ).filter( ( [ , value ] ) => '' !== value )
	);

// La vista resta per la sessione: tornando dal dettaglio di una registrazione si ritrova.
export const VIEW = 'barrio-entries-view';
export function storedView( form ) {
	try {
		const view = JSON.parse( window.sessionStorage.getItem( VIEW ) );
		if ( form && view?.form === form ) {
			return {
				query: { ...INITIAL, ...view.query },
				shown: view.shown ?? {},
			};
		}
	} catch {}
	return { query: INITIAL, shown: {} };
}
