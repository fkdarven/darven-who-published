<?php
/**
 * Guesses the original author who published a post.
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

class PublisherGuesser {


	public function guess_publisher( $post ): int {


		$publisher_id = $this->get_by_meta( $post->ID );

		if ( $publisher_id ) {
			return (int) $publisher_id;
		}

		$publisher_id = $this->get_by_meta( $post->ID, DARVEN_WHO_PUBLISHED_WAS_GUESSED );
		if ( 0 !== $publisher_id ) {
			return (int) $publisher_id;
		}

		$publisher_id = $this->get_by_meta( $post->ID, '_edit_last' );
		if ( $publisher_id ) {
			update_post_meta( $post->ID, DARVEN_WHO_PUBLISHED_WAS_GUESSED, $publisher_id );

			return (int) $publisher_id;
		}

		$post_revisions  = wp_get_post_revisions( $post->ID );
		$latest_revision = array_shift( $post_revisions );
		if ( $latest_revision ) {
			$rev = wp_get_post_revision( $latest_revision );
			if ( $rev && $rev->post_author ) {
				update_post_meta( $post->ID, DARVEN_WHO_PUBLISHED_WAS_GUESSED, (int) $rev->post_author );

				return (int) $rev->post_author;
			}
		}

		update_post_meta( $post->ID, DARVEN_WHO_PUBLISHED_WAS_GUESSED, (int) $post->post_author );

		return (int) $post->post_author;
	}

	private function get_by_meta( $post_id, $meta_key = null ): int {
		$meta_key     = $meta_key ?? DARVEN_WHO_PUBLISHED_ORIGINAL_AUTHOR;
		$publisher_id = get_post_meta( $post_id, $meta_key, true );

		return $publisher_id ? (int) $publisher_id : 0;
	}



}