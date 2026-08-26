<?php
/**
 * Integration tests for publisher editor metaboxes.
 *
 * @package Darven\WhoPublished
 */

use Darven\WhoPublished\Admin\MetaBoxDisplay;
use Darven\WhoPublished\Tracker\RegisterMeta;

/**
 * Tests metabox registration and safely rendered publisher identity markup.
 */
class Test_Meta_Box_Display extends WP_UnitTestCase {

	/**
	 * Registers the shared metadata schema.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();
		RegisterMeta::register();
	}

	/**
	 * Removes the estimation setting after each test.
	 *
	 * @return void
	 */
	public function tear_down(): void {
		delete_option( 'darven_who_published_enable_estimation' );
		parent::tear_down();
	}

	/**
	 * Catches the legacy metabox title that names an ambiguous actor.
	 *
	 * @return void
	 */
	public function test_metabox_is_registered_as_published_by_for_posts_and_pages(): void {
		$display = new MetaBoxDisplay();
		$display->register();
		do_action( 'add_meta_boxes' );

		$this->assertSame( 'Published by', $GLOBALS['wp_meta_boxes']['post']['side']['core']['darven-who-published-meta']['title'] );
		$this->assertSame( 'Published by', $GLOBALS['wp_meta_boxes']['page']['side']['core']['darven-who-published-meta']['title'] );
	}

	/**
	 * Catches rendering badge markup as escaped text.
	 *
	 * @return void
	 */
	public function test_confirmed_metabox_renders_permitted_badge_markup(): void {
		$publisher_id = self::factory()->user->create(
			array(
				'role'         => 'editor',
				'display_name' => 'Metabox Publisher',
			)
		);
		$post_id      = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		update_post_meta( $post_id, DARVEN_WHO_PUBLISHED_ORIGINAL_AUTHOR, $publisher_id );

		ob_start();
		( new MetaBoxDisplay() )->render( get_post( $post_id ) );
		$output = (string) ob_get_clean();

		$this->assertStringContainsString( '<span', $output );
		$this->assertStringNotContainsString( '&lt;span', $output );
		$this->assertStringContainsString( 'darven-badge-confirmed', $output );
		$this->assertStringContainsString( 'Metabox Publisher', $output );
	}

	/**
	 * Catches an unknown editor state presented as an empty or invented publisher.
	 *
	 * @return void
	 */
	public function test_unknown_metabox_renders_unknown_without_a_link(): void {
		$post_id = self::factory()->post->create( array( 'post_status' => 'publish' ) );

		ob_start();
		( new MetaBoxDisplay() )->render( get_post( $post_id ) );
		$output = (string) ob_get_clean();

		$this->assertStringContainsString( 'Unknown', $output );
		$this->assertStringNotContainsString( '<a ', $output );
	}
}
