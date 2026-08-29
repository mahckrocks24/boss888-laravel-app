<?php
/**
 * LGSC Settings Page.
 *
 * Settings → LevelUp SEO Connector.
 *
 * @package LevelUp_Growth_SEO_Connector
 */

defined( 'ABSPATH' ) || exit;

class LGSC_Settings_Page {

	const OPTION_GROUP   = 'lgsc_options';
	const PAGE_SLUG      = 'lgsc-settings';
	const NONCE_TEST     = 'lgsc_test_connection';

	public function register() {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_scripts' ) );
		add_action( 'wp_ajax_lgsc_test_connection', array( $this, 'ajax_test_connection' ) );
		// v1.0.2: surface a non-blocking admin notice when the API key is missing
		// or has the wrong format. Helps existing v1.0.1 installs that need to be
		// reconnected with a long-lived `lgs_*` key.
		add_action( 'admin_notices', array( $this, 'maybe_show_setup_notice' ) );
	}

	/**
	 * Show a one-line admin notice if the plugin is unconfigured or holds a
	 * legacy JWT-shaped key (v1.0.1 and earlier accepted JWTs in the same
	 * field; the new server side requires `lgs_*` API keys).
	 */
	public function maybe_show_setup_notice() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		// Don't double-render on the Settings page itself.
		if ( $screen && isset( $screen->id ) && false !== strpos( $screen->id, 'lgsc-settings' ) ) {
			return;
		}
		$key = (string) get_option( 'lgsc_api_key', '' );
		$ws  = (int) get_option( 'lgsc_workspace_id', 0 );
		$bad_format = $key !== '' && strpos( $key, 'lgs_' ) !== 0;

		if ( $key === '' || $ws === 0 ) {
			$url = esc_url( admin_url( 'options-general.php?page=lgsc-settings' ) );
			echo '<div class="notice notice-warning"><p>';
			echo '<strong>LevelUp Growth SEO:</strong> Plugin is not connected. ';
			echo '<a href="' . $url . '">Open Settings</a> to paste your API Key + Workspace ID.';
			echo '</p></div>';
			return;
		}
		if ( $bad_format ) {
			$url = esc_url( admin_url( 'options-general.php?page=lgsc-settings' ) );
			echo '<div class="notice notice-error"><p>';
			echo '<strong>LevelUp Growth SEO:</strong> Your API Key format looks legacy (pre-v1.0.2). ';
			echo 'New keys start with <code>lgs_</code>. ';
			echo '<a href="' . $url . '">Issue a new API key</a> in your LevelUp workspace and paste it here.';
			echo '</p></div>';
		}
	}

	public function add_menu() {
		add_options_page(
			__( 'LevelUp SEO Connector', 'lgsc' ),
			__( 'LevelUp SEO', 'lgsc' ),
			'manage_options',
			self::PAGE_SLUG,
			array( $this, 'render' )
		);
	}

	public function register_settings() {
		register_setting( self::OPTION_GROUP, 'lgsc_api_url', array(
			'type'              => 'string',
			'sanitize_callback' => 'esc_url_raw',
			'default'           => LGSC_API_BASE_DEFAULT,
		) );
		register_setting( self::OPTION_GROUP, 'lgsc_api_key', array(
			'type'              => 'string',
			'sanitize_callback' => array( $this, 'sanitize_secret' ),
			'default'           => '',
		) );
		register_setting( self::OPTION_GROUP, 'lgsc_workspace_id', array(
			'type'              => 'integer',
			'sanitize_callback' => 'absint',
			'default'           => 0,
		) );
		register_setting( self::OPTION_GROUP, 'lgsc_auto_analyze', array(
			'type'              => 'string',
			'sanitize_callback' => array( $this, 'sanitize_checkbox' ),
			'default'           => '1',
		) );
		register_setting( self::OPTION_GROUP, 'lgsc_webhook_secret', array(
			'type'              => 'string',
			'sanitize_callback' => array( $this, 'sanitize_secret' ),
			'default'           => '',
		) );
	}

	public function sanitize_secret( $value ) {
		$value = is_string( $value ) ? trim( $value ) : '';
		// Allow letters, digits, dashes, underscores, dots, and the "Bearer "-style token chars.
		return preg_replace( '/[^A-Za-z0-9\-\._=:\/\+]/', '', $value );
	}

	public function sanitize_checkbox( $value ) {
		return ( '1' === (string) $value || 1 === $value || true === $value ) ? '1' : '0';
	}

	public function enqueue_scripts( $hook ) {
		if ( 'settings_page_' . self::PAGE_SLUG !== $hook ) {
			return;
		}

		wp_enqueue_style(
			'lgsc-settings',
			LGSC_PLUGIN_URL . 'assets/css/settings.css',
			array(),
			LGSC_VERSION
		);

		wp_enqueue_script(
			'lgsc-settings',
			LGSC_PLUGIN_URL . 'assets/js/settings.js',
			array(),
			LGSC_VERSION,
			true
		);

		wp_localize_script( 'lgsc-settings', 'LGSC_SETTINGS', array(
			'ajaxUrl' => admin_url( 'admin-ajax.php' ),
			'nonce'   => wp_create_nonce( self::NONCE_TEST ),
			'strings' => array(
				'testing'      => __( 'Testing connection...', 'lgsc' ),
				'success'      => __( 'Connected.', 'lgsc' ),
				'failed'       => __( 'Connection failed.', 'lgsc' ),
				'workspace'    => __( 'Workspace', 'lgsc' ),
				'plan'         => __( 'Plan', 'lgsc' ),
				'credits'      => __( 'Credits', 'lgsc' ),
				'pages'        => __( 'Pages indexed', 'lgsc' ),
			),
		) );
	}

	public function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'lgsc' ) );
		}

		$api_url      = (string) get_option( 'lgsc_api_url', LGSC_API_BASE_DEFAULT );
		$api_key      = (string) get_option( 'lgsc_api_key', '' );
		$workspace_id = (int) get_option( 'lgsc_workspace_id', 0 );
		$auto         = (string) get_option( 'lgsc_auto_analyze', '1' );
		$webhook_sec  = (string) get_option( 'lgsc_webhook_secret', '' );
		?>
		<div class="wrap lgsc-wrap">
			<h1><?php esc_html_e( 'LevelUp Growth SEO Connector', 'lgsc' ); ?></h1>
			<p class="lgsc-intro">
				<?php esc_html_e( 'This plugin connects your WordPress site to LevelUp Growth. All SEO intelligence lives in your LevelUp workspace — this connector is a thin client.', 'lgsc' ); ?>
			</p>

			<form method="post" action="options.php" class="lgsc-form">
				<?php settings_fields( self::OPTION_GROUP ); ?>

				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="lgsc_api_url"><?php esc_html_e( 'API URL', 'lgsc' ); ?></label></th>
						<td>
							<input type="url" id="lgsc_api_url" name="lgsc_api_url" value="<?php echo esc_attr( $api_url ); ?>" class="regular-text" placeholder="https://levelupgrowth.io/api" />
							<p class="description"><?php esc_html_e( 'Your LevelUp Growth instance URL (no trailing slash).', 'lgsc' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="lgsc_api_key"><?php esc_html_e( 'API Key', 'lgsc' ); ?></label></th>
						<td>
							<input type="password" id="lgsc_api_key" name="lgsc_api_key" value="<?php echo esc_attr( $api_key ); ?>" class="regular-text" autocomplete="off" placeholder="lgs_..." />
							<p class="description">
								<?php esc_html_e( 'Long-lived API key from LevelUp Growth → Settings → API Keys. Must start with', 'lgsc' ); ?>
								<code>lgs_</code>.
								<?php esc_html_e( 'Each key is shown only once at issue time — paste it here, then click Save Settings.', 'lgsc' ); ?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="lgsc_workspace_id"><?php esc_html_e( 'Workspace ID', 'lgsc' ); ?></label></th>
						<td>
							<input type="number" min="1" id="lgsc_workspace_id" name="lgsc_workspace_id" value="<?php echo esc_attr( $workspace_id > 0 ? $workspace_id : '' ); ?>" class="small-text" />
							<p class="description"><?php esc_html_e( 'Your workspace ID from LevelUp Growth.', 'lgsc' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Auto-analyze on publish', 'lgsc' ); ?></th>
						<td>
							<label>
								<input type="checkbox" id="lgsc_auto_analyze" name="lgsc_auto_analyze" value="1" <?php checked( '1', $auto ); ?> />
								<?php esc_html_e( 'Send page data to LevelUp Growth automatically when a post or page is published or updated.', 'lgsc' ); ?>
							</label>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="lgsc_webhook_secret"><?php esc_html_e( 'Webhook Secret', 'lgsc' ); ?></label></th>
						<td>
							<input type="text" id="lgsc_webhook_secret" name="lgsc_webhook_secret" value="<?php echo esc_attr( $webhook_sec ); ?>" class="regular-text" />
							<p class="description"><?php esc_html_e( 'Shared secret for incoming push updates from LevelUp Growth (POST /wp-json/lgsc/v1/update-meta). Set the same value in your LevelUp workspace settings.', 'lgsc' ); ?></p>
						</td>
					</tr>
				</table>

				<?php submit_button( __( 'Save Settings', 'lgsc' ) ); ?>
			</form>

			<hr />

			<div class="lgsc-test-section">
				<h2><?php esc_html_e( 'Test Connection', 'lgsc' ); ?></h2>
				<p><?php esc_html_e( 'Verify your API credentials by pinging LevelUp Growth.', 'lgsc' ); ?></p>
				<button type="button" class="button button-primary" id="lgsc-test-btn">
					<?php esc_html_e( 'Test Connection', 'lgsc' ); ?>
				</button>
				<div id="lgsc-test-result" class="lgsc-test-result" aria-live="polite"></div>
			</div>

			<hr />

			<div class="lgsc-help-section">
				<h2><?php esc_html_e( 'How it works', 'lgsc' ); ?></h2>
				<ol>
					<li><?php esc_html_e( 'When you publish or update a post/page, the connector sends its content to LevelUp Growth.', 'lgsc' ); ?></li>
					<li><?php esc_html_e( 'LevelUp Growth scores it, finds quick wins, and identifies internal-link opportunities.', 'lgsc' ); ?></li>
					<li><?php esc_html_e( 'You see the result in the LevelUp SEO meta box on the post editor — and can edit your meta title / description right there.', 'lgsc' ); ?></li>
					<li><?php esc_html_e( 'No SEO logic runs in WordPress. All decisions happen in your LevelUp Growth workspace.', 'lgsc' ); ?></li>
				</ol>
			</div>
		</div>
		<?php
	}

	public function ajax_test_connection() {
		check_ajax_referer( self::NONCE_TEST, 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'forbidden' ), 403 );
		}

		$client = new LGSC_API_Client();
		if ( ! $client->is_configured() ) {
			wp_send_json_error( array( 'message' => __( 'API key and Workspace ID are required.', 'lgsc' ) ), 400 );
		}

		$result = $client->verify_connection();

		if ( ! is_array( $result ) || ! empty( $result['error'] ) ) {
			$msg  = isset( $result['error'] ) ? (string) $result['error'] : 'unknown_error';
			$code = isset( $result['http_code'] ) ? (int) $result['http_code'] : 502;
			wp_send_json_error( array( 'message' => $msg, 'http_code' => $code ), 502 );
		}

		// v1.1.0 — a passing test registers this site with the workspace (site URL + webhook
		// secret), which is what lets LevelUp publish articles into this WordPress.
		$reg = $client->register_site();
		if ( ! is_array( $reg ) || ! empty( $reg['error'] ) ) {
			$msg = isset( $reg['error'] ) ? (string) $reg['error'] : 'register_failed';
			wp_send_json_error( array( 'message' => 'Connected, but the site could not be registered: ' . $msg ), 502 );
		}
		update_option( 'lgsc_registered_at', current_time( 'mysql' ) );

		wp_send_json_success( array(
			'registered'        => true,
			'connection_id'     => isset( $reg['connection_id'] ) ? (int) $reg['connection_id'] : 0,
			'workspace_name'    => isset( $result['workspace_name'] ) ? (string) $result['workspace_name'] : '',
			'plan'              => isset( $result['plan'] ) ? (string) $result['plan'] : '',
			'credits_remaining' => isset( $result['credits_remaining'] ) ? (int) $result['credits_remaining'] : 0,
			'seo_pages_indexed' => isset( $result['seo_pages_indexed'] ) ? (int) $result['seo_pages_indexed'] : 0,
		) );
	}
}
