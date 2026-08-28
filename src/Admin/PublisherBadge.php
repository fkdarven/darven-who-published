<?php
/**
 * Builds safe, state-aware publisher presentation markup.
 *
 * @package Darven\WhoPublished
 * @subpackage Admin
 */

namespace Darven\WhoPublished\Admin;

use Darven\WhoPublished\Publisher\PublisherIdentity;

if ( ! defined( 'ABSPATH' ) ) {

	exit;
}

/**
 * Renders confirmed, estimated, and unknown publisher states.
 */
class PublisherBadge {

	/**
	 * Renders an identity as safe list-table or metabox markup.
	 *
	 * @param PublisherIdentity $identity Publisher identity state.
	 * @return string
	 */
	public static function render( PublisherIdentity $identity ): string {
		if ( PublisherIdentity::UNKNOWN === $identity->status() ) {
			return '<span class="darven-publisher-unknown">' . esc_html__( 'Unknown', 'darven-who-published' ) . '</span>';
		}

		$user = get_userdata( $identity->user_id() );
		if ( ! $user ) {
			return '<span class="darven-publisher-unknown">' . esc_html__( 'Unknown', 'darven-who-published' ) . '</span>';
		}

		if ( PublisherIdentity::CONFIRMED === $identity->status() ) {
			$label      = $user->display_name;
			$class      = 'darven-badge darven-badge-confirmed';
			$aria_label = __( 'Confirmed publisher', 'darven-who-published' );
			$title      = '';
		} else {
			/* translators: %s: estimated publisher display name. */
			$label      = sprintf( __( 'Estimated %s', 'darven-who-published' ), $user->display_name );
			$class      = 'darven-badge darven-badge-estimated';
			$aria_label = __( 'Estimated publisher', 'darven-who-published' );
			$title      = sprintf(
				/* translators: %s: estimation evidence source. */
				__( 'Estimated from %s.', 'darven-who-published' ),
				self::source_label( $identity->source() )
			);
		}

		$attributes = sprintf(
			' class="%1$s" aria-label="%2$s"%3$s',
			esc_attr( $class ),
			esc_attr( $aria_label ),
			'' !== $title ? ' title="' . esc_attr( $title ) . '"' : ''
		);
		$user_url   = get_edit_user_link( $user->ID );

		if ( $user_url ) {
			return sprintf( '<a href="%1$s"%2$s>%3$s</a>', esc_url( $user_url ), $attributes, esc_html( $label ) );
		}

		return sprintf( '<span%1$s>%2$s</span>', $attributes, esc_html( $label ) );
	}

	/**
	 * Converts stored estimation evidence to an honest human label.
	 *
	 * @param string $source Persisted estimation source.
	 * @return string
	 */
	private static function source_label( string $source ): string {
		$labels = array(
			'legacy'          => __( 'legacy Who Published data', 'darven-who-published' ),
			'edit_last'       => __( 'the last edit', 'darven-who-published' ),
			'latest_revision' => __( 'the latest revision', 'darven-who-published' ),
			'post_author'     => __( 'the credited post author', 'darven-who-published' ),
		);

		return isset( $labels[ $source ] ) ? $labels[ $source ] : __( 'available post data', 'darven-who-published' );
	}
}
