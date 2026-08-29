<?php
/**
 * LGSC Admin Shell.
 *
 * Top-level "LevelUp SEO" admin menu with 11 sub-pages mirroring the Laravel
 * SPA tab structure. The shell calls /api/connector/* via LGSC_API_Client
 * where backend exists; otherwise it shows an honest "Open in LevelUp
 * Growth" deep-link to the SPA equivalent.
 *
 * Subpage map (Laravel SPA tab → WP shell):
 *   Overview     → Dashboard
 *   Audit        → Site Audit
 *   (no SPA tab) → Page Analyzer  (per-post analyze, lives in editor meta box too)
 *   Pages        → Indexed Content
 *   Keywords     → Keywords
 *   Competitors  → Competitors
 *   Links        → Internal Links
 *   Pages/Wins   → Quick Wins (subset)
 *   Reports      → Reports
 *   (WP-only)    → AI Assistant (SEO-focused; needs Laravel /connector/assistant/* endpoint to be functional)
 *   (WP-only)    → Settings
 *
 * @package LevelUp_Growth_SEO_Connector
 * @since   1.0.3
 */

defined( 'ABSPATH' ) || exit;

class LGSC_Admin_Shell {

	const MENU_SLUG = 'lgsc-dashboard';
	const CAP       = 'manage_options';

	/**
	 * Map: page slug → ['title' => string, 'spa_tab' => string|null, 'render' => method, 'has_backend' => bool]
	 * spa_tab is appended to the SPA URL for "Open in LevelUp Growth" deep-link.
	 * has_backend=false → page renders the "needs backend" notice + deep-link only.
	 */
	private $pages;

	public function __construct() {
		$this->pages = array(
			'lgsc-dashboard'         => array( 'title' => 'Dashboard',         'spa_tab' => 'overview',    'render' => 'render_dashboard',         'has_backend' => true ),
			'lgsc-site-audit'        => array( 'title' => 'Site Audit',        'spa_tab' => 'audit',       'render' => 'render_site_audit',        'has_backend' => false ),
			'lgsc-page-analyzer'     => array( 'title' => 'Page Analyzer',     'spa_tab' => 'pages',       'render' => 'render_page_analyzer',     'has_backend' => true ),
			'lgsc-indexed-content'   => array( 'title' => 'Indexed Content',   'spa_tab' => 'pages',       'render' => 'render_indexed_content',   'has_backend' => false ),
			'lgsc-keywords'          => array( 'title' => 'Keywords',          'spa_tab' => 'keywords',    'render' => 'render_keywords',          'has_backend' => false ),
			'lgsc-competitors'       => array( 'title' => 'Competitors',       'spa_tab' => 'competitors', 'render' => 'render_competitors',       'has_backend' => false ),
			'lgsc-internal-links'    => array( 'title' => 'Internal Links',    'spa_tab' => 'links',       'render' => 'render_internal_links',    'has_backend' => true ),
			'lgsc-quick-wins'        => array( 'title' => 'Quick Wins',        'spa_tab' => 'overview',    'render' => 'render_quick_wins',        'has_backend' => true ),
			'lgsc-reports'           => array( 'title' => 'Reports',           'spa_tab' => 'reports',     'render' => 'render_reports',           'has_backend' => false ),
			'lgsc-ai-assistant'      => array( 'title' => 'AI Assistant',      'spa_tab' => null,          'render' => 'render_ai_assistant',      'has_backend' => false ),
			'lgsc-chatbot'           => array( 'title' => 'Chatbot',           'spa_tab' => null,          'render' => 'render_chatbot',           'has_backend' => true ),
			'lgsc-settings-shell'    => array( 'title' => 'Settings',          'spa_tab' => null,          'render' => 'render_settings_proxy',    'has_backend' => true ),
		);
	}

	public function register() {
		add_action( 'admin_menu',            array( $this, 'register_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'wp_ajax_lgsc_shell_dashboard',     array( $this, 'ajax_dashboard' ) );
		add_action( 'wp_ajax_lgsc_shell_quick_wins',    array( $this, 'ajax_quick_wins' ) );
		add_action( 'wp_ajax_lgsc_shell_link_opps',     array( $this, 'ajax_link_opps' ) );
		add_action( 'wp_ajax_lgsc_shell_page_analyze',  array( $this, 'ajax_page_analyze' ) );
		// v1.0.4 — wire the previously-scaffold pages to live connector endpoints.
		add_action( 'wp_ajax_lgsc_shell_audits',        array( $this, 'ajax_audits' ) );
		add_action( 'wp_ajax_lgsc_shell_indexed',       array( $this, 'ajax_indexed' ) );
		add_action( 'wp_ajax_lgsc_shell_keywords',      array( $this, 'ajax_keywords' ) );
		add_action( 'wp_ajax_lgsc_shell_competitors',   array( $this, 'ajax_competitors' ) );
		add_action( 'wp_ajax_lgsc_shell_reports',       array( $this, 'ajax_reports' ) );
		add_action( 'wp_ajax_lgsc_shell_assistant',     array( $this, 'ajax_assistant' ) );
	}

	public function register_menu() {
		add_menu_page(
			__( 'LevelUp SEO', 'lgsc' ),
			__( 'LevelUp SEO', 'lgsc' ),
			self::CAP,
			self::MENU_SLUG,
			array( $this, 'render_dashboard' ),
			'dashicons-chart-line',
			58
		);

		foreach ( $this->pages as $slug => $cfg ) {
			add_submenu_page(
				self::MENU_SLUG,
				$cfg['title'],
				$cfg['title'],
				self::CAP,
				$slug,
				array( $this, $cfg['render'] )
			);
		}
	}

	public function enqueue_assets( $hook ) {
		// Only load on our pages (hook contains the slug for top-level + sub-pages).
		if ( strpos( (string) $hook, 'lgsc-' ) === false && strpos( (string) $hook, 'levelup-seo' ) === false ) {
			return;
		}
		wp_enqueue_style(
			'lgsc-admin-shell',
			LGSC_PLUGIN_URL . 'assets/css/admin-shell.css',
			array(),
			LGSC_VERSION
		);
		wp_enqueue_script(
			'lgsc-admin-shell',
			LGSC_PLUGIN_URL . 'assets/js/admin-shell.js',
			array(),
			LGSC_VERSION,
			true
		);
		wp_localize_script( 'lgsc-admin-shell', 'LGSC_SHELL', array(
			'ajaxUrl' => admin_url( 'admin-ajax.php' ),
			'nonce'   => wp_create_nonce( 'lgsc_shell' ),
			'spaUrl'  => $this->get_spa_url(),
		) );
	}

	// ── Page renderers ──────────────────────────────────────────────────────

	public function render_dashboard() {
		$this->page_header( 'Dashboard', 'overview', 'Live workspace metrics from LevelUp Growth.' );
		?>
		<div class="lgsc-shell-grid">
			<div class="lgsc-card lgsc-card-loading" data-load="dashboard">
				<div class="lgsc-spinner"></div>
				<div class="lgsc-card-loading-text"><?php esc_html_e( 'Loading workspace…', 'lgsc' ); ?></div>
			</div>
		</div>
		<div id="lgsc-shell-result"></div>
		<?php
		$this->page_footer();
	}

	public function render_site_audit() {
		$this->page_header( 'Site Audit', 'audit', 'Audit history + summary aggregates from your workspace.' );
		?>
		<div class="lgsc-card lgsc-card-loading" data-load="audits">
			<div class="lgsc-spinner"></div>
			<div class="lgsc-card-loading-text"><?php esc_html_e( 'Loading audits…', 'lgsc' ); ?></div>
		</div>
		<div id="lgsc-audits-result"></div>
		<?php
		$this->page_footer();
	}

	public function render_page_analyzer() {
		$this->page_header( 'Page Analyzer', 'pages', 'Score any post or page on demand and see the breakdown.' );
		?>
		<div class="lgsc-card">
			<h3><?php esc_html_e( 'Analyze a published post', 'lgsc' ); ?></h3>
			<p class="lgsc-muted"><?php esc_html_e( 'Pick a post to send to LevelUp Growth for scoring. The result is also saved into seo_content_index for the workspace.', 'lgsc' ); ?></p>
			<?php
			$posts = get_posts( array( 'post_type' => array( 'post', 'page' ), 'post_status' => 'publish', 'posts_per_page' => 50, 'orderby' => 'modified', 'order' => 'DESC' ) );
			if ( ! $posts ) {
				echo '<p>' . esc_html__( 'No published posts/pages yet.', 'lgsc' ) . '</p>';
			} else {
				echo '<select id="lgsc-page-analyzer-select" class="regular-text"><option value="">' . esc_html__( '— Select a post —', 'lgsc' ) . '</option>';
				foreach ( $posts as $p ) {
					echo '<option value="' . (int) $p->ID . '">' . esc_html( $p->post_title ?: '(no title)' ) . ' [' . esc_html( $p->post_type ) . ']</option>';
				}
				echo '</select> ';
				echo '<button class="button button-primary" id="lgsc-page-analyzer-run">' . esc_html__( 'Analyze', 'lgsc' ) . '</button>';
			}
			?>
		</div>
		<div id="lgsc-page-analyzer-result"></div>
		<?php
		$this->page_footer();
	}

	public function render_indexed_content() {
		$this->page_header( 'Indexed Content', 'pages', 'Pages indexed in your LevelUp Growth workspace.' );
		?>
		<div class="lgsc-card">
			<div class="lgsc-toolbar">
				<input id="lgsc-indexed-q" class="regular-text" placeholder="<?php esc_attr_e( 'Search title or URL…', 'lgsc' ); ?>" />
				<select id="lgsc-indexed-filter">
					<option value=""><?php esc_html_e( 'All pages', 'lgsc' ); ?></option>
					<option value="low_score"><?php esc_html_e( 'Low score (<50)', 'lgsc' ); ?></option>
					<option value="missing_meta"><?php esc_html_e( 'Missing meta', 'lgsc' ); ?></option>
					<option value="thin_content"><?php esc_html_e( 'Thin content', 'lgsc' ); ?></option>
					<option value="no_h1"><?php esc_html_e( 'No H1', 'lgsc' ); ?></option>
				</select>
				<button class="button button-primary" id="lgsc-indexed-refresh"><?php esc_html_e( 'Reload', 'lgsc' ); ?></button>
			</div>
		</div>
		<div class="lgsc-card lgsc-card-loading" data-load="indexed">
			<div class="lgsc-spinner"></div>
			<div class="lgsc-card-loading-text"><?php esc_html_e( 'Loading indexed pages…', 'lgsc' ); ?></div>
		</div>
		<div id="lgsc-indexed-result"></div>
		<?php
		$this->page_footer();
	}

	public function render_keywords() {
		$this->page_header( 'Keywords', 'keywords', 'Tracked keywords for this workspace.' );
		?>
		<div class="lgsc-card lgsc-card-loading" data-load="keywords">
			<div class="lgsc-spinner"></div>
			<div class="lgsc-card-loading-text"><?php esc_html_e( 'Loading keywords…', 'lgsc' ); ?></div>
		</div>
		<div id="lgsc-keywords-result"></div>
		<div class="lgsc-card lgsc-disabled lgsc-card-soft">
			<h4><?php esc_html_e( 'Add a keyword', 'lgsc' ); ?></h4>
			<input type="text" class="regular-text" placeholder="<?php esc_attr_e( 'Keyword writes are not yet enabled on the WP shell. Add via your LevelUp Growth dashboard for now.', 'lgsc' ); ?>" disabled />
			<button class="button" disabled><?php esc_html_e( 'Add (coming soon)', 'lgsc' ); ?></button>
			<p class="lgsc-muted"><?php esc_html_e( 'Endpoint POST /api/connector/keywords ships in a future runbook. Until then, use the Keywords tab in your LevelUp Growth workspace.', 'lgsc' ); ?></p>
		</div>
		<?php
		$this->page_footer();
	}

	public function render_competitors() {
		$this->page_header( 'Competitors', 'competitors', 'Currently-tracked competitor domains for this workspace.' );
		?>
		<div class="lgsc-card lgsc-card-loading" data-load="competitors">
			<div class="lgsc-spinner"></div>
			<div class="lgsc-card-loading-text"><?php esc_html_e( 'Loading competitors…', 'lgsc' ); ?></div>
		</div>
		<div id="lgsc-competitors-result"></div>
		<?php
		$this->page_footer();
	}

	public function render_internal_links() {
		$this->page_header( 'Internal Links', 'links', 'Suggestions for the post you are editing.' );
		?>
		<div class="lgsc-card">
			<h3><?php esc_html_e( 'Link opportunities for a published post', 'lgsc' ); ?></h3>
			<p class="lgsc-muted"><?php esc_html_e( 'Pick a post; LevelUp Growth returns internal-link opportunities scoped to that source URL.', 'lgsc' ); ?></p>
			<?php
			$posts = get_posts( array( 'post_type' => array( 'post', 'page' ), 'post_status' => 'publish', 'posts_per_page' => 50, 'orderby' => 'modified', 'order' => 'DESC' ) );
			if ( $posts ) {
				echo '<select id="lgsc-links-select" class="regular-text"><option value="">' . esc_html__( '— Select a post —', 'lgsc' ) . '</option>';
				foreach ( $posts as $p ) {
					echo '<option value="' . esc_url( get_permalink( $p ) ) . '">' . esc_html( $p->post_title ?: '(no title)' ) . '</option>';
				}
				echo '</select> ';
				echo '<button class="button button-primary" id="lgsc-links-run">' . esc_html__( 'Find opportunities', 'lgsc' ) . '</button>';
			} else {
				echo '<p>' . esc_html__( 'No published posts.', 'lgsc' ) . '</p>';
			}
			?>
		</div>
		<div id="lgsc-links-result"></div>
		<?php
		$this->page_footer();
	}

	public function render_quick_wins() {
		$this->page_header( 'Quick Wins', 'overview', 'Highest-impact small fixes for any indexed page.' );
		?>
		<div class="lgsc-card">
			<h3><?php esc_html_e( 'Quick wins for a published post', 'lgsc' ); ?></h3>
			<p class="lgsc-muted"><?php esc_html_e( 'Pick a post; LevelUp Growth returns the top quick-wins for that URL.', 'lgsc' ); ?></p>
			<?php
			$posts = get_posts( array( 'post_type' => array( 'post', 'page' ), 'post_status' => 'publish', 'posts_per_page' => 50, 'orderby' => 'modified', 'order' => 'DESC' ) );
			if ( $posts ) {
				echo '<select id="lgsc-wins-select" class="regular-text"><option value="">' . esc_html__( '— Select a post —', 'lgsc' ) . '</option>';
				foreach ( $posts as $p ) {
					echo '<option value="' . esc_url( get_permalink( $p ) ) . '">' . esc_html( $p->post_title ?: '(no title)' ) . '</option>';
				}
				echo '</select> ';
				echo '<button class="button button-primary" id="lgsc-wins-run">' . esc_html__( 'Show wins', 'lgsc' ) . '</button>';
			} else {
				echo '<p>' . esc_html__( 'No published posts.', 'lgsc' ) . '</p>';
			}
			?>
		</div>
		<div id="lgsc-wins-result"></div>
		<?php
		$this->page_footer();
	}

	public function render_reports() {
		$this->page_header( 'Reports', 'reports', 'Aggregated SEO health report for your workspace.' );
		?>
		<div class="lgsc-card lgsc-card-loading" data-load="reports">
			<div class="lgsc-spinner"></div>
			<div class="lgsc-card-loading-text"><?php esc_html_e( 'Generating report…', 'lgsc' ); ?></div>
		</div>
		<div id="lgsc-reports-result"></div>
		<?php
		$this->page_footer();
	}

	public function render_ai_assistant() {
		$this->page_header( 'AI Assistant', null, 'SEO-focused assistant. Routes through LevelUp Growth — never calls AI directly from WordPress.' );
		?>
		<div class="lgsc-card lgsc-chat-card">
			<div class="lgsc-chat-thread" id="lgsc-chat-thread">
				<div class="lgsc-chat-msg lgsc-chat-msg-bot">
					<div class="lgsc-chat-bubble">
						<?php esc_html_e( 'Hi — I can answer SEO questions about your workspace using your real audit data, indexed pages, and tracked keywords. Ask me something.', 'lgsc' ); ?>
					</div>
				</div>
			</div>
			<div class="lgsc-chat-suggestions" id="lgsc-chat-suggestions">
				<button class="button lgsc-chat-suggest" type="button"><?php esc_html_e( 'What are my biggest SEO issues right now?', 'lgsc' ); ?></button>
				<button class="button lgsc-chat-suggest" type="button"><?php esc_html_e( 'Which pages should I optimize first?', 'lgsc' ); ?></button>
				<button class="button lgsc-chat-suggest" type="button"><?php esc_html_e( 'Summarize my latest audit.', 'lgsc' ); ?></button>
			</div>
			<form id="lgsc-chat-form" class="lgsc-chat-form">
				<textarea id="lgsc-chat-input" rows="2" class="large-text" placeholder="<?php esc_attr_e( 'Ask anything about your SEO…', 'lgsc' ); ?>" maxlength="2000"></textarea>
				<button type="submit" class="button button-primary" id="lgsc-chat-send"><?php esc_html_e( 'Send', 'lgsc' ); ?></button>
			</form>
			<p class="lgsc-chat-foot lgsc-muted"><?php esc_html_e( 'Powered by LevelUp Growth. Responses are based on your workspace data; if the assistant doesn\'t know, it will say so.', 'lgsc' ); ?></p>
		</div>
		<?php
		$this->page_footer();
	}

	/**
	 * v1.0.5: Chatbot888 sub-page. Delegates rendering to LGSC_Chatbot
	 * which handles the form + token mint via Laravel API.
	 */
	public function render_chatbot() {
		if ( class_exists( 'LGSC_Chatbot' ) ) {
			$cb = new LGSC_Chatbot();
			$cb->render_admin();
		} else {
			echo '<div class="wrap"><h1>Chatbot</h1><p>Chatbot module not loaded.</p></div>';
		}
	}

	public function render_settings_proxy() {
		// The existing Settings_Page is registered under wp options-general.
		// Embed a redirect to that existing page rather than duplicating.
		$url = admin_url( 'options-general.php?page=lgsc-settings' );
		?>
		<div class="wrap lgsc-shell">
			<?php $this->page_header( 'Settings', null, 'Connect this site to LevelUp Growth.' ); ?>
			<div class="lgsc-card">
				<p><?php esc_html_e( 'Settings live under the standard WordPress Settings menu (single source of truth).', 'lgsc' ); ?></p>
				<p>
					<a class="button button-primary" href="<?php echo esc_url( $url ); ?>">
						<?php esc_html_e( 'Open Settings', 'lgsc' ); ?> →
					</a>
				</p>
			</div>
			<?php $this->page_footer( false ); ?>
		</div>
		<?php
	}

	// ── Helpers ─────────────────────────────────────────────────────────────

	/**
	 * Wrap the page in our shell + show header with Open-in-Laravel button.
	 *
	 * @param string      $title    Visible page title.
	 * @param string|null $spa_tab  Laravel SPA tab id; null = no deep link button.
	 * @param string      $sub      One-line subhead.
	 */
	private function page_header( $title, $spa_tab, $sub = '' ) {
		$client = new LGSC_API_Client();
		$config = $client->is_configured();
		echo '<div class="wrap lgsc-shell">';
		echo '<h1>' . esc_html( 'LevelUp SEO · ' . $title ) . '</h1>';
		if ( $sub ) {
			echo '<p class="lgsc-shell-sub">' . esc_html( $sub ) . '</p>';
		}
		if ( ! $config ) {
			echo '<div class="notice notice-warning inline"><p><strong>' . esc_html__( 'Not connected.', 'lgsc' ) . '</strong> ';
			echo '<a href="' . esc_url( admin_url( 'options-general.php?page=lgsc-settings' ) ) . '">' . esc_html__( 'Open Settings', 'lgsc' ) . '</a> ';
			echo esc_html__( 'and paste your API Key + Workspace ID before using this page.', 'lgsc' ) . '</p></div>';
		}
		if ( $spa_tab ) {
			$spa_url = $this->get_spa_url() . '#seo/' . $spa_tab;
			echo '<p class="lgsc-shell-cta"><a class="button" href="' . esc_url( $spa_url ) . '" target="_blank" rel="noopener">';
			echo esc_html__( 'Open in LevelUp Growth dashboard', 'lgsc' ) . ' →</a></p>';
		}
	}

	private function page_footer( $close_wrap = true ) {
		if ( $close_wrap ) {
			echo '</div>';
		}
	}

	/**
	 * Friendly "this lives in the SPA" notice for pages without a WP-side
	 * implementation yet. Always honest — no fake metrics, no fake buttons.
	 */
	private function coming_soon_notice( $spa_tab, $feature_name, $description ) {
		$spa_url = $this->get_spa_url() . '#seo/' . $spa_tab;
		?>
		<div class="lgsc-notice lgsc-notice-info">
			<strong><?php echo esc_html( $feature_name ) . ' '; ?></strong>
			<?php esc_html_e( 'is fully built and live in your LevelUp Growth dashboard. A WordPress-side panel for it is on the roadmap.', 'lgsc' ); ?>
			<p><?php echo esc_html( $description ); ?></p>
			<p>
				<a class="button button-primary" href="<?php echo esc_url( $spa_url ); ?>" target="_blank" rel="noopener">
					<?php esc_html_e( 'Open', 'lgsc' ); ?> <?php echo esc_html( $feature_name ); ?>
					<?php esc_html_e( 'in LevelUp Growth', 'lgsc' ); ?> →
				</a>
			</p>
		</div>
		<?php
	}

	/**
	 * Resolve the SPA URL from the configured API URL (lgsc_api_url).
	 * The SPA is typically at https://{host}/app/ for the LevelUp domain.
	 */
	private function get_spa_url() {
		$api = (string) get_option( 'lgsc_api_url', LGSC_API_BASE_DEFAULT );
		// Strip /api[/] suffix if present, then append /app/.
		$base = preg_replace( '#/api/?$#', '', rtrim( $api, '/' ) );
		return $base . '/app/';
	}

	// ── AJAX endpoints ──────────────────────────────────────────────────────

	private function check_nonce_or_die() {
		check_ajax_referer( 'lgsc_shell', 'nonce' );
		if ( ! current_user_can( self::CAP ) ) {
			wp_send_json_error( array( 'message' => 'forbidden' ), 403 );
		}
	}

	public function ajax_dashboard() {
		$this->check_nonce_or_die();
		$client = new LGSC_API_Client();
		if ( ! $client->is_configured() ) {
			wp_send_json_error( array( 'message' => 'not_configured' ), 400 );
		}
		$result = $client->verify_connection();
		if ( ! is_array( $result ) || ! empty( $result['error'] ) ) {
			wp_send_json_error( array( 'message' => isset( $result['error'] ) ? (string) $result['error'] : 'unknown' ), 502 );
		}
		wp_send_json_success( $result );
	}

	public function ajax_quick_wins() {
		$this->check_nonce_or_die();
		$url = isset( $_POST['url'] ) ? esc_url_raw( wp_unslash( $_POST['url'] ) ) : '';
		if ( ! $url ) {
			wp_send_json_error( array( 'message' => 'url_required' ), 400 );
		}
		$client = new LGSC_API_Client();
		$result = $client->get_quick_wins( $url );
		if ( ! is_array( $result ) || ! empty( $result['error'] ) ) {
			wp_send_json_error( array( 'message' => isset( $result['error'] ) ? (string) $result['error'] : 'unknown' ), 502 );
		}
		wp_send_json_success( $result );
	}

	public function ajax_link_opps() {
		$this->check_nonce_or_die();
		$url = isset( $_POST['url'] ) ? esc_url_raw( wp_unslash( $_POST['url'] ) ) : '';
		if ( ! $url ) {
			wp_send_json_error( array( 'message' => 'url_required' ), 400 );
		}
		$client = new LGSC_API_Client();
		$result = $client->get_link_suggestions( $url );
		if ( ! is_array( $result ) || ! empty( $result['error'] ) ) {
			wp_send_json_error( array( 'message' => isset( $result['error'] ) ? (string) $result['error'] : 'unknown' ), 502 );
		}
		wp_send_json_success( $result );
	}

	public function ajax_page_analyze() {
		$this->check_nonce_or_die();
		$post_id = isset( $_POST['post_id'] ) ? (int) $_POST['post_id'] : 0;
		if ( ! $post_id ) {
			wp_send_json_error( array( 'message' => 'post_id_required' ), 400 );
		}
		$post = get_post( $post_id );
		if ( ! $post ) {
			wp_send_json_error( array( 'message' => 'post_not_found' ), 404 );
		}
		// Reuse the meta-box helper to build the analyze payload exactly the same way.
		$mb = new LGSC_Meta_Box();
		// Use reflection to call the private build_page_payload — keeps a single source of truth.
		$ref = new ReflectionMethod( $mb, 'build_page_payload' );
		$ref->setAccessible( true );
		$payload = $ref->invoke( $mb, $post );
		$client = new LGSC_API_Client();
		$result = $client->analyze_page( $payload );
		if ( ! is_array( $result ) || ! empty( $result['error'] ) ) {
			wp_send_json_error( array( 'message' => isset( $result['error'] ) ? (string) $result['error'] : 'unknown' ), 502 );
		}
		wp_send_json_success( $result );
	}

	// ── v1.0.4 AJAX handlers — read-only wrappers around connector endpoints. ─

	public function ajax_audits() {
		$this->check_nonce_or_die();
		$page    = isset( $_POST['page'] ) ? (int) $_POST['page'] : 1;
		$perPage = isset( $_POST['per_page'] ) ? (int) $_POST['per_page'] : 10;
		$client  = new LGSC_API_Client();
		if ( ! $client->is_configured() ) {
			wp_send_json_error( array( 'message' => 'not_configured' ), 400 );
		}
		$result = $client->get_audits( $page, $perPage );
		if ( ! is_array( $result ) || ! empty( $result['error'] ) ) {
			wp_send_json_error( array( 'message' => isset( $result['error'] ) ? (string) $result['error'] : 'unknown' ), 502 );
		}
		wp_send_json_success( $result );
	}

	public function ajax_indexed() {
		$this->check_nonce_or_die();
		$page    = isset( $_POST['page'] ) ? (int) $_POST['page'] : 1;
		$perPage = isset( $_POST['per_page'] ) ? (int) $_POST['per_page'] : 25;
		$filter  = isset( $_POST['filter'] ) ? sanitize_text_field( wp_unslash( $_POST['filter'] ) ) : '';
		$q       = isset( $_POST['q'] ) ? sanitize_text_field( wp_unslash( $_POST['q'] ) ) : '';
		$client  = new LGSC_API_Client();
		if ( ! $client->is_configured() ) {
			wp_send_json_error( array( 'message' => 'not_configured' ), 400 );
		}
		$result = $client->get_indexed_content( $page, $perPage, $filter, $q );
		if ( ! is_array( $result ) || ! empty( $result['error'] ) ) {
			wp_send_json_error( array( 'message' => isset( $result['error'] ) ? (string) $result['error'] : 'unknown' ), 502 );
		}
		wp_send_json_success( $result );
	}

	public function ajax_keywords() {
		$this->check_nonce_or_die();
		$page    = isset( $_POST['page'] ) ? (int) $_POST['page'] : 1;
		$perPage = isset( $_POST['per_page'] ) ? (int) $_POST['per_page'] : 25;
		$client  = new LGSC_API_Client();
		if ( ! $client->is_configured() ) {
			wp_send_json_error( array( 'message' => 'not_configured' ), 400 );
		}
		$result = $client->get_keywords( $page, $perPage );
		if ( ! is_array( $result ) || ! empty( $result['error'] ) ) {
			wp_send_json_error( array( 'message' => isset( $result['error'] ) ? (string) $result['error'] : 'unknown' ), 502 );
		}
		wp_send_json_success( $result );
	}

	public function ajax_competitors() {
		$this->check_nonce_or_die();
		$client = new LGSC_API_Client();
		if ( ! $client->is_configured() ) {
			wp_send_json_error( array( 'message' => 'not_configured' ), 400 );
		}
		$result = $client->get_competitors();
		if ( ! is_array( $result ) || ! empty( $result['error'] ) ) {
			wp_send_json_error( array( 'message' => isset( $result['error'] ) ? (string) $result['error'] : 'unknown' ), 502 );
		}
		wp_send_json_success( $result );
	}

	public function ajax_reports() {
		$this->check_nonce_or_die();
		$client = new LGSC_API_Client();
		if ( ! $client->is_configured() ) {
			wp_send_json_error( array( 'message' => 'not_configured' ), 400 );
		}
		$result = $client->get_reports();
		if ( ! is_array( $result ) || ! empty( $result['error'] ) ) {
			wp_send_json_error( array( 'message' => isset( $result['error'] ) ? (string) $result['error'] : 'unknown' ), 502 );
		}
		wp_send_json_success( $result );
	}

	public function ajax_assistant() {
		$this->check_nonce_or_die();
		$message = isset( $_POST['message'] ) ? trim( (string) wp_unslash( $_POST['message'] ) ) : '';
		if ( $message === '' ) {
			wp_send_json_error( array( 'message' => 'message_required' ), 400 );
		}
		if ( strlen( $message ) > 2000 ) {
			wp_send_json_error( array( 'message' => 'message_too_long' ), 400 );
		}
		$context = array(
			'source'      => 'wordpress_connector',
			'site_url'    => get_site_url(),
		);
		if ( isset( $_POST['post_id'] ) ) {
			$context['post_id']     = (int) $_POST['post_id'];
			$context['current_url'] = (string) get_permalink( (int) $_POST['post_id'] );
		}
		$client = new LGSC_API_Client();
		if ( ! $client->is_configured() ) {
			wp_send_json_error( array( 'message' => 'not_configured' ), 400 );
		}
		$result = $client->assistant_message( $message, $context );
		if ( ! is_array( $result ) || ! empty( $result['error'] ) ) {
			wp_send_json_error( array( 'message' => isset( $result['error'] ) ? (string) $result['error'] : 'unknown' ), 502 );
		}
		wp_send_json_success( $result );
	}
}
