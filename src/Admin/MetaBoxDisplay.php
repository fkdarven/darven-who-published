<?php
/**
 * Displays publisher identity in the post editor.
 *
 * @package Darven\WhoPublished
 * @subpackage Admin
 */

namespace Darven\WhoPublished\Admin;

use Darven\WhoPublished\Publisher\PublisherRetriever;
use WP_Post;

if ( ! defined( 'ABSPATH' ) ) {

	exit;
}

/**
 * Registers and renders the publisher metabox for posts and pages.
 */
class MetaBoxDisplay {

	/**
	 * Registers the editor metabox.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'add_meta_boxes', array( $this, 'add_meta_boxes' ) );
	}

	/**
	 * Adds the metabox for each supported post type.
	 *
	 * @return void
	 */
	public function add_meta_boxes(): void {
		foreach ( array( 'post', 'page' ) as $post_type ) {
			add_meta_box(
				'darven-who-published-meta',
				__( 'Published by', 'darven-who-published' ),
				array( $this, 'render' ),
				$post_type,
				'side',
				'core'
			);
		}
	}

	/**
	 * Renders publisher identity with an explicit safe HTML allowlist.
	 *
	 * @param WP_Post $post Current post.
	 * @return void
	 */
	public function render( WP_Post $post ): void {
		$identity = ( new PublisherRetriever() )->get_publisher( $post );
		$allowed  = array(
			'a'    => array(
				'href'       => true,
				'class'      => true,
				'title'      => true,
				'aria-label' => true,
			),
			'span' => array(
				'class'      => true,
				'title'      => true,
				'aria-label' => true,
			),
		);

		printf( '<p>%s</p>', wp_kses( PublisherBadge::render( $identity ), $allowed ) );
	}
}
