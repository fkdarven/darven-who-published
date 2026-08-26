<?php
/**
 * Entry point for initializing the Who Published plugin.
 *
 * @package Darven\WhoPublished
 * @subpackage Core
 * @author Darven
 * @since 1.0.0
 * @version 1.0.0
 */

namespace Darven\WhoPublished\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Darven\WhoPublished\Admin\ColumnManager;
use Darven\WhoPublished\Admin\MetaBoxDisplay;
use Darven\WhoPublished\Admin\SettingsPage;
use Darven\WhoPublished\Tracker\RegisterMeta;
use Darven\WhoPublished\Publisher\PublisherTracker;

/**
 * Class Starter
 *
 * Handles the plugin's initialization and hook registration.
 *
 * @package Darven\WhoPublished
 * @subpackage Core
 * @author Darven
 * @since 1.0.0
 * @version 1.0.0
 */
class Starter {

    /**
     * Bootstraps the plugin by calling the setup process.
     *
     * @return void
     * @since 1.0.0
     */
    public function start() {
        $this->setup();
    }

    /**
     * Sets up the plugin's components and hooks.
     *
     * @return void
     * @since 1.0.0
     */
    public function setup() {
        RegisterMeta::register();
        (new PublisherTracker())->init();

        if ( is_admin() ) {
            (new ColumnManager())->start();
            (new MetaBoxDisplay())->register();
            (new SettingsPage())->start();
        }
    }
}
