<?php

/**
 * Tracks and stores the original author who published a post.
 *
 * @package Darven\WhoPublished
 * @subpackage Publisher
 * @author Darven
 * @since 1.0.0
 * @version 1.0.0
 */

namespace Darven\WhoPublished\Publisher;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class PublisherTracker
 *
 * Observes various WordPress hooks to determine the original publishing author of a post.
 *
 * @package Darven\WhoPublished
 * @subpackage Publisher
 * @author Darven
 * @since 1.0.0
 * @version 1.0.0
 */
class PublisherTracker {

    /**
     * The user ID of the current user.
     *
     * @var int|null
     */
    private ?int $user_id = null;

    /**
     * Whether a publication meta entry already exists.
     *
     * @var string|false|null
     */
    private string|false|null $meta_exists = null;

    /**
     * Registers the WordPress hooks needed to track publishing.
     *
     * @return void
     * @since 1.0.0
     */
    public function init(): void {
        add_action( 'transition_post_status', [ $this, 'handle_publish_transition' ], 10, 3 );
        add_action( 'rest_after_insert_post', [ $this, 'handle_rest_publish' ], 10, 2 );
        add_action( 'wp_insert_post',         [ $this, 'handle_wp_insert' ], 10, 3 );
        add_action( 'publish_post',           [ $this, 'handle_publish_simple' ] );
        add_action( 'publish_page',           [ $this, 'handle_publish_simple' ] );
    }

    /**
     * Loads relevant metadata and user context for publishing tracking.
     *
     * @param int $post_id The ID of the post being published.
     * @return void
     * @since 1.0.0
     */
    private function prepare( int $post_id ): void {
        $this->meta_exists = get_post_meta( $post_id, DARVEN_WHO_PUBLISHED_ORIGINAL_AUTHOR, true );
        $this->user_id = get_current_user_id();
    }

    /**
     * Attempts to save the publishing author metadata.
     *
     * @param int $post_id The ID of the post being processed.
     * @return void
     * @since 1.0.0
     */
    private function try_register_author( int $post_id ): void {
        $this->prepare( $post_id );

        if ( $this->meta_exists || ! $this->user_id ) {
            return;
        }

        update_post_meta( $post_id, DARVEN_WHO_PUBLISHED_ORIGINAL_AUTHOR, $this->user_id );
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
        if ( $new_status === 'publish' && $old_status !== 'publish' ) {
            $this->try_register_author( $post->ID );
        }
    }

    /**
     * Handles REST-based post publication.
     *
     * @param \WP_Post         $post    The post object.
     * @param \WP_REST_Request $request The REST request.
     * @return void
     * @since 1.0.0
     */
    public function handle_rest_publish( \WP_Post $post, \WP_REST_Request $request ): void {
        if ( $post->post_status === 'publish' ) {
            $this->try_register_author( $post->ID );
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
        if ( $post->post_status === 'publish' && ! $update ) {
            $this->try_register_author( $post_id );
        }
    }

    /**
     * Fallback for classic publish actions for post/page.
     *
     * @param int $post_id The ID of the post or page being published.
     * @return void
     * @since 1.0.0
     */
    public function handle_publish_simple( int $post_id ): void {
        $this->try_register_author( $post_id );
    }
}
