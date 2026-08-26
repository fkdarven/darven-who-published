<?php
/**
 * Retrieves the confirmed, estimated, or unknown publisher of a post.
 *
 * @package Darven\WhoPublished
 * @subpackage Publisher
 * @author Darven
 * @since 1.0.0
 * @version 1.1.0
 */

namespace Darven\WhoPublished\Publisher;

use Darven\WhoPublished\Settings\SettingsRepository;
use WP_Post;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Retrieves publisher identities without treating estimates as confirmation.
 */
class PublisherRetriever {

	/**
	 * Gets the publisher identity for a supported publication status.
	 *
	 * @param WP_Post $post Post being displayed.
	 * @return PublisherIdentity
	 */
	public function get_publisher( WP_Post $post ): PublisherIdentity {
		if ( ! in_array( $post->post_status, $this->allowed_statuses(), true ) ) {
			return PublisherIdentity::unknown();
		}

		$publisher_id = $this->confirmed_publisher_id( $post->ID );

		if ( $publisher_id > 0 ) {
			return PublisherIdentity::confirmed( $publisher_id );
		}

		if ( ! ( new SettingsRepository() )->estimation_enabled( $post ) ) {
			return PublisherIdentity::unknown();
		}

		return ( new PublisherEstimator() )->estimate( $post );
	}

	/**
	 * Reads confirmed metadata without changing existing evidence.
	 *
	 * @param int $post_id Post ID.
	 * @return int
	 */
	private function confirmed_publisher_id( int $post_id ): int {
		$publisher_id = get_post_meta( $post_id, DARVEN_WHO_PUBLISHED_ORIGINAL_AUTHOR, true );

		return absint( $publisher_id );
	}

	/**
	 * Lists statuses that may have a publisher identity.
	 *
	 * @return string[]
	 */
	private function allowed_statuses(): array {
		return array( 'publish', 'private', 'future' );
	}
}
