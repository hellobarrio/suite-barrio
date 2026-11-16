<?php

namespace BarrioSuite\Forms\Model;

// Le impostazioni di un'integrazione (registrata da un'estensione) per un form.
final readonly class Integration {

	/**
	 * @param string                              $slug     Lo slug con cui l'estensione si registra.
	 * @param array<string, mixed>                $settings Uguali in tutte le lingue.
	 * @param array<string, array<string, mixed>> $i18n     Per lingua, le impostazioni marcate translatable.
	 * @param array<string, mixed>|null           $conditions Null = parte sempre.
	 */
	public function __construct(
		public string $slug,
		public array $settings = [],
		public array $i18n = [],
		public bool $enabled = true,
		public ?array $conditions = null,
	) {}
}
