<?php
/**
 * Manages publisher columns and filters in post and page list tables.
 *
 * @package Darven\WhoPublished
 * @subpackage Admin
 */

namespace Darven\WhoPublished\Admin;

use Darven\WhoPublished\Publisher\PublisherRetriever;
use WP_Query;

if ( ! defined( 'ABSPATH' ) ) {

	exit;
}

/**
 * Displays publisher identity alongside WordPress's native author column.
 */
class ColumnManager {

	/**
	 * Registers administration hooks.
	 *
	 * @return void
	 */
	public function start(): void {
		add_filter( 'manage_post_posts_columns', array( $this, 'add_post_column' ) );
		add_action( 'manage_post_posts_custom_column', array( $this, 'handle_column_data' ), 10, 2 );
		add_filter( 'manage_page_posts_columns', array( $this, 'add_post_column' ) );
		add_action( 'manage_page_posts_custom_column', array( $this, 'handle_column_data' ), 10, 2 );
		add_action( 'restrict_manage_posts', array( $this, 'add_filter_dropdown' ) );
		add_action( 'pre_get_posts', array( $this, 'apply_filter_query' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_styles' ) );
	}

	/**
	 * Inserts Published by immediately after the native Author column.
	 *
	 * @param array<string, string> $columns Existing list-table columns.
	 * @return array<string, string>
	 */
	public function add_post_column( array $columns ): array {
		$position = array_search( 'author', array_keys( $columns ), true );
		$column   = array( 'darven_who_published' => __( 'Published by', 'darven-who-published' ) );

		if ( false === $position ) {
			return $columns + $column;
		}

		return array_slice( $columns, 0, $position + 1, true )
			+ $column
			+ array_slice( $columns, $position + 1, null, true );
	}

	/**
	 * Outputs publisher markup for the custom list-table column.
	 *
	 * @param string $column_name Current list-table column name.
	 * @param int    $post_id     Current post ID.
	 * @return void
	 */
	public function handle_column_data( string $column_name, int $post_id ): void {
		if ( 'darven_who_published' !== $column_name ) {
			return;
		}

		$post = get_post( $post_id );
		if ( ! $post ) {
			echo '<span class="darven-publisher-unknown">' . esc_html__( 'Unknown', 'darven-who-published' ) . '</span>';
			return;
		}

		echo PublisherBadge::render( ( new PublisherRetriever() )->get_publisher( $post ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- PublisherBadge escapes all dynamic data.
	}

	/**
	 * Outputs a publisher-filter dropdown for post and page list tables.
	 *
	 * @param string $post_type Current list-table post type.
	 * @return void
	 */
	public function add_filter_dropdown( string $post_type ): void {
		if ( ! in_array( $post_type, array( 'post', 'page' ), true ) ) {
			return;
		}

		$publishers = $this->eligible_publishers();
		if ( empty( $publishers ) ) {
			return;
		}

		echo '<select name="darven_who_published_filter">';
		printf( '<option value="">%s</option>', esc_html__( 'All publishers', 'darven-who-published' ) );
		$current_value = $this->selected_publisher_id( array_keys( $publishers ) );

		foreach ( $publishers as $publisher_id => $user ) {
			printf(
				'<option value="%1$d"%2$s>%3$s</option>',
				absint( $publisher_id ),
				selected( $current_value, $publisher_id, false ),
				esc_html( $user->display_name )
			);
		}

		echo '</select>';
		wp_nonce_field( 'darven_who_published_filter_action', 'darven_who_published_filter_nonce' );
	}

	/**
	 * Adds the selected publisher condition without replacing existing metadata clauses.
	 *
	 * @param WP_Query $query Main administration list-table query.
	 * @return void
	 */
	public function apply_filter_query( WP_Query $query ): void {
		if ( ! is_admin() || ! $query->is_main_query() || ! in_array( $query->get( 'post_type' ), array( 'post', 'page' ), true ) ) {
			return;
		}

		$publisher_id = $this->selected_publisher_id( array_keys( $this->eligible_publishers() ) );
		if ( $publisher_id <= 0 ) {
			return;
		}

		$publisher_query = array(
			'relation' => 'OR',
			array(
				'key'   => DARVEN_WHO_PUBLISHED_ORIGINAL_AUTHOR,
				'value' => $publisher_id,
				'type'  => 'NUMERIC',
			),
		);
		if ( $this->estimation_enabled() ) {
			$publisher_query[] = array(
				'key'   => '_darven_who_published_estimated_author',
				'value' => $publisher_id,
				'type'  => 'NUMERIC',
			);
			$publisher_query[] = array(
				'key'   => DARVEN_WHO_PUBLISHED_WAS_GUESSED,
				'value' => $publisher_id,
				'type'  => 'NUMERIC',
			);
		}

		$meta_query = $query->get( 'meta_query' );
		if ( ! is_array( $meta_query ) ) {
			$meta_query = array( $publisher_query );
		} else {
			$meta_query = array(
				'relation' => 'AND',
				$meta_query,
				$publisher_query,
			);
		}
		$query->set( 'meta_query', $meta_query );
	}

	/**
	 * Enqueues the WordPress-native publisher state styles on supported list and editor screens.
	 *
	 * @return void
	 */
	public function enqueue_admin_styles(): void {
		$screen = get_current_screen();
		if ( isset( $screen->id ) && in_array( $screen->id, array( 'edit-post', 'edit-page', 'post', 'page' ), true ) ) {
			wp_enqueue_style( 'darven-who-published-admin', DARVEN_WHO_PUBLISHED_URL . 'assets/css/admin.css', array(), DARVEN_WHO_PUBLISHED_VERSION );
		}
	}

	/**
	 * Returns existing users represented in filterable publisher metadata.
	 *
	 * @return array<int, \WP_User>
	 */
	private function eligible_publishers(): array {
		global $wpdb;
		$meta_keys = array( DARVEN_WHO_PUBLISHED_ORIGINAL_AUTHOR );
		if ( $this->estimation_enabled() ) {
			$meta_keys[] = '_darven_who_published_estimated_author';
			$meta_keys[] = DARVEN_WHO_PUBLISHED_WAS_GUESSED;
		}

		$publisher_ids = array();
		foreach ( $meta_keys as $meta_key ) {
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Distinct metadata values cannot be queried efficiently through WP_Query.
			$ids = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT DISTINCT meta_value FROM {$wpdb->postmeta} WHERE meta_key = %s",
					$meta_key
				)
			);
			// phpcs:enable
			foreach ( $ids as $publisher_id ) {
				$publisher_id = absint( $publisher_id );
				if ( $publisher_id > 0 ) {
					$publisher_ids[] = $publisher_id;
				}
			}
		}

		$publisher_ids = array_values( array_unique( $publisher_ids ) );
		sort( $publisher_ids, SORT_NUMERIC );
		$publishers = array();
		foreach ( $publisher_ids as $publisher_id ) {
			$user = get_userdata( $publisher_id );
			if ( $user ) {
				$publishers[ $publisher_id ] = $user;
			}
		}

		return $publishers;
	}

	/**
	 * Reads a valid, nonce-protected selected publisher ID from the list-table request.
	 *
	 * @param int[] $eligible_ids Existing filterable publisher IDs.
	 * @return int
	 */
	private function selected_publisher_id( array $eligible_ids ): int {
		if ( ! isset( $_GET['darven_who_published_filter'], $_GET['darven_who_published_filter_nonce'] ) ) {
			return 0;
		}

		$nonce = sanitize_text_field( wp_unslash( $_GET['darven_who_published_filter_nonce'] ) );
		if ( ! wp_verify_nonce( $nonce, 'darven_who_published_filter_action' ) ) {
			return 0;
		}

		$publisher_id = absint( wp_unslash( $_GET['darven_who_published_filter'] ) );

		return in_array( $publisher_id, $eligible_ids, true ) ? $publisher_id : 0;
	}

	/**
	 * Returns whether estimation is globally enabled for filter-dropdown behavior.
	 *
	 * @return bool
	 */
	private function estimation_enabled(): bool {
		return rest_sanitize_boolean( get_option( 'darven_who_published_enable_estimation', false ) );
	}
}
