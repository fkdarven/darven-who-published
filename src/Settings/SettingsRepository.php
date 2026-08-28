<?php
/**
 * Reads Who Published settings.
 *
 * @package Darven\WhoPublished
 * @subpackage Settings
 */

namespace Darven\WhoPublished\Settings;

use WP_Post;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Provides access to the historical-estimation setting.
 */
class SettingsRepository {

	/**
	 * Returns whether historical publisher estimation is enabled for a post.
	 *
	 * @param WP_Post $post Post being displayed.
	 * @return bool
	 */
	public function estimation_enabled( WP_Post $post ): bool {
		$enabled = rest_sanitize_boolean( get_option( 'darven_who_published_enable_estimation', false ) );

		return (bool) apply_filters( 'darven_who_published_estimation_enabled', $enabled, $post );
	}
}
