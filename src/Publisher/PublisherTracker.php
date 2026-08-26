<?php
/**
 * Tracks and stores the authenticated user who first publishes a post.
 *
 * @package Darven\WhoPublished
 * @subpackage Publisher
 * @author Darven
 * @since 1.0.0
 * @version 1.1.0
 */

namespace Darven\WhoPublished\Publisher;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class PublisherTracker
 *
 * Observes WordPress hooks to determine the first publisher of a post.
 *
 * @package Darven\WhoPublished
 * @subpackage Publisher
 * @author Darven
 * @since 1.0.0
 * @version 1.1.0
 */
class PublisherTracker {

	/**
	 * Registers the WordPress hooks needed to track publishing.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public function init(): void {
		add_action( 'transition_post_status', array( $this, 'handle_publish_transition' ), 10, 3 );
		add_action( 'wp_insert_post', array( $this, 'handle_wp_insert' ), 10, 3 );
	}

	/**
	 * Captures the authenticated user who first publishes a supported item.
	 *
	 * @param int $post_id The ID of the post being processed.
	 * @return void
	 * @since 1.0.0
	 */
	public function capture( int $post_id ): void {
		$post = get_post( $post_id );

		if ( ! $post || ! $this->is_supported_post( $post ) ) {
			return;
		}

		if ( get_post_meta( $post_id, DARVEN_WHO_PUBLISHED_ORIGINAL_AUTHOR, true ) || get_post_meta( $post_id, DARVEN_WHO_PUBLISHED_FIRST_PUBLICATION_OBSERVED, true ) ) {
			return;
		}

		if ( ! add_post_meta( $post_id, DARVEN_WHO_PUBLISHED_FIRST_PUBLICATION_OBSERVED, true, true ) ) {
			return;
		}

		$user_id = get_current_user_id();

		if ( $user_id <= 0 ) {
			return;
		}

		add_post_meta( $post_id, DARVEN_WHO_PUBLISHED_ORIGINAL_AUTHOR, $user_id, true );
	}

	/**
	 * Checks whether a post belongs to a supported content type.
	 *
	 * @param \WP_Post $post The post to inspect.
	 * @return bool
	 * @since 1.0.0
	 */
	private function is_supported_post( \WP_Post $post ): bool {
		return in_array( $post->post_type, array( 'post', 'page' ), true );
	}

	/**
	 * Handles post status transitions to detect publishing events.
	 *
	 * @param string   $new_status The new post status.
	 * @param string   $old_status The old post status.
	 * @param \WP_Post $post       The post object.
	 * @return void
	 * @since 1.0.0
	 */
	public function handle_publish_transition( string $new_status, string $old_status, \WP_Post $post ): void {
		if ( 'publish' === $new_status && 'publish' !== $old_status ) {
			$this->capture( $post->ID );
		}
	}

	/**
	 * Handles post insertion and checks if it is the first publish.
	 *
	 * @param int      $post_id The ID of the post.
	 * @param \WP_Post $post    The post object.
	 * @param bool     $update  Whether this is an update.
	 * @return void
	 * @since 1.0.0
	 */
	public function handle_wp_insert( int $post_id, \WP_Post $post, bool $update ): void {
		if ( 'publish' === $post->post_status && ! $update ) {
			$this->capture( $post_id );
		}
	}
}
