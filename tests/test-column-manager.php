<?php
/**
 * Integration tests for the publisher list-table column and filter.
 *
 * @package Darven\WhoPublished
 */

use Darven\WhoPublished\Admin\ColumnManager;
use Darven\WhoPublished\Tracker\RegisterMeta;

/**
 * Tests list-table presentation and filtering against WordPress data.
 */
class Test_Column_Manager extends WP_UnitTestCase {

	/**
	 * Registers metadata and clears request state before each test.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();
		RegisterMeta::register();
		set_current_screen( 'edit-post' );
		$_GET = array();
	}

	/**
	 * Restores settings and request state shared by list-table hooks.
	 *
	 * @return void
	 */
	public function tear_down(): void {
		delete_option( 'darven_who_published_enable_estimation' );
		set_current_screen( 'front' );
		$_GET = array();
		parent::tear_down();
	}

	/**
	 * Catches a Published by column that is appended instead of adjacent to Author.
	 *
	 * @return void
	 */
	public function test_published_by_column_is_inserted_immediately_after_author(): void {
		$columns = array(
			'cb'     => '',
			'title'  => 'Title',
			'author' => 'Author',
			'date'   => 'Date',
		);

		$actual = ( new ColumnManager() )->add_post_column( $columns );

		$this->assertSame( array( 'cb', 'title', 'author', 'darven_who_published', 'date' ), array_keys( $actual ) );
		$this->assertSame( 'Published by', $actual['darven_who_published'] );
	}

	/**
	 * Catches the loss of associative column keys when Author is not present.
	 *
	 * @return void
	 */
	public function test_published_by_column_is_appended_when_author_is_absent(): void {
		$columns = array(
			'cb'    => '',
			'title' => 'Title',
			'date'  => 'Date',
		);

		$actual = ( new ColumnManager() )->add_post_column( $columns );

		$this->assertSame( array( 'cb', 'title', 'date', 'darven_who_published' ), array_keys( $actual ) );
	}

	/**
	 * Catches confirmed rendering without a safe user edit link and state treatment.
	 *
	 * @return void
	 */
	public function test_confirmed_publisher_renders_a_linked_confirmed_badge(): void {
		$administrator_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$publisher_id     = self::factory()->user->create(
			array(
				'role'         => 'editor',
				'display_name' => 'Confirmed Publisher',
			)
		);
		$post_id          = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		update_post_meta( $post_id, DARVEN_WHO_PUBLISHED_ORIGINAL_AUTHOR, $publisher_id );
		wp_set_current_user( $administrator_id );

		ob_start();
		( new ColumnManager() )->handle_column_data( 'darven_who_published', $post_id );
		$output = (string) ob_get_clean();

		$this->assertStringContainsString( 'darven-badge-confirmed', $output );
		$this->assertStringContainsString( 'Confirmed Publisher', $output );
		$this->assertStringContainsString( 'href="' . esc_url( get_edit_user_link( $publisher_id ) ) . '"', $output );
		$this->assertStringContainsString( 'aria-label=', $output );
	}

	/**
	 * Catches estimated rendering that looks confirmed or omits its evidence source.
	 *
	 * @return void
	 */
	public function test_estimated_publisher_renders_an_amber_badge_with_evidence_tooltip(): void {
		$publisher_id = self::factory()->user->create(
			array(
				'role'         => 'editor',
				'display_name' => 'Estimated Publisher',
			)
		);
		$post_id      = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		update_option( 'darven_who_published_enable_estimation', true );
		update_post_meta( $post_id, '_darven_who_published_estimated_author', $publisher_id );
		update_post_meta( $post_id, '_darven_who_published_estimation_source', 'edit_last' );

		ob_start();
		( new ColumnManager() )->handle_column_data( 'darven_who_published', $post_id );
		$output = (string) ob_get_clean();

		$this->assertStringContainsString( 'darven-badge-estimated', $output );
		$this->assertStringContainsString( 'Estimated', $output );
		$this->assertStringContainsString( 'last edit', $output );
	}

	/**
	 * Catches unknown rendering that offers an invented or broken user link.
	 *
	 * @return void
	 */
	public function test_unknown_publisher_renders_neutral_text_without_a_link(): void {
		$post_id = self::factory()->post->create( array( 'post_status' => 'publish' ) );

		ob_start();
		( new ColumnManager() )->handle_column_data( 'darven_who_published', $post_id );
		$output = (string) ob_get_clean();

		$this->assertStringContainsString( 'darven-publisher-unknown', $output );
		$this->assertStringContainsString( 'Unknown', $output );
		$this->assertStringNotContainsString( '<a ', $output );
	}

	/**
	 * Catches a filter dropdown that omits estimates or duplicates user options.
	 *
	 * @return void
	 */
	public function test_filter_dropdown_uses_the_unique_union_of_confirmed_and_estimated_publishers(): void {
		$shared_id    = self::factory()->user->create(
			array(
				'role'         => 'editor',
				'display_name' => 'Shared Publisher',
			)
		);
		$estimated_id = self::factory()->user->create(
			array(
				'role'         => 'editor',
				'display_name' => 'Estimated Only',
			)
		);
		$confirmed_id = self::factory()->user->create(
			array(
				'role'         => 'editor',
				'display_name' => 'Confirmed Only',
			)
		);
		$confirmed    = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		$estimated    = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		$shared       = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		update_post_meta( $confirmed, DARVEN_WHO_PUBLISHED_ORIGINAL_AUTHOR, $confirmed_id );
		update_post_meta( $estimated, '_darven_who_published_estimated_author', $estimated_id );
		update_post_meta( $shared, DARVEN_WHO_PUBLISHED_ORIGINAL_AUTHOR, $shared_id );
		update_post_meta( $shared, '_darven_who_published_estimated_author', $shared_id );
		update_option( 'darven_who_published_enable_estimation', true );

		ob_start();
		( new ColumnManager() )->add_filter_dropdown( 'post' );
		$output = (string) ob_get_clean();

		$this->assertStringContainsString( 'All publishers', $output );
		$this->assertSame( 1, substr_count( $output, 'Shared Publisher' ) );
		$this->assertStringContainsString( 'Estimated Only', $output );
		$this->assertStringContainsString( 'Confirmed Only', $output );
	}

	/**
	 * Catches a selected publisher replacing existing meta filters or ignoring estimates.
	 *
	 * @return void
	 */
	public function test_selected_publisher_matches_confirmed_and_estimated_posts_without_losing_existing_meta_query(): void {
		$publisher_id = self::factory()->user->create( array( 'role' => 'editor' ) );
		$confirmed    = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		$estimated    = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		$excluded     = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		foreach ( array( $confirmed, $estimated ) as $post_id ) {
			update_post_meta( $post_id, '_editorial_section', 'newsroom' );
		}
		update_post_meta( $confirmed, DARVEN_WHO_PUBLISHED_ORIGINAL_AUTHOR, $publisher_id );
		update_post_meta( $estimated, '_darven_who_published_estimated_author', $publisher_id );
		update_post_meta( $excluded, DARVEN_WHO_PUBLISHED_ORIGINAL_AUTHOR, $publisher_id );
		update_option( 'darven_who_published_enable_estimation', true );
		$_GET['darven_who_published_filter']       = (string) $publisher_id;
		$_GET['darven_who_published_filter_nonce'] = wp_create_nonce( 'darven_who_published_filter_action' );

		// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Exercises preservation of an unrelated existing metadata clause.
		$query = new WP_Query(
			array(
				'post_type'   => 'post',
				'post_status' => 'publish',
				'fields'      => 'ids',
				'meta_query'  => array(
					array(
						'key'   => '_editorial_section',
						'value' => 'newsroom',
					),
				),
			)
		);
		global $wp_the_query;
		$wp_the_query = $query;
		( new ColumnManager() )->apply_filter_query( $query );
		$filtered = new WP_Query( $query->query_vars );

		$this->assertEqualSets( array( $confirmed, $estimated ), $filtered->posts );
		$this->assertSame( 'AND', $filtered->get( 'meta_query' )['relation'] );
		$this->assertSame( '_editorial_section', $filtered->get( 'meta_query' )[0][0]['key'] );
		$this->assertSame( 'OR', $filtered->get( 'meta_query' )[1]['relation'] );
	}

	/**
	 * Catches estimation-disabled filter queries that still match estimated metadata.
	 *
	 * @return void
	 */
	public function test_selected_publisher_matches_only_confirmed_posts_when_estimation_is_disabled(): void {
		$publisher_id = self::factory()->user->create( array( 'role' => 'editor' ) );
		$confirmed    = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		$estimated    = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		update_post_meta( $confirmed, DARVEN_WHO_PUBLISHED_ORIGINAL_AUTHOR, $publisher_id );
		update_post_meta( $estimated, '_darven_who_published_estimated_author', $publisher_id );
		$_GET['darven_who_published_filter']       = (string) $publisher_id;
		$_GET['darven_who_published_filter_nonce'] = wp_create_nonce( 'darven_who_published_filter_action' );

		$query = new WP_Query(
			array(
				'post_type'   => 'post',
				'post_status' => 'publish',
				'fields'      => 'ids',
			)
		);
		global $wp_the_query;
		$wp_the_query = $query;
		( new ColumnManager() )->apply_filter_query( $query );
		$filtered = new WP_Query( $query->query_vars );

		$this->assertSame( array( $confirmed ), $filtered->posts );
	}

	/**
	 * Catches a publisher clause that changes an existing top-level OR filter into a broad match.
	 *
	 * @return void
	 */
	public function test_selected_publisher_preserves_an_existing_or_meta_query_as_a_nested_clause(): void {
		$publisher_id       = self::factory()->user->create( array( 'role' => 'editor' ) );
		$other_publisher_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$matched            = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		$only_existing      = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		$only_publisher     = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		update_post_meta( $matched, DARVEN_WHO_PUBLISHED_ORIGINAL_AUTHOR, $publisher_id );
		update_post_meta( $matched, '_editorial_section', 'newsroom' );
		update_post_meta( $only_existing, DARVEN_WHO_PUBLISHED_ORIGINAL_AUTHOR, $other_publisher_id );
		update_post_meta( $only_existing, '_editorial_section', 'newsroom' );
		update_post_meta( $only_publisher, DARVEN_WHO_PUBLISHED_ORIGINAL_AUTHOR, $publisher_id );
		update_post_meta( $only_publisher, '_editorial_section', 'opinion' );
		$_GET['darven_who_published_filter']       = (string) $publisher_id;
		$_GET['darven_who_published_filter_nonce'] = wp_create_nonce( 'darven_who_published_filter_action' );

		$query = new WP_Query(
			array(
				'post_type'   => 'post',
				'post_status' => 'publish',
				'fields'      => 'ids',
				'meta_query'  => array(
					'relation' => 'OR',
					array(
						'key'   => '_editorial_section',
						'value' => 'newsroom',
					),
				),
			)
		);
		global $wp_the_query;
		$wp_the_query = $query;
		( new ColumnManager() )->apply_filter_query( $query );
		$filtered = new WP_Query( $query->query_vars );

		$this->assertSame( array( $matched ), $filtered->posts );
		$this->assertSame( 'AND', $filtered->get( 'meta_query' )['relation'] );
		$this->assertSame( 'OR', $filtered->get( 'meta_query' )[1]['relation'] );
	}
}
