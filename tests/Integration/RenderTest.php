<?php

namespace BarrioSuite\Tests\Integration;

use BarrioSuite\Database\Db;
use BarrioSuite\Forms\Model\Field;
use BarrioSuite\Forms\Model\Form;
use BarrioSuite\Forms\Model\FormVersion;
use BarrioSuite\Forms\Repository\FormRepository;
use WP_Block_Type_Registry;
use WP_UnitTestCase;

final class RenderTest extends WP_UnitTestCase {

	private Form $form;

	public function set_up(): void {
		parent::set_up();
		$this->form = FormRepository::create(
			'Contact',
			[ 'en' => [ 'submit_label' => 'Send it' ] ],
			[],
			new FormVersion(
				[
					new Field(
						'name',
						'text',
						[ 'en' => [ 'label' => 'Name' ] ],
						true,
						[
							'pattern'      => '[A-Za-z ]+',
							'autocomplete' => 'name',
						]
					),
					new Field(
						'request',
						'radio',
						[
							'en' => [
								'label'   => 'Request',
								'options' => [ 'info' => '<b>Info</b>' ],
							],
						],
						true,
						[ 'choices' => [ 'info', 'quote' ] ]
					),
					new Field(
						'budget',
						'number',
						[ 'en' => [ 'label' => 'Budget' ] ],
						false,
						[ 'min' => 500 ],
						[
							'action' => 'show',
							'logic'  => 'all',
							'rules'  => [
								[
									'field'    => 'request',
									'operator' => '=',
									'value'    => 'quote',
								],
							],
						]
					),
					new Field( 'next', 'page_break', [ 'en' => [ 'label' => 'Continue' ] ] ),
					new Field( 'topics', 'checkbox', [ 'en' => [ 'label' => 'Topics' ] ], true, [ 'choices' => [ 'news' ] ] ),
					new Field( 'cv', 'file', [ 'en' => [ 'label' => 'CV' ] ], false, [ 'extensions' => [ 'pdf', 'exe' ] ] ),
					new Field( 'privacy', 'consent', [ 'en' => [ 'label' => 'I agree to the <a href="/privacy">policy</a><script>alert(1)</script>' ] ], true ),
					new Field( 'campaign', 'hidden', [ 'en' => [ 'label' => 'Campaign' ] ], false, [ 'param' => 'utm_campaign' ] ),
					new Field( 'source', 'hidden', [ 'en' => [ 'label' => 'Source' ] ], false, [ 'value' => 'landing' ] ),
				]
			)
		);
	}

	public function test_the_shortcode_renders_steps_fields_and_service_inputs(): void {
		$html = do_shortcode( "[barrio_form id=\"{$this->form->id}\"]" );

		$this->assertStringContainsString( 'action="' . esc_url( rest_url( "barrio/v1/forms/{$this->form->id}/entries" ) ) . '"', $html );
		$this->assertSame( 2, substr_count( $html, 'data-barrio-step' ) );
		$this->assertStringContainsString( "name=\"_barrio_version\" value=\"{$this->form->current->id}\"", $html );
		$this->assertStringContainsString( 'name="_barrio_lang" value="en"', $html );
		$this->assertStringContainsString( 'name="_barrio_hp"', $html );
		$this->assertSame( [ 'name', 'request', 'budget', 'topics', 'cv', 'privacy', 'campaign', 'source' ], self::captures( '/data-barrio-field="([a-z_]+)"/', $html ) );
		$this->assertMatchesRegularExpression( '/data-barrio-field="budget" data-conditions="\{[^"]*&quot;action&quot;:&quot;show&quot;/', $html );
		$this->assertStringContainsString( 'pattern="[A-Za-z ]+"', $html );
		$this->assertStringContainsString( 'autocomplete="name"', $html );
		// Senza JavaScript l'invio non passerebbe l'antispam: lo si dice.
		$this->assertStringContainsString( '<noscript>', $html );
		$this->assertStringContainsString( 'min="500"', $html );
		// Etichette con HTML limitato; le scelte sono testo.
		$this->assertStringContainsString( 'I agree to the <a href="/privacy">policy</a>alert(1)', $html );
		$this->assertStringContainsString( '&lt;b&gt;Info&lt;/b&gt;', $html );
		// Radio obbligatorio sul controllo; checkbox obbligatoria ("almeno una") sul gruppo.
		$this->assertMatchesRegularExpression( '/type="radio"[^>]*value="info" required/', $html );
		$this->assertStringContainsString( 'data-required', $html );
		$this->assertStringNotContainsString( 'name="topics[]" value="news" required', $html );
		// exe non è tra le estensioni ammesse da WordPress.
		$this->assertStringContainsString( 'accept=".pdf"', $html );
		$this->assertStringContainsString( 'name="campaign" data-param="utm_campaign"', $html );
		$this->assertStringContainsString( 'name="source" value="landing"', $html );
		$this->assertStringContainsString( '>Continue</button>', $html );
		$this->assertStringContainsString( '>Send it</button>', $html );
		$this->assertTrue( wp_style_is( 'barrio-forms' ) );
	}

	public function test_two_copies_of_a_form_have_distinct_ids(): void {
		$html = do_shortcode( "[barrio_form id=\"{$this->form->id}\"][barrio_form id=\"{$this->form->id}\"]" );
		$ids  = self::captures( '/ id="([^"]+)"/', $html );

		$this->assertSame( $ids, array_values( array_unique( $ids ) ) );
	}

	public function test_closed_trashed_and_missing_forms(): void {
		global $wpdb;
		$status = function ( string $status ): string {
			global $wpdb;
			$wpdb->update( Db::table( 'forms' ), [ 'status' => $status ], [ 'id' => $this->form->id ] );
			wp_cache_delete( $this->form->id, FormRepository::CACHE_GROUP );
			return do_shortcode( "[barrio_form id=\"{$this->form->id}\"]" );
		};

		$this->assertStringContainsString( 'no longer accepting submissions', $status( 'closed' ) );
		$this->assertSame( '', $status( 'trash' ) );
		$this->assertSame( '', do_shortcode( '[barrio_form id="999999"]' ) );
		$this->assertSame( '', do_shortcode( '[barrio_form]' ) );
	}

	public function test_the_block_renders_the_same_form(): void {
		$this->assertTrue( WP_Block_Type_Registry::get_instance()->is_registered( 'barrio/form' ) );

		$html = do_blocks( "<!-- wp:barrio/form {\"id\":{$this->form->id}} /-->" );
		$this->assertStringContainsString( 'data-barrio-form', $html );
		$this->assertSame( 8, substr_count( $html, 'data-barrio-field=' ) );
	}

	/** @return list<string> */
	private static function captures( string $pattern, string $html ): array {
		preg_match_all( $pattern, $html, $matches );
		return $matches[1];
	}
}
