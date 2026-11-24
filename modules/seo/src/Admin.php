<?php

namespace BarrioSuite\Seo;

use BarrioSuite\Ai;
use WP_Post_Type;
use WP_Taxonomy;

/**
 * La pagina SEO nel menu di Barrio (src/Admin.php), con le schede Contenuti, Redirect, Organizzazione e Impostazioni, e i
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
		$types = array_map( static fn( WP_Post_Type $type ): array => self::type( 'post_types', $type ), array_values( Module::post_types() ) );
		// Prima le pagine, poi gli articoli e gli altri tipi (sort è stabile).
		usort( $types, static fn( array $a, array $b ): int => ( 'page' === $b['name'] ) <=> ( 'page' === $a['name'] ) );
		foreach ( Module::taxonomies() as $taxonomy ) {
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
				// Senza permalink WordPress non riceve /llms.txt; un sito nascosto ai motori non lo pubblica (Llms::serve()).
				'llms'          => get_option( 'permalink_structure' ) && get_option( 'blog_public' ) ? [ home_url( '/llms.txt' ), home_url( '/llms-full.txt' ) ] : [],
				// Le pagine degli allegati, se il sito le ha ancora: una riga in più nell'indicizzazione.
				'attachments'   => (bool) get_option( 'wp_attachment_pages_enabled' ),
				'shortcodes'    => Editor::shortcodes(),
				// "Genera con AI" resta spento finché l'AI non è configurata (src/Ai.php).
				'ai'            => Ai::is_ready(),
			],
		];
	}

	/**
	 * rest: dove la REST del core modifica i meta; null per i tipi che non sono nella REST, che nella tabella
	 * si leggono soltanto. llms: cosa va in llms.txt senza una scelta.
	 *
	 * @return array{group: string, name: string, label: string, rest: string|null, llms: string}
	 */
	private static function type( string $group, WP_Post_Type|WP_Taxonomy $type ): array {
		return [
			'group' => $group,
			'name'  => $type->name,
			'label' => $type->labels->name,
			'rest'  => $type->show_in_rest ? ( $type->rest_namespace ?: 'wp/v2' ) . '/' . ( $type->rest_base ?: $type->name ) : null,
			'llms'  => Llms::preset( $group, $type->name ),
		];
	}
}
