<?php
/**
 * Plugin Name: Darven - Who Published
 * Description: Registers and show who first published a post.
 * Author: Leticia (Darven)
 * Version: 1.0.0
 * License: GPLv2 or later
 * Text Domain: darven-who-published
 *
 * @package Darven\WhoPublished
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( file_exists( __DIR__ . '/vendor/autoload.php' ) ) {
	require_once __DIR__ . '/vendor/autoload.php';
}


define('DARVEN_WHO_PUBLISHED_URL', plugin_dir_url( __FILE__ ));
define('DARVEN_WHO_PUBLISHED_DIR', dirname(plugin_basename( __FILE__ )));
const DARVEN_WHO_PUBLISHED_ORIGINAL_AUTHOR = '_darven_who_published_author';
const DARVEN_WHO_PUBLISHED_WAS_GUESSED     = '_darven_who_published_author_was_guessed';

add_action( 'plugins_loaded', function () {
	if( ! class_exists('Darven\WhoPublished\Core\Starter')){
		return;
	}
	( new \Darven\WhoPublished\Core\Starter() )->start();

} );
