<?php
/**
 * Integration tests for confirmed publisher capture.
 *
 * @package Darven\WhoPublished
 */

/**
 * Tests confirmed publisher capture through WordPress publication flows.
 */
class Test_Publisher_Tracker extends WP_UnitTestCase {

	/**
	 * Catches a tracker that overwrites the first publisher after later edits.
	 *
	 * @return void
	 */
	public function test_first_authenticated_publisher_is_recorded_and_never_overwritten(): void {
		$author_id    = self::factory()->user->create( array( 'role' => 'author' ) );
		$publisher_id = self::factory()->user->create( array( 'role' => 'editor' ) );
		$later_id     = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$post_id      = self::factory()->post->create(
			array(
				'post_author' => $author_id,
				'post_status' => 'draft',
			)
		);

		wp_set_current_user( $publisher_id );
		wp_publish_post( $post_id );
		$this->assertSame( $publisher_id, (int) get_post_meta( $post_id, '_darven_who_published_author', true ) );

		wp_set_current_user( $later_id );
		wp_update_post(
			array(
				'ID'         => $post_id,
				'post_title' => 'Edited later',
			)
		);
		$this->assertSame( $publisher_id, (int) get_post_meta( $post_id, '_darven_who_published_author', true ) );
	}

	/**
	 * Catches a tracker that invents a publisher for unauthenticated publication.
	 *
	 * @return void
	 */
	public function test_publish_without_authenticated_user_remains_unknown(): void {
		$post_id = self::factory()->post->create( array( 'post_status' => 'draft' ) );

		wp_set_current_user( 0 );
		wp_publish_post( $post_id );

		$this->assertSame( '', get_post_meta( $post_id, '_darven_who_published_author', true ) );
	}

	/**
	 * Catches an authenticated later edit that fills an anonymous first publication.
	 *
	 * @return void
	 */
	public function test_later_authenticated_edit_does_not_fill_an_anonymous_first_publication(): void {
		$publisher_id = self::factory()->user->create( array( 'role' => 'editor' ) );
		$post_id      = self::factory()->post->create( array( 'post_status' => 'draft' ) );

		wp_set_current_user( 0 );
		wp_publish_post( $post_id );

		wp_set_current_user( $publisher_id );
		wp_update_post(
			array(
				'ID'         => $post_id,
				'post_title' => 'Edited by a later user',
			)
		);

		$this->assertSame( '', get_post_meta( $post_id, '_darven_who_published_author', true ) );
	}

	/**
	 * Catches a REST edit that credits a user who did not first publish the post.
	 *
	 * @return void
	 */
	public function test_later_authenticated_rest_edit_does_not_fill_an_anonymous_first_publication(): void {
		$publisher_id = self::factory()->user->create( array( 'role' => 'editor' ) );
		$post_id      = self::factory()->post->create( array( 'post_status' => 'draft' ) );

		wp_set_current_user( 0 );
		wp_publish_post( $post_id );

		wp_set_current_user( $publisher_id );
		$request = new WP_REST_Request( 'POST', '/wp/v2/posts/' . $post_id );
		$request->set_param( 'title', 'Edited by a later REST user' );
		$response = rest_do_request( $request );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( '', get_post_meta( $post_id, '_darven_who_published_author', true ) );
	}

	/**
	 * Catches a republish that credits a user after an anonymous first publication.
	 *
	 * @return void
	 */
	public function test_authenticated_republish_after_anonymous_first_publication_remains_unknown(): void {
		$publisher_id = self::factory()->user->create( array( 'role' => 'editor' ) );
		$post_id      = self::factory()->post->create( array( 'post_status' => 'draft' ) );

		wp_set_current_user( 0 );
		wp_publish_post( $post_id );
		wp_update_post(
			array(
				'ID'          => $post_id,
				'post_status' => 'draft',
			)
		);

		wp_set_current_user( $publisher_id );
		wp_publish_post( $post_id );

		$this->assertSame( '', get_post_meta( $post_id, '_darven_who_published_author', true ) );
	}

	/**
	 * Catches a tracker that stores metadata on unsupported custom post types.
	 *
	 * @return void
	 */
	public function test_unsupported_post_types_are_not_tracked(): void {
		$publisher_id = self::factory()->user->create( array( 'role' => 'editor' ) );

		register_post_type(
			'book',
			array(
				'public' => true,
			)
		);
		$post_id = self::factory()->post->create(
			array(
				'post_status' => 'draft',
				'post_type'   => 'book',
			)
		);

		wp_set_current_user( $publisher_id );
		wp_publish_post( $post_id );

		$this->assertSame( '', get_post_meta( $post_id, '_darven_who_published_author', true ) );
	}

	/**
	 * Catches a tracker that fails to track the supported page post type.
	 *
	 * @return void
	 */
	public function test_page_publication_records_the_authenticated_publisher(): void {
		$publisher_id = self::factory()->user->create( array( 'role' => 'editor' ) );
		$page_id      = self::factory()->post->create(
			array(
				'post_status' => 'draft',
				'post_type'   => 'page',
			)
		);

		wp_set_current_user( $publisher_id );
		wp_publish_post( $page_id );

		$this->assertSame( $publisher_id, (int) get_post_meta( $page_id, '_darven_who_published_author', true ) );
	}

	/**
	 * Catches a tracker that is not initialized for real REST publication requests.
	 *
	 * @return void
	 */
	public function test_rest_publish_records_the_request_user(): void {
		$publisher_id = self::factory()->user->create( array( 'role' => 'editor' ) );
		$post_id      = self::factory()->post->create( array( 'post_status' => 'draft' ) );

		wp_set_current_user( $publisher_id );

		$request = new WP_REST_Request( 'POST', '/wp/v2/posts/' . $post_id );
		$request->set_param( 'status', 'publish' );
		$response = rest_do_request( $request );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( $publisher_id, (int) get_post_meta( $post_id, '_darven_who_published_author', true ) );
	}
}
