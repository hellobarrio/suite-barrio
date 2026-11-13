<?php

namespace BarrioSuite\Forms;

/**
 * Lingue dei form. Il core conosce solo la lingua del sito: un plugin multilingua si collega con
 * un'estensione (extensions/barrio-suite-wpml) che usa i filtri barrio_forms_languages,
 * barrio_forms_current_language, barrio_forms_locale e, per l'admin, barrio_forms_language_flags.
 * Senza estensione le traduzioni salvate restano nel database e tornano attive con l'estensione.
 */
final class I18n {

	/** @return non-empty-list<string> Codici in minuscolo (it, en, pt-br), la lingua principale per prima. */
	public static function languages(): array {
		$site      = self::code( get_locale() );
		$languages = array_map( static fn( mixed $code ): string => self::code( (string) $code ), (array) apply_filters( 'barrio_forms_languages', [ $site ] ) );
		return array_values( array_unique( $languages ) ) ?: [ $site ];
	}

	// La lingua della pagina che si sta mostrando.
	public static function current(): string {
		return self::resolve( apply_filters( 'barrio_forms_current_language', null ) );
	}

	// La lingua richiesta se è attiva, altrimenti la principale.
	public static function resolve( mixed $lang ): string {
		$languages = self::languages();
		$lang      = is_string( $lang ) ? self::code( $lang ) : null;
		return in_array( $lang, $languages, true ) ? (string) $lang : $languages[0];
	}

	/** @return array<string, string> L'immagine della bandiera per codice, per le lingue che ne hanno una. */
	public static function flags(): array {
		$flags = [];
		foreach ( (array) apply_filters( 'barrio_forms_language_flags', [] ) as $code => $url ) {
			$flags[ self::code( (string) $code ) ] = esc_url_raw( (string) $url );
		}
		return array_filter( $flags );
	}

	// Il locale di WordPress di una lingua (en-us → en_US); senza estensione quello del sito.
	public static function locale( string $lang ): string {
		return (string) apply_filters( 'barrio_forms_locale', get_locale(), $lang );
	}

	/**
	 * $callback con WordPress e il plugin tradotti nella lingua $lang: messaggi standard, date, "Yes"
	 * del consenso. Se la traduzione di WordPress per quella lingua non è installata, resta il locale attuale.
	 *
	 * @template T
	 * @param callable(): T $callback
	 * @return T
	 */
	public static function in( string $lang, callable $callback ): mixed {
		// false anche quando è già il locale attuale: allora non c'è niente da ripristinare.
		$switched = switch_to_locale( self::locale( $lang ) );
		try {
			return $callback();
		} finally {
			if ( $switched ) {
				restore_previous_locale();
			}
		}
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

	// Un locale di WordPress (it_IT) diventa la sola lingua; i codici come pt-br restano come sono.
	private static function code( string $code ): string {
		return strtolower( str_contains( $code, '_' ) ? (string) strtok( $code, '_' ) : $code );
	}
}
