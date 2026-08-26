<?php

namespace Darven\WhoPublished\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Darven\WhoPublished\Publisher\PublisherRetriever;

/**
 * Class ColumnManager
 *
 * @package Darven\WhoPublished
 * @subpackage Admin
 */
class ColumnManager {

	public function start(): void {
		add_filter( 'manage_posts_columns', [ $this, 'add_post_column' ] );
		add_action( 'manage_posts_custom_column', [ $this, 'handle_column_data' ], 10, 2 );
		add_filter( 'manage_pages_columns', [ $this, 'add_post_column' ] );
		add_action( 'manage_pages_custom_column', [ $this, 'handle_column_data' ], 10, 2 );
		add_action( 'restrict_manage_posts', [ $this, 'add_filter_dropdown' ] );
		add_action( 'pre_get_posts', [ $this, 'apply_filter_query' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_admin_styles' ] );
	}

	public function add_post_column( array $columns ): array {
		$columns['darven_who_published'] = __( 'Who Published', 'darven-who-published' );
		return $columns;
	}

	public function handle_column_data( string $column_name, int $post_id ): void {
		if ( 'darven_who_published' !== $column_name ) {
			return;
		}

		$post = get_post( $post_id );
		if ( ! $post ) {
			echo '&mdash;';
			return;
		}

		$retriever    = new PublisherRetriever();
		$publisher_id = $retriever->get_publisher( $post );

		if ( ! $publisher_id ) {
			echo '&mdash;';
			return;
		}

		$is_guessed = get_post_meta( $post_id, DARVEN_WHO_PUBLISHED_WAS_GUESSED, true );
		$user       = get_userdata( $publisher_id );

		if ( $user ) {
			$name    = esc_html( $user->display_name );
			$tooltip = $is_guessed ? ' title="' . esc_attr__( 'Based on revision or last edit.', 'darven-who-published' ) . '"' : '';
			$label   = $is_guessed ? __( 'Probably ', 'darven-who-published' ) . $name : $name;
			$class   = $is_guessed ? 'darven-badge darven-badge-guessed' : 'darven-badge darven-badge-confirmed';

			printf(
				'<a href="%s" class="%s"%s>%s</a>',
				esc_url( get_edit_user_link( $user->ID ) ),
				esc_attr( $class ),
				esc_html($tooltip),
				esc_html( $label )
			);
		} else {
			echo '&mdash;';
		}
	}

	public function add_filter_dropdown( string $post_type ): void {
		if ( ! in_array( $post_type, [ 'post', 'page' ], true ) ) {
			return;
		}

		global $wpdb;
		$meta_key  = DARVEN_WHO_PUBLISHED_ORIGINAL_AUTHOR;
		$cache_key = "darven_who_published_{$meta_key}";

		$authors = wp_cache_get( $cache_key, 'darven_who_published_columns_cache' );

		if ( ! $authors ) {
			// Direct SQL query via $wpdb is used here for performance reasons.
			// WP_Query and get_posts() do not support DISTINCT queries on post meta
			// and would be significantly less efficient in this context.
			$authors = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT DISTINCT meta_value FROM {$wpdb->postmeta} WHERE meta_key = %s ORDER BY meta_value ASC",
					$meta_key
				)
			);

			$authors = array_filter( $authors, static function ( $id ) {
				return is_numeric( $id ) && $id > 0;
			});

			wp_cache_add( $cache_key, $authors, 'darven_who_published_columns_cache' );
		}

		if ( empty( $authors ) ) {
			return;
		}

		$current_value = '';

		if (
			isset( $_GET['darven_who_published_filter'], $_GET['darven_who_published_filter_nonce'] ) &&
			wp_verify_nonce(
				sanitize_text_field( wp_unslash( $_GET['darven_who_published_filter_nonce'] ) ),
				'darven_who_published_filter_action'
			)
		) {
			$current_value = sanitize_text_field( wp_unslash( $_GET['darven_who_published_filter'] ) );
		}
		echo '<select name="darven_who_published_filter">';
		echo '<option value="">' . esc_html__( 'All Original Authors', 'darven-who-published' ) . '</option>';

		foreach ( $authors as $author_id ) {
			$user = get_user_by( 'ID', $author_id );
			if ( ! $user ) {
				continue;
			}

			$selected = selected( $current_value, $author_id, false );
			printf(
				'<option value="%d" %s>%s</option>',
				esc_attr( $author_id ),
				esc_attr( $selected ),
				esc_html( $user->display_name )
			);
		}
		echo '</select>';


		wp_nonce_field( 'darven_who_published_filter_action', 'darven_who_published_filter_nonce' );
	}

	public function apply_filter_query( \WP_Query $query ): void {
		if ( ! is_admin() || ! $query->is_main_query() ) {
			return;
		}

		if (
			isset( $_GET['darven_who_published_filter'], $_GET['darven_who_published_filter_nonce'] ) &&
			wp_verify_nonce(
				sanitize_text_field( wp_unslash( $_GET['darven_who_published_filter_nonce'] ) ),
				'darven_who_published_filter_action'
			)
		) {
			$author_id = sanitize_text_field( wp_unslash( $_GET['darven_who_published_filter'] ) );
			$query->set( 'meta_key', DARVEN_WHO_PUBLISHED_ORIGINAL_AUTHOR );
			$query->set( 'meta_value', $author_id );
		}
	}

	public function enqueue_admin_styles(): void {
		$screen = get_current_screen();
		if ( isset( $screen->id ) && in_array( $screen->id, [ 'edit-post', 'edit-page' ], true ) ) {
			wp_enqueue_style( 'darven-who-published-admin', DARVEN_WHO_PUBLISHED_URL . 'assets/css/admin.css', array(), "1.0.0" );
		}
	}
}