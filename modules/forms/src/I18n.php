<?php

namespace BarrioSuite\Forms;

/**
 * Lingue dei form. Con WPML vengono dai suoi filtri; senza, i filtri restituiscono il default e
 * resta solo la lingua del sito. Un'estensione può aggiungere altre fonti con barrio_forms_languages.
 */
final class I18n {

	/** @return non-empty-list<string> Codici in minuscolo (it, en, pt-br), la lingua principale per prima. */
	public static function languages(): array {
		$default   = apply_filters( 'wpml_default_language', null ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- filtro di WPML.
		$languages = [ is_string( $default ) ? $default : get_locale() ];
		foreach ( array_keys( (array) apply_filters( 'wpml_active_languages', null, [ 'skip_missing' => 0 ] ) ) as $code ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- filtro di WPML.
			$languages[] = (string) $code;
		}
		/** @var non-empty-list<string> */
		return apply_filters( 'barrio_forms_languages', array_values( array_unique( array_map( [ self::class, 'code' ], $languages ) ) ) );
	}

	// La lingua della pagina che si sta mostrando.
	public static function current(): string {
		$current = apply_filters( 'wpml_current_language', null ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- filtro di WPML.
		return self::resolve( is_string( $current ) ? $current : null );
	}

	// La lingua richiesta se è attiva, altrimenti la principale.
	public static function resolve( ?string $lang ): string {
		$languages = self::languages();
		return in_array( $lang, $languages, true ) ? (string) $lang : $languages[0];
	}

	/**
	 * I testi nella lingua richiesta; se mancano, quelli della lingua principale o i primi che ci sono.
	 *
	 * @template T of array<string, mixed>
	 * @param array<string, T> $i18n
	 * @return T|array{}
	 */
	public static function pick( array $i18n, string $lang ): array {
		return $i18n[ $lang ] ?? $i18n[ self::languages()[0] ] ?? ( reset( $i18n ) ?: [] );
	}

	// Un locale di WordPress (it_IT) diventa la sola lingua; i codici di WPML (pt-br) restano come sono.
	private static function code( string $code ): string {
		return strtolower( str_contains( $code, '_' ) ? (string) strtok( $code, '_' ) : $code );
	}
}
