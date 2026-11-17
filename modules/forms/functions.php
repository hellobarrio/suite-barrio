<?php
// API per le estensioni (docs/hooks.md): esistono solo con il modulo Forms attivo, quindi si
// chiamano da barrio_forms_loaded.

use BarrioSuite\Forms\Integrations;

defined( 'ABSPATH' ) || exit;

/** @param array<string, mixed> $definition label, description, global_settings, form_settings, process. */
function barrio_forms_register_integration( string $slug, array $definition ): void {
	Integrations::register( $slug, $definition );
}

/** @return array<string, mixed> Le impostazioni globali di un'integrazione, con i segreti in chiaro. */
function barrio_forms_integration_settings( string $slug ): array {
	return Integrations::settings( $slug );
}
