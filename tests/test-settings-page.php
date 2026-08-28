<?php
/**
 * Integration tests for the Who Published settings page.
 *
 * @package Darven\WhoPublished
 */

use Darven\WhoPublished\Admin\SettingsPage;

/**
 * Tests Settings API registration and administrator-facing controls.
 */
class Test_Settings_Page extends WP_UnitTestCase {

	/**
	 * Clears the option and registered setting state.
	 *
	 * @return void
	 */
	public function tear_down(): void {
		delete_option( 'darven_who_published_enable_estimation' );
		unset( $GLOBALS['wp_registered_settings']['darven_who_published_enable_estimation'] );
		parent::tear_down();
	}

	/**
	 * Catches settings that default to unsafe historical estimation or fail boolean sanitization.
	 *
	 * @return void
	 */
	public function test_estimation_setting_registers_false_by_default_and_sanitizes_boolean_values(): void {
		$page = new SettingsPage();
		$page->register_settings();
		$settings = get_registered_settings();

		$this->assertArrayHasKey( 'darven_who_published_enable_estimation', $settings );
		$this->assertFalse( $settings['darven_who_published_enable_estimation']['default'] );
		$this->assertTrue( $page->sanitize_estimation( '1' ) );
		$this->assertFalse( $page->sanitize_estimation( '0' ) );
		$this->assertFalse( $page->sanitize_estimation( 'false' ) );
	}

	/**
	 * Catches an options page without WordPress Settings API nonce fields or integrity explanation.
	 *
	 * @return void
	 */
	public function test_settings_page_renders_a_nonce_protected_historical_estimation_control(): void {
		$administrator_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $administrator_id );
		$page = new SettingsPage();
		$page->register_settings();

		ob_start();
		$page->render_page();
		$output = (string) ob_get_clean();

		$this->assertStringContainsString( 'Estimate publishers for historical posts', $output );
		$this->assertStringContainsString( 'WordPress cannot prove who published content created before plugin activation', $output );
		$this->assertStringContainsString( 'name="_wpnonce"', $output );
		$this->assertStringContainsString( 'darven_who_published_enable_estimation', $output );
	}

	/**
	 * Catches the settings menu using an insufficient capability or Plugins screen link without the settings target.
	 *
	 * @return void
	 */
	public function test_menu_requires_manage_options_and_plugin_link_targets_the_settings_page(): void {
		$administrator_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $administrator_id );
		$page = new SettingsPage();
		$page->register_menu();
		$settings_link = $page->settings_link( array( '<a href="plugins.php">Plugins</a>' ) );
		$submenu       = $GLOBALS['submenu']['options-general.php'];
		$matching      = array_filter(
			$submenu,
			static function ( $item ) {
				return 'darven-who-published' === $item[2];
			}
		);

		$this->assertNotEmpty( $matching );
		$this->assertSame( 'manage_options', reset( $matching )[1] );
		$this->assertStringContainsString( 'options-general.php?page=darven-who-published', $settings_link[0] );
		$this->assertStringContainsString( 'Settings', $settings_link[0] );
	}
}
