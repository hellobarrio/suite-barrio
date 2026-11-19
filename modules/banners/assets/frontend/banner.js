// I banner nel sito (modules/banners/src/Module.php): toglie quelli chiusi di recente o fuori dal periodo, fa scorrere la
// scritta e scrive l'altezza di quello mostrato in --barrio-banner-height, per i temi con l'header fisso.
// eslint-disable-next-line import/no-unresolved -- lo risolve l'import map di WordPress.
import { remember, visible } from '@barrio/visibility';

// Pixel al secondo.
const SPEED = 50;

/**
 * La scritta che scorre: copie del testo, nascoste agli screen reader, fino a riempire la barra più una, e una Web
 * Animation lunga quanto il testo con il suo spazio, così il giro non salta. Si rifà quando cambiano le misure (i font
 * caricati, la finestra). Si ferma col mouse, col focus e col tocco; senza link un clic la ferma fino al clic dopo.
 * Con prefers-reduced-motion resta ferma (banner.css).
 *
 * @param {HTMLElement} banner
 * @return {() => void} Toglie animazione e copie: per l'anteprima dell'admin.
 */
export function start( banner ) {
	const track = banner.querySelector( '.barrio-banner__track' );
	const text = track.firstElementChild;
	if (
		! banner.classList.contains( 'is-scroll' ) ||
		window.matchMedia( '(prefers-reduced-motion: reduce)' ).matches
	) {
		return () => {};
	}
	let animation;
	let stopped = false;
	let held = false;
	const sync = () =>
		stopped || held ? animation?.pause() : animation?.play();
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
		sync();
	};
	const observer = new window.ResizeObserver( run );
	observer.observe( banner );
	observer.observe( text );
	const hold = ( on ) => () => {
		held = on;
		sync();
	};
	banner.addEventListener( 'pointerenter', hold( true ) );
	banner.addEventListener( 'pointerleave', hold( false ) );
	banner.addEventListener( 'focusin', hold( true ) );
	banner.addEventListener( 'focusout', hold( false ) );
	if ( ! banner.querySelector( 'a' ) ) {
		banner.addEventListener( 'click', () => {
			stopped = ! stopped;
			sync();
		} );
	}
	return () => {
		observer.disconnect();
		animation?.cancel();
		track.replaceChildren( text );
	};
}

const banners = [
	...document.querySelectorAll( '.barrio-banner[data-barrio-visibility]' ),
];

// Il CSS nasconde quello dell'altro dispositivo: conta quello che ha un'altezza. In fondo serve anche lo spazio sotto
// la pagina, perché il banner non copra il footer.
function measure() {
	const shown = banners.find(
		( banner ) => banner.isConnected && banner.offsetHeight
	);
	document.documentElement.style.setProperty(
		'--barrio-banner-height',
		`${ shown?.offsetHeight ?? 0 }px`
	);
	document.documentElement.classList.toggle(
		'barrio-banner-bottom',
		!! shown?.classList.contains( 'is-bottom' )
	);
}

const resize = new window.ResizeObserver( measure );
for ( const banner of banners ) {
	if ( ! visible( banner ) ) {
		banner.remove();
		continue;
	}
	start( banner );
	resize.observe( banner );
	banner
		.querySelector( '.barrio-banner__close' )
		?.addEventListener( 'click', () => {
			remember( banner );
			banner.remove();
			measure();
		} );
}
measure();
