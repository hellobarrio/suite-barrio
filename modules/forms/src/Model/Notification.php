<?php

namespace BarrioSuite\Forms\Model;

final readonly class Notification {

	/**
	 * @param string                    $recipients Indirizzi separati da virgola, anche con merge tag: è un template.
	 * @param array<string, array{subject: string, heading?: string|null, body: string}> $i18n
	 *        Testi per lingua; heading null = uguale all'oggetto.
	 * @param array<string, mixed>|null $conditions Null = parte sempre.
	 */
	public function __construct(
		public string $name,
		public string $recipients,
		public array $i18n,
		public ?string $reply_to = null,
		public bool $enabled = true,
		public bool $attach_files = false,
		public ?array $conditions = null,
		public ?int $id = null,
	) {}
}
