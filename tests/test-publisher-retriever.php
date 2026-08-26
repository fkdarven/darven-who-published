<?php
/**
 * Integration tests for publisher identity retrieval.
 *
 * @package Darven\WhoPublished
 */

use Darven\WhoPublished\Publisher\PublisherIdentity;
use Darven\WhoPublished\Publisher\PublisherRetriever;

/**
 * Tests explicit publisher identity states through real WordPress metadata.
 */
class Test_Publisher_Retriever extends WP_UnitTestCase {

	/**
	 * Removes global setting and filter state after a test.
	 *
	 * @return void
	 */
	public function tear_down(): void {
		delete_option( 'darven_who_published_enable_estimation' );
		remove_all_filters( 'darven_who_published_estimation_enabled' );
		parent::tear_down();
	}

	/**
	 * Catches unconditional historical guessing while estimation is disabled.
	 *
	 * @return void
	 */
	public function test_historical_post_is_unknown_by_default_without_persisting_an_estimate(): void {
		$editor_id = self::factory()->user->create( array( 'role' => 'editor' ) );
		$post_id   = self::factory()->post->create(
			array(
				'post_author' => $editor_id,
				'post_status' => 'publish',
			)
		);
		update_post_meta( $post_id, '_edit_last', $editor_id );

		$identity = ( new PublisherRetriever() )->get_publisher( get_post( $post_id ) );

		$this->assertSame( 'unknown', $identity->status() );
		$this->assertSame( 0, $identity->user_id() );
		$this->assertSame( '', $identity->source() );
		$this->assertSame( '', get_post_meta( $post_id, '_darven_who_published_estimated_author', true ) );
	}

	/**
	 * Catches an estimated legacy value overwriting or beating confirmed data.
	 *
	 * @return void
	 */
	public function test_confirmed_publisher_always_wins_without_mutating_legacy_estimation_metadata(): void {
		$confirmed_id = self::factory()->user->create( array( 'role' => 'editor' ) );
		$legacy_id    = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$post_id      = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		update_post_meta( $post_id, '_darven_who_published_author', $confirmed_id );
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
		update_option( 'darven_who_published_enable_estimation', true );

		$identity = ( new PublisherRetriever() )->get_publisher( get_post( $post_id ) );

		$this->assertSame( 'confirmed', $identity->status() );
		$this->assertSame( $confirmed_id, $identity->user_id() );
		$this->assertSame( '', $identity->source() );
		$this->assertSame( (string) $legacy_id, get_post_meta( $post_id, '_darven_who_published_author_was_guessed', true ) );
	}

	/**
	 * Catches retrieval that estimates statuses outside the permitted set.
	 *
	 * @return void
	 */
	public function test_disallowed_post_status_is_unknown_even_when_estimation_is_enabled(): void {
		$editor_id = self::factory()->user->create( array( 'role' => 'editor' ) );
		$post_id   = self::factory()->post->create(
			array(
				'post_author' => $editor_id,
				'post_status' => 'draft',
			)
		);
		update_post_meta( $post_id, '_edit_last', $editor_id );
		update_option( 'darven_who_published_enable_estimation', true );

		$identity = ( new PublisherRetriever() )->get_publisher( get_post( $post_id ) );

		$this->assertSame( 'unknown', $identity->status() );
		$this->assertSame( '', get_post_meta( $post_id, '_darven_who_published_estimated_author', true ) );
	}

	/**
	 * Catches the setting filter being ignored or missing the post context.
	 *
	 * @return void
	 */
	public function test_estimation_enabled_filter_can_enable_estimation_with_the_current_post_context(): void {
		$editor_id    = self::factory()->user->create( array( 'role' => 'editor' ) );
		$post_id      = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		$seen_post_id = 0;
		update_post_meta( $post_id, '_edit_last', $editor_id );
		add_filter(
			'darven_who_published_estimation_enabled',
			function ( $enabled, $post ) use ( &$seen_post_id ) {
				$seen_post_id = $post->ID;
				return true;
			},
			10,
			2
		);

		$identity = ( new PublisherRetriever() )->get_publisher( get_post( $post_id ) );

		$this->assertSame( 'estimated', $identity->status() );
		$this->assertSame( $editor_id, $identity->user_id() );
		$this->assertSame( 'edit_last', $identity->source() );
		$this->assertSame( $post_id, $seen_post_id );
	}

	/**
	 * Catches identity factories accepting impossible IDs or evidence sources.
	 *
	 * @return void
	 */
	public function test_identity_factories_validate_ids_and_estimation_sources(): void {
		$unknown = PublisherIdentity::unknown();

		$this->assertSame( 'unknown', $unknown->status() );
		$this->assertSame( 0, $unknown->user_id() );
		$this->assertSame( '', $unknown->source() );
		$this->assertSame( 'legacy', PublisherIdentity::estimated( 23, 'legacy' )->source() );
		$this->assertSame( 'edit_last', PublisherIdentity::estimated( 23, 'edit_last' )->source() );
		$this->assertSame( 'latest_revision', PublisherIdentity::estimated( 23, 'latest_revision' )->source() );
		$this->assertSame( 'post_author', PublisherIdentity::estimated( 23, 'post_author' )->source() );

		$this->expectException( InvalidArgumentException::class );
		PublisherIdentity::confirmed( 0 );
	}

	/**
	 * Catches invalid estimated identities being represented as valid states.
	 *
	 * @return void
	 */
	public function test_estimated_identity_rejects_zero_ids_and_unknown_sources(): void {
		try {
			PublisherIdentity::estimated( 0, 'legacy' );
			$this->fail( 'Estimated identities must reject a zero user ID.' );
		} catch ( InvalidArgumentException $exception ) {
			$this->assertSame( 'Estimated publisher IDs must be positive.', $exception->getMessage() );
		}

		$this->expectException( InvalidArgumentException::class );
		PublisherIdentity::estimated( 23, 'manual' );
	}
}
