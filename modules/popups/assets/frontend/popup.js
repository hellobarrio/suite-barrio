// Il popup nel sito (modules/popups/src/Module.php). Il server l'ha già scelto per il periodo; qui si ricontrollano il
// periodo, perché con la cache di pagina l'HTML può essere vecchio, e la chiusura, che sta solo nel browser: la data in
// localStorage non va al server e non è un cookie.

const DAY = 86400000;

/**
 * Se il popup si apre da solo adesso. Senza WordPress: lo prova tests/js/popup.test.js.
 *
 * @param {Object}      rules       Da Module::html().
 * @param {number|null} rules.start In secondi; null senza inizio.
 * @param {number|null} rules.end   In secondi; null senza fine.
 * @param {number}      rules.days  Dopo la chiusura non torna per tanti giorni.
 * @param {number}      now         In millisecondi.
 * @param {number|null} closed      Quando chi guarda l'ha chiuso, in millisecondi; Infinity dopo un form inviato.
 * @return {boolean} Aprirlo.
 */
export const allowed = ( { start, end, days }, now, closed ) =>
	( ! start || now >= start * 1000 ) &&
	( ! end || now < end * 1000 ) &&
	( ! closed || now - closed >= days * DAY );

// localStorage può mancare (navigazione privata di vecchi Safari, cookie bloccati): allora torna sempre.
function closedAt( key ) {
	try {
		return Number( window.localStorage.getItem( key ) ) || null;
	} catch {
		return null;
	}
}

function remember( key, value ) {
	try {
		window.localStorage.setItem( key, String( value ) );
	} catch {}
}

// showModal() rende inerte il resto della pagina, Esc chiude e il browser rimette il focus dove era.
function open( dialog ) {
	if ( ! dialog.open ) {
		dialog.dataset.barrioOpened = '';
		dialog.showModal();
	}
}

function setup( dialog ) {
	const rules = JSON.parse( dialog.dataset.barrioPopupRules );
	const { trigger } = rules;
	let sent = false;
	const show = () => {
		if (
			! ( 'barrioOpened' in dialog.dataset ) &&
			allowed( rules, Date.now(), closedAt( rules.key ) )
		) {
			open( dialog );
		}
	};

	dialog
		.querySelector( '.barrio-popup__close' )
		.addEventListener( 'click', () => dialog.close() );
	// Un clic sullo sfondo arriva al dialog, non al suo contenuto.
	dialog.addEventListener( 'click', ( event ) => {
		if ( event.target === dialog ) {
			dialog.close();
		}
	} );
	dialog.addEventListener( 'close', () => {
		// Con 0 giorni torna alla pagina dopo: niente da ricordare.
		if ( ! sent && rules.days ) {
			remember( rules.key, Date.now() );
		}
	} );
	// Un form inviato nel popup: non torna più, e si chiude dopo il messaggio.
	dialog.addEventListener( 'barrio:success', () => {
		sent = true;
		remember( rules.key, Infinity );
		setTimeout( () => dialog.close(), 4000 );
	} );

	if ( 'delay' === trigger.type ) {
		setTimeout( show, trigger.delay * 1000 );
	} else if ( 'scroll' === trigger.type ) {
		const scrolled = () => {
			const max =
				document.documentElement.scrollHeight - window.innerHeight;
			if (
				max <= 0 ||
				( window.scrollY / max ) * 100 >= trigger.scroll
			) {
				window.removeEventListener( 'scroll', scrolled );
				show();
			}
		};
		window.addEventListener( 'scroll', scrolled, { passive: true } );
		scrolled();
	} else {
		// All'uscita: il mouse che lascia la finestra dall'alto, quindi solo sui computer.
		document.addEventListener( 'mouseout', function left( event ) {
			if ( ! event.relatedTarget && event.clientY <= 0 ) {
				document.removeEventListener( 'mouseout', left );
				show();
			}
		} );
	}
}

// Nei test (node --test) non c'è il DOM.
if ( typeof document !== 'undefined' ) {
	document.querySelectorAll( '.barrio-popup' ).forEach( setup );

	// Un link a #barrio-popup-ID o un elemento con data-barrio-popup="ID" lo apre sempre, anche già chiuso.
	const target = ( id ) => document.getElementById( `barrio-popup-${ id }` );
	document.addEventListener( 'click', ( event ) => {
		const trigger = event.target.closest(
			'[data-barrio-popup], a[href*="#barrio-popup-"]'
		);
		const dialog =
			trigger &&
			target(
				trigger.dataset.barrioPopup ??
					trigger.hash.replace( '#barrio-popup-', '' )
			);
		if ( dialog ) {
			event.preventDefault();
			open( dialog );
		}
	} );
	const linked = window.location.hash.match( /^#barrio-popup-(\d+)$/ );
	if ( linked && target( linked[ 1 ] ) ) {
		open( target( linked[ 1 ] ) );
	}
}
