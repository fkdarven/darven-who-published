<?php
/**
 * Estimates historical post publishers from available WordPress evidence.
 *
 * @package Darven\WhoPublished
 * @subpackage Publisher
 */

namespace Darven\WhoPublished\Publisher;

use WP_Post;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Resolves and persists explicitly estimated publisher identities.
 */
class PublisherEstimator {

	/**
	 * Returns the best available historical estimate for a post.
	 *
	 * @param WP_Post $post Post being estimated.
	 * @return PublisherIdentity
	 */
	public function estimate( WP_Post $post ): PublisherIdentity {
		$legacy_id = $this->metadata_user_id( $post->ID, DARVEN_WHO_PUBLISHED_WAS_GUESSED );
		if ( $legacy_id > 0 ) {
			return $this->filter_estimate( PublisherIdentity::estimated( $legacy_id, 'legacy' ), $post );
		}

		$persisted_id     = $this->metadata_user_id( $post->ID, '_darven_who_published_estimated_author' );
		$persisted_source = get_post_meta( $post->ID, '_darven_who_published_estimation_source', true );
		if ( $persisted_id > 0 && in_array( $persisted_source, PublisherIdentity::estimation_sources(), true ) ) {
			return $this->filter_estimate( PublisherIdentity::estimated( $persisted_id, $persisted_source ), $post );
		}

		$identity = $this->first_computed_estimate( $post );
		if ( PublisherIdentity::UNKNOWN === $identity->status() ) {
			return $identity;
		}

		$identity = $this->filter_estimate( $identity, $post );
		if ( PublisherIdentity::UNKNOWN === $identity->status() ) {
			return PublisherIdentity::unknown();
		}

		update_post_meta( $post->ID, '_darven_who_published_estimated_author', $identity->user_id() );
		update_post_meta( $post->ID, '_darven_who_published_estimation_source', $identity->source() );

		return $identity;
	}

	/**
	 * Applies the public estimate filter and accepts only existing WordPress users.
	 *
	 * @param PublisherIdentity $identity Estimated identity before filtering.
	 * @param WP_Post           $post     Post being estimated.
	 * @return PublisherIdentity
	 */
	private function filter_estimate( PublisherIdentity $identity, WP_Post $post ): PublisherIdentity {
		$estimated_user_id = absint(
			apply_filters(
				'darven_who_published_estimated_publisher',
				$identity->user_id(),
				$identity->source(),
				$post
			)
		);

		if ( $estimated_user_id <= 0 || ! get_userdata( $estimated_user_id ) ) {
			return PublisherIdentity::unknown();
		}

		return PublisherIdentity::estimated( $estimated_user_id, $identity->source() );
	}

	/**
	 * Finds the first available non-persisted estimation evidence source.
	 *
	 * @param WP_Post $post Post being estimated.
	 * @return PublisherIdentity
	 */
	private function first_computed_estimate( WP_Post $post ): PublisherIdentity {
		$edit_last_id = $this->metadata_user_id( $post->ID, '_edit_last' );
		if ( $edit_last_id > 0 ) {
			return PublisherIdentity::estimated( $edit_last_id, 'edit_last' );
		}

		$revisions       = wp_get_post_revisions( $post->ID, array( 'numberposts' => 1 ) );
		$latest_revision = reset( $revisions );
		if ( $latest_revision instanceof WP_Post && (int) $latest_revision->post_author > 0 ) {
			return PublisherIdentity::estimated( (int) $latest_revision->post_author, 'latest_revision' );
		}

		if ( (int) $post->post_author > 0 ) {
			return PublisherIdentity::estimated( (int) $post->post_author, 'post_author' );
		}

		return PublisherIdentity::unknown();
	}

	/**
	 * Reads a positive user ID from post metadata.
	 *
	 * @param int    $post_id  Post ID.
	 * @param string $meta_key Metadata key.
	 * @return int
	 */
	private function metadata_user_id( int $post_id, string $meta_key ): int {
		return absint( get_post_meta( $post_id, $meta_key, true ) );
	}
}
