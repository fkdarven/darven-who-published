<?php
/**
 * Plugin loading smoke test.
 *
 * @package Darven\WhoPublished
 */

/**
 * Tests the plugin loading integration.
 */
class Test_Plugin_Loads extends WP_UnitTestCase {
	/**
	 * Verifies that WordPress loads the plugin's Composer-autoloaded starter.
	 *
	 * @return void
	 */
	public function test_starter_class_is_loaded(): void {
		$this->assertTrue( class_exists( \Darven\WhoPublished\Core\Starter::class ) );
	}
}
