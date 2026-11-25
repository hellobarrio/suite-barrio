<?php
// Il passaggio dei blocchi del prototipo: la sua lista dei blocchi ammessi per chi non è amministratore diventa, ruolo
// per ruolo, la lista dei blocchi disattivati, con le restrizioni che il prototipo applicava.

defined( 'ABSPATH' ) || exit;

/** @param BarrioMigrationReport $report */
function barrio_migration_blocks_run( bool $dry_run, array &$report ): ?WP_Error {
	$saved = get_option( 'suite_barrio_blocks_settings' );
	if ( false === $saved && ! barrio_migration_prototype( 'blocks' ) ) {
		return new WP_Error( 'barrio_migration_no_prototype', 'There are no block settings of Suite Barrio in this database.' );
	}
	barrio_migration_unknown_options( 'Blocks', [ 'suite_barrio_blocks_' ], [ 'suite_barrio_blocks_settings' ], $report['warnings'] );
	barrio_migration_unknown( 'Blocks, suite_barrio_blocks_settings', (array) $saved, [ 'enabled', 'allowed', 'gutenberg_post_types' ], $report['warnings'] );
	if ( false !== get_option( 'barrio_blocks' ) ) {
		$report['notes'][] = 'Blocks: Barrio Suite already has its settings, they stay as they are.';
		return null;
	}
	// I default del prototipo valgono anche senza l'opzione: la restrizione era accesa con 5 blocchi.
	$settings = array_merge(
		[
			'enabled' => true,
			'allowed' => [ 'core/paragraph', 'core/list', 'core/heading', 'core/separator', 'core/embed' ],
		],
		(array) $saved
	);
	$allowed  = array_filter( (array) $settings['allowed'], 'is_string' );

	// Come la pagina Contenuti → Blocchi: i blocchi figli (voce di elenco, colonna) seguono il loro blocco, quindi
	// tornano disponibili anche dove il prototipo li toglieva. Quelli di Barrio Suite il prototipo non li conosceva:
	// come ogni blocco nuovo restano disponibili, anche se il loro modulo si accende prima di questa migrazione.
	$blocks = [];
	foreach ( WP_Block_Type_Registry::get_instance()->get_all_registered() as $block ) {
		if ( ! $block->parent && ! $block->ancestor && false !== ( $block->supports['inserter'] ?? true ) && ! str_starts_with( $block->name, 'barrio/' ) ) {
			$blocks[] = $block->name;
		}
	}
	// Una lista vuota non restringeva i blocchi.
	$disabled = $settings['enabled'] && $allowed ? array_values( array_diff( $blocks, $allowed ) ) : [];

	// Chi ha manage_options non aveva restrizioni. I pannelli il prototipo li nascondeva anche con la restrizione
	// spenta; colori e tipografia li toglieva dai blocchi di testo, qui dall'editor.
	$roles = [];
	foreach ( wp_roles()->role_objects as $name => $role ) {
		if ( ! $role->has_cap( 'manage_options' ) ) {
			$roles[ $name ] = [
				'disabled' => $disabled,
				'h1'       => (bool) $settings['enabled'],
				'styles'   => (bool) $settings['enabled'],
				'code'     => false,
				'lock'     => false,
				'panels'   => true,
			];
		}
	}

	// Un tipo senza "editor Gutenberg" perdeva il supporto all'editor: nessun editor.
	$editors = [];
	if ( is_array( $settings['gutenberg_post_types'] ?? null ) ) {
		foreach ( get_post_types( [ 'public' => true ] ) as $type ) {
			if ( 'attachment' !== $type && ! in_array( $type, $settings['gutenberg_post_types'], true ) ) {
				$editors[ $type ] = 'none';
			}
		}
	}

	$report['counts'][] = [
		'data'     => 'disabled blocks',
		'found'    => count( $disabled ),
		'migrated' => count( $disabled ),
		'skipped'  => 0,
	];
	$report['notes'][]  = 'Blocks: restrictions for ' . ( implode( ', ', array_keys( $roles ) ) ?: 'no role' ) . ( $settings['enabled'] ? ' (disabled blocks, no H1, no custom colors and typography, hidden panels).' : ' (hidden panels).' );
	if ( $editors ) {
		$report['notes'][] = 'Blocks: no editor for ' . implode( ', ', array_keys( $editors ) ) . '.';
	}
	if ( ! $dry_run ) {
		update_option(
			'barrio_blocks',
			[
				'roles'           => $roles,
				'editors'         => $editors,
				'remote_patterns' => true,
				'openverse'       => true,
			],
			false
		);
	}
	return null;
}
