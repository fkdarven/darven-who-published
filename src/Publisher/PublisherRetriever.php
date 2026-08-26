<?php
/**
 * Retrieves the original author who published a post.
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

class PublisherRetriever {

	public function get_publisher( $post ): int {
		if ( ! in_array( $post->post_status, $this->allowed_statuses(), true ) ) {
			return 0;
		}

		$publisher_id = $this->get_by_meta( $post->ID );

		if ( $publisher_id ) {
			update_post_meta($post->ID, DARVEN_WHO_PUBLISHED_WAS_GUESSED, false);;
			return (int) $publisher_id;
		}

		$publisher_guesser = new PublisherGuesser();
		$publisher_id = $publisher_guesser->guess_publisher( $post );
		return (int) $publisher_id;
	}

	private function get_by_meta( $post_id ): int {
		$publisher_id = get_post_meta( $post_id, DARVEN_WHO_PUBLISHED_ORIGINAL_AUTHOR, true );

		return $publisher_id ? (int) $publisher_id : 0;
	}

	private function allowed_statuses(): array {
		return [ 'publish', 'private', 'future' ];
	}
}
