<?php
// Il passaggio di Auto Alt del prototipo: passa solo la generazione al caricamento. Chiavi, modello, tono, stile e
// registro restano: Barrio Suite usa il service account, il modello e la descrizione del sito dell'AI del core.

defined( 'ABSPATH' ) || exit;

/** @param BarrioMigrationReport $report */
function barrio_migration_alt_run( bool $dry_run, array &$report ): ?WP_Error {
	$names = [ 'auto_upload', 'provider', 'api_key', 'gemini_key', 'model', 'language', 'tone', 'style', 'context', 'keywords', 'log_enabled' ];
	$saved = array_filter( array_combine( $names, array_map( static fn( string $name ): mixed => get_option( "suite_barrio_alt_ai_{$name}" ), $names ) ), static fn( mixed $value ): bool => false !== $value );
	if ( ! $saved && ! barrio_migration_prototype( 'auto_alt' ) ) {
		return new WP_Error( 'barrio_migration_no_prototype', 'There are no Auto Alt settings of Suite Barrio in this database.' );
	}
	// Anche il lavoro in blocco e il registro, che non passano.
	barrio_migration_unknown_options( 'Alt text', [ 'suite_barrio_alt_ai_' ], array_map( static fn( string $name ): string => "suite_barrio_alt_ai_{$name}", [ ...$names, 'bulk_job', 'log' ] ), $report['warnings'] );
	if ( array_diff_key( array_filter( $saved ), array_flip( [ 'auto_upload', 'log_enabled' ] ) ) ) {
		$report['warnings'][] = 'Alt text: the provider, the API keys, the model, the language, the tone, the style, the context and the keywords of Auto Alt are not migrated. Barrio Suite uses the AI of Barrio → Settings: the service account, the model and the description of the site, in the main language of the site.';
	}
	if ( false !== get_option( 'barrio_alt' ) ) {
		$report['notes'][] = 'Alt text: Barrio Suite already has its settings, they stay as they are.';
		return null;
	}
	$on_upload         = (bool) ( $saved['auto_upload'] ?? false );
	$report['notes'][] = 'Alt text: generation on upload ' . ( $on_upload ? 'on' : 'off' ) . ', titles off.';
	if ( ! $dry_run ) {
		update_option(
			'barrio_alt',
			[
				'on_upload' => $on_upload,
				'title'     => false,
			],
			false
		);
	}
	return null;
}
