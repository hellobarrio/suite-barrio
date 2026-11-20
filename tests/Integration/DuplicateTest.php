<?php

namespace BarrioSuite\Tests\Integration;

use BarrioSuite\Admin;
use BarrioSuite\Duplicate\Module;
use BarrioSuite\Modules;
use BarrioSuite\Plugin;
use WP_Error;
use WP_Hook;
use WP_REST_Request;
use WP_REST_Response;
use WP_UnitTestCase;

// Il modulo Duplicazione: la copia di contenuti e termini intatta, solo per chi può modificare l'originale.
final class DuplicateTest extends WP_UnitTestCase {

	/** @var array<string, WP_Hook> */
	private array $hooks;

	private ?\WP_Scripts $scripts;

	private int $editor;

	public function set_up(): void {
		parent::set_up();
		$this->editor = self::factory()->user->create( [ 'role' => 'editor' ] );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$this->hooks   = array_map( static fn( WP_Hook $hook ): WP_Hook => clone $hook, $GLOBALS['wp_filter'] );
		$this->scripts = $GLOBALS['wp_scripts'] ?? null;
		register_post_type(
			'book',
			[
				'public' => true,
				'label'  => 'Books',
			]
		);
		register_taxonomy(
			'genre',
			'book',
			[
				'public' => true,
				'label'  => 'Genres',
			]
		);
		require_once ABSPATH . 'wp-admin/includes/template.php';
	}

	public function tear_down(): void {
		$GLOBALS['wp_scripts']     = $this->scripts; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		$GLOBALS['wp_filter']      = $this->hooks; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- gli hook di prima del test.
		$GLOBALS['wp_rest_server'] = null; // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- il server REST di WordPress.
		parent::tear_down();
		// I ruoli restano in memoria anche dopo il rollback: si rileggono dal database, non dalla cache.
		self::flush_cache();
		wp_roles()->for_site();
	}

	public function test_a_page_a_post_and_a_custom_type_are_copied_with_content_meta_and_terms(): void {
		global $wpdb;
		// Gli attributi dei blocchi in JSON, con <, ", & e \ scritti come \u003c, \u0022…: le barre si perdono senza wp_slash().
		$content = serialize_blocks(
			[
				[
					'blockName'    => 'core/paragraph',
					'attrs'        => [ 'metadata' => [ 'name' => 'Un "nome" <con> & \\ barra' ] ],
					'innerBlocks'  => [],
					'innerHTML'    => '<p>Testo</p>',
					'innerContent' => [ '<p>Testo</p>' ],
				],
			]
		);
		$this->assertStringContainsString( '\u003c', $content );
		$terms  = [
			'category' => [ self::factory()->category->create() ],
			'post_tag' => [ self::factory()->tag->create(), self::factory()->tag->create() ],
			'genre'    => [ self::factory()->term->create( [ 'taxonomy' => 'genre' ] ) ],
		];
		$parent = self::factory()->post->create( [ 'post_type' => 'page' ] );
		$image  = self::factory()->attachment->create();
		// Un plugin che scrive un suo meta a ogni salvataggio: nella copia, prima che arrivino quelli dell'originale.
		add_action( 'save_post', static fn( int $id ) => add_post_meta( $id, 'layout', 'predefinito', true ) );

		foreach ( [ 'post', 'page', 'book' ] as $type ) {
			$original = self::factory()->post->create(
				wp_slash(
					[
						'post_type'      => $type,
						'post_author'    => $this->editor,
						'post_title'     => 'Chi siamo',
						'post_content'   => $content,
						'post_excerpt'   => 'Riassunto \\ con barra',
						'post_parent'    => 'page' === $type ? $parent : 0,
						'menu_order'     => 3,
						'comment_status' => 'closed',
						'ping_status'    => 'closed',
						'post_password'  => 'segreto',
					]
				)
			);
			$this->assertSame( $content, get_post_field( 'post_content', $original ), 'The original is intact.' );
			update_post_meta( $original, 'layout', 'largo' );
			foreach ( get_object_taxonomies( $type ) as $taxonomy ) {
				wp_set_object_terms( $original, $terms[ $taxonomy ] ?? [], $taxonomy );
			}
			add_post_meta(
				$original,
				'_barrio_json',
				wp_slash(
					(string) wp_json_encode(
						[
							'quote'  => '"x"',
							'path'   => 'C:\\dir',
							'accent' => 'è',
						]
					)
				)
			);
			add_post_meta(
				$original,
				'list',
				wp_slash(
					[
						'a'      => 'b\\c',
						'n'      => 1,
						'nested' => [ 'yes' => true ],
					]
				)
			);
			add_post_meta( $original, 'multi', 'one' );
			add_post_meta( $original, 'multi', 'two' );
			add_post_meta( $original, '_thumbnail_id', $image );
			add_post_meta( $original, '_wp_page_template', 'full-width.php' );
			// ACF: il valore e il riferimento al campo, anche per le righe di un ripetitore e le scelte multiple.
			$acf = [
				'price'        => '10',
				'_price'       => 'field_1',
				'rows'         => '2',
				'_rows'        => 'field_2',
				'rows_0_text'  => 'Uno',
				'_rows_0_text' => 'field_3',
				'rows_1_text'  => 'Due',
				'_rows_1_text' => 'field_3',
				'colors'       => [ 'red', 'blue' ],
				'_colors'      => 'field_4',
			];
			foreach ( $acf as $key => $value ) {
				add_post_meta( $original, $key, $value );
			}
			// Un oggetto con una barra, che add_post_meta() non saprebbe scrivere.
			$object       = new \stdClass();
			$object->path = 'C:\\dir';
			$wpdb->insert(
				$wpdb->postmeta,
				[
					'post_id'    => $original,
					'meta_key'   => 'object', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_query_meta_key
					'meta_value' => maybe_serialize( $object ), // phpcs:ignore WordPress.DB.SlowDBQuery.slow_query_meta_value
				]
			);
			wp_cache_delete( $original, 'post_meta' );
			$skipped = [ '_edit_lock', '_edit_last', '_wp_old_slug', '_wp_old_date', '_wp_desired_post_slug', '_wp_trash_meta_status' ];
			foreach ( $skipped as $key ) {
				add_post_meta( $original, $key, 'x' );
			}

			$copy = Module::post( $original );

			$this->assertIsInt( $copy, $type );
			$post = get_post( $copy );
			$this->assertSame( 'draft', $post?->post_status );
			$this->assertSame( (string) get_current_user_id(), $post->post_author, 'A draft of whoever duplicates.' );
			$this->assertSame( 'Chi siamo (copy)', $post->post_title );
			$this->assertSame( $content, $post->post_content, "The block attributes of the {$type} are intact." );
			$this->assertSame( 'Riassunto \\ con barra', $post->post_excerpt );
			$this->assertSame( 'page' === $type ? $parent : 0, $post->post_parent );
			$this->assertSame( 3, $post->menu_order );
			$this->assertSame( [ 'closed', 'closed', 'segreto' ], [ $post->comment_status, $post->ping_status, $post->post_password ] );
			// I valori grezzi, ancora serializzati: uguali byte per byte, una volta sola.
			$this->assertSame( array_diff_key( get_post_meta( $original ), array_flip( $skipped ) ), get_post_meta( $copy ), $type );
			foreach ( get_object_taxonomies( $type ) as $taxonomy ) {
				$this->assertEqualsCanonicalizing( wp_get_object_terms( $original, $taxonomy, [ 'fields' => 'ids' ] ), wp_get_object_terms( $copy, $taxonomy, [ 'fields' => 'ids' ] ), $taxonomy );
			}
		}
	}

	public function test_the_second_copy_of_a_term_has_a_number(): void {
		$parent = self::factory()->category->create();
		$term   = self::factory()->category->create(
			wp_slash(
				[
					'name'        => 'Notizie',
					'description' => 'Una \\ barra',
					'parent'      => $parent,
				]
			)
		);
		add_term_meta( $term, 'color', wp_slash( [ 'path' => 'a\\b' ] ) );
		add_term_meta( $term, '_barrio_order', 3 );
		add_action( 'created_category', static fn( int $id ) => add_term_meta( $id, 'color', 'predefinito', true ) );

		$first  = Module::term( $term );
		$second = Module::term( $term );

		$this->assertIsInt( $first );
		$this->assertIsInt( $second );
		$this->assertSame( [ 'Notizie (copy)', 'Notizie (copy 2)' ], [ get_term( $first )->name ?? null, get_term( $second )->name ?? null ] );
		$this->assertSame( 'Una \\ barra', get_term( $first )->description ?? null );
		$this->assertSame( $parent, get_term( $first )->parent ?? null );
		$this->assertSame( array_diff_key( get_term_meta( $term ), [ '_barrio_order' => true ] ), get_term_meta( $first ), 'Not the manual order: the copy goes to the end.' );

		// Un tag ha il nome unico in tutta la tassonomia.
		$tag = self::factory()->tag->create( [ 'name' => 'Pizza' ] );
		Module::term( $tag );
		$this->assertSame( 'Pizza (copy 2)', get_term( (int) Module::term( $tag ) )->name ?? null );
	}

	public function test_who_cannot_edit_the_original_cannot_duplicate_it(): void {
		$private     = self::factory()->post->create(
			[
				'post_author' => $this->editor,
				'post_status' => 'private',
			]
		);
		$contributor = self::factory()->user->create( [ 'role' => 'contributor' ] );
		$own         = self::factory()->post->create(
			[
				'post_author' => $contributor,
				'post_status' => 'draft',
			]
		);
		$inserted    = $this->inserted();
		wp_set_current_user( $contributor );

		$error = Module::post( $private );
		$this->assertInstanceOf( WP_Error::class, $error );
		$this->assertSame( [ 'status' => 403 ], $error->get_error_data() );
		$this->assertSame( [], $inserted->ids, 'Not even a half copy.' );
		$this->assertSame( [], Module::post_row_actions( [], get_post( $private ) ) );
		$this->assertIsInt( Module::post( $own ), 'Its own content, yes.' );

		// Un autore non modifica le pagine e non aggiunge categorie.
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'author' ] ) );
		$this->assertInstanceOf( WP_Error::class, Module::post( self::factory()->post->create( [ 'post_type' => 'page' ] ) ) );
		$category = self::factory()->category->create();
		$this->assertInstanceOf( WP_Error::class, Module::term( $category ) );
		$this->assertSame( [], Module::term_row_actions( [], get_term( $category ) ) );
	}

	public function test_a_failed_step_deletes_the_half_copy(): void {
		$post = self::factory()->post->create();
		add_post_meta( $post, 'broken', '1' );
		$term = self::factory()->category->create( [ 'name' => 'Rotta' ] );
		add_term_meta( $term, 'broken', '1' );
		$inserted = $this->inserted();
		$fail     = static fn( mixed $check, int $object_id, string $key ): mixed => 'broken' === $key ? false : $check;
		add_filter( 'add_post_metadata', $fail, 10, 3 );
		add_filter( 'add_term_metadata', $fail, 10, 3 );

		$error = Module::post( $post );
		$this->assertInstanceOf( WP_Error::class, $error );
		$this->assertSame( [ 'status' => 500 ], $error->get_error_data() );
		$this->assertCount( 1, $inserted->ids );
		$this->assertNull( get_post( $inserted->ids[0] ) );

		$this->assertInstanceOf( WP_Error::class, Module::term( $term ) );
		$this->assertFalse( get_term_by( 'name', 'Rotta (copy)', 'category' ) );
	}

	public function test_duplicate_is_in_the_rows_in_the_bulk_actions_and_in_the_editors(): void {
		$post    = get_post( self::factory()->post->create( [ 'post_title' => 'Chi siamo' ] ) );
		$actions = Module::post_row_actions( [ 'edit' => '' ], $post );

		$this->assertStringContainsString( 'aria-label="Duplicate &#8220;Chi siamo&#8221;"', $actions['barrio_duplicate'] ?? '' );
		$this->assertSame( 1, preg_match( '/href="([^"]+)"/', $actions['barrio_duplicate'] ?? '', $href ) );
		parse_str( (string) wp_parse_url( html_entity_decode( $href[1] ), PHP_URL_QUERY ), $query );
		$this->assertSame( [ 'barrio_duplicate', (string) $post->ID ], [ $query['action'] ?? null, $query['post'] ?? null ] );
		$this->assertSame( 1, wp_verify_nonce( (string) ( $query['_wpnonce'] ?? '' ), "barrio_duplicate-post-{$post->ID}" ) );
		$this->assertArrayHasKey( 'barrio_duplicate', Module::term_row_actions( [], get_term( self::factory()->category->create() ) ) );

		// Nel cestino solo ripristina ed elimina.
		wp_trash_post( $post->ID );
		$this->assertSame( [], Module::post_row_actions( [], get_post( $post->ID ) ) );
		set_query_var( 'post_status', 'trash' );
		$this->assertSame( [], Module::bulk_actions( [] ) );
		set_query_var( 'post_status', '' );

		Module::admin_init();
		$this->assertSame( 10, has_filter( 'bulk_actions-edit-page', [ Module::class, 'bulk_actions' ] ) );
		$this->assertSame( 10, has_filter( 'genre_row_actions', [ Module::class, 'term_row_actions' ] ) );
		$this->assertFalse( has_filter( 'link_category_row_actions', [ Module::class, 'term_row_actions' ] ), 'Only the taxonomies of the content types.' );

		// Un autore copia il suo articolo, non quello di un altro.
		$author = self::factory()->user->create( [ 'role' => 'author' ] );
		$own    = self::factory()->post->create( [ 'post_author' => $author ] );
		$other  = self::factory()->post->create( [ 'post_author' => $this->editor ] );
		wp_set_current_user( $author );
		$this->assertSame( 10, has_filter( 'handle_bulk_actions-edit-post', [ Module::class, 'handle_bulk' ] ) );
		$this->assertSame( 'edit.php?paged=2&barrio_duplicated=1&barrio_not_duplicated=1', Module::handle_bulk( 'edit.php?paged=2', 'barrio_duplicate', [ $own, $other ] ) );
		$this->assertSame( 'edit.php', Module::handle_bulk( 'edit.php', 'trash', [ $own ] ), 'The other actions are not touched.' );
		$_GET['barrio_duplicated']     = '1';
		$_GET['barrio_not_duplicated'] = '1';
		ob_start();
		Module::notice();
		$notice = (string) ob_get_clean();
		unset( $_GET['barrio_duplicated'], $_GET['barrio_not_duplicated'] );
		$this->assertStringContainsString( 'notice-warning', $notice );
		$this->assertStringContainsString( '1 copy created as a draft. 1 content could not be duplicated.', $notice );

		// Nell'editor classico un link, nell'editor a blocchi un pulsante; non per un contenuto mai salvato.
		$draft = get_post( self::factory()->post->create( [ 'post_author' => $author ] ) );
		ob_start();
		Module::classic_editor( $draft );
		$this->assertStringContainsString( 'class="misc-pub-section"', (string) ob_get_clean() );
		ob_start();
		Module::classic_editor( get_post( self::factory()->post->create( [ 'post_status' => 'auto-draft' ] ) ) );
		$this->assertSame( '', ob_get_clean() );
		set_current_screen( 'post' );
		$GLOBALS['post'] = $draft; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		Module::block_editor();
		set_current_screen( 'front' );
		unset( $GLOBALS['post'] );
		$this->assertTrue( wp_script_is( 'barrio-duplicate' ) );
		$this->assertStringContainsString( "post={$draft->ID}", implode( '', (array) wp_scripts()->get_data( 'barrio-duplicate', 'before' ) ) );
	}

	public function test_by_default_only_the_types_with_pages_on_the_site_have_duplicate(): void {
		$this->assertEquals(
			[
				'post_types' => (object) [],
				'taxonomies' => (object) [],
			],
			$this->types( rest_do_request( new WP_REST_Request( 'GET', '/barrio/v1/duplicate' ) ) ),
			'Nothing chosen.'
		);
		$pattern = get_post( self::factory()->post->create( [ 'post_type' => 'wp_block' ] ) );
		$this->assertSame( [], Module::post_row_actions( [], $pattern ), 'A pattern has no page on the site.' );
		$this->assertArrayHasKey( 'barrio_duplicate', Module::post_row_actions( [], get_post( self::factory()->post->create( [ 'post_type' => 'book' ] ) ) ) );

		$this->put( [ 'post_types' => [ 'wp_block' => true ] ] );
		$this->assertArrayHasKey( 'barrio_duplicate', Module::post_row_actions( [], $pattern ), 'Turned on by hand.' );
	}

	public function test_settings_turn_duplicate_off_for_a_type_and_are_validated(): void {
		$this->assertSame( 400, $this->put( [ 'post_types' => [ 'Le pagine!' => false ] ] )->get_status() );
		$this->assertSame( 400, $this->put( [ 'taxonomies' => [ 'category' => 'no' ] ] )->get_status() );
		$this->assertSame( 400, $this->put( [ 'taxonomies' => [ 'category' ] ] )->get_status() );

		$this->put( [ 'post_types' => [ 'page' => false ] ] );
		$this->assertEquals(
			[
				'post_types' => (object) [ 'page' => false ],
				'taxonomies' => (object) [ 'post_tag' => false ],
			],
			$this->types( $this->put( [ 'taxonomies' => [ 'post_tag' => false ] ] ) ),
			'A list that is not sent stays.'
		);
		$this->assertArrayNotHasKey( 'barrio_duplicate', wp_load_alloptions(), 'Read only in the admin.' );

		$page = get_post( self::factory()->post->create( [ 'post_type' => 'page' ] ) );
		$this->assertSame( [], Module::post_row_actions( [], $page ) );
		$this->assertInstanceOf( WP_Error::class, Module::post( $page->ID ) );
		$this->assertSame( [], Module::term_row_actions( [], get_term( self::factory()->tag->create() ) ) );
		Module::admin_init();
		$this->assertFalse( has_filter( 'bulk_actions-edit-page', [ Module::class, 'bulk_actions' ] ) );
		$this->assertNotFalse( has_filter( 'bulk_actions-edit-post', [ Module::class, 'bulk_actions' ] ) );

		wp_set_current_user( $this->editor );
		$this->assertSame( 403, rest_do_request( new WP_REST_Request( 'GET', '/barrio/v1/duplicate' ) )->get_status() );
		$this->assertSame( 403, $this->put( [ 'post_types' => [] ] )->get_status() );
	}

	public function test_only_the_roles_chosen_in_the_permissions_duplicate(): void {
		$settings = rest_do_request( new WP_REST_Request( 'GET', '/barrio/v1/duplicate' ) )->get_data();
		$this->assertSame( [ 'barrio_duplicate' ], $settings['roles']['contributor'], 'From migration 0001: whoever writes contents.' );
		$this->assertSame( [], $settings['roles']['subscriber'] );
		$this->assertArrayNotHasKey( 'administrator', $settings['roles'] );
		$this->assertSame( 'Administrator', $settings['role_names']['administrator'] );

		$response = $this->put( [ 'roles' => [ 'editor' => [] ] ] );
		$this->assertSame( [], $response->get_data()['roles']['editor'] );
		$this->assertSame( [ 'barrio_duplicate' ], $response->get_data()['roles']['author'], 'Roles not sent stay as they are.' );
		$this->assertTrue( get_role( 'administrator' )->has_cap( 'barrio_duplicate' ), 'Administrators keep everything.' );
		$this->assertSame( 400, $this->put( [ 'roles' => [ 'editor' => [ 'manage_options' ] ] ] )->get_status(), 'Only the capability of the module.' );

		$post = get_post( self::factory()->post->create( [ 'post_author' => $this->editor ] ) );
		$term = get_term( self::factory()->tag->create() );
		wp_set_current_user( $this->editor );
		$this->assertSame( [], Module::post_row_actions( [], $post ) );
		$this->assertSame( [], Module::term_row_actions( [], $term ) );
		$this->assertSame( [], Module::bulk_actions( [] ) );
		$this->assertInstanceOf( WP_Error::class, Module::post( $post->ID ) );
		$this->assertInstanceOf( WP_Error::class, Module::term( $term->term_id ) );
		$this->assertSame( 403, $this->put( [ 'roles' => [ 'editor' => [ 'barrio_duplicate' ] ] ] )->get_status() );
	}

	public function test_the_contents_page_has_the_tab_for_administrators(): void {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		$names = [ 'menu', 'submenu', '_registered_pages', '_parent_pages', 'admin_page_hooks', '_wp_submenu_nopriv' ];
		$saved = array_map( static fn( string $name ): mixed => $GLOBALS[ $name ] ?? null, $names );
		$pages = static fn(): array => array_values( array_filter( array_column( $GLOBALS['submenu']['barrio'] ?? [], 2 ), static fn( string $slug ): bool => 'barrio-contents' === $slug ) );

		Admin::menu();
		Module::menu();
		// Un altro modulo della pagina la chiede di nuovo.
		Admin::contents_tab( 'edit_posts' );
		$this->assertSame( [ 'barrio-contents' ], $pages() );
		$types = Admin::content_types();
		$this->assertContains(
			[
				'name'     => 'book',
				'label'    => 'Books',
				'viewable' => true,
			],
			$types['post_types']
		);
		$this->assertFalse( array_column( $types['post_types'], 'viewable', 'name' )['wp_block'] );
		$this->assertNotContains( 'attachment', array_column( $types['post_types'], 'name' ) );
		$this->assertContains(
			[
				'name'     => 'genre',
				'label'    => 'Genres',
				'viewable' => true,
				'types'    => [ 'Books' ],
			],
			$types['taxonomies'],
			'With its content types: two taxonomies can both be called Categories.'
		);
		$this->assertNotContains( 'link_category', array_column( $types['taxonomies'], 'name' ) );
		$this->assertEquals(
			[
				'chosen' => [
					'post_types' => (object) [],
					'taxonomies' => (object) [],
				],
			],
			apply_filters( 'barrio_admin_data', [] )['duplicate'] ?? null,
			'For the card of the Dashboard.'
		);

		$GLOBALS['submenu'] = []; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		wp_set_current_user( $this->editor );
		Admin::menu();
		Module::menu();
		$this->assertSame( [], $pages(), 'Only the settings of Duplication so far, for administrators.' );

		foreach ( $names as $index => $name ) {
			$GLOBALS[ $name ] = $saved[ $index ]; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- i menu di WordPress.
		}
	}

	public function test_turned_off_there_is_no_duplicate(): void {
		Modules::set( 'duplicate', false );
		$GLOBALS['wp_filter']      = []; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		$GLOBALS['wp_rest_server'] = null; // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- il server REST di WordPress.
		Plugin::boot();

		$this->assertArrayNotHasKey( 'duplicate', apply_filters( 'barrio_admin_data', [] ), 'No tab in Barrio → Contents.' );
		$this->assertFalse( has_filter( 'post_row_actions', [ Module::class, 'post_row_actions' ] ) );
		$this->assertFalse( has_action( 'admin_post_barrio_duplicate' ) );
		$this->assertFalse( has_action( 'admin_menu', [ Module::class, 'menu' ] ) );
		$this->assertSame( 404, rest_do_request( new WP_REST_Request( 'GET', '/barrio/v1/duplicate' ) )->get_status() );
	}

	/** @return array<string, mixed> Le scelte per tipo e per tassonomia, senza i ruoli. */
	private function types( WP_REST_Response $response ): array {
		return array_intersect_key( $response->get_data(), array_flip( [ 'post_types', 'taxonomies' ] ) );
	}

	/** @param array<string, mixed> $body */
	private function put( array $body ): WP_REST_Response {
		$request = new WP_REST_Request( 'PUT', '/barrio/v1/duplicate' );
		$request->set_body_params( $body );
		return rest_do_request( $request );
	}

	// Gli ID dei contenuti inseriti da qui in poi.
	private function inserted(): \stdClass {
		$inserted      = new \stdClass();
		$inserted->ids = [];
		add_action(
			'wp_insert_post',
			static function ( int $post_id ) use ( $inserted ): void {
				$inserted->ids[] = $post_id;
			}
		);
		return $inserted;
	}
}
