// Le regole di popup e banner nel browser (src/Visibility.php). Il server ha già scelto pagina, utente e periodo; qui si
// ricontrollano il periodo, perché con la cache di pagina l'HTML può essere vecchio, e la chiusura, che sta solo nel
// browser: la data in localStorage non va al server e non è un cookie.

const DAY = 86400000;

/**
 * Se l'elemento si mostra adesso. Senza WordPress: lo prova tests/js/visibility.test.js.
 *
 * @param {Object}      rules       Da Visibility::data().
 * @param {number|null} rules.start In secondi; null senza inizio.
 * @param {number|null} rules.end   In secondi; null senza fine.
 * @param {number}      rules.days  Dopo la chiusura non torna per tanti giorni.
 * @param {number}      now         In millisecondi.
 * @param {number|null} closed      Quando chi guarda l'ha chiuso, in millisecondi.
 * @return {boolean} Mostrarlo.
 */
export const allowed = ( { start, end, days }, now, closed ) =>
	( ! start || now >= start * 1000 ) &&
	( ! end || now < end * 1000 ) &&
	( ! closed || now - closed >= days * DAY );

const rules = ( element ) => JSON.parse( element.dataset.barrioVisibility );

// localStorage può mancare (navigazione privata di vecchi Safari, cookie bloccati): allora torna sempre.
function closedAt( key ) {
	try {
		return Number( window.localStorage.getItem( key ) ) || null;
	} catch {
		return null;
	}
}

export const visible = ( element ) => {
	const { key, ...rest } = rules( element );
	return allowed( rest, Date.now(), closedAt( key ) );
};

// Con 0 giorni torna alla pagina dopo: niente da ricordare.
export function remember( element ) {
	const { key, days } = rules( element );
	try {
		if ( days ) {
			window.localStorage.setItem( key, String( Date.now() ) );
		}
	} catch {}
}
