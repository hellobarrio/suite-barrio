<?php

namespace BarrioSuite\Tests\Integration;

use BarrioSuite\Admin;
use BarrioSuite\Modules;
use BarrioSuite\Ordering\Module;
use BarrioSuite\Plugin;
use WP_Hook;
use WP_Query;
use WP_REST_Request;
use WP_REST_Response;
use WP_UnitTestCase;

// Il modulo Ordinamento: l'ordine dell'albero scritto in un colpo, e usato solo dove la query non chiede altro.
final class OrderingTest extends WP_UnitTestCase {

	/** @var array<string, WP_Hook> */
	private array $hooks;

	private int $editor;

	/** @var list<array{string, string}> */
	private array $changed = [];

	public function set_up(): void {
		parent::set_up();
		$this->editor = self::factory()->user->create( [ 'role' => 'editor' ] );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$this->hooks = array_map( static fn( WP_Hook $hook ): WP_Hook => clone $hook, $GLOBALS['wp_filter'] );
		register_post_type(
			'book',
			[
				'public'       => true,
				'label'        => 'Books',
				'show_in_rest' => true,
				'has_archive'  => true,
			]
		);
		register_taxonomy(
			'genre',
			'book',
			[
				'public'       => true,
				'label'        => 'Genres',
				'hierarchical' => true,
			]
		);
		add_action(
			'barrio_ordering_changed',
			function ( string $kind, string $name ): void {
				$this->changed[] = [ $kind, $name ];
			},
			10,
			2
		);
	}

	public function tear_down(): void {
		$GLOBALS['wp_filter']      = $this->hooks; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- gli hook di prima del test.
		$GLOBALS['wp_rest_server'] = null; // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- il server REST di WordPress.
		set_current_screen( 'front' );
		parent::tear_down();
	}

	public function test_reordering_writes_the_order_of_the_tree_without_touching_the_modification_date(): void {
		global $wpdb;
		$about   = self::factory()->post->create( $this->page( 'Chi siamo', 1 ) );
		$team    = self::factory()->post->create( $this->page( 'Team &amp; soci', 2, $about ) );
		$history = self::factory()->post->create( $this->page( 'Storia', 3, $about ) );
		$contact = self::factory()->post->create( $this->page( 'Contatti', 4 ) + [ 'post_status' => 'draft' ] );
		$trashed = self::factory()->post->create( $this->page( 'Vecchia', 5 ) );
		$orphan  = self::factory()->post->create( $this->page( 'Orfana', 6, $trashed ) );
		wp_trash_post( $trashed );
		$wpdb->query( $wpdb->prepare( 'UPDATE %i SET post_modified = %s, post_modified_gmt = %s WHERE post_type = %s', $wpdb->posts, '2020-01-01 00:00:00', '2020-01-01 00:00:00', 'page' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		// Come le query: menu_order, poi il più recente. Il cestino non c'è, e chi aveva lì il genitore va alla radice.
		$this->assertSame(
			[
				[
					'id'     => $orphan,
					'title'  => 'Orfana',
					'status' => null,
					'parent' => 0,
				],
				[
					'id'     => $contact,
					'title'  => 'Contatti',
					'status' => 'Draft',
					'parent' => 0,
				],
				[
					'id'     => $history,
					'title'  => 'Storia',
					'status' => null,
					'parent' => $about,
				],
				[
					'id'     => $team,
					'title'  => 'Team & soci',
					'status' => null,
					'parent' => $about,
				],
				[
					'id'     => $about,
					'title'  => 'Chi siamo',
					'status' => null,
					'parent' => 0,
				],
			],
			$this->request( 'GET', '/ordering/post/page' )->get_data()
		);

		$order    = [ $about, $team, $history, $contact, $orphan ];
		$response = $this->request( 'PUT', '/ordering/post/page', [ 'ids' => $order ] );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( $order, array_column( (array) $response->get_data(), 'id' ) );
		$this->assertSame( [ 1, 2, 3, 4, 5 ], array_map( static fn( int $id ): int => (int) get_post_field( 'menu_order', $id ), $order ), 'The position in the whole list: parents before children.' );
		$this->assertSame( [ '2020-01-01 00:00:00' ], array_unique( $wpdb->get_col( $wpdb->prepare( 'SELECT post_modified FROM %i WHERE post_type = %s', $wpdb->posts, 'page' ) ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$this->assertSame( $about, wp_get_post_parent_id( $team ), 'The parent is changed in the content.' );
		$this->assertSame( [ [ 'post', 'page' ] ], $this->changed );
	}

	public function test_the_order_applies_where_no_other_order_is_asked(): void {
		$this->request( 'PUT', '/ordering/settings', [ 'post_types' => [ 'book' ] ] );
		$genre = self::factory()->term->create( [ 'taxonomy' => 'genre' ] );
		$books = [];
		foreach ( [ 'Book A', 'Book B', 'Book C' ] as $day => $title ) {
			$books[] = self::factory()->post->create(
				[
					'post_type'  => 'book',
					'post_title' => $title,
					'post_date'  => '2026-01-0' . ( $day + 1 ) . ' 10:00:00',
					'tax_input'  => [ 'genre' => [ $genre ] ],
				]
			);
		}
		[ $a, $b, $c ] = $books;
		$manual        = [ $b, $a, $c ];
		$this->request( 'PUT', '/ordering/post/book', [ 'ids' => $manual ] );

		$this->assertSame( $manual, get_posts( $this->args( 'book' ) ), 'No order asked.' );
		$this->assertSame( $manual, get_posts( $this->args( 'book', [ 'orderby' => 'date' ] ) ), 'The default order, as the Query Loop block asks.' );
		$rest = rest_do_request( new WP_REST_Request( 'GET', '/wp/v2/book' ) );
		$this->assertSame( $manual, array_column( (array) $rest->get_data(), 'id' ), 'REST.' );
		$this->go_to( home_url( '?post_type=book' ) );
		$this->assertSame( $manual, wp_list_pluck( $GLOBALS['wp_query']->posts, 'ID' ), 'The archive.' );
		$this->go_to( (string) get_term_link( $genre ) );
		$this->assertSame( $manual, wp_list_pluck( $GLOBALS['wp_query']->posts, 'ID' ), 'The archive of a term of its taxonomy.' );
		require_once ABSPATH . 'wp-admin/includes/post.php';
		set_current_screen( 'edit-book' );
		wp_edit_posts_query( [ 'post_type' => 'book' ] );
		$this->assertSame( $manual, wp_list_pluck( $GLOBALS['wp_query']->posts, 'ID' ), 'The list in the admin.' );

		// Un ordine scelto resta.
		wp_edit_posts_query(
			[
				'post_type' => 'book',
				'orderby'   => 'date',
				'order'     => 'desc',
			]
		);
		$this->assertSame( [ $c, $b, $a ], wp_list_pluck( $GLOBALS['wp_query']->posts, 'ID' ), 'A click on the Date column of the admin.' );
		set_current_screen( 'front' );
		$this->assertSame( [ $a, $b, $c ], get_posts( $this->args( 'book', [ 'order' => 'ASC' ] ) ) );
		$this->assertSame( [ $c, $b, $a ], get_posts( $this->args( 'book', [ 'orderby' => 'title' ] ) ) );
		$this->assertSame( [ $c, $b, $a ], get_posts( $this->args( 'book', [ 's' => 'Book' ] ) ), 'Search: the relevance, then the date.' );
		$post = self::factory()->post->create( [ 'post_date' => '2026-02-01 10:00:00' ] );
		$this->assertSame( [ $post, $c, $b, $a ], get_posts( $this->args( [ 'book', 'post' ] ) ), 'Not with a type that is not in manual order.' );

		$new = self::factory()->post->create( [ 'post_type' => 'book' ] );
		$this->assertSame( [ $new, ...$manual ], get_posts( $this->args( 'book' ) ), 'A new content goes to the top.' );
	}

	public function test_terms_follow_their_order_and_the_others_go_to_the_end_by_name(): void {
		$this->request( 'PUT', '/ordering/settings', [ 'taxonomies' => [ 'genre' ] ] );
		$this->changed = [];
		$zeta          = self::factory()->term->create( $this->genre( 'Zeta' ) );
		$alfa          = self::factory()->term->create( $this->genre( 'Alfa' ) );
		$child         = self::factory()->term->create( $this->genre( 'Alfa figlio', $alfa ) );
		$beta          = self::factory()->term->create( $this->genre( 'Beta' ) );
		self::factory()->term->create( $this->genre( 'Gamma' ) );

		$this->assertSame(
			[ 'Alfa', 'Alfa figlio', 'Beta', 'Gamma', 'Zeta' ],
			array_column( (array) $this->request( 'GET', '/ordering/term/genre' )->get_data(), 'title' ),
			'Without an order, by name.'
		);
		$this->assertSame( $alfa, $this->request( 'GET', '/ordering/term/genre' )->get_data()[1]['parent'] ?? null );

		$this->request( 'PUT', '/ordering/term/genre', [ 'ids' => [ $zeta, $alfa, $child ] ] );

		$terms = static fn( array $args = [] ): mixed => get_terms(
			$args + [
				'taxonomy'   => 'genre',
				'hide_empty' => false,
				'fields'     => 'names',
			]
		);
		$names = static fn( array $args = [] ): array => (array) $terms( $args );
		$this->assertSame( [ 'Zeta', 'Alfa', 'Alfa figlio', 'Beta', 'Gamma' ], $names() );
		$this->assertSame( [ [ 'term', 'genre' ] ], $this->changed );
		$this->assertSame( [ 'Zeta', 'Gamma', 'Beta', 'Alfa figlio', 'Alfa' ], $names( [ 'order' => 'DESC' ] ), 'A chosen order stays.' );
		$this->assertSame( [ 'Zeta', 'Alfa', 'Alfa figlio', 'Beta' ], array_slice( $names( [ 'orderby' => 'term_id' ] ), 0, 4 ) );
		$this->assertEquals( 5, $terms( [ 'fields' => 'count' ] ), 'A count.' );

		// Un altro salvataggio si vede subito: la cache delle query dei termini è scaduta.
		$this->request( 'PUT', '/ordering/term/genre', [ 'ids' => [ $beta, $zeta, $alfa, $child ] ] );
		$this->assertSame( [ 'Beta', 'Zeta', 'Alfa', 'Alfa figlio', 'Gamma' ], $names() );

		set_current_screen( 'edit-genre' );
		$_GET['orderby'] = 'name';
		$this->assertSame( [ 'Alfa', 'Alfa figlio', 'Beta', 'Gamma', 'Zeta' ], $names(), 'A click on the Name column of the admin.' );
		unset( $_GET['orderby'] );

		$category = self::factory()->category->create( [ 'name' => 'Zzz' ] );
		add_term_meta( $category, '_barrio_order', 1 );
		$this->assertSame( [ 'Uncategorized', 'Zzz' ], $names( [ 'taxonomy' => 'category' ] ), 'Not in a taxonomy without manual order.' );
	}

	// I frontend headless (hello_standard) leggono gli elenchi da WPGraphQL senza chiedere un ordine.
	public function test_wpgraphql_lists_follow_the_order(): void {
		if ( ! class_exists( 'WPGraphQL' ) ) {
			$this->markTestSkipped( 'Needs WPGraphQL (WP_GRAPHQL=1).' );
		}
		$this->request(
			'PUT',
			'/ordering/settings',
			[
				'post_types' => [ 'page' ],
				'taxonomies' => [ 'category' ],
			]
		);
		$pages = [ self::factory()->post->create( $this->page( 'A', 1 ) ), self::factory()->post->create( $this->page( 'B', 2 ) ), self::factory()->post->create( $this->page( 'C', 3 ) ) ];
		$terms = [ self::factory()->category->create( [ 'name' => 'Zeta' ] ), self::factory()->category->create( [ 'name' => 'Alfa' ] ) ];
		$this->request( 'PUT', '/ordering/post/page', [ 'ids' => [ $pages[1], $pages[2], $pages[0] ] ] );
		$this->request( 'PUT', '/ordering/term/category', [ 'ids' => $terms ] );

		$response = graphql( [ 'query' => '{ pages { nodes { databaseId } } categories { nodes { name } } }' ] );

		$this->assertSame( [ $pages[1], $pages[2], $pages[0] ], array_column( $response['data']['pages']['nodes'] ?? [], 'databaseId' ), (string) wp_json_encode( $response ) );
		$this->assertSame( [ 'Zeta', 'Alfa' ], array_slice( array_column( $response['data']['categories']['nodes'] ?? [], 'name' ), 0, 2 ) );
	}

	public function test_a_failed_write_leaves_the_order_as_it_was(): void {
		global $wpdb;
		$this->request( 'PUT', '/ordering/settings', [ 'taxonomies' => [ 'genre' ] ] );
		$terms = [ self::factory()->term->create( $this->genre( 'A' ) ), self::factory()->term->create( $this->genre( 'B' ) ) ];
		$this->request( 'PUT', '/ordering/term/genre', [ 'ids' => $terms ] );
		$pages = [ self::factory()->post->create( $this->page( 'A', 1 ) ), self::factory()->post->create( $this->page( 'B', 2 ) ) ];
		$this->request( 'PUT', '/ordering/post/page', [ 'ids' => $pages ] );
		$this->changed = [];

		$suppress = $wpdb->suppress_errors();
		// Il secondo passo dei termini, dopo che il primo ha tolto l'ordine di prima.
		add_filter( 'query', static fn( string $sql ): string => str_starts_with( $sql, "INSERT INTO `{$wpdb->termmeta}`" ) || str_starts_with( $sql, "UPDATE `{$wpdb->posts}` SET menu_order" ) ? 'SELECT broken' : $sql );
		$terms_response = $this->request( 'PUT', '/ordering/term/genre', [ 'ids' => array_reverse( $terms ) ] );
		$pages_response = $this->request( 'PUT', '/ordering/post/page', [ 'ids' => array_reverse( $pages ) ] );
		$wpdb->suppress_errors( $suppress );

		$this->assertSame( [ 500, 500 ], [ $terms_response->get_status(), $pages_response->get_status() ] );
		$positions = $wpdb->get_col( $wpdb->prepare( 'SELECT meta_value FROM %i WHERE meta_key = %s AND term_id IN (%d, %d) ORDER BY term_id', $wpdb->termmeta, '_barrio_order', $terms[0], $terms[1] ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$this->assertSame( [ '1', '2' ], $positions, 'The deletion has been rolled back.' );
		$this->assertSame( [ '1', '2' ], $wpdb->get_col( $wpdb->prepare( 'SELECT menu_order FROM %i WHERE ID IN (%d, %d) ORDER BY ID', $wpdb->posts, $pages[0], $pages[1] ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$this->assertSame( [], $this->changed );
	}

	public function test_only_who_can_edit_the_contents_of_others_reorders(): void {
		$pages = [ self::factory()->post->create( $this->page( 'A', 1 ) ), self::factory()->post->create( $this->page( 'B', 2 ) ) ];
		$post  = self::factory()->post->create();

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'author' ] ) );
		$this->assertSame( 403, $this->request( 'GET', '/ordering/post/page' )->get_status() );
		$this->assertSame( 403, $this->request( 'PUT', '/ordering/post/page', [ 'ids' => $pages ] )->get_status() );
		$this->assertSame( 403, $this->request( 'GET', '/ordering/term/category' )->get_status() );

		wp_set_current_user( $this->editor );
		$this->assertSame( 200, $this->request( 'GET', '/ordering/term/category' )->get_status() );
		$this->assertSame( 400, $this->request( 'PUT', '/ordering/post/page', [ 'ids' => [ $pages[0], $post ] ] )->get_status(), 'Only contents of the list.' );
		$this->assertSame( 400, $this->request( 'PUT', '/ordering/post/page', [ 'ids' => [ $pages[0], $pages[0] ] ] )->get_status() );
		$locked = $pages[1];
		add_filter( 'map_meta_cap', static fn( array $caps, string $cap, int $user, array $args ): array => 'edit_post' === $cap && ( $args[0] ?? 0 ) === $locked ? [ 'do_not_allow' ] : $caps, 10, 4 );
		$this->assertSame( 403, $this->request( 'PUT', '/ordering/post/page', [ 'ids' => array_reverse( $pages ) ] )->get_status(), 'Every content must be editable.' );
		$this->assertSame( [ 0, 0 ], array_map( static fn( int $id ): int => (int) get_post_field( 'menu_order', $id ), $pages ) );
		$this->assertSame( 403, $this->request( 'GET', '/ordering/settings' )->get_status() );
		$this->assertSame( 403, $this->request( 'PUT', '/ordering/settings', [ 'post_types' => [] ] )->get_status() );

		wp_set_current_user( 1 );
		$this->assertSame( 403, $this->request( 'GET', '/ordering/post/attachment' )->get_status(), 'Not the attachments.' );
	}

	public function test_settings_are_validated_and_turning_off_deletes_nothing(): void {
		$this->assertSame(
			[
				'post_types' => [],
				'taxonomies' => [],
			],
			$this->request( 'GET', '/ordering/settings' )->get_data(),
			'Nothing in manual order by default.'
		);
		$this->assertSame( 400, $this->request( 'PUT', '/ordering/settings', [ 'post_types' => [ 'Le pagine!' ] ] )->get_status() );
		$this->assertSame( 400, $this->request( 'PUT', '/ordering/settings', [ 'taxonomies' => [ 'genre', 'genre' ] ] )->get_status() );
		$this->assertSame(
			[
				'post_types' => [ 'book' ],
				'taxonomies' => [ 'genre' ],
			],
			$this->request(
				'PUT',
				'/ordering/settings',
				[
					'post_types' => [ 'book' ],
					'taxonomies' => [ 'genre' ],
				]
			)->get_data()
		);
		$this->assertSame( [ [ 'post', 'book' ], [ 'term', 'genre' ] ], $this->changed );
		$this->assertArrayHasKey( 'barrio_ordering', wp_load_alloptions(), 'Read by the queries of the site.' );

		$old   = self::factory()->post->create(
			[
				'post_type' => 'book',
				'post_date' => '2026-01-01 10:00:00',
			]
		);
		$new   = self::factory()->post->create(
			[
				'post_type' => 'book',
				'post_date' => '2026-01-02 10:00:00',
			]
		);
		$genre = self::factory()->term->create( $this->genre( 'Zeta' ) );
		$this->request( 'PUT', '/ordering/post/book', [ 'ids' => [ $old, $new ] ] );
		$this->request( 'PUT', '/ordering/term/genre', [ 'ids' => [ $genre ] ] );
		$this->changed = [];

		$this->assertSame(
			[
				'post_types' => [],
				'taxonomies' => [ 'genre' ],
			],
			$this->request( 'PUT', '/ordering/settings', [ 'post_types' => [] ] )->get_data(),
			'A list that is not sent stays.'
		);
		$this->assertSame( [ [ 'post', 'book' ] ], $this->changed );
		$this->assertSame( [ $new, $old ], get_posts( $this->args( 'book' ) ), 'The order of WordPress again.' );
		$this->assertSame( 1, get_post_field( 'menu_order', $old ), 'The order is kept for when it is turned on again.' );

		Modules::set( 'ordering', false );
		$GLOBALS['wp_filter']      = []; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		$GLOBALS['wp_rest_server'] = null; // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- il server REST di WordPress.
		Plugin::boot();
		$this->assertFalse( has_action( 'pre_get_posts', [ Module::class, 'posts' ] ) );
		$this->assertFalse( has_filter( 'terms_clauses', [ Module::class, 'terms' ] ) );
		$this->assertArrayNotHasKey( 'ordering', apply_filters( 'barrio_admin_data', [] ) );
		$this->assertSame( 404, $this->request( 'GET', '/ordering/settings' )->get_status() );
		$this->assertSame( '1', get_term_meta( $genre, '_barrio_order', true ) );

		Module::uninstall();
		$this->assertSame( '', get_term_meta( $genre, '_barrio_order', true ), 'Deleting the plugin with its data deletes the order of the terms.' );
		$this->assertSame( 1, get_post_field( 'menu_order', $old ), 'menu_order is of WordPress.' );
	}

	public function test_reorder_is_in_the_menu_of_the_list_for_who_can_reorder_it(): void {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		$names = [ 'menu', 'submenu', '_registered_pages', '_parent_pages', 'admin_page_hooks', '_wp_submenu_nopriv' ];
		$saved = array_map( static fn( string $name ): mixed => $GLOBALS[ $name ] ?? null, $names );
		// Le voci di un menu: slug => titolo.
		$items = static fn( string $menu ): array => array_column( $GLOBALS['submenu'][ $menu ] ?? [], 0, 2 );
		$this->request(
			'PUT',
			'/ordering/settings',
			[
				'post_types' => [ 'page', 'book' ],
				'taxonomies' => [ 'category', 'genre' ],
			]
		);

		foreach ( [ 'administrator', 'editor', 'author' ] as $role ) {
			$GLOBALS['submenu'] = []; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
			wp_set_current_user( self::factory()->user->create( [ 'role' => $role ] ) );
			Admin::menu();
			Module::menu();
			$pages = array_keys( apply_filters( 'barrio_admin_data', [] )['ordering']['pages'] ?? [] );
			if ( 'author' === $role ) {
				$this->assertSame( [], $items( 'edit.php?post_type=page' ) + $items( 'edit.php' ), 'An author does not reorder.' );
				$this->assertSame( [], $pages );
				continue;
			}
			$this->assertSame( [ 'barrio-order-post-page' => 'Reorder Pages' ], $items( 'edit.php?post_type=page' ), "After All Pages and Add Page ({$role})." );
			$this->assertSame(
				[
					'barrio-order-post-book'  => 'Reorder Books',
					'barrio-order-term-genre' => 'Reorder Genres',
				],
				$items( 'edit.php?post_type=book' )
			);
			$this->assertSame( [ 'barrio-order-term-category' => 'Reorder Categories' ], $items( 'edit.php' ), 'The terms in the menu of their content type.' );
			$this->assertSame( [ 'barrio-order-post-page', 'barrio-order-post-book', 'barrio-order-term-category', 'barrio-order-term-genre' ], $pages );
			$this->assertSame( 'administrator' === $role ? [ 'barrio-editor' ] : [], array_values( array_intersect( array_column( $GLOBALS['submenu']['barrio'] ?? [], 2 ), [ 'barrio-editor' ] ) ), 'The settings, in Barrio → Editor, only for administrators.' );
		}

		foreach ( $names as $index => $name ) {
			$GLOBALS[ $name ] = $saved[ $index ]; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- i menu di WordPress.
		}
	}

	/** @param array<string, mixed> $body */
	private function request( string $method, string $path, array $body = [] ): WP_REST_Response {
		$request = new WP_REST_Request( $method, '/barrio/v1' . $path );
		$request->set_body_params( $body );
		return rest_do_request( $request );
	}

	/** @return array<string, mixed> */
	private function page( string $title, int $day, int $parent_id = 0 ): array {
		return [
			'post_type'   => 'page',
			'post_title'  => $title,
			'post_parent' => $parent_id,
			'post_date'   => "2026-01-0{$day} 10:00:00",
		];
	}

	/** @return array<string, mixed> */
	private function genre( string $name, int $parent_id = 0 ): array {
		return [
			'taxonomy' => 'genre',
			'name'     => $name,
			'parent'   => $parent_id,
		];
	}

	/**
	 * Gli argomenti di get_posts(), che come WP_Query non chiede un ordine.
	 *
	 * @param string|list<string>  $type
	 * @param array<string, mixed> $extra
	 * @return array<string, mixed>
	 */
	private function args( string|array $type, array $extra = [] ): array {
		return $extra + [
			'post_type'   => $type,
			'numberposts' => -1,
			'fields'      => 'ids',
			'orderby'     => '',
			'order'       => '',
		];
	}
}
