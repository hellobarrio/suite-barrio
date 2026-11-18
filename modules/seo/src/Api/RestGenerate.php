<?php

namespace BarrioSuite\Seo\Api;

use BarrioSuite\Ai;
use BarrioSuite\I18n;
use BarrioSuite\Seo\Llms;
use BarrioSuite\Seo\Meta;
use BarrioSuite\Seo\Settings;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * "Genera con AI" (src/Ai.php): titolo, descrizione e parole chiave di una pagina per il pannello e la tabella
 * Contenuti, e l'introduzione di llms.txt. Qui non si salva niente: il pannello e la tabella salvano i meta come sempre.
 */
final class RestGenerate {

	public static function register(): void {
		$text = [
			'type'    => 'string',
			'default' => '',
		];
		register_rest_route(
			'barrio/v1',
			'/seo/generate',
			[
				'methods'             => 'POST',
				'callback'            => [ self::class, 'meta' ],
				'permission_callback' => static fn(): bool => current_user_can( 'barrio_edit_seo' ),
				'args'                => [
					'lang'  => $text,
					'title' => $text,
					'url'   => $text,
					'text'  => $text,
				],
			]
		);
		register_rest_route(
			'barrio/v1',
			'/seo/generate/llms',
			[
				'methods'             => 'POST',
				'callback'            => [ self::class, 'llms' ],
				'permission_callback' => static fn(): bool => current_user_can( 'manage_options' ),
				'args'                => [ 'lang' => $text ],
			]
		);
	}

	// Dal testo che manda il pannello, anche non salvato.
	public static function meta( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$lang = I18n::resolve( $request['lang'] );
		$site = I18n::in( $lang, static fn(): string => Meta::text( get_bloginfo( 'name' ) ) );
		// Il <title> completo, con il separatore e il nome del sito, sta in 60 caratteri.
		$max  = max( 30, 60 - mb_strlen( ' ' . Settings::get()['title']['separator'] . " {$site}" ) );
		$text = mb_substr( Meta::text( (string) $request['text'] ), 0, 4000 );
		$json = Ai::generate(
			'Write the SEO title, the meta description and the keywords of this web page, in the language of the locale ' . I18n::locale( $lang ) . ".\n"
			. "- title: at most {$max} characters, with the main keyword near the beginning, without the site name \"{$site}\": it is added after the title.\n"
			. "- description: 120 to 160 characters, with the main keyword, written to make people click.\n"
			. "- keywords: 5 to 8 phrases that people search to find this page, the main one first.\n\n"
			. 'Title: ' . Meta::text( (string) $request['title'] ) . "\nURL: {$request['url']}\nText:\n{$text}",
			[
				'responseMimeType' => 'application/json',
				'responseSchema'   => [
					'type'       => 'OBJECT',
					'properties' => [
						'title'       => [ 'type' => 'STRING' ],
						'description' => [ 'type' => 'STRING' ],
						'keywords'    => [
							'type'  => 'ARRAY',
							'items' => [ 'type' => 'STRING' ],
						],
					],
					'required'   => [ 'title', 'description', 'keywords' ],
				],
			]
		);
		$json = is_wp_error( $json ) ? $json : json_decode( $json, true );
		if ( ! is_array( $json ) ) {
			return $json instanceof WP_Error ? $json : new WP_Error( 'barrio_ai_json', __( 'The answer of Google is not valid. Try again, or choose another model.', 'barrio-suite' ), [ 'status' => 502 ] );
		}
		$title = sanitize_text_field( is_string( $json['title'] ?? null ) ? $json['title'] : '' );
		// Il nome del sito lo aggiunge il <title>, ma i modelli a volte lo ripetono, staccato da un separatore
		// ("Camere — Hotel Lago"; "Vacanze sul Lago" resta com'è).
		if ( '' !== $site ) {
			$quoted = preg_quote( $site, '/' );
			$title  = trim( (string) preg_replace( "/^{$quoted}\s*[\p{P}\p{S}]+\s*|\s*[\p{P}\p{S}]+\s*{$quoted}$/iu", '', $title ) ) ?: $title;
		}
		$keywords = array_map( static fn( mixed $keyword ): string => sanitize_text_field( is_string( $keyword ) ? $keyword : '' ), (array) ( $json['keywords'] ?? [] ) );
		return new WP_REST_Response(
			[
				'title'       => $title,
				'description' => sanitize_text_field( is_string( $json['description'] ?? null ) ? $json['description'] : '' ),
				// Il meta vuole parole chiave non vuote e senza doppioni.
				'keywords'    => array_values( array_unique( array_filter( $keywords ) ) ),
			]
		);
	}

	// L'introduzione in una lingua, da ciò che llms.txt contiene già.
	public static function llms( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$text = Ai::generate(
			'Write the introduction of the llms.txt file of this website, in the language of the locale ' . I18n::locale( I18n::resolve( $request['lang'] ) ) . ': '
			. 'two to four sentences that say what the site is, who it is for and what it offers, so that AI assistants such as ChatGPT, Gemini and Claude understand the site and cite it. '
			. "Plain text only: no headings, lists, links or Markdown.\n\nThe llms.txt file now:\n" . Llms::get( false )['text']
		);
		return is_wp_error( $text ) ? $text : new WP_REST_Response( [ 'text' => sanitize_textarea_field( $text ) ] );
	}
}
