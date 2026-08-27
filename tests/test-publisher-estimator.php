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
	 * Catches legacy and persisted estimates bypassing explicit filter suppression.
	 *
	 * @return void
	 */
	public function test_estimated_publisher_filter_can_suppress_every_estimate_path(): void {
		$legacy_id    = self::factory()->user->create( array( 'role' => 'editor' ) );
		$persisted_id = self::factory()->user->create( array( 'role' => 'editor' ) );
		$computed_id  = self::factory()->user->create( array( 'role' => 'editor' ) );
		$legacy_post  = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		$stored_post  = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		$new_post     = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		global $wpdb;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.SlowDBQuery.slow_db_query_meta_key, WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- Simulates the legacy integer schema before its boolean registration.
		$wpdb->insert(
			$wpdb->postmeta,
			array(
				'post_id'    => $legacy_post,
				'meta_key'   => DARVEN_WHO_PUBLISHED_WAS_GUESSED,
				'meta_value' => $legacy_id,
			)
		);
		// phpcs:enable
		wp_cache_delete( $legacy_post, 'post_meta' );
		update_post_meta( $stored_post, '_darven_who_published_estimated_author', $persisted_id );
		update_post_meta( $stored_post, '_darven_who_published_estimation_source', 'latest_revision' );
		update_post_meta( $new_post, '_edit_last', $computed_id );

		$seen = array();
		add_filter(
			'darven_who_published_estimated_publisher',
			function ( $estimated_user_id, $source, $post ) use ( &$seen ) {
				$seen[ $post->ID ] = array( $estimated_user_id, $source );
				return 0;
			},
			10,
			3
		);

		$retriever = new PublisherRetriever();
		foreach ( array( $legacy_post, $stored_post, $new_post ) as $post_id ) {
			$identity = $retriever->get_publisher( get_post( $post_id ) );
			$this->assertSame( 'unknown', $identity->status(), (string) $post_id );
			$this->assertSame( 0, $identity->user_id(), (string) $post_id );
			$this->assertSame( '', get_post_meta( $post_id, DARVEN_WHO_PUBLISHED_ORIGINAL_AUTHOR, true ) );
		}

		$this->assertSame( array( $legacy_id, 'legacy' ), $seen[ $legacy_post ] );
		$this->assertSame( array( $persisted_id, 'latest_revision' ), $seen[ $stored_post ] );
		$this->assertSame( array( $computed_id, 'edit_last' ), $seen[ $new_post ] );
		$this->assertSame( (string) $persisted_id, get_post_meta( $stored_post, '_darven_who_published_estimated_author', true ) );
		$this->assertSame( '', get_post_meta( $new_post, '_darven_who_published_estimated_author', true ) );
	}

	/**
	 * Catches estimate overrides being skipped or written into the wrong metadata path.
	 *
	 * @return void
	 */
	public function test_estimated_publisher_filter_can_override_every_estimate_path(): void {
		$override_id  = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$legacy_id    = self::factory()->user->create( array( 'role' => 'editor' ) );
		$persisted_id = self::factory()->user->create( array( 'role' => 'editor' ) );
		$computed_id  = self::factory()->user->create( array( 'role' => 'editor' ) );
		$legacy_post  = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		$stored_post  = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		$new_post     = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		global $wpdb;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.SlowDBQuery.slow_db_query_meta_key, WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- Simulates the legacy integer schema before its boolean registration.
		$wpdb->insert(
			$wpdb->postmeta,
			array(
				'post_id'    => $legacy_post,
				'meta_key'   => DARVEN_WHO_PUBLISHED_WAS_GUESSED,
				'meta_value' => $legacy_id,
			)
		);
		// phpcs:enable
		wp_cache_delete( $legacy_post, 'post_meta' );
		update_post_meta( $stored_post, '_darven_who_published_estimated_author', $persisted_id );
		update_post_meta( $stored_post, '_darven_who_published_estimation_source', 'post_author' );
		update_post_meta( $new_post, '_edit_last', $computed_id );
		add_filter(
			'darven_who_published_estimated_publisher',
			function () use ( $override_id ) {
				return $override_id;
			},
			10,
			3
		);

		$retriever = new PublisherRetriever();
		$expected  = array(
			$legacy_post => 'legacy',
			$stored_post => 'post_author',
			$new_post    => 'edit_last',
		);
		foreach ( $expected as $post_id => $source ) {
			$identity = $retriever->get_publisher( get_post( $post_id ) );
			$this->assertSame( 'estimated', $identity->status(), (string) $post_id );
			$this->assertSame( $override_id, $identity->user_id(), (string) $post_id );
			$this->assertSame( $source, $identity->source(), (string) $post_id );
			$this->assertSame( '', get_post_meta( $post_id, DARVEN_WHO_PUBLISHED_ORIGINAL_AUTHOR, true ) );
		}

		$this->assertSame( '', get_post_meta( $legacy_post, '_darven_who_published_estimated_author', true ) );
		$this->assertSame( (string) $persisted_id, get_post_meta( $stored_post, '_darven_who_published_estimated_author', true ) );
		$this->assertSame( (string) $override_id, get_post_meta( $new_post, '_darven_who_published_estimated_author', true ) );
	}

	/**
	 * Catches filter outputs that do not identify an existing WordPress user.
	 *
	 * @return void
	 */
	public function test_estimated_publisher_filter_rejects_nonexistent_users_on_every_estimate_path(): void {
		$legacy_id    = self::factory()->user->create( array( 'role' => 'editor' ) );
		$persisted_id = self::factory()->user->create( array( 'role' => 'editor' ) );
		$computed_id  = self::factory()->user->create( array( 'role' => 'editor' ) );
		$legacy_post  = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		$stored_post  = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		$new_post     = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		global $wpdb;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.SlowDBQuery.slow_db_query_meta_key, WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- Simulates the legacy integer schema before its boolean registration.
		$wpdb->insert(
			$wpdb->postmeta,
			array(
				'post_id'    => $legacy_post,
				'meta_key'   => DARVEN_WHO_PUBLISHED_WAS_GUESSED,
				'meta_value' => $legacy_id,
			)
		);
		// phpcs:enable
		wp_cache_delete( $legacy_post, 'post_meta' );
		update_post_meta( $stored_post, '_darven_who_published_estimated_author', $persisted_id );
		update_post_meta( $stored_post, '_darven_who_published_estimation_source', 'latest_revision' );
		update_post_meta( $new_post, '_edit_last', $computed_id );
		add_filter(
			'darven_who_published_estimated_publisher',
			function () {
				return 999999;
			}
		);

		$retriever = new PublisherRetriever();
		foreach ( array( $legacy_post, $stored_post, $new_post ) as $post_id ) {
			$identity = $retriever->get_publisher( get_post( $post_id ) );
			$this->assertSame( 'unknown', $identity->status(), (string) $post_id );
		}
		$this->assertSame( '', get_post_meta( $new_post, '_darven_who_published_estimated_author', true ) );
	}

	/**
	 * Catches negative filter results being normalized into an existing user ID.
	 *
	 * @return void
	 */
	public function test_estimated_publisher_filter_rejects_negative_existing_user_on_every_estimate_path(): void {
		$negative_user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$legacy_id        = self::factory()->user->create( array( 'role' => 'editor' ) );
		$persisted_id     = self::factory()->user->create( array( 'role' => 'editor' ) );
		$computed_id      = self::factory()->user->create( array( 'role' => 'editor' ) );
		$legacy_post      = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		$stored_post      = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		$new_post         = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		global $wpdb;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.SlowDBQuery.slow_db_query_meta_key, WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- Simulates the legacy integer schema before its boolean registration.
		$wpdb->insert(
			$wpdb->postmeta,
			array(
				'post_id'    => $legacy_post,
				'meta_key'   => DARVEN_WHO_PUBLISHED_WAS_GUESSED,
				'meta_value' => $legacy_id,
			)
		);
		// phpcs:enable
		wp_cache_delete( $legacy_post, 'post_meta' );
		update_post_meta( $stored_post, '_darven_who_published_estimated_author', $persisted_id );
		update_post_meta( $stored_post, '_darven_who_published_estimation_source', 'latest_revision' );
		update_post_meta( $new_post, '_edit_last', $computed_id );
		add_filter(
			'darven_who_published_estimated_publisher',
			function () use ( $negative_user_id ) {
				return -$negative_user_id;
			}
		);

		$retriever = new PublisherRetriever();
		foreach ( array( $legacy_post, $stored_post, $new_post ) as $post_id ) {
			$identity = $retriever->get_publisher( get_post( $post_id ) );
			$this->assertSame( 'unknown', $identity->status(), (string) $post_id );
			$this->assertSame( 0, $identity->user_id(), (string) $post_id );
		}
		$this->assertSame( '', get_post_meta( $new_post, '_darven_who_published_estimated_author', true ) );
		$this->assertSame( '', get_post_meta( $new_post, '_darven_who_published_estimation_source', true ) );
	}

	/**
	 * Catches float and junk filter outputs being truncated into existing user IDs.
	 *
	 * @return void
	 */
	public function test_estimated_publisher_filter_rejects_float_and_junk_outputs(): void {
		$existing_user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );

		foreach ( array( (float) $existing_user_id, $existing_user_id . 'junk', $existing_user_id . '.0' ) as $invalid_output ) {
			$post_id = self::factory()->post->create( array( 'post_status' => 'publish' ) );
			update_post_meta( $post_id, '_edit_last', $existing_user_id );
			$filter = function () use ( $invalid_output ) {
				return $invalid_output;
			};
			add_filter( 'darven_who_published_estimated_publisher', $filter );

			$identity = ( new PublisherRetriever() )->get_publisher( get_post( $post_id ) );

			$this->assertSame( 'unknown', $identity->status(), gettype( $invalid_output ) . ':' . (string) $invalid_output );
			$this->assertSame( '', get_post_meta( $post_id, '_darven_who_published_estimated_author', true ) );
			remove_filter( 'darven_who_published_estimated_publisher', $filter );
		}
	}

	/**
	 * Catches canonical numeric-string user IDs being rejected by strict validation.
	 *
	 * @return void
	 */
	public function test_estimated_publisher_filter_accepts_a_positive_numeric_string_user_id(): void {
		$override_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$evidence_id = self::factory()->user->create( array( 'role' => 'editor' ) );
		$post_id     = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		update_post_meta( $post_id, '_edit_last', $evidence_id );
		add_filter(
			'darven_who_published_estimated_publisher',
			function () use ( $override_id ) {
				return (string) $override_id;
			}
		);

		$identity = ( new PublisherRetriever() )->get_publisher( get_post( $post_id ) );

		$this->assertSame( 'estimated', $identity->status() );
		$this->assertSame( $override_id, $identity->user_id() );
		$this->assertSame( (string) $override_id, get_post_meta( $post_id, '_darven_who_published_estimated_author', true ) );
	}

	/**
	 * Pins the rejected boundary of the canonical publisher-ID contract on every estimate path.
	 *
	 * @dataProvider invalid_publisher_id_cases
	 * @param string $boundary_case Boundary case name.
	 * @return void
	 */
	public function test_estimated_publisher_filter_rejects_the_complete_invalid_id_boundary( string $boundary_case ): void {
		try {
			$existing_user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
			$candidate        = $this->publisher_id_boundary_value( $boundary_case, $existing_user_id );
			$posts            = $this->create_estimation_path_posts();
			if ( 'overflow' === $boundary_case ) {
				$cached_user     = clone get_userdata( $existing_user_id )->data;
				$cached_user->ID = PHP_INT_MAX;
				wp_cache_set( PHP_INT_MAX, $cached_user, 'users' );
			}
			add_filter(
				'darven_who_published_estimated_publisher',
				function () use ( $candidate ) {
					return $candidate;
				}
			);

			$retriever = new PublisherRetriever();
			foreach ( $posts as $path => $post_id ) {
				$identity = $retriever->get_publisher( get_post( $post_id ) );
				$this->assertSame( 'unknown', $identity->status(), $boundary_case . ':' . $path );
				$this->assertSame( 0, $identity->user_id(), $boundary_case . ':' . $path );
			}
			$this->assertSame( '', get_post_meta( $posts['computed'], '_darven_who_published_estimated_author', true ), $boundary_case );
			$this->assertSame( '', get_post_meta( $posts['computed'], '_darven_who_published_estimation_source', true ), $boundary_case );
		} finally {
			if ( 'overflow' === $boundary_case ) {
				wp_cache_delete( PHP_INT_MAX, 'users' );
				wp_cache_delete( PHP_INT_MAX, 'user_meta' );
			}
		}
	}

	/**
	 * Pins both accepted representations on every estimate path.
	 *
	 * @dataProvider valid_publisher_id_cases
	 * @param string $boundary_case Accepted boundary case name.
	 * @return void
	 */
	public function test_estimated_publisher_filter_accepts_the_complete_valid_id_boundary( string $boundary_case ): void {
		$publisher_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$candidate    = 'positive_integer' === $boundary_case ? $publisher_id : (string) $publisher_id;
		$posts        = $this->create_estimation_path_posts();
		add_filter(
			'darven_who_published_estimated_publisher',
			function () use ( $candidate ) {
				return $candidate;
			}
		);

		$retriever        = new PublisherRetriever();
		$expected_sources = array(
			'legacy'    => 'legacy',
			'persisted' => 'latest_revision',
			'computed'  => 'edit_last',
		);
		foreach ( $posts as $path => $post_id ) {
			$identity = $retriever->get_publisher( get_post( $post_id ) );
			$this->assertSame( 'estimated', $identity->status(), $boundary_case . ':' . $path );
			$this->assertSame( $publisher_id, $identity->user_id(), $boundary_case . ':' . $path );
			$this->assertSame( $expected_sources[ $path ], $identity->source(), $boundary_case . ':' . $path );
		}
		$this->assertSame( (string) $publisher_id, get_post_meta( $posts['computed'], '_darven_who_published_estimated_author', true ), $boundary_case );
		$this->assertSame( 'edit_last', get_post_meta( $posts['computed'], '_darven_who_published_estimation_source', true ), $boundary_case );
	}

	/**
	 * Provides every rejected representation in the canonical positive-ID contract.
	 *
	 * @return array<string, array{string}>
	 */
	public function invalid_publisher_id_cases(): array {
		return array(
			'zero integer'     => array( 'zero_integer' ),
			'zero string'      => array( 'zero_string' ),
			'negative integer' => array( 'negative_integer' ),
			'negative string'  => array( 'negative_string' ),
			'float'            => array( 'float' ),
			'float string'     => array( 'float_string' ),
			'junk suffix'      => array( 'junk' ),
			'leading zero'     => array( 'leading_zero' ),
			'plus sign'        => array( 'plus_sign' ),
			'whitespace'       => array( 'whitespace' ),
			'exponent'         => array( 'exponent' ),
			'integer overflow' => array( 'overflow' ),
			'array'            => array( 'array' ),
			'object'           => array( 'object' ),
			'boolean'          => array( 'boolean' ),
			'null'             => array( 'null' ),
		);
	}

	/**
	 * Provides both accepted representations in the canonical positive-ID contract.
	 *
	 * @return array<string, array{string}>
	 */
	public function valid_publisher_id_cases(): array {
		return array(
			'positive integer'         => array( 'positive_integer' ),
			'canonical decimal string' => array( 'canonical_string' ),
		);
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

	/**
	 * Creates one post for each estimator evidence path.
	 *
	 * @return array{legacy: int, persisted: int, computed: int}
	 */
	private function create_estimation_path_posts(): array {
		$legacy_id    = self::factory()->user->create( array( 'role' => 'editor' ) );
		$persisted_id = self::factory()->user->create( array( 'role' => 'editor' ) );
		$computed_id  = self::factory()->user->create( array( 'role' => 'editor' ) );
		$legacy_post  = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		$stored_post  = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		$new_post     = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		global $wpdb;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.SlowDBQuery.slow_db_query_meta_key, WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- Simulates the legacy integer schema before its boolean registration.
		$wpdb->insert(
			$wpdb->postmeta,
			array(
				'post_id'    => $legacy_post,
				'meta_key'   => DARVEN_WHO_PUBLISHED_WAS_GUESSED,
				'meta_value' => $legacy_id,
			)
		);
		// phpcs:enable
		wp_cache_delete( $legacy_post, 'post_meta' );
		update_post_meta( $stored_post, '_darven_who_published_estimated_author', $persisted_id );
		update_post_meta( $stored_post, '_darven_who_published_estimation_source', 'latest_revision' );
		update_post_meta( $new_post, '_edit_last', $computed_id );

		return array(
			'legacy'    => $legacy_post,
			'persisted' => $stored_post,
			'computed'  => $new_post,
		);
	}

	/**
	 * Builds a boundary value relative to a real existing user ID.
	 *
	 * @param string $boundary_case    Boundary case name.
	 * @param int    $existing_user_id Existing user ID used to expose lossy normalization.
	 * @return mixed
	 */
	private function publisher_id_boundary_value( string $boundary_case, int $existing_user_id ) {
		switch ( $boundary_case ) {
			case 'zero_integer':
				return 0;
			case 'zero_string':
				return '0';
			case 'negative_integer':
				return -$existing_user_id;
			case 'negative_string':
				return '-' . $existing_user_id;
			case 'float':
				return (float) $existing_user_id;
			case 'float_string':
				return $existing_user_id . '.0';
			case 'junk':
				return $existing_user_id . 'junk';
			case 'leading_zero':
				return '0' . $existing_user_id;
			case 'plus_sign':
				return '+' . $existing_user_id;
			case 'whitespace':
				return ' ' . $existing_user_id . ' ';
			case 'exponent':
				return $existing_user_id . 'e0';
			case 'overflow':
				return (string) PHP_INT_MAX . '0';
			case 'array':
				return array( $existing_user_id );
			case 'object':
				return (object) array( 'id' => $existing_user_id );
			case 'boolean':
				return true;
			case 'null':
				return null;
		}

		$this->fail( 'Unknown boundary case: ' . $boundary_case );
	}
}
