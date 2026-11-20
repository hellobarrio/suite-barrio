// Le tacche dell'asse verticale dell'andamento: da 0 a un massimo tondo sopra il valore più alto, in 4 intervalli
// uguali di 1, 2 o 5 per una potenza di 10. Visualizzazioni e utenti sono interi, quindi l'intervallo è almeno 1: senza
// dati l'asse va da 0 a 4.
export function scale( max, intervals = 4 ) {
	const rough = Math.max( max / intervals, 1 );
	const power = 10 ** Math.floor( Math.log10( rough ) );
	const step = [ 1, 2, 5, 10 ]
		.map( ( factor ) => factor * power )
		.find( ( size ) => size >= rough );
	return Array.from(
		{ length: intervals + 1 },
		( _, index ) => index * step
	);
}
