<?php
/**
 * Integration tests for historical publisher estimation.
 *
 * @package Darven\WhoPublished
 */

use Darven\WhoPublished\Publisher\PublisherRetriever;
use Darven\WhoPublished\Tracker\RegisterMeta;

/**
 * Tests real WordPress evidence sources and estimate persistence.
 */
class Test_Publisher_Estimator extends WP_UnitTestCase {

	/**
	 * Registers metadata and enables estimation for each estimation test.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();
		RegisterMeta::register();
		update_option( 'darven_who_published_enable_estimation', true );
	}

	/**
	 * Removes state shared through the options and filter APIs.
	 *
	 * @return void
	 */
	public function tear_down(): void {
		delete_option( 'darven_who_published_enable_estimation' );
		remove_all_filters( 'darven_who_published_estimated_publisher' );
		parent::tear_down();
	}

	/**
	 * Catches legacy guessed IDs being read as booleans or promoted to confirmed.
	 *
	 * @return void
	 */
	public function test_legacy_estimated_user_id_has_priority_and_is_not_promoted_to_confirmed(): void {
		$legacy_id    = self::factory()->user->create( array( 'role' => 'editor' ) );
		$persisted_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$edit_last_id = self::factory()->user->create( array( 'role' => 'author' ) );
		$post_id      = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		global $wpdb;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.SlowDBQuery.slow_db_query_meta_key, WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- Simulates raw metadata written before the legacy boolean schema was registered.
		$wpdb->insert(
			$wpdb->postmeta,
			array(
				'post_id'    => $post_id,
				'meta_key'   => '_darven_who_published_author_was_guessed',
				'meta_value' => $legacy_id,
			)
		);
		// phpcs:enable
		wp_cache_delete( $post_id, 'post_meta' );
		update_post_meta( $post_id, '_darven_who_published_estimated_author', $persisted_id );
		update_post_meta( $post_id, '_darven_who_published_estimation_source', 'post_author' );
		update_post_meta( $post_id, '_edit_last', $edit_last_id );

		$identity = ( new PublisherRetriever() )->get_publisher( get_post( $post_id ) );

		$this->assertSame( 'estimated', $identity->status() );
		$this->assertSame( $legacy_id, $identity->user_id() );
		$this->assertSame( 'legacy', $identity->source() );
		$this->assertSame( '', get_post_meta( $post_id, '_darven_who_published_author', true ) );
	}

	/**
	 * Catches persisted estimates being replaced by a later editor attribution.
	 *
	 * @return void
	 */
	public function test_persisted_estimate_has_priority_over_edit_last(): void {
		$persisted_id = self::factory()->user->create( array( 'role' => 'editor' ) );
		$edit_last_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$post_id      = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		update_post_meta( $post_id, '_darven_who_published_estimated_author', $persisted_id );
		update_post_meta( $post_id, '_darven_who_published_estimation_source', 'latest_revision' );
		update_post_meta( $post_id, '_edit_last', $edit_last_id );

		$identity = ( new PublisherRetriever() )->get_publisher( get_post( $post_id ) );

		$this->assertSame( $persisted_id, $identity->user_id() );
		$this->assertSame( 'latest_revision', $identity->source() );
	}

	/**
	 * Catches an estimator that skips edit-last or changes the first estimate later.
	 *
	 * @return void
	 */
	public function test_edit_last_estimate_is_persisted_and_stable_after_later_edits(): void {
		$editor_id = self::factory()->user->create( array( 'role' => 'editor' ) );
		$later_id  = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$post_id   = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		update_post_meta( $post_id, '_edit_last', $editor_id );

		$retriever = new PublisherRetriever();
		$first     = $retriever->get_publisher( get_post( $post_id ) );
		update_post_meta( $post_id, '_edit_last', $later_id );
		$second = $retriever->get_publisher( get_post( $post_id ) );

		$this->assertSame( 'estimated', $first->status() );
		$this->assertSame( $editor_id, $first->user_id() );
		$this->assertSame( 'edit_last', $first->source() );
		$this->assertSame( (string) $editor_id, get_post_meta( $post_id, '_darven_who_published_estimated_author', true ) );
		$this->assertSame( 'edit_last', get_post_meta( $post_id, '_darven_who_published_estimation_source', true ) );
		$this->assertSame( $editor_id, $second->user_id() );
		$this->assertSame( 'edit_last', $second->source() );
	}

	/**
	 * Catches an estimator that ignores latest revisions before post author.
	 *
	 * @return void
	 */
	public function test_latest_revision_is_used_before_post_author_when_edit_last_is_absent(): void {
		$author_id   = self::factory()->user->create( array( 'role' => 'author' ) );
		$revision_id = self::factory()->user->create( array( 'role' => 'editor' ) );
		$post_id     = self::factory()->post->create(
			array(
				'post_author' => $author_id,
				'post_status' => 'publish',
			)
		);
		wp_insert_post(
			array(
				'post_author'  => $revision_id,
				'post_content' => 'Revision evidence',
				'post_parent'  => $post_id,
				'post_status'  => 'inherit',
				'post_type'    => 'revision',
			)
		);

		$identity = ( new PublisherRetriever() )->get_publisher( get_post( $post_id ) );

		$this->assertSame( 'estimated', $identity->status() );
		$this->assertSame( $revision_id, $identity->user_id() );
		$this->assertSame( 'latest_revision', $identity->source() );
	}

	/**
	 * Catches fallback behavior that does not label post-author evidence honestly.
	 *
	 * @return void
	 */
	public function test_post_author_is_used_when_no_higher_priority_evidence_exists(): void {
		$author_id = self::factory()->user->create( array( 'role' => 'author' ) );
		$post_id   = self::factory()->post->create(
			array(
				'post_author' => $author_id,
				'post_status' => 'publish',
			)
		);

		$identity = ( new PublisherRetriever() )->get_publisher( get_post( $post_id ) );

		$this->assertSame( 'estimated', $identity->status() );
		$this->assertSame( $author_id, $identity->user_id() );
		$this->assertSame( 'post_author', $identity->source() );
	}

	/**
	 * Catches filters that persist an estimate after explicit suppression.
	 *
	 * @return void
	 */
	public function test_estimated_publisher_filter_can_suppress_an_estimate_before_persistence(): void {
		$editor_id   = self::factory()->user->create( array( 'role' => 'editor' ) );
		$post_id     = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		$seen_source = '';
		$seen_post   = 0;
		update_post_meta( $post_id, '_edit_last', $editor_id );
		add_filter(
			'darven_who_published_estimated_publisher',
			function ( $estimated_user_id, $source, $post ) use ( &$seen_source, &$seen_post ) {
				$seen_source = $source;
				$seen_post   = $post->ID;
				return 0;
			},
			10,
			3
		);

		$identity = ( new PublisherRetriever() )->get_publisher( get_post( $post_id ) );

		$this->assertSame( 'unknown', $identity->status() );
		$this->assertSame( 0, $identity->user_id() );
		$this->assertSame( 'edit_last', $seen_source );
		$this->assertSame( $post_id, $seen_post );
		$this->assertSame( '', get_post_meta( $post_id, '_darven_who_published_estimated_author', true ) );
	}

	/**
	 * Catches registration that exposes estimation internals or omits pages.
	 *
	 * @return void
	 */
	public function test_estimation_metadata_is_private_and_registered_for_posts_and_pages(): void {
		$post_meta = get_registered_meta_keys( 'post', 'post' );
		$page_meta = get_registered_meta_keys( 'post', 'page' );

		foreach ( array( $post_meta, $page_meta ) as $registered_meta ) {
			$this->assertArrayHasKey( '_darven_who_published_estimated_author', $registered_meta );
			$this->assertSame( 'integer', $registered_meta['_darven_who_published_estimated_author']['type'] );
			$this->assertTrue( $registered_meta['_darven_who_published_estimated_author']['single'] );
			$this->assertFalse( $registered_meta['_darven_who_published_estimated_author']['show_in_rest'] );
			$this->assertArrayHasKey( '_darven_who_published_estimation_source', $registered_meta );
			$this->assertSame( 'string', $registered_meta['_darven_who_published_estimation_source']['type'] );
			$this->assertTrue( $registered_meta['_darven_who_published_estimation_source']['single'] );
			$this->assertFalse( $registered_meta['_darven_who_published_estimation_source']['show_in_rest'] );
		}
	}
}
