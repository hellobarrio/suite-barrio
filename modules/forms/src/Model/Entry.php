<?php

namespace BarrioSuite\Forms\Model;

final readonly class Entry {

	/**
	 * @param string                   $lang   La lingua in cui il visitatore ha letto il form.
	 * @param array<int, list<string>> $values Per id del campo: una checkbox ha più valori.
	 * @param list<array{field_id: int, stored_path: string, original_name: string, mime_type: string, size_bytes: int}> $files
	 * @param string|null              $source_url Pagina di invio; query string e frammento si tolgono al salvataggio.
	 * @param string|null              $created_at UTC; null = adesso.
	 * @param int|null                 $id         Null finché la registrazione non è salvata.
	 */
	public function __construct(
		public int $form_id,
		public int $version_id,
		public string $lang,
		public array $values,
		public array $files = [],
		public ?string $source_url = null,
		public ?string $created_at = null,
		public ?int $id = null,
		public bool $is_read = false,
	) {}
}
