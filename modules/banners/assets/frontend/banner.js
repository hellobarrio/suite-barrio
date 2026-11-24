// Il banner nel sito (modules/banners/src/Module.php): dopo la sua fine si toglie, perché con la cache di pagina
// l'HTML può essere vecchio; la scritta scorre; la sua altezza va in --barrio-banner-height, per i temi con l'header
// fisso. L'anteprima dell'admin usa start().

// Pixel al secondo.
const SPEED = 50;

/**
 * La scritta che scorre: copie del testo, nascoste agli screen reader, fino a riempire la barra più una, e una Web
 * Animation lunga quanto il testo con il suo spazio, così il giro non salta. Si rifà quando cambiano le misure (i font
 * caricati, la finestra). Col mouse, il focus e il tocco rallenta fino a fermarsi, e poi riparte; il bottone di pausa,
 * che c'è solo mentre scorre, la ferma fino al clic dopo (WCAG 2.2.2). Con prefers-reduced-motion resta ferma
 * (banner.css).
 *
 * @param {HTMLElement} banner
 * @return {() => void} Toglie animazione, copie e bottone.
 */
export function start( banner ) {
	const track = banner.querySelector( '.barrio-banner__track' );
	const text = track.firstElementChild;
	if ( window.matchMedia( '(prefers-reduced-motion: reduce)' ).matches ) {
		return () => {};
	}
	let animation;
	let stopped = false;
	// Mouse e focus, ognuno per conto suo: il focus resta anche quando il mouse esce.
	const holds = new Set();
	let rate = 1;
	let frame;
	const ease = () => {
		const target = stopped || holds.size ? 0 : 1;
		rate += ( target - rate ) * 0.12;
		if ( Math.abs( target - rate ) < 0.01 ) {
			rate = target;
		}
		if ( animation ) {
			animation.playbackRate = rate;
		}
		frame = rate === target ? null : window.requestAnimationFrame( ease );
	};
	const sync = () => {
		window.cancelAnimationFrame( frame );
		ease();
	};
	const run = () => {
		const width = text.offsetWidth;
		if ( ! width ) {
			return;
		}
		const copies = Math.ceil( banner.offsetWidth / width ) + 1;
		while ( track.children.length < copies ) {
			const copy = text.cloneNode( true );
			copy.setAttribute( 'aria-hidden', 'true' );
			track.append( copy );
		}
		while ( track.children.length > copies ) {
			track.lastElementChild.remove();
		}
		const shift =
			'rtl' === window.getComputedStyle( banner ).direction
				? width
				: -width;
		animation?.cancel();
		animation = track.animate(
			[
				{ transform: 'translateX(0)' },
				{ transform: `translateX(${ shift }px)` },
			],
			{ duration: ( width / SPEED ) * 1000, iterations: Infinity }
		);
		animation.playbackRate = rate;
	};
	const observer = new window.ResizeObserver( run );
	observer.observe( banner );
	observer.observe( text );
	const hold = ( reason, on ) => () => {
		if ( on ) {
			holds.add( reason );
		} else {
			holds.delete( reason );
		}
		sync();
	};
	const pause = document.createElement( 'button' );
	banner.addEventListener( 'pointerenter', hold( 'pointer', true ) );
	banner.addEventListener( 'pointerleave', hold( 'pointer', false ) );
	// Il focus sul bottone di pausa non conta: dopo "riprendi" la scritta resterebbe ferma finché non lo si lascia.
	banner.addEventListener(
		'focusin',
		( event ) => event.target !== pause && hold( 'focus', true )()
	);
	banner.addEventListener( 'focusout', hold( 'focus', false ) );
	pause.type = 'button';
	pause.className = 'barrio-banner__pause';
	pause.setAttribute( 'aria-label', banner.dataset.pause );
	pause.setAttribute( 'aria-pressed', 'false' );
	// Pausa e, da fermo, riprendi: banner.css mostra quello giusto.
	pause.innerHTML =
		'<svg viewBox="0 0 24 24" width="16" height="16" fill="currentColor" aria-hidden="true"><path d="M7 5h3v14H7zM14 5h3v14h-3z"/><path d="M8 5v14l11-7z"/></svg>';
	pause.addEventListener( 'click', () => {
		stopped = ! stopped;
		pause.setAttribute( 'aria-pressed', String( stopped ) );
		sync();
	} );
	banner.append( pause );
	return () => {
		observer.disconnect();
		window.cancelAnimationFrame( frame );
		animation?.cancel();
		track.replaceChildren( text );
		pause.remove();
	};
}

const banner = document.getElementById( 'barrio-banner' );
if ( banner && Date.now() / 1000 >= Number( banner.dataset.end ?? Infinity ) ) {
	banner.remove();
} else if ( banner ) {
	// Stampato in fondo, da un tema senza wp_body_open().
	if ( 'late' in banner.dataset ) {
		document.body.prepend( banner );
	}
	start( banner );
	new window.ResizeObserver( () =>
		document.documentElement.style.setProperty(
			'--barrio-banner-height',
			`${ banner.offsetHeight }px`
		)
	).observe( banner );
}
