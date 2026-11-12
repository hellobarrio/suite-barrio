<?php

namespace BarrioSuite\Forms\Model;

final readonly class Field {

	/**
	 * @param array<string, array{label: string, placeholder?: string|null, error_message?: string|null, options?: array<string, string>|null}> $i18n
	 *        Testi per lingua, compresa la principale. options è {valore: etichetta}: l'ordine lo dà config, non le chiavi.
	 * @param array<string, mixed>      $config     Dipende dal tipo e non si traduce (valori delle scelte, limiti, pattern…).
	 * @param array<string, mixed>|null $conditions Logica condizionale; null = sempre visibile.
	 * @param int|null                  $id         Null finché il campo non è salvato.
	 */
	public function __construct(
		public string $key,
		public string $type,
		public array $i18n,
		public bool $required = false,
		public array $config = [],
		public ?array $conditions = null,
		public ?int $id = null,
	) {}
}
