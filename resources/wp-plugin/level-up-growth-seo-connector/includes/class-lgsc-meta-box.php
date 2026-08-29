<?php
/**
 * LGSC Meta Box.
 *
 * Adds a "LevelUp SEO" panel to post/page editor.
 * Renders shell HTML; meta-box.js fetches live data via admin-ajax → API client → Laravel.
 *
 * @package LevelUp_Growth_SEO_Connector
 */

defined( 'ABSPATH' ) || exit;

class LGSC_Meta_Box {

	const META_SCORE   = '_lgsc_score';
	const META_TITLE   = '_lgsc_meta_title';
	const META_DESC    = '_lgsc_meta_description';
	const META_UPDATED = '_lgsc_last_updated';

	public function register() {
		add_action( 'add_meta_boxes', array( $this, 'add_box' ) );
		add_action( 'save_post', array( $this, 'on_save_post' ), 20, 2 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_scripts' ) );

		// AJAX endpoints — both authenticated only.
		add_action( 'wp_ajax_lgsc_analyze_page', array( $this, 'ajax_analyze_page' ) );
		add_action( 'wp_ajax_lgsc_save_meta', array( $this, 'ajax_save_meta' ) );
	}

	public function add_box() {
		$post_types = array( 'post', 'page' );
		foreach ( $post_types as $type ) {
			add_meta_box(
				'lgsc_seo_panel',
				__( 'LevelUp SEO', 'lgsc' ),
				array( $this, 'render' ),
				$type,
				'normal',
				'high'
			);
		}
	}

	/**
	 * Render the meta box. The actual data is loaded by JS on the client.
	 *
	 * @param WP_Post $post
	 */
	public function render( $post ) {
		$client    = new LGSC_API_Client();
		$score     = (int) get_post_meta( $post->ID, self::META_SCORE, true );
		$meta_t    = (string) get_post_meta( $post->ID, self::META_TITLE, true );
		$meta_d    = (string) get_post_meta( $post->ID, self::META_DESC, true );
		$updated   = (string) get_post_meta( $post->ID, self::META_UPDATED, true );
		$is_config = $client->is_configured();
		?>
		<div class="lgsc-panel" data-post-id="<?php echo esc_attr( $post->ID ); ?>">

			<?php if ( ! $is_config ) : ?>
				<div class="lgsc-setup-prompt">
					<p><strong><?php esc_html_e( 'LevelUp SEO is not configured yet.', 'lgsc' ); ?></strong></p>
					<p><?php esc_html_e( 'Connect this site to LevelUp Growth to start analyzing your content.', 'lgsc' ); ?></p>
					<a href="<?php echo esc_url( admin_url( 'options-general.php?page=lgsc-settings' ) ); ?>" class="button button-primary">
						<?php esc_html_e( 'Open Settings', 'lgsc' ); ?>
					</a>
				</div>
			<?php else : ?>

				<div class="lgsc-row lgsc-row-score">
					<div class="lgsc-gauge" data-score="<?php echo esc_attr( $score ); ?>">
						<div class="lgsc-gauge-inner">
							<div class="lgsc-gauge-value"><?php echo esc_html( $score > 0 ? $score : '—' ); ?></div>
							<div class="lgsc-gauge-label"><?php esc_html_e( 'SEO Score', 'lgsc' ); ?></div>
						</div>
					</div>
					<div class="lgsc-gauge-meta">
						<button type="button" class="button button-primary lgsc-btn-analyze">
							<?php esc_html_e( 'Analyze', 'lgsc' ); ?>
						</button>
						<div class="lgsc-last-analyzed">
							<?php
							if ( '' !== $updated ) {
								/* translators: %s: human-readable date. */
								echo esc_html( sprintf( __( 'Last analyzed: %s', 'lgsc' ), $updated ) );
							} else {
								esc_html_e( 'Not analyzed yet', 'lgsc' );
							}
							?>
						</div>
					</div>
				</div>

				<div class="lgsc-row">
					<label for="lgsc-meta-title"><?php esc_html_e( 'Meta Title', 'lgsc' ); ?></label>
					<input type="text" id="lgsc-meta-title" class="lgsc-input lgsc-meta-title" value="<?php echo esc_attr( $meta_t ); ?>" maxlength="200" />
					<div class="lgsc-counter" data-target="title" data-soft="60" data-hard="70"><span class="lgsc-counter-value">0</span> / 60</div>
				</div>

				<div class="lgsc-row">
					<label for="lgsc-meta-desc"><?php esc_html_e( 'Meta Description', 'lgsc' ); ?></label>
					<textarea id="lgsc-meta-desc" class="lgsc-input lgsc-meta-desc" rows="3" maxlength="320"><?php echo esc_textarea( $meta_d ); ?></textarea>
					<div class="lgsc-counter" data-target="desc" data-soft="155" data-hard="165"><span class="lgsc-counter-value">0</span> / 155</div>
				</div>

				<div class="lgsc-row lgsc-actions">
					<button type="button" class="button button-secondary lgsc-btn-save">
						<?php esc_html_e( 'Save to LevelUp', 'lgsc' ); ?>
					</button>
					<span class="lgsc-status" aria-live="polite"></span>
				</div>

				<div class="lgsc-section lgsc-quickwins">
					<h4><?php esc_html_e( 'Quick Wins', 'lgsc' ); ?></h4>
					<div class="lgsc-quickwins-list">
						<div class="lgsc-empty"><?php esc_html_e( 'Click Analyze to check this page', 'lgsc' ); ?></div>
					</div>
				</div>

				<div class="lgsc-section lgsc-linksuggestions">
					<h4><?php esc_html_e( 'Internal Link Opportunities', 'lgsc' ); ?></h4>
					<div class="lgsc-linksuggestions-list">
						<div class="lgsc-empty"><?php esc_html_e( 'No suggestions yet', 'lgsc' ); ?></div>
					</div>
				</div>

			<?php endif; ?>
		</div>
		<?php
		wp_nonce_field( 'lgsc_meta_box', 'lgsc_meta_nonce' );
	}

	/**
	 * Auto-analyze on save when the post is published.
	 *
	 * @param int     $post_id
	 * @param WP_Post $post
	 */
	public function on_save_post( $post_id, $post ) {
		// Skip autosaves, revisions, and unpublished posts.
		if ( wp_is_post_autosave( $post_id ) || wp_is_post_revision( $post_id ) ) {
			return;
		}
		if ( ! $post || 'publish' !== $post->post_status ) {
			return;
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		$auto = (string) get_option( 'lgsc_auto_analyze', '1' );
		if ( '1' !== $auto ) {
			return;
		}

		$client = new LGSC_API_Client();
		if ( ! $client->is_configured() ) {
			return;
		}

		$payload = $this->build_page_payload( $post );
		$result  = $client->analyze_page( $payload );

		// Persist a thin local mirror of score + meta + timestamp so the meta box
		// shows something even before JS hydrates.
		if ( is_array( $result ) && empty( $result['error'] ) ) {
			$score = isset( $result['score']['total'] )
				? (int) $result['score']['total']
				: ( isset( $result['score'] ) && is_numeric( $result['score'] ) ? (int) $result['score'] : 0 );
			update_post_meta( $post_id, self::META_SCORE, $score );
			update_post_meta( $post_id, self::META_UPDATED, current_time( 'mysql' ) );
		}
	}

	/**
	 * Enqueue assets only on post-edit screens.
	 *
	 * @param string $hook
	 */
	public function enqueue_scripts( $hook ) {
		if ( 'post.php' !== $hook && 'post-new.php' !== $hook ) {
			return;
		}

		wp_enqueue_style(
			'lgsc-meta-box',
			LGSC_PLUGIN_URL . 'assets/css/meta-box.css',
			array(),
			LGSC_VERSION
		);

		wp_enqueue_script(
			'lgsc-meta-box',
			LGSC_PLUGIN_URL . 'assets/js/meta-box.js',
			array(),
			LGSC_VERSION,
			true
		);

		$client = new LGSC_API_Client();
		$post   = get_post();
		$post_id = $post ? (int) $post->ID : 0;

		wp_localize_script( 'lgsc-meta-box', 'LGSC_DATA', array(
			'ajaxUrl'      => admin_url( 'admin-ajax.php' ),
			'nonce'        => wp_create_nonce( 'lgsc_meta_box' ),
			'postId'       => $post_id,
			'postUrl'      => $post_id ? get_permalink( $post_id ) : '',
			'isConfigured' => $client->is_configured(),
			'autoOnLoad'   => $post_id && $post && 'publish' === $post->post_status,
			'strings'      => array(
				'analyzing' => __( 'Analyzing...', 'lgsc' ),
				'saving'    => __( 'Saving...', 'lgsc' ),
				'saved'     => __( 'Saved.', 'lgsc' ),
				'failed'    => __( 'Failed: ', 'lgsc' ),
				'noData'    => __( 'Click Analyze to check this page', 'lgsc' ),
				'noWins'    => __( 'No quick wins for this page.', 'lgsc' ),
				'noLinks'   => __( 'No link opportunities yet.', 'lgsc' ),
				'high'      => __( 'HIGH', 'lgsc' ),
				'medium'    => __( 'MEDIUM', 'lgsc' ),
				'low'       => __( 'LOW', 'lgsc' ),
			),
		) );
	}

	/**
	 * AJAX: analyze the current post via the LevelUp Growth API.
	 */
	public function ajax_analyze_page() {
		check_ajax_referer( 'lgsc_meta_box', 'nonce' );

		$post_id = isset( $_POST['post_id'] ) ? (int) $_POST['post_id'] : 0;
		if ( ! $post_id || ! current_user_can( 'edit_post', $post_id ) ) {
			wp_send_json_error( array( 'message' => 'forbidden' ), 403 );
		}

		$post = get_post( $post_id );
		if ( ! $post ) {
			wp_send_json_error( array( 'message' => 'post_not_found' ), 404 );
		}

		$client = new LGSC_API_Client();
		if ( ! $client->is_configured() ) {
			wp_send_json_error( array( 'message' => 'not_configured' ), 400 );
		}

		$payload = $this->build_page_payload( $post );
		$result  = $client->analyze_page( $payload );

		if ( ! is_array( $result ) || ! empty( $result['error'] ) ) {
			$msg = isset( $result['error'] ) ? (string) $result['error'] : 'unknown_error';
			wp_send_json_error( array( 'message' => $msg ), 502 );
		}

		// Mirror score + analyzed-at into post meta.
		$score = isset( $result['score']['total'] )
			? (int) $result['score']['total']
			: ( isset( $result['score'] ) && is_numeric( $result['score'] ) ? (int) $result['score'] : 0 );
		update_post_meta( $post_id, self::META_SCORE, $score );
		update_post_meta( $post_id, self::META_UPDATED, current_time( 'mysql' ) );

		// Also fetch link suggestions for this page.
		$links_resp = $client->get_link_suggestions( $payload['url'] );
		$link_sugs  = array();
		if ( is_array( $links_resp ) && empty( $links_resp['error'] ) ) {
			$opps = isset( $links_resp['opportunities'] ) ? $links_resp['opportunities'] : ( isset( $links_resp['data'] ) ? $links_resp['data'] : array() );
			if ( is_array( $opps ) ) {
				$link_sugs = array_slice( $opps, 0, 3 );
			}
		}

		wp_send_json_success( array(
			'score'              => $score,
			'analysis'           => $result,
			'quick_wins'         => isset( $result['quick_wins'] ) ? $result['quick_wins'] : array(),
			'link_suggestions'   => $link_sugs,
			'analyzed_at'        => current_time( 'mysql' ),
		) );
	}

	/**
	 * AJAX: save the user-edited meta title + description back to LevelUp Growth.
	 */
	public function ajax_save_meta() {
		check_ajax_referer( 'lgsc_meta_box', 'nonce' );

		$post_id = isset( $_POST['post_id'] ) ? (int) $_POST['post_id'] : 0;
		if ( ! $post_id || ! current_user_can( 'edit_post', $post_id ) ) {
			wp_send_json_error( array( 'message' => 'forbidden' ), 403 );
		}

		$meta_title = isset( $_POST['meta_title'] ) ? sanitize_text_field( wp_unslash( $_POST['meta_title'] ) ) : '';
		$meta_desc  = isset( $_POST['meta_description'] ) ? sanitize_textarea_field( wp_unslash( $_POST['meta_description'] ) ) : '';

		$url = get_permalink( $post_id );
		if ( ! $url ) {
			wp_send_json_error( array( 'message' => 'no_permalink' ), 400 );
		}

		$client = new LGSC_API_Client();
		$result = $client->save_meta( $url, $meta_title, $meta_desc );

		if ( ! is_array( $result ) || ! empty( $result['error'] ) ) {
			$msg = isset( $result['error'] ) ? (string) $result['error'] : 'unknown_error';
			wp_send_json_error( array( 'message' => $msg ), 502 );
		}

		update_post_meta( $post_id, self::META_TITLE, $meta_title );
		update_post_meta( $post_id, self::META_DESC, $meta_desc );

		wp_send_json_success( array(
			'message' => 'saved',
			'url'     => $url,
		) );
	}

	/**
	 * Build the analyze-page payload from a WP_Post.
	 *
	 * @param WP_Post $post
	 * @return array
	 */
	private function build_page_payload( $post ) {
		$url     = get_permalink( $post->ID );
		$content = wp_strip_all_tags( $post->post_content );
		$wc      = str_word_count( $content );

		$h1   = $post->post_title;
		$h2s  = array();
		$imgs = array();
		$inl  = array();

		// Extract H2s.
		if ( preg_match_all( '/<h2[^>]*>(.*?)<\/h2>/is', (string) $post->post_content, $m_h2 ) ) {
			foreach ( $m_h2[1] as $h2 ) {
				$txt = trim( wp_strip_all_tags( $h2 ) );
				if ( '' !== $txt ) {
					$h2s[] = $txt;
				}
			}
		}

		// Extract images.
		if ( preg_match_all( '/<img[^>]+src=["\']([^"\']+)["\'][^>]*>/i', (string) $post->post_content, $m_img, PREG_SET_ORDER ) ) {
			foreach ( $m_img as $tag ) {
				$alt = '';
				if ( preg_match( '/alt=["\']([^"\']*)["\']/i', $tag[0], $am ) ) {
					$alt = $am[1];
				}
				$imgs[] = array(
					'src' => $tag[1],
					'alt' => $alt,
				);
			}
		}

		// Extract internal links (same host).
		$site_host = wp_parse_url( home_url(), PHP_URL_HOST );
		if ( preg_match_all( '/<a[^>]+href=["\']([^"\']+)["\'][^>]*>(.*?)<\/a>/is', (string) $post->post_content, $m_a, PREG_SET_ORDER ) ) {
			foreach ( $m_a as $a ) {
				$href = $a[1];
				$host = wp_parse_url( $href, PHP_URL_HOST );
				if ( $host && $site_host && $host === $site_host ) {
					$inl[] = array(
						'href'   => $href,
						'anchor' => trim( wp_strip_all_tags( $a[2] ) ),
					);
				}
			}
		}

		return array(
			'url'              => $url ? $url : '',
			'title'            => $post->post_title,
			'meta_description' => (string) get_post_meta( $post->ID, self::META_DESC, true ),
			'content'          => $content,
			'h1'               => $h1,
			'h2s'              => $h2s,
			'word_count'       => $wc,
			'images'           => $imgs,
			'internal_links'   => $inl,
		);
	}
}
