<?php

namespace BarrioSuite\Tests\Integration;

use BarrioSuite\Launch;
use BarrioSuite\Seo\Admin;
use BarrioSuite\Seo\Module;
use BarrioSuite\Seo\RedirectCode;
use BarrioSuite\Seo\Repository\RedirectRepository;
use BarrioSuite\Seo\Settings;
use WP_REST_Request;
use WP_REST_Response;
use WP_UnitTestCase;

// Le pagine SEO: Contenuti, Redirect, la sitemap, l'anteprima dello schema, la card della Bacheca e la messa online.
final class SeoAdminTest extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		Module::register_meta();
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
	}

	public function test_contents_have_what_the_panel_and_the_score_need(): void {
		$this->set_permalink_structure( '/%postname%/' );
		$page  = self::factory()->post->create(
			[
				'post_type'  => 'page',
				'post_title' => 'Chi siamo',
			]
		);
		$draft = self::factory()->post->create(
			[
				'post_type'   => 'page',
				'post_title'  => 'Nuova pagina',
				'post_status' => 'draft',
			]
		);
		self::factory()->post->create(
			[
				'post_type'   => 'page',
				'post_status' => 'trash',
			]
		);
		update_post_meta( $page, '_barrio_seo_index', 'noindex' );

		$response = $this->rest( 'GET', '/barrio/v1/seo/contents/post_types/page' );

		$this->assertSame( 200, $response->get_status(), (string) wp_json_encode( $response->get_data() ) );
		$data = $response->get_data();
		$rows = array_column( $data['rows'], null, 'id' );
		$this->assertSame( [ $page, $draft ], array_keys( $rows ), 'Without the trash.' );
		$this->assertSame( [ 'Chi siamo', 'noindex', '' ], [ $rows[ $page ]['page']['title'], $rows[ $page ]['meta']['_barrio_seo_index'], $rows[ $page ]['meta']['_barrio_seo_title'] ] );
		$this->assertStringContainsString( 'nuova-pagina', $rows[ $draft ]['page']['url'], 'A draft has the address it will have, for the score.' );
		$this->assertSame( 'draft', $rows[ $draft ]['status'] );
		$this->assertSame( [ 'en', 'en' ], array_column( $rows, 'lang' ), 'Without a multilingual plugin, the language of the site.' );
		$this->assertSame( [ 'en' ], array_keys( $data['contexts'] ) );
		$this->assertTrue( $data['contexts']['en']['robots']['index'] );
		$this->assertContains( 'post', array_column( $data['contexts']['en']['collections'], 'value' ), 'Pages can be the archive of a type.' );

		$term = self::factory()->category->create( [ 'name' => 'Pizze' ] );
		$rows = $this->rest( 'GET', '/barrio/v1/seo/contents/taxonomies/category' )->get_data()['rows'];
		$this->assertContains( $term, array_column( $rows, 'id' ) );
		$this->assertNull( $rows[0]['page']['content'], 'Terms have no content checks.' );

		$this->assertSame( 404, $this->rest( 'GET', '/barrio/v1/seo/contents/post_types/attachment' )->get_status() );
		$this->assertSame( 404, $this->rest( 'GET', '/barrio/v1/seo/contents/taxonomies/nav_menu' )->get_status() );

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );
		$this->assertSame( 403, $this->rest( 'GET', '/barrio/v1/seo/contents/post_types/page' )->get_status(), 'Editors only have the panel.' );
	}

	public function test_redirects_are_added_edited_searched_and_deleted(): void {
		$changed = 0;
		add_action(
			'barrio_seo_changed',
			static function ( string $what ) use ( &$changed ): void {
				$changed += (int) ( 'redirects' === $what );
			}
		);

		$created = $this->redirect( 'POST', '/barrio/v1/seo/redirects', home_url( '/Vecchia-Pagina/?ref=1' ), '/nuova-pagina/', 301, 'Dal vecchio sito' );
		$this->assertSame( 201, $created->get_status(), (string) wp_json_encode( $created->get_data() ) );
		$data = $created->get_data();
		$this->assertSame( [ '/Vecchia-Pagina?ref=1', '/nuova-pagina/', 301, false ], [ $data['source'], $data['target'], $data['code'], $data['is_auto'] ], 'The source is a path, as the site reads it.' );
		$id = $data['id'];

		$gone = $this->redirect( 'POST', '/barrio/v1/seo/redirects', '/chiuso', '/ignorato', 410 );
		$this->assertSame( [ 201, null ], [ $gone->get_status(), $gone->get_data()['target'] ], 'A 410 has no destination.' );

		foreach (
			[
				'already used'        => [ '/Vecchia-Pagina/?ref=1', '/altro', 301 ],
				'without destination' => [ '/senza', '', 301 ],
				'relative without /'  => [ '/senza', 'nuova', 302 ],
				'another site'        => [ '/senza', '//altro-sito.example/x', 302 ],
				'loop'                => [ '/giro', home_url( '/giro/' ), 301 ],
				'unknown code'        => [ '/codice', '/x', 303 ],
			] as $case => [ $source, $target, $code ]
		) {
			$this->assertSame( 400, $this->redirect( 'POST', '/barrio/v1/seo/redirects', $source, $target, $code )->get_status(), $case );
		}

		$updated = $this->redirect( 'PUT', "/barrio/v1/seo/redirects/{$id}", '/vecchia-pagina', 'https://example.org/', 308 );
		$this->assertSame( [ 200, '/vecchia-pagina', 308, null ], [ $updated->get_status(), $updated->get_data()['source'], $updated->get_data()['code'], $updated->get_data()['note'] ] );
		$this->assertSame( 404, $this->redirect( 'PUT', '/barrio/v1/seo/redirects/999999', '/a', '/b', 301 )->get_status() );

		$list = $this->rest( 'GET', '/barrio/v1/seo/redirects', [ 'search' => 'VECCHIA' ] )->get_data();
		$this->assertSame( [ 1, [ $id ] ], [ $list['total'], array_column( $list['rows'], 'id' ) ], 'The search ignores capitals, also on the source.' );
		$list = $this->rest(
			'GET',
			'/barrio/v1/seo/redirects',
			[
				'code' => 410,
				'auto' => false,
			]
		)->get_data();
		$this->assertSame( [ '/chiuso' ], array_column( $list['rows'], 'source' ) );
		$list = $this->rest(
			'GET',
			'/barrio/v1/seo/redirects',
			[
				'sort'  => 'source',
				'order' => 'asc',
			]
		)->get_data();
		$this->assertSame( [ '/chiuso', '/vecchia-pagina' ], array_column( $list['rows'], 'source' ) );
		$this->redirect( 'PUT', "/barrio/v1/seo/redirects/{$id}", '/vecchia-pagina', 'https://example.org/', 308, 'Campagna 2025' );
		$list = $this->rest(
			'GET',
			'/barrio/v1/seo/redirects',
			[
				'sort'  => 'note',
				'order' => 'desc',
			]
		)->get_data();
		$this->assertSame( [ '/vecchia-pagina', '/chiuso' ], array_column( $list['rows'], 'source' ), 'Every column sorts, the note too.' );

		$deleted = $this->rest(
			'POST',
			'/barrio/v1/seo/redirects/bulk',
			[
				'action' => 'delete',
				'ids'    => [ $id, $gone->get_data()['id'] ],
			]
		);
		$this->assertSame( 2, $deleted->get_data()['deleted'] );
		$this->assertSame( 0, RedirectRepository::count() );
		$this->assertSame( 5, $changed, 'Two added, two edited, one bulk delete.' );

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );
		$this->assertSame( 403, $this->rest( 'GET', '/barrio/v1/seo/redirects' )->get_status() );
	}

	public function test_a_redirect_that_leads_back_to_its_source_is_rejected(): void {
		$first = $this->redirect( 'POST', '/barrio/v1/seo/redirects', '/giro-a', '/giro-b/', 301 );
		$this->redirect( 'POST', '/barrio/v1/seo/redirects', '/giro-b', '/giro-c', 302 );
		$this->assertSame( 400, $this->redirect( 'POST', '/barrio/v1/seo/redirects', '/giro-c', home_url( '/giro-a' ), 301 )->get_status(), 'Through other redirects too.' );
		$this->assertSame( 201, $this->redirect( 'POST', '/barrio/v1/seo/redirects', '/giro-c', 'https://example.net/giro-a', 301 )->get_status(), 'Another site ends the chain.' );

		$this->assertSame( 201, $first->get_status() );

		$swap = $this->redirect( 'POST', '/barrio/v1/seo/redirects', '/solo-x', '/solo-y', 301 );
		$this->assertSame( 200, $this->redirect( 'PUT', "/barrio/v1/seo/redirects/{$swap->get_data()['id']}", '/solo-y', '/solo-x', 301 )->get_status(), 'The saved version of the same redirect does not count.' );
	}

	public function test_the_sitemap_counts_are_those_of_the_sitemap_of_wordpress(): void {
		// Un sito che chiede di non essere indicizzato: il core non registra i provider della sitemap.
		update_option( 'blog_public', '0' );
		Settings::save( [ 'index' => [ 'archives' => [ 'author' => [ 'index' => false ] ] ] ] );
		$GLOBALS['wp_sitemaps'] = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- la sitemap del core, da ricreare.

		$before = array_map( 'get_object_vars', $this->rest( 'GET', '/barrio/v1/seo/settings/sitemap' )->get_data()['counts'] );

		self::factory()->post->create();
		update_post_meta( self::factory()->post->create(), '_barrio_seo_index', 'noindex' );
		self::factory()->post->create( [ 'post_password' => 'segreto' ] );
		$after  = $this->rest( 'GET', '/barrio/v1/seo/settings/sitemap' )->get_data();
		$counts = array_map( 'get_object_vars', $after['counts'] );

		$this->assertSame( ( $before['post_types']['post'] ?? 0 ) + 1, $counts['post_types']['post'], 'Neither noindex nor protected contents.' );
		$this->assertArrayNotHasKey( 'author', $counts['archives'], 'Authors only with their archives indexed.' );
		$this->assertSame( array_sum( array_map( 'array_sum', $counts ) ), $after['total'] );

		require_once ABSPATH . 'wp-admin/includes/file.php';
		$data = Admin::data( [] )['seo'];
		$this->assertFalse( $data['sitemap']['enabled'], 'WordPress does not publish it, but the counts are what it will contain.' );
		$this->assertFalse( $data['robots']['file'] );
	}

	public function test_the_dashboard_counts_indexed_contents_without_description_and_redirects(): void {
		$with = self::factory()->post->create();
		update_post_meta( $with, '_barrio_seo_description', 'Una descrizione.' );
		self::factory()->post->create();
		update_post_meta( self::factory()->post->create(), '_barrio_seo_index', 'noindex' );
		self::factory()->post->create( [ 'post_status' => 'draft' ] );
		self::factory()->post->create( [ 'post_type' => 'page' ] );
		RedirectRepository::create( '/a', '/b', RedirectCode::MovedPermanently );

		$this->assertSame(
			[
				'indexed'             => 3,
				'without_description' => 2,
				'redirects'           => 1,
			],
			$this->rest( 'GET', '/barrio/v1/seo/stats' )->get_data()
		);
	}

	public function test_the_schema_preview_uses_the_organization_being_written(): void {
		$organization = [
			'name'  => 'Pizzeria da Mario',
			'phone' => '+39 011 123456',
		] + Settings::get()['organization'];

		$node = $this->rest(
			'POST',
			'/barrio/v1/seo/settings/schema',
			[
				'organization' => $organization,
				'description'  => 'Pizza a Torino.',
			]
		)->get_data();

		$this->assertSame( [ 'LocalBusiness', 'Pizzeria da Mario', '+39 011 123456', 'Pizza a Torino.' ], [ $node['@type'], $node['name'], $node['telephone'], $node['description'] ] );
		$this->assertFalse( get_option( 'barrio_seo' ), 'Nothing is saved.' );
		$this->assertSame( 400, $this->rest( 'POST', '/barrio/v1/seo/settings/schema', [ 'organization' => [ 'type' => 'Pizzeria' ] + $organization ] )->get_status() );
	}

	public function test_the_launch_step_needs_default_texts_and_the_organization(): void {
		$this->assertFalse( Launch::steps()['seo'] );

		$image = self::factory()->attachment->create();
		Settings::save(
			[
				'default_image' => $image,
				'organization'  => [
					'name' => 'Pizzeria da Mario',
					'logo' => $image,
				] + Settings::get()['organization'],
			]
		);
		$this->assertFalse( Launch::steps()['seo'], 'The default description is missing.' );

		Settings::save(
			[
				'texts' => [
					'en' => [
						'default_description'      => 'Pizza in Turin.',
						'organization_description' => '',
						'llms_intro'               => '',
					],
				],
			]
		);
		$this->assertTrue( Launch::steps()['seo'] );
	}

	public function test_the_admin_data_lists_pages_first_then_the_other_types_and_the_taxonomies(): void {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		$types = Admin::data( [] )['seo']['types'];

		$this->assertSame( [ 'page', 'post' ], array_slice( array_column( $types, 'name' ), 0, 2 ) );
		$this->assertNotContains( 'attachment', array_column( $types, 'name' ) );
		$this->assertSame( 'wp/v2/categories', array_column( $types, 'rest', 'name' )['category'] );
		$this->assertSame( 'taxonomies', end( $types )['group'] );
	}

	/** @param array<string, mixed> $params */
	private function rest( string $method, string $route, array $params = [] ): WP_REST_Response {
		$request = new WP_REST_Request( $method, $route );
		if ( 'GET' === $method ) {
			$request->set_query_params( $params );
		} else {
			$request->set_body_params( $params );
		}
		return rest_do_request( $request );
	}

	private function redirect( string $method, string $route, string $source, string $target, int $code, string $note = '' ): WP_REST_Response {
		return $this->rest(
			$method,
			$route,
			[
				'source' => $source,
				'target' => $target,
				'code'   => $code,
				'note'   => $note,
			]
		);
	}
}
