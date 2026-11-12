/**
 * Form di Barrio Suite: logica condizionale, validazione accessibile, multistep e invio alla REST API.
 *
 * Eventi sul form, per esempio per GTM: barrio:success e barrio:error, con la risposta in detail.
 * I captcha delle estensioni si agganciano con window.barrioForms.beforeSubmit( fn ):
 * fn( form, data ) può aggiungere valori a data (FormData) e restituire una Promise.
 */
// eslint-disable-next-line import/no-unresolved -- lo risolve l'import map di WordPress.
import { visibility } from '@barrio/forms-conditions';

const beforeSubmit = [];
window.barrioForms = { beforeSubmit: ( fn ) => beforeSubmit.push( fn ) };

const CONTROLS = 'input, select, textarea';
const controls = ( el ) => [ ...el.querySelectorAll( CONTROLS ) ];

function init( form ) {
	const fields = [ ...form.querySelectorAll( '[data-barrio-field]' ) ].map(
		( el ) => ( {
			el,
			key: el.dataset.barrioField,
			conditions: el.dataset.conditions
				? JSON.parse( el.dataset.conditions )
				: null,
		} )
	);
	const steps = [ ...form.querySelectorAll( '[data-barrio-step]' ) ];
	const status = form.querySelector( '.barrio-form__message' );
	const messages = JSON.parse( form.dataset.messages );
	const params = new URLSearchParams( window.location.search );
	let current = 0;

	// Senza JS valida il browser; con il JS i messaggi compaiono nel form, accanto ai campi.
	form.noValidate = true;
	form.querySelector( '[name="_barrio_js"]' ).value = '1';
	// Senza query string né frammento: possono contenere dati personali.
	form.querySelector( '[name="_barrio_url"]' ).value =
		window.location.origin + window.location.pathname;
	form.querySelectorAll( 'input[data-param]' ).forEach( ( input ) => {
		input.value = params.get( input.dataset.param ) ?? '';
	} );

	// Come FieldTypes::normalize(): una lista di valori non vuoti per campo.
	function values() {
		const result = {};
		for ( const { el, key } of fields ) {
			result[ key ] = controls( el )
				.flatMap( ( control ) => {
					if (
						control.type === 'checkbox' ||
						control.type === 'radio'
					) {
						return control.checked ? [ control.value ] : [];
					}
					if ( control.type === 'file' ) {
						return [ ...control.files ].map(
							( file ) => file.name
						);
					}
					// Su una riga gli spazi si comprimono, come sul server.
					return [
						control.tagName === 'TEXTAREA'
							? control.value.trim()
							: control.value.trim().replace( /\s+/g, ' ' ),
					];
				} )
				.filter( ( value ) => value !== '' );
		}
		return result;
	}

	// Un campo nascosto è disabilitato: non si valida e non si invia, come sul server.
	function refresh() {
		const visible = visibility( fields, values() );
		for ( const { el, key } of fields ) {
			el.hidden = ! visible[ key ];
			controls( el ).forEach( ( control ) => {
				control.disabled = el.hidden;
			} );
		}
	}

	// Il messaggio d'errore del campo, o '' se è valido.
	function error( el ) {
		const list = controls( el );
		const invalid = list.find( ( c ) => ! c.checkValidity() );
		// Gli altri messaggi nativi sono nella lingua del browser; questo, il più frequente, in
		// quella della pagina, come gli errori che arrivano dal server.
		let message = invalid?.validity.valueMissing
			? messages.required
			: invalid?.validationMessage;
		// required su una checkbox vorrebbe dire "tutte": "almeno una" si controlla qui.
		if (
			el.querySelector( 'fieldset[data-required]' ) &&
			! list.some( ( c ) => c.checked )
		) {
			message = messages.required;
		}
		if (
			list.some(
				( c ) => c.files?.[ 0 ]?.size > Number( c.dataset.maxSize )
			)
		) {
			message = messages.fileSize;
		}
		return message ? el.dataset.errorMessage || message : '';
	}

	function show( el, message ) {
		el.querySelector( '.barrio-form__error' ).textContent = message;
		controls( el ).forEach( ( control ) => {
			if ( message ) {
				control.setAttribute( 'aria-invalid', 'true' );
			} else {
				control.removeAttribute( 'aria-invalid' );
			}
		} );
	}

	// Mostra gli errori dello step e porta il focus sul primo campo da correggere.
	function validate( step ) {
		let first = null;
		for ( const { el } of fields ) {
			if ( ! el.hidden && step.contains( el ) ) {
				const message = error( el );
				show( el, message );
				first ??= message ? el : null;
			}
		}
		first?.querySelector( CONTROLS )?.focus();
		return ! first;
	}

	function go( index, focus = true ) {
		current = index;
		steps.forEach( ( step, i ) => {
			step.hidden = i !== index;
		} );
		if ( focus ) {
			controls( steps[ index ] )
				.find( ( c ) => ! c.disabled && c.type !== 'hidden' )
				?.focus();
		}
	}

	// Gli errori per campo arrivano con le chiavi dei campi: si torna allo step del primo.
	function failure( body ) {
		const errors = body.data?.fields ?? {};
		const failed = fields.filter( ( f ) => Object.hasOwn( errors, f.key ) );
		failed.forEach( ( f ) => show( f.el, errors[ f.key ] ) );
		const step = failed.length
			? steps.findIndex( ( s ) => s.contains( failed[ 0 ].el ) )
			: current;
		if ( step !== current ) {
			go( step );
		}
		status.textContent = body.message ?? messages.error;
		form.dispatchEvent(
			new CustomEvent( 'barrio:error', { bubbles: true, detail: body } )
		);
	}

	async function submit() {
		if (
			form.getAttribute( 'aria-busy' ) === 'true' ||
			! validate( steps[ current ] )
		) {
			return;
		}
		form.setAttribute( 'aria-busy', 'true' );
		status.textContent = '';
		try {
			const data = new FormData( form );
			for ( const fn of beforeSubmit ) {
				await fn( form, data );
			}
			const response = await fetch( form.action, {
				method: 'POST',
				body: data,
			} );
			const body = await response.json();
			if ( ! response.ok ) {
				failure( body );
				return;
			}
			form.dispatchEvent(
				new CustomEvent( 'barrio:success', {
					bubbles: true,
					detail: body,
				} )
			);
			if ( body.redirect ) {
				window.location.assign( body.redirect );
				return;
			}
			steps.forEach( ( step ) => {
				step.hidden = true;
			} );
			status.textContent = body.message;
		} catch {
			// Rete assente, risposta non JSON, un beforeSubmit fallito.
			failure( { message: messages.error } );
		} finally {
			form.removeAttribute( 'aria-busy' );
		}
	}

	form.addEventListener( 'input', ( event ) => {
		refresh();
		// Un campo con un errore si riconvalida mentre lo si corregge. Non sul change, che arriva
		// quando si esce dal campo: l'errore che sparisce sposta il layout sotto il puntatore.
		const el = event.target.closest( '[data-barrio-field]' );
		if ( el?.querySelector( '.barrio-form__error' ).textContent ) {
			show( el, error( el ) );
		}
	} );
	form.addEventListener( 'click', ( event ) => {
		if (
			event.target.closest( '[data-barrio-next]' ) &&
			validate( steps[ current ] )
		) {
			go( current + 1 );
		}
		if ( event.target.closest( '[data-barrio-back]' ) ) {
			go( current - 1 );
		}
	} );
	form.addEventListener( 'submit', ( event ) => {
		event.preventDefault();
		submit();
	} );

	refresh();
	go( 0, false );
}

document.querySelectorAll( 'form[data-barrio-form]' ).forEach( init );
