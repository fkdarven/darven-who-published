<?php

/**
 * Adds a metabox in the post editor to display the original author.
 *
 * @package Darven\WhoPublished
 * @subpackage Admin
 * @author Darven
 * @since 1.0.0
 * @version 1.0.0
 */

namespace Darven\WhoPublished\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Darven\WhoPublished\Publisher\PublisherRetriever;

/**
 * Class MetaBoxDisplay
 *
 * Displays original author information with visual badge in post/page edit screens.
 *
 * @package Darven\WhoPublished
 * @subpackage Admin
 * @since 1.0.0
 * @version 1.0.0
 */
class MetaBoxDisplay {

	/**
	 * Registers the metabox.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public function register(): void {
		add_action( 'add_meta_boxes', function () {
			add_meta_box(
				'darven-who-published-meta',
				__( 'Who Published', 'darven-who-published' ),
				[ $this, 'render' ],
				[ 'post', 'page' ],
				'side',
				'core'
			);
		} );
	}

	/**
	 * Renders the content of the metabox.
	 *
	 * @param \WP_Post $post The current post object.
	 *
	 * @return void
	 * @since 1.0.0
	 */
	public function render( \WP_Post $post ): void {
		$retriever    = new PublisherRetriever();
		$publisher_id = $retriever->get_publisher( $post );
		if ( ! $publisher_id ) {
			echo '<p>' . esc_html__( 'No original author found.', 'darven-who-published' ) . '</p>';

			return;
		}

		$is_guessed = get_post_meta( $post->ID, DARVEN_WHO_PUBLISHED_WAS_GUESSED, true );
		$user       = get_userdata( $publisher_id );
		if ( ! $user ) {
			echo '<p>&mdash;</p>';

			return;
		}

		$label = $is_guessed
			? '<span class="darven-badge darven-badge-guessed" title="' . esc_attr__( 'Based on revision or last edit.', 'darven-who-published' ) . '">' . esc_html__( 'Probably ', 'darven-who-published' ) . esc_html( $user->display_name ) . '</span>'
			: '<span class="darven-badge darven-badge-confirmed">' . esc_html( $user->display_name ) . '</span>';

		echo '<p>' . esc_html( $label ) . '</p>';
	}
}
