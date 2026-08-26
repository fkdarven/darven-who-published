<?php

/**
 * Registers custom post meta fields used by the Who Published plugin.
 *
 * @package Darven\WhoPublished
 * @subpackage Tracker
 * @author Darven
 * @since 1.0.0
 * @version 1.0.0
 */

namespace Darven\WhoPublished\Tracker;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class RegisterMeta
 *
 * Handles registration of post meta fields related to original publication tracking.
 *
 * @package Darven\WhoPublished
 * @subpackage Tracker
 * @author Darven
 * @since 1.0.0
 * @version 1.0.0
 */
class RegisterMeta {

    /**
     * Registers custom meta fields for storing the original author and guess status.
     *
     * @return void
     * @since 1.0.0
     */
    public static function register(): void {
        foreach ( [ 'post', 'page' ] as $post_type ) {
            register_post_meta($post_type, DARVEN_WHO_PUBLISHED_ORIGINAL_AUTHOR, [
                'type'              => 'integer',
                'single'            => true,
                'sanitize_callback' => 'absint',
                'auth_callback'     => function () {
                    return current_user_can('edit_posts');
                },
                'show_in_rest'      => true,
                'description'       => 'Original author who published the post',
            ]);

            register_post_meta($post_type, DARVEN_WHO_PUBLISHED_FIRST_PUBLICATION_OBSERVED, [
                'type'              => 'boolean',
                'single'            => true,
                'sanitize_callback' => 'rest_sanitize_boolean',
                'auth_callback'     => function () {
                    return current_user_can('edit_posts');
                },
                'show_in_rest'      => false,
                'description'       => 'Whether the first publication was observed',
            ]);

            register_post_meta($post_type, '_darven_who_published_estimated_author', [
                'type'              => 'integer',
                'single'            => true,
                'sanitize_callback' => 'absint',
                'auth_callback'     => function () {
                    return current_user_can('edit_posts');
                },
                'show_in_rest'      => false,
                'description'       => 'Estimated publisher user ID',
            ]);

            register_post_meta($post_type, '_darven_who_published_estimation_source', [
                'type'              => 'string',
                'single'            => true,
                'sanitize_callback' => function ( $source ) {
                    $sources = array( 'legacy', 'edit_last', 'latest_revision', 'post_author' );

                    return in_array( $source, $sources, true ) ? $source : '';
                },
                'auth_callback'     => function () {
                    return current_user_can('edit_posts');
                },
                'show_in_rest'      => false,
                'description'       => 'Estimated publisher evidence source',
            ]);
        }

        register_post_meta('post', DARVEN_WHO_PUBLISHED_WAS_GUESSED, [
            'type'              => 'boolean',
            'single'            => true,
            'sanitize_callback' => 'rest_sanitize_boolean',
            'auth_callback'     => function () {
                return current_user_can('edit_posts');
            },
            'show_in_rest'      => false,
            'description'       => 'Whether the original author was guessed',
        ]);
    }
}
