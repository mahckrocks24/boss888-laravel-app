<?php
/**
 * LGSC_Chatbot — Chatbot888 admin sub-page + frontend widget injector.
 *
 * Per architecture rule 3+4: ZERO chatbot intelligence in WP. This class
 * only:
 *   1. Renders an admin sub-page with enable toggle + allowed-domain list
 *   2. Mints/fetches a public widget token from Laravel using the existing
 *      private API key (lgs_*) — and stores ONLY the public widget token
 *      (cwt_*) in WP options. The private key never leaves wp-admin.
 *   3. On wp_footer, injects <script src=".../chatbot/widget.js"
 *      data-token="cwt_..."> when enabled.
 *
 * @package LevelUp_Growth_SEO_Connector
 * @since   1.0.5
 */

defined( 'ABSPATH' ) || exit;

class LGSC_Chatbot {

	const ENABLED_OPT       = 'lgsc_chatbot_enabled';
	const TOKEN_OPT         = 'lgsc_chatbot_widget_token';
	const TOKEN_PREFIX_OPT  = 'lgsc_chatbot_widget_token_prefix';
	const ALLOWED_DOMAINS_OPT = 'lgsc_chatbot_allowed_domains';

	public function register() {
		// Frontend injection — fires on every public-facing render.
		add_action( 'wp_footer', array( $this, 'inject_widget' ), 99 );

		// Admin POST handler — settings save.
		add_action( 'admin_post_lgsc_chatbot_save', array( $this, 'handle_save' ) );
		add_action( 'admin_post_lgsc_chatbot_revoke', array( $this, 'handle_revoke' ) );
	}

	/**
	 * Inject widget bootstrap on the public site.
	 * No-op if disabled, no token, or in admin/login/feed contexts.
	 */
	public function inject_widget() {
		if ( is_admin() || is_feed() ) {
			return;
		}
		$enabled = (bool) get_option( self::ENABLED_OPT, false );
		if ( ! $enabled ) {
			return;
		}
		$token = get_option( self::TOKEN_OPT, '' );
		if ( empty( $token ) || strpos( $token, 'cwt_' ) !== 0 ) {
			return;
		}
		$base = defined( 'LGSC_API_BASE_DEFAULT' ) ? LGSC_API_BASE_DEFAULT : 'https://staging.levelupgrowth.io/api';
		// Strip /api suffix to get the app origin (where /chatbot/widget.js lives).
		$app_url = preg_replace( '#/api/?$#', '', $base );

		printf(
			'<script src="%s/chatbot/widget.js" data-token="%s" data-source="wp-plugin" defer></script>' . "\n",
			esc_url( $app_url ),
			esc_attr( $token )
		);
	}

	/**
	 * Render the admin Chatbot sub-page. Called from LGSC_Admin_Shell.
	 */
	public function render_admin() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'lgsc' ) );
		}
		$enabled = (bool) get_option( self::ENABLED_OPT, false );
		$token_prefix = (string) get_option( self::TOKEN_PREFIX_OPT, '' );
		$has_token = ! empty( get_option( self::TOKEN_OPT, '' ) );
		$domains_raw = (string) get_option( self::ALLOWED_DOMAINS_OPT, '' );
		$plan_required_msg = '';

		// Derive the site domain for the suggested allowed-domain default.
		$site_host = parse_url( home_url(), PHP_URL_HOST );
		?>
		<div class="wrap lgsc-shell-wrap">
			<h1>Chatbot888</h1>
			<p style="color:#666;max-width:700px">
				The AI receptionist for your WordPress site. Answers FAQs, captures leads, books appointments — using the knowledge base you upload in LevelUp Growth.
			</p>

			<?php if ( isset( $_GET['saved'] ) ) : ?>
				<div class="notice notice-success"><p>Settings saved.</p></div>
			<?php endif; ?>
			<?php if ( isset( $_GET['error'] ) ) : ?>
				<div class="notice notice-error"><p><?php echo esc_html( wp_unslash( $_GET['error'] ) ); ?></p></div>
			<?php endif; ?>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'lgsc_chatbot_save' ); ?>
				<input type="hidden" name="action" value="lgsc_chatbot_save" />

				<table class="form-table">
					<tr>
						<th scope="row">Enable on this site</th>
						<td>
							<label>
								<input type="checkbox" name="enabled" value="1" <?php checked( $enabled ); ?> />
								Show the chatbot widget on the public site
							</label>
							<p class="description">
								Disable any time. The widget never loads unless this is checked.
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row">Allowed domains</th>
						<td>
							<textarea name="domains" rows="3" cols="60" placeholder="<?php echo esc_attr( $site_host ); ?>"><?php echo esc_textarea( $domains_raw ); ?></textarea>
							<p class="description">
								One domain per line. The widget will only load and accept requests from these origins.
								Default = <code><?php echo esc_html( $site_host ); ?></code>.
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row">Widget token</th>
						<td>
							<?php if ( $has_token ) : ?>
								<p>
									<code><?php echo esc_html( $token_prefix ); ?>…</code>
									<span style="color:#16a34a;margin-left:6px">active</span>
								</p>
							<?php else : ?>
								<p style="color:#666">No widget token yet — saving with "Enable on this site" checked will mint one.</p>
							<?php endif; ?>
							<p class="description">
								The token is fetched from LevelUp Growth using your private API key, then stored in this WordPress install. The token is public — it ships in the widget script tag — and only works on the allowed domains above.
							</p>
						</td>
					</tr>
				</table>

				<p>
					<button type="submit" class="button button-primary">Save settings</button>
				</p>
			</form>

			<?php if ( $has_token ) : ?>
				<hr style="margin:24px 0" />
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return confirm('Revoke the widget token? The chatbot will stop loading on this site until a new token is minted.');">
					<?php wp_nonce_field( 'lgsc_chatbot_revoke' ); ?>
					<input type="hidden" name="action" value="lgsc_chatbot_revoke" />
					<button type="submit" class="button button-secondary">Revoke widget token</button>
				</form>
			<?php endif; ?>

			<hr style="margin:24px 0" />
			<h2>Manage in LevelUp Growth</h2>
			<p>
				Knowledge base, conversation history, leads, bookings, and analytics all live in your LevelUp Growth dashboard.
				<a href="https://staging.levelupgrowth.io/app/#chatbot" target="_blank" rel="noopener">Open Chatbot888 →</a>
			</p>
		</div>
		<?php
	}

	/**
	 * Save settings. Mints/refreshes the widget token via Laravel API
	 * using the existing private lgs_* key.
	 */
	public function handle_save() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'forbidden' );
		}
		check_admin_referer( 'lgsc_chatbot_save' );

		$enabled = ! empty( $_POST['enabled'] );
		$domains_raw = isset( $_POST['domains'] ) ? wp_unslash( $_POST['domains'] ) : '';
		// Normalise domains: one per line, strip comments + empty lines.
		$lines = array_filter( array_map( 'trim', preg_split( '/\r?\n/', $domains_raw ) ) );
		$lines = array_slice( $lines, 0, 10 );
		if ( empty( $lines ) ) {
			$lines = array( parse_url( home_url(), PHP_URL_HOST ) );
		}

		update_option( self::ENABLED_OPT, $enabled );
		update_option( self::ALLOWED_DOMAINS_OPT, implode( "\n", $lines ) );

		// If enabling and we don't have a token yet, mint one.
		$existing_token = get_option( self::TOKEN_OPT, '' );
		if ( $enabled && empty( $existing_token ) ) {
			$result = $this->mint_token_via_api( $lines );
			if ( is_wp_error( $result ) ) {
				$msg = urlencode( $result->get_error_message() );
				wp_safe_redirect( admin_url( 'admin.php?page=lgsc-chatbot&error=' . $msg ) );
				exit;
			}
			update_option( self::TOKEN_OPT, $result['token'] );
			update_option( self::TOKEN_PREFIX_OPT, $result['prefix'] );
		}

		wp_safe_redirect( admin_url( 'admin.php?page=lgsc-chatbot&saved=1' ) );
		exit;
	}

	public function handle_revoke() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'forbidden' );
		}
		check_admin_referer( 'lgsc_chatbot_revoke' );

		// Best-effort revoke on Laravel (non-fatal if it fails — clear local anyway).
		// Note: revocation requires the token id; we only have the prefix. Skip
		// remote revoke for now and just delete the local copy. Owner can revoke
		// manually from the LevelUp Growth admin UI.
		delete_option( self::TOKEN_OPT );
		delete_option( self::TOKEN_PREFIX_OPT );

		wp_safe_redirect( admin_url( 'admin.php?page=lgsc-chatbot&saved=1' ) );
		exit;
	}

	/**
	 * Call Laravel POST /api/chatbot/widget-token using the existing private
	 * API key. Returns ['token' => 'cwt_...', 'prefix' => 'cwt_xxx'] or WP_Error.
	 */
	private function mint_token_via_api( array $domains ) {
		$api_key = get_option( 'lgsc_api_key', '' );
		if ( empty( $api_key ) ) {
			return new WP_Error( 'no_api_key', 'No LevelUp Growth API key configured. Save your API key in Settings first.' );
		}
		$site_conn_id = (int) get_option( 'lgsc_site_connection_id', 0 );

		$body = array(
			'allowed_domains' => array_values( $domains ),
			'label'           => 'WP plugin v' . LGSC_VERSION . ' on ' . parse_url( home_url(), PHP_URL_HOST ),
		);
		if ( $site_conn_id > 0 ) {
			$body['site_connection_id'] = $site_conn_id;
		}

		$base = function_exists( 'lgsc_api_base' ) ? lgsc_api_base() : ( defined( 'LGSC_API_BASE_DEFAULT' ) ? LGSC_API_BASE_DEFAULT : 'https://staging.levelupgrowth.io/api' );
		$url  = rtrim( $base, '/' ) . '/chatbot/widget-token';

		$resp = wp_remote_post( $url, array(
			'timeout' => 15,
			'headers' => array(
				'X-API-KEY'    => $api_key,
				'Content-Type' => 'application/json',
				'Accept'       => 'application/json',
			),
			'body'    => wp_json_encode( $body ),
		) );
		if ( is_wp_error( $resp ) ) {
			return new WP_Error( 'http', 'Could not reach LevelUp Growth: ' . $resp->get_error_message() );
		}
		$status = wp_remote_retrieve_response_code( $resp );
		$json   = json_decode( wp_remote_retrieve_body( $resp ), true );
		if ( $status >= 400 || empty( $json['success'] ) ) {
			$err = isset( $json['error'] ) ? $json['error'] : 'http_' . $status;
			$msg = isset( $json['message'] ) ? ' — ' . $json['message'] : '';
			return new WP_Error( 'api', 'LevelUp Growth rejected the request (' . $err . ')' . $msg );
		}
		if ( empty( $json['data']['token'] ) ) {
			return new WP_Error( 'no_token', 'LevelUp Growth did not return a token.' );
		}
		return array(
			'token'  => (string) $json['data']['token'],
			'prefix' => isset( $json['data']['prefix'] ) ? (string) $json['data']['prefix'] : substr( (string) $json['data']['token'], 0, 12 ),
		);
	}
}
