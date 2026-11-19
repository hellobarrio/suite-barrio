// Gli orari nel sito (modules/hours/src/Render.php). Oggi e lo stato si riscrivono con l'ora del browser, sui dati dei
// prossimi giorni (Render::window()), così restano giusti con la cache di pagina; i giorni speciali passati spariscono.

// Come sprintf per %s e %1$s.
const format = ( text, ...args ) => {
	let next = 0;
	return text.replace( /%(?:(\d)\$)?s/g, ( match, position ) =>
		position ? args[ position - 1 ] : args[ next++ ]
	);
};

// Il giorno dei dati in cui cade un momento: l'ultimo già iniziato.
const dayOf = ( days, time ) => days.findLastIndex( ( day ) => day.at <= time );

/**
 * Aperto o chiuso a now, con il testo: lo stesso calcolo di Render::status(), sugli stessi dati
 * (tests/fixtures/hours-status.json).
 *
 * @param {Object} data      Da Render::window().
 * @param {Array}  data.days I giorni: at (secondi), name.
 * @param {Array}  data.open I periodi aperti: inizio, fine, le loro ore scritte.
 * @param {Object} data.text I testi dello stato.
 * @param {number} now       In secondi.
 * @return {{open: boolean, text: string}|null} Null fuori dai giorni dei dati.
 */
export function status( { days, open, text }, now ) {
	const today = dayOf( days, now );
	const last = days.length - 1;
	if ( today < 0 || today >= last ) {
		return null;
	}
	for ( const [ start, end, opens, closes ] of open ) {
		if ( start <= now && now < end ) {
			const on = dayOf( days, end );
			let message = format( text.closesOn, days[ on ].name, closes );
			if ( start <= days[ 0 ].at && end >= days[ last ].at ) {
				message = text.always;
			} else if ( on <= today + 1 ) {
				message = format( text.closes, closes );
			}
			return { open: true, text: message };
		}
		if ( start > now ) {
			const on = dayOf( days, start );
			let message = format( text.opensOn, days[ on ].name, opens );
			if ( on === today ) {
				message = format( text.opens, opens );
			} else if ( on === today + 1 ) {
				message = format( text.opensTomorrow, opens );
			}
			return { open: false, text: message };
		}
	}
	return { open: false, text: text.closed };
}

if ( typeof document !== 'undefined' ) {
	const now = Date.now() / 1000;
	for ( const element of document.querySelectorAll(
		'.barrio-hours[data-hours]'
	) ) {
		const data = JSON.parse( element.dataset.hours );
		if ( element.classList.contains( 'is-status' ) ) {
			const result = status( data, now );
			if ( result ) {
				element.firstElementChild.textContent = result.text;
				element.classList.toggle( 'is-open', result.open );
				element.classList.toggle( 'is-closed', ! result.open );
			}
		} else {
			const day = dayOf( data.days, now );
			if ( day >= 0 && day < data.days.length - 1 ) {
				element.textContent = data.days[ day ].today;
			}
		}
	}
	for ( const item of document.querySelectorAll(
		'.barrio-hours [data-until]'
	) ) {
		if ( now >= Number( item.dataset.until ) ) {
			item.remove();
		}
	}
	for ( const table of document.querySelectorAll(
		'.barrio-hours__special'
	) ) {
		if ( ! table.querySelector( 'tr' ) ) {
			table.remove();
		}
	}
}
