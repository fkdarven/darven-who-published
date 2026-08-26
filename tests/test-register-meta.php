<?php
/**
 * Integration tests for confirmed publisher metadata registration.
 *
 * @package Darven\WhoPublished
 */

/**
 * Tests the public meta contract for supported WordPress content types.
 */
class Test_Register_Meta extends WP_UnitTestCase {

	/**
	 * Restores plugin metadata registration after WordPress test cleanup.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();
		\Darven\WhoPublished\Tracker\RegisterMeta::register();
	}

	/**
	 * Catches registration that leaves pages without the confirmed publisher schema.
	 *
	 * @return void
	 */
	public function test_confirmed_publisher_meta_is_registered_as_a_single_integer_for_posts_and_pages(): void {
		$post_meta = get_registered_meta_keys( 'post', 'post' );
		$page_meta = get_registered_meta_keys( 'post', 'page' );

		$this->assertArrayHasKey( '_darven_who_published_author', $post_meta );
		$this->assertSame( 'integer', $post_meta['_darven_who_published_author']['type'] );
		$this->assertTrue( $post_meta['_darven_who_published_author']['single'] );

		$this->assertArrayHasKey( '_darven_who_published_author', $page_meta );
		$this->assertSame( 'integer', $page_meta['_darven_who_published_author']['type'] );
		$this->assertTrue( $page_meta['_darven_who_published_author']['single'] );
	}
}
