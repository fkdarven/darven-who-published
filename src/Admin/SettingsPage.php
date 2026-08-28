<?php
/**
 * Registers the Who Published administration settings page.
 *
 * @package Darven\WhoPublished
 * @subpackage Admin
 */

namespace Darven\WhoPublished\Admin;

if ( ! defined( 'ABSPATH' ) ) {

	exit;
}

/**
 * Provides the opt-in historical-estimation setting.
 */
class SettingsPage {

	/**
	 * Registers Settings API and administration hooks.
	 *
	 * @return void
	 */
	public function start(): void {
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( dirname( __DIR__, 2 ) . '/darven-who-published.php' ), array( $this, 'settings_link' ) );
	}

	/**
	 * Registers the default-off boolean setting.
	 *
	 * @return void
	 */
	public function register_settings(): void {
		register_setting(
			'darven_who_published_settings',
			'darven_who_published_enable_estimation',
			array(
				'type'              => 'boolean',
				'sanitize_callback' => array( $this, 'sanitize_estimation' ),
				'default'           => false,
			)
		);
	}

	/**
	 * Converts Settings API input to a strict boolean.
	 *
	 * @param mixed $value Submitted setting value.
	 * @return bool
	 */
	public function sanitize_estimation( $value ): bool {
		return rest_sanitize_boolean( $value );
	}

	/**
	 * Registers the Settings → Who Published page.
	 *
	 * @return void
	 */
	public function register_menu(): void {
		add_options_page(
			__( 'Who Published', 'darven-who-published' ),
			__( 'Who Published', 'darven-who-published' ),
			'manage_options',
			'darven-who-published',
			array( $this, 'render_page' )
		);
	}

	/**
	 * Renders Settings API markup for authorized administrators.
	 *
	 * @return void
	 */
	public function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to manage Who Published settings.', 'darven-who-published' ) );
		}

		$enabled = rest_sanitize_boolean( get_option( 'darven_who_published_enable_estimation', false ) );
		?>
		<div class="wrap">
			<h1><?php echo esc_html__( 'Who Published', 'darven-who-published' ); ?></h1>
			<form action="options.php" method="post">
				<?php settings_fields( 'darven_who_published_settings' ); ?>
				<p>
					<label for="darven_who_published_enable_estimation">
						<input type="hidden" name="darven_who_published_enable_estimation" value="0" />
						<input type="checkbox" id="darven_who_published_enable_estimation" name="darven_who_published_enable_estimation" value="1" <?php checked( $enabled ); ?> />
						<?php echo esc_html__( 'Estimate publishers for historical posts', 'darven-who-published' ); ?>
					</label>
				</p>
				<p class="description">
					<?php echo esc_html__( 'WordPress cannot prove who published content created before plugin activation. Enabling this permits clearly marked estimates; disabling it hides estimates without deleting confirmed publisher data.', 'darven-who-published' ); ?>
				</p>
				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * Adds an administrator settings link to the Plugins screen.
	 *
	 * @param string[] $links Existing plugin action links.
	 * @return string[]
	 */
	public function settings_link( array $links ): array {
		$url  = admin_url( 'options-general.php?page=darven-who-published' );
		$link = sprintf( '<a href="%1$s">%2$s</a>', esc_url( $url ), esc_html__( 'Settings', 'darven-who-published' ) );
		array_unshift( $links, $link );

		return $links;
	}
}
