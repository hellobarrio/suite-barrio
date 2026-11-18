<?php

namespace BarrioSuite\Seo;

use BarrioSuite\Ai;
use WP_Post_Type;
use WP_Taxonomy;

/**
 * La pagina SEO nel menu di Barrio (src/Admin.php), con le schede Contenuti, Redirect e Impostazioni, e i
 * dati che le servono nel JavaScript.
 */
final class Admin {

	public static function menu(): void {
		$hook = \BarrioSuite\Admin::add_page( 'barrio-seo', __( 'SEO', 'barrio-suite' ), 'barrio_manage_seo' );
		if ( $hook ) {
			// Le immagini delle impostazioni e del pannello SEO nella modale dei contenuti.
			add_action( "load-{$hook}", 'wp_enqueue_media' );
		}
	}

	/**
	 * Su barrio_admin_data, in window.barrioAdmin.seo. Senza, il JavaScript sa che il modulo è spento.
	 *
	 * @param array<string, mixed> $data
	 * @return array<string, mixed>
	 */
	public static function data( array $data ): array {
		$types = [];
		foreach ( get_post_types( [ 'public' => true ], 'objects' ) as $type ) {
			if ( 'attachment' !== $type->name ) {
				$types[] = self::type( 'post_types', $type );
			}
		}
		// Prima le pagine, poi gli articoli e gli altri tipi (sort è stabile).
		usort( $types, static fn( array $a, array $b ): int => ( 'page' === $b['name'] ) <=> ( 'page' === $a['name'] ) );
		foreach ( get_taxonomies( [ 'public' => true ], 'objects' ) as $taxonomy ) {
			$types[] = self::type( 'taxonomies', $taxonomy );
		}
		return $data + [
			'seo' => [
				'types'         => $types,
				'canManageSeo'  => current_user_can( 'barrio_manage_seo' ),
				'canEditSeo'    => current_user_can( 'barrio_edit_seo' ),
				'organizations' => Settings::schema()['organization']['properties']['type']['enum'],
				'sitemap'       => [
					'url'     => get_sitemap_url( 'index' ),
					'enabled' => wp_sitemaps_get_server()->sitemaps_enabled(),
				],
				// Un file robots.txt nella cartella del sito prende il posto di quello di WordPress, con la riga Sitemap.
				'robots'        => [
					'url'  => home_url( '/robots.txt' ),
					'file' => file_exists( get_home_path() . 'robots.txt' ),
				],
				'llms'          => [ home_url( '/llms.txt' ), home_url( '/llms-full.txt' ) ],
				// "Genera con AI" resta spento finché l'AI non è configurata (src/Ai.php).
				'ai'            => Ai::is_ready(),
			],
		];
	}

	/**
	 * rest: dove la REST del core modifica i meta; null per i tipi che non sono nella REST, che nella tabella
	 * si leggono soltanto.
	 *
	 * @return array{group: string, name: string, label: string, rest: string|null}
	 */
	private static function type( string $group, WP_Post_Type|WP_Taxonomy $type ): array {
		return [
			'group' => $group,
			'name'  => $type->name,
			'label' => $type->labels->name,
			'rest'  => $type->show_in_rest ? ( $type->rest_namespace ?: 'wp/v2' ) . '/' . ( $type->rest_base ?: $type->name ) : null,
		];
	}
}
