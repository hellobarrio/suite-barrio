<?php

namespace BarrioSuite;

// Segreti salvati nelle opzioni (password SMTP, chiavi API delle integrazioni), cifrati con una chiave
// derivata dai salt di WordPress: se i salt cambiano non si leggono più e vanno inseriti di nuovo.
// sodium c'è sempre: WordPress include sodium_compat dove manca l'estensione.
final class Crypto {

	public static function encrypt( string $plain ): string {
		$nonce = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		return base64_encode( $nonce . sodium_crypto_secretbox( $plain, $nonce, self::key() ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- testo cifrato da salvare in un'opzione.
	}

	// Null se non si legge: salvato con altri salt, o non cifrato da qui.
	public static function decrypt( string $stored ): ?string {
		$raw = base64_decode( $stored, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		if ( false === $raw || strlen( $raw ) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ) {
			return null;
		}
		$plain = sodium_crypto_secretbox_open( substr( $raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ), substr( $raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ), self::key() );
		return false === $plain ? null : $plain;
	}

	private static function key(): string {
		return sodium_crypto_generichash( wp_salt( 'auth' ), '', SODIUM_CRYPTO_SECRETBOX_KEYBYTES );
	}
}
