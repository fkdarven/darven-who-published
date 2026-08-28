<?php
/**
 * Integration tests for confirmed publisher metadata in the REST API.
 *
 * @package Darven\WhoPublished
 */

/**
 * Tests the REST contract for immutable confirmed publisher metadata.
 */
class Test_REST_Confirmed_Publisher_Meta extends WP_UnitTestCase {

	/**
	 * Restores plugin metadata registration after WordPress test cleanup.
	 *
	 * @return void
	 */
	public function set_up(): void {
		global $wp_rest_server;

		parent::set_up();
		\Darven\WhoPublished\Tracker\RegisterMeta::register();

		foreach ( array( 'post', 'page' ) as $post_type ) {
			$post_type_object                  = get_post_type_object( $post_type );
			$post_type_object->rest_controller = null;
		}

		$wp_rest_server = null;
	}

	/**
	 * Catches REST publication that accepts a forged confirmed publisher ID.
	 *
	 * @return void
	 */
	public function test_rest_publish_rejects_forged_confirmed_publisher_and_captures_request_user(): void {
		$maria_id = self::factory()->user->create( array( 'role' => 'editor' ) );
		$sofia_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$post_id  = self::factory()->post->create( array( 'post_status' => 'draft' ) );

		wp_set_current_user( $maria_id );

		$request = new WP_REST_Request( 'POST', '/wp/v2/posts/' . $post_id );
		$request->set_param( 'status', 'publish' );
		$request->set_param(
			'meta',
			array(
				DARVEN_WHO_PUBLISHED_ORIGINAL_AUTHOR => $sofia_id,
			)
		);
		$response = rest_do_request( $request );
		$data     = $response->get_data();

		$this->assertSame( 403, $response->get_status(), wp_json_encode( $data ) );
		$this->assertSame( 'rest_cannot_update', $data['code'] );
		$this->assertSame( 'publish', get_post_status( $post_id ) );
		$this->assertSame( $maria_id, (int) get_post_meta( $post_id, DARVEN_WHO_PUBLISHED_ORIGINAL_AUTHOR, true ) );
	}

	/**
	 * Catches a later REST request that overwrites the confirmed publisher.
	 *
	 * @return void
	 */
	public function test_rest_cannot_overwrite_confirmed_publisher(): void {
		$maria_id = self::factory()->user->create( array( 'role' => 'editor' ) );
		$sofia_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$post_id  = self::factory()->post->create( array( 'post_status' => 'draft' ) );

		wp_set_current_user( $maria_id );
		wp_publish_post( $post_id );

		wp_set_current_user( $sofia_id );
		$request = new WP_REST_Request( 'POST', '/wp/v2/posts/' . $post_id );
		$request->set_param(
			'meta',
			array(
				DARVEN_WHO_PUBLISHED_ORIGINAL_AUTHOR => $sofia_id,
			)
		);
		$response = rest_do_request( $request );
		$data     = $response->get_data();

		$this->assertSame( 403, $response->get_status(), wp_json_encode( $data ) );
		$this->assertSame( 'rest_cannot_update', $data['code'] );
		$this->assertSame( $maria_id, (int) get_post_meta( $post_id, DARVEN_WHO_PUBLISHED_ORIGINAL_AUTHOR, true ) );
	}

	/**
	 * Catches a later REST request that deletes the confirmed publisher.
	 *
	 * @return void
	 */
	public function test_rest_cannot_delete_confirmed_publisher(): void {
		$maria_id = self::factory()->user->create( array( 'role' => 'editor' ) );
		$sofia_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$post_id  = self::factory()->post->create( array( 'post_status' => 'draft' ) );

		wp_set_current_user( $maria_id );
		wp_publish_post( $post_id );

		wp_set_current_user( $sofia_id );
		$request = new WP_REST_Request( 'POST', '/wp/v2/posts/' . $post_id );
		$request->set_param(
			'meta',
			array(
				DARVEN_WHO_PUBLISHED_ORIGINAL_AUTHOR => null,
			)
		);
		$response = rest_do_request( $request );
		$data     = $response->get_data();

		$this->assertSame( 403, $response->get_status(), wp_json_encode( $data ) );
		$this->assertSame( 'rest_cannot_delete', $data['code'] );
		$this->assertSame( $maria_id, (int) get_post_meta( $post_id, DARVEN_WHO_PUBLISHED_ORIGINAL_AUTHOR, true ) );
	}

	/**
	 * Catches confirmed publisher data leaking into public view-context responses.
	 *
	 * @return void
	 */
	public function test_anonymous_view_context_does_not_expose_confirmed_publisher(): void {
		$maria_id = self::factory()->user->create( array( 'role' => 'editor' ) );
		$post_id  = self::factory()->post->create( array( 'post_status' => 'draft' ) );

		wp_set_current_user( $maria_id );
		wp_publish_post( $post_id );

		wp_set_current_user( 0 );
		$request = new WP_REST_Request( 'GET', '/wp/v2/posts/' . $post_id );
		$request->set_param( 'context', 'view' );
		$response = rest_do_request( $request );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status(), wp_json_encode( $data ) );
		$this->assertArrayNotHasKey( DARVEN_WHO_PUBLISHED_ORIGINAL_AUTHOR, $data['meta'] );
	}

	/**
	 * Catches confirmed publisher data disappearing from authorized edit responses.
	 *
	 * @return void
	 */
	public function test_authorized_edit_context_can_read_confirmed_publisher(): void {
		$maria_id = self::factory()->user->create( array( 'role' => 'editor' ) );
		$post_id  = self::factory()->post->create( array( 'post_status' => 'draft' ) );

		wp_set_current_user( $maria_id );
		wp_publish_post( $post_id );

		$request = new WP_REST_Request( 'GET', '/wp/v2/posts/' . $post_id );
		$request->set_param( 'context', 'edit' );
		$response = rest_do_request( $request );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status(), wp_json_encode( $data ) );
		$this->assertSame( $maria_id, $data['meta'][ DARVEN_WHO_PUBLISHED_ORIGINAL_AUTHOR ] );
	}
}
