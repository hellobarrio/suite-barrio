/**
 * reCAPTCHA Enterprise sui form di Barrio Suite: prima di ogni invio chiede a Google un token
 * invisibile e lo aggiunge ai dati come _barrio_recaptcha, che il server verifica. Ogni errore (script
 * di Google bloccato, chiave non valida) fa fallire l'invio con il messaggio d'errore del form.
 */
// Prima il form, che definisce window.barrioForms. Lo risolve l'import map di WordPress.
// eslint-disable-next-line import/no-unresolved
import '@barrio/forms';

const { siteKey, action } = window.barrioRecaptcha;

const ready = () =>
	new Promise( ( resolve ) => window.grecaptcha.enterprise.ready( resolve ) );

// Con una chiave non valida execute() lancia l'errore invece di rifiutare la Promise: dentro una
// funzione async diventa comunque un rifiuto, e il form non resta in attesa.
window.barrioForms.beforeSubmit( async ( form, data ) => {
	await ready();
	data.set(
		'_barrio_recaptcha',
		await window.grecaptcha.enterprise.execute( siteKey, { action } )
	);
} );
