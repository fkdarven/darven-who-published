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
	 * Catches the generic post-column hook leaking Published by into unsupported post types.
	 *
	 * @return void
	 */
	public function test_started_column_hooks_only_add_published_by_to_posts_and_pages(): void {
		( new ColumnManager() )->start();
		require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-posts-list-table.php';

		register_post_type(
			'book',
			array(
				'public'       => true,
				'show_ui'      => true,
				'show_in_menu' => true,
				'supports'     => array( 'title', 'author' ),
			)
		);

		$columns_by_type = array();
		foreach ( array( 'post', 'page', 'book' ) as $post_type ) {
			set_current_screen( 'edit-' . $post_type );
			$list_table = new WP_Posts_List_Table( array( 'screen' => get_current_screen() ) );

			$columns_by_type[ $post_type ] = $list_table->get_columns();
		}

		$post_keys = array_keys( $columns_by_type['post'] );
		$page_keys = array_keys( $columns_by_type['page'] );

		$this->assertSame( array_search( 'author', $post_keys, true ) + 1, array_search( 'darven_who_published', $post_keys, true ) );
		$this->assertSame( array_search( 'author', $page_keys, true ) + 1, array_search( 'darven_who_published', $page_keys, true ) );
		$this->assertArrayNotHasKey( 'darven_who_published', $columns_by_type['book'] );
	}

	/**
	 * Catches generic custom-column rendering hooks leaking publisher output into unsupported post types.
	 *
	 * @return void
	 */
	public function test_started_renderer_hooks_only_output_for_posts_and_pages(): void {
		( new ColumnManager() )->start();
		require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-posts-list-table.php';
		register_post_type(
			'book',
			array(
				'public'  => true,
				'show_ui' => true,
			)
		);

		$post_id = self::factory()->post->create( array( 'post_type' => 'post' ) );
		$page_id = self::factory()->post->create( array( 'post_type' => 'page' ) );
		$book_id = self::factory()->post->create( array( 'post_type' => 'book' ) );

		set_current_screen( 'edit-post' );
		$list_table = new WP_Posts_List_Table( array( 'screen' => get_current_screen() ) );
		ob_start();
		$list_table->column_default( get_post( $post_id ), 'darven_who_published' );
		$post_output = (string) ob_get_clean();

		set_current_screen( 'edit-page' );
		$list_table = new WP_Posts_List_Table( array( 'screen' => get_current_screen() ) );
		ob_start();
		$list_table->column_default( get_post( $page_id ), 'darven_who_published' );
		$page_output = (string) ob_get_clean();

		set_current_screen( 'edit-book' );
		$list_table = new WP_Posts_List_Table( array( 'screen' => get_current_screen() ) );
		ob_start();
		$list_table->column_default( get_post( $book_id ), 'darven_who_published' );
		$book_output = (string) ob_get_clean();

		$this->assertStringContainsString( 'darven-publisher-unknown', $post_output );
		$this->assertStringContainsString( 'darven-publisher-unknown', $page_output );
		$this->assertSame( '', $book_output );
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
	 * Catches legacy estimates being omitted from enabled post and page dropdowns.
	 *
	 * @return void
	 */
	public function test_legacy_estimates_join_post_and_page_dropdowns_only_when_enabled(): void {
		$confirmed_id = self::factory()->user->create(
			array(
				'role'         => 'editor',
				'display_name' => 'Confirmed Dropdown Publisher',
			)
		);
		$estimated_id = self::factory()->user->create(
			array(
				'role'         => 'editor',
				'display_name' => 'New Estimated Dropdown Publisher',
			)
		);
		$legacy_id    = self::factory()->user->create(
			array(
				'role'         => 'editor',
				'display_name' => 'Legacy Dropdown Publisher',
			)
		);
		$deleted_id   = self::factory()->user->create(
			array(
				'role'         => 'editor',
				'display_name' => 'Deleted Dropdown Publisher',
			)
		);
		$confirmed    = self::factory()->post->create( array( 'post_type' => 'post' ) );
		$estimated    = self::factory()->post->create( array( 'post_type' => 'page' ) );
		$legacy       = self::factory()->post->create( array( 'post_type' => 'post' ) );
		$stale        = self::factory()->post->create( array( 'post_type' => 'page' ) );
		update_post_meta( $confirmed, DARVEN_WHO_PUBLISHED_ORIGINAL_AUTHOR, $confirmed_id );
		update_post_meta( $estimated, '_darven_who_published_estimated_author', $estimated_id );
		$this->add_legacy_publisher_meta( $legacy, $legacy_id );
		$this->add_legacy_publisher_meta( $stale, $deleted_id );
		wp_delete_user( $deleted_id );
		update_option( 'darven_who_published_enable_estimation', true );

		$manager = new ColumnManager();
		foreach ( array( 'post', 'page' ) as $post_type ) {
			ob_start();
			$manager->add_filter_dropdown( $post_type );
			$output = (string) ob_get_clean();

			$this->assertSame( 1, substr_count( $output, 'Confirmed Dropdown Publisher' ), $post_type );
			$this->assertSame( 1, substr_count( $output, 'New Estimated Dropdown Publisher' ), $post_type );
			$this->assertSame( 1, substr_count( $output, 'Legacy Dropdown Publisher' ), $post_type );
			$this->assertStringNotContainsString( 'Deleted Dropdown Publisher', $output, $post_type );
		}

		update_option( 'darven_who_published_enable_estimation', false );
		foreach ( array( 'post', 'page' ) as $post_type ) {
			ob_start();
			$manager->add_filter_dropdown( $post_type );
			$output = (string) ob_get_clean();

			$this->assertStringContainsString( 'Confirmed Dropdown Publisher', $output, $post_type );
			$this->assertStringNotContainsString( 'New Estimated Dropdown Publisher', $output, $post_type );
			$this->assertStringNotContainsString( 'Legacy Dropdown Publisher', $output, $post_type );
		}

		$this->assertSame( (string) $legacy_id, get_post_meta( $legacy, DARVEN_WHO_PUBLISHED_WAS_GUESSED, true ) );
		$this->assertSame( '', get_post_meta( $legacy, '_darven_who_published_estimated_author', true ) );
		$this->assertSame( '', get_post_meta( $legacy, DARVEN_WHO_PUBLISHED_ORIGINAL_AUTHOR, true ) );
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

		$query = new WP_Query(
			array(
				'post_type'   => 'post',
				'post_status' => 'publish',
				'fields'      => 'ids',
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Deliberate integration fixture, bounded to the disposable test database, verifies preservation of an existing metadata clause.
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
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Deliberate integration fixture, bounded to the disposable test database, verifies nesting of a pre-existing OR metadata clause.
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

	/**
	 * Catches legacy estimates being omitted from enabled post and page queries.
	 *
	 * @return void
	 */
	public function test_legacy_estimates_join_post_and_page_queries_without_flattening_existing_filters(): void {
		$publisher_id       = self::factory()->user->create( array( 'role' => 'editor' ) );
		$other_publisher_id = self::factory()->user->create( array( 'role' => 'editor' ) );
		$manager            = new ColumnManager();
		update_option( 'darven_who_published_enable_estimation', true );

		foreach ( array( 'post', 'page' ) as $post_type ) {
			$confirmed = self::factory()->post->create(
				array(
					'post_status' => 'publish',
					'post_type'   => $post_type,
				)
			);
			$estimated = self::factory()->post->create(
				array(
					'post_status' => 'publish',
					'post_type'   => $post_type,
				)
			);
			$legacy    = self::factory()->post->create(
				array(
					'post_status' => 'publish',
					'post_type'   => $post_type,
				)
			);
			$wrong     = self::factory()->post->create(
				array(
					'post_status' => 'publish',
					'post_type'   => $post_type,
				)
			);
			$excluded  = self::factory()->post->create(
				array(
					'post_status' => 'publish',
					'post_type'   => $post_type,
				)
			);
			foreach ( array( $confirmed, $estimated, $legacy, $wrong ) as $post_id ) {
				update_post_meta( $post_id, '_editorial_section', 'newsroom' );
			}
			update_post_meta( $confirmed, DARVEN_WHO_PUBLISHED_ORIGINAL_AUTHOR, $publisher_id );
			update_post_meta( $estimated, '_darven_who_published_estimated_author', $publisher_id );
			$this->add_legacy_publisher_meta( $legacy, $publisher_id );
			$this->add_legacy_publisher_meta( $wrong, $other_publisher_id );
			$this->add_legacy_publisher_meta( $excluded, $publisher_id );
			update_post_meta( $excluded, '_editorial_section', 'opinion' );
			$_GET['darven_who_published_filter']       = (string) $publisher_id;
			$_GET['darven_who_published_filter_nonce'] = wp_create_nonce( 'darven_who_published_filter_action' );

			$query = new WP_Query(
				array(
					'post_type'   => $post_type,
					'post_status' => 'publish',
					'fields'      => 'ids',
					// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Deliberate integration fixture verifies that the publisher OR remains nested beneath an existing OR.
					'meta_query'  => array(
						'relation' => 'OR',
						array(
							'key'   => '_editorial_section',
							'value' => 'newsroom',
						),
						array(
							'key'   => '_editorial_section',
							'value' => 'investigations',
						),
					),
				)
			);
			global $wp_the_query;
			$wp_the_query = $query;
			$manager->apply_filter_query( $query );
			$filtered = new WP_Query( $query->query_vars );

			$this->assertEqualSets( array( $confirmed, $estimated, $legacy ), $filtered->posts, $post_type );
			$this->assertSame( 'AND', $filtered->get( 'meta_query' )['relation'], $post_type );
			$this->assertSame( 'OR', $filtered->get( 'meta_query' )[0]['relation'], $post_type );
			$this->assertSame( 'OR', $filtered->get( 'meta_query' )[1]['relation'], $post_type );
			$this->assertCount( 4, $filtered->get( 'meta_query' )[1], $post_type );
			$this->assertSame( '', get_post_meta( $legacy, '_darven_who_published_estimated_author', true ) );
			$this->assertSame( '', get_post_meta( $legacy, DARVEN_WHO_PUBLISHED_ORIGINAL_AUTHOR, true ) );
		}
	}

	/**
	 * Catches disabled estimation queries matching new or legacy estimate metadata.
	 *
	 * @return void
	 */
	public function test_disabled_estimation_excludes_new_and_legacy_estimates_for_posts_and_pages(): void {
		$publisher_id = self::factory()->user->create( array( 'role' => 'editor' ) );
		$manager      = new ColumnManager();

		foreach ( array( 'post', 'page' ) as $post_type ) {
			$confirmed = self::factory()->post->create(
				array(
					'post_status' => 'publish',
					'post_type'   => $post_type,
				)
			);
			$estimated = self::factory()->post->create(
				array(
					'post_status' => 'publish',
					'post_type'   => $post_type,
				)
			);
			$legacy    = self::factory()->post->create(
				array(
					'post_status' => 'publish',
					'post_type'   => $post_type,
				)
			);
			update_post_meta( $confirmed, DARVEN_WHO_PUBLISHED_ORIGINAL_AUTHOR, $publisher_id );
			update_post_meta( $estimated, '_darven_who_published_estimated_author', $publisher_id );
			$this->add_legacy_publisher_meta( $legacy, $publisher_id );
			$_GET['darven_who_published_filter']       = (string) $publisher_id;
			$_GET['darven_who_published_filter_nonce'] = wp_create_nonce( 'darven_who_published_filter_action' );

			$query = new WP_Query(
				array(
					'post_type'   => $post_type,
					'post_status' => 'publish',
					'fields'      => 'ids',
				)
			);
			global $wp_the_query;
			$wp_the_query = $query;
			$manager->apply_filter_query( $query );
			$filtered = new WP_Query( $query->query_vars );

			$this->assertSame( array( $confirmed ), $filtered->posts, $post_type );
		}
	}

	/**
	 * Catches nonce-valid arbitrary IDs that add a publisher condition without being eligible options.
	 *
	 * @return void
	 */
	public function test_nonexistent_selected_publisher_does_not_modify_the_query(): void {
		$nonexistent_id                            = 999999;
		$_GET['darven_who_published_filter']       = (string) $nonexistent_id;
		$_GET['darven_who_published_filter_nonce'] = wp_create_nonce( 'darven_who_published_filter_action' );
		$query                                     = new WP_Query(
			array(
				'post_type'   => 'post',
				'post_status' => 'publish',
				'fields'      => 'ids',
			)
		);
		global $wp_the_query;
		$wp_the_query = $query;

		( new ColumnManager() )->apply_filter_query( $query );

		$this->assertSame( '', $query->get( 'meta_query' ) );
	}

	/**
	 * Catches a selector shell left behind when all publisher metadata points to deleted users.
	 *
	 * @return void
	 */
	public function test_deleted_publisher_metadata_does_not_render_an_empty_selector(): void {
		$publisher_id = self::factory()->user->create( array( 'role' => 'editor' ) );
		$post_id      = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		update_post_meta( $post_id, DARVEN_WHO_PUBLISHED_ORIGINAL_AUTHOR, $publisher_id );
		wp_delete_user( $publisher_id );

		ob_start();
		( new ColumnManager() )->add_filter_dropdown( 'post' );
		$output = (string) ob_get_clean();

		$this->assertSame( '', $output );
	}

	/**
	 * Catches editor screens that render publisher badges without the registered stylesheet.
	 *
	 * @return void
	 */
	public function test_admin_styles_are_enqueued_on_post_and_page_list_and_editor_screens(): void {
		$manager = new ColumnManager();

		foreach ( array( 'edit-post', 'edit-page', 'post', 'page' ) as $screen_id ) {
			set_current_screen( $screen_id );
			wp_dequeue_style( 'darven-who-published-admin' );
			$manager->enqueue_admin_styles();

			$this->assertTrue( wp_style_is( 'darven-who-published-admin', 'enqueued' ), $screen_id );
			$this->assertSame( DARVEN_WHO_PUBLISHED_VERSION, wp_styles()->registered['darven-who-published-admin']->ver, $screen_id );
		}
	}

	/**
	 * Inserts an integer legacy estimate without boolean meta sanitization.
	 *
	 * @param int $post_id      Post ID.
	 * @param int $publisher_id Legacy estimated publisher ID.
	 * @return void
	 */
	private function add_legacy_publisher_meta( int $post_id, int $publisher_id ): void {
		global $wpdb;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.SlowDBQuery.slow_db_query_meta_key, WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- Simulates the legacy integer schema before its boolean registration.
		$wpdb->insert(
			$wpdb->postmeta,
			array(
				'post_id'    => $post_id,
				'meta_key'   => DARVEN_WHO_PUBLISHED_WAS_GUESSED,
				'meta_value' => $publisher_id,
			)
		);
		// phpcs:enable
		wp_cache_delete( $post_id, 'post_meta' );
	}
}
