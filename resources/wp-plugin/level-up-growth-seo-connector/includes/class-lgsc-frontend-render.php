<?php
/**
 * LGSC Frontend Render.
 *
 * Renders LevelUp-controlled meta to the WordPress frontend so the
 * customer's site actually shows what was edited in the LevelUp panel.
 *
 * Detects active SEO plugin (Yoast, RankMath, AIOSEO) and routes through
 * its filter chain to avoid double output. Falls back to native rendering
 * via `pre_get_document_title` + `wp_head` when no third-party SEO plugin
 * is active.
 *
 * Reads from these post_meta keys (written by LGSC_Meta_Box::ajax_save_meta
 * and LGSC_Rest_Receiver::handle_update_meta):
 *   - _lgsc_meta_title
 *   - _lgsc_meta_description
 *
 * @package LevelUp_Growth_SEO_Connector
 * @since   1.0.1
 */

defined( 'ABSPATH' ) || exit;

class LGSC_Frontend_Render {

	/** @var string Detected SEO plugin: 'yoast' | 'rankmath' | 'aioseo' | 'native' */
	private $mode = 'native';

	public function register() {
		$this->mode = $this->detect_seo_plugin();

		if ( 'native' === $this->mode ) {
			add_filter( 'pre_get_document_title', array( $this, 'filter_document_title' ), 100 );
			add_filter( 'document_title_parts',   array( $this, 'filter_document_title_parts' ), 100 );
			add_action( 'wp_head',                array( $this, 'output_head_meta' ), 1 );
		}

		if ( 'yoast' === $this->mode ) {
			add_filter( 'wpseo_title',          array( $this, 'filter_title' ), 100 );
			add_filter( 'wpseo_metadesc',       array( $this, 'filter_description' ), 100 );
			add_filter( 'wpseo_opengraph_title', array( $this, 'filter_title' ), 100 );
			add_filter( 'wpseo_opengraph_desc',  array( $this, 'filter_description' ), 100 );
			add_filter( 'wpseo_twitter_title',  array( $this, 'filter_title' ), 100 );
			add_filter( 'wpseo_twitter_description', array( $this, 'filter_description' ), 100 );
		}

		if ( 'rankmath' === $this->mode ) {
			add_filter( 'rank_math/frontend/title',       array( $this, 'filter_title' ), 100 );
			add_filter( 'rank_math/frontend/description', array( $this, 'filter_description' ), 100 );
		}

		if ( 'aioseo' === $this->mode ) {
			add_filter( 'aioseo_title',       array( $this, 'filter_title' ), 100 );
			add_filter( 'aioseo_description', array( $this, 'filter_description' ), 100 );
		}
	}

	/**
	 * Detect active SEO plugin. Returns 'yoast' | 'rankmath' | 'aioseo' | 'native'.
	 */
	private function detect_seo_plugin() {
		if ( defined( 'WPSEO_VERSION' ) || class_exists( 'WPSEO_Options' ) ) {
			return 'yoast';
		}
		if ( defined( 'RANK_MATH_VERSION' ) || class_exists( 'RankMath' ) ) {
			return 'rankmath';
		}
		if ( defined( 'AIOSEO_VERSION' ) || class_exists( '\\AIOSEO\\Plugin\\AIOSEO' ) ) {
			return 'aioseo';
		}
		return 'native';
	}

	/**
	 * Read LGSC meta title for the current queried post (singular only).
	 */
	private function get_lgsc_title() {
		if ( ! is_singular() ) {
			return '';
		}
		$post_id = get_queried_object_id();
		if ( ! $post_id ) {
			return '';
		}
		$value = get_post_meta( $post_id, '_lgsc_meta_title', true );
		return is_string( $value ) ? trim( $value ) : '';
	}

	/**
	 * Read LGSC meta description for the current queried post (singular only).
	 */
	private function get_lgsc_description() {
		if ( ! is_singular() ) {
			return '';
		}
		$post_id = get_queried_object_id();
		if ( ! $post_id ) {
			return '';
		}
		$value = get_post_meta( $post_id, '_lgsc_meta_description', true );
		return is_string( $value ) ? trim( $value ) : '';
	}

	// ── Native mode ────────────────────────────────────────────────────────

	public function filter_document_title( $title ) {
		$lgsc = $this->get_lgsc_title();
		return $lgsc !== '' ? $lgsc : $title;
	}

	public function filter_document_title_parts( $parts ) {
		$lgsc = $this->get_lgsc_title();
		if ( $lgsc !== '' && is_array( $parts ) ) {
			$parts['title'] = $lgsc;
		}
		return $parts;
	}

	public function output_head_meta() {
		$title = $this->get_lgsc_title();
		$desc  = $this->get_lgsc_description();

		if ( $desc !== '' ) {
			echo '<meta name="description" content="' . esc_attr( $desc ) . '" />' . "\n";
			echo '<meta property="og:description" content="' . esc_attr( $desc ) . '" />' . "\n";
			echo '<meta name="twitter:description" content="' . esc_attr( $desc ) . '" />' . "\n";
		}
		if ( $title !== '' ) {
			echo '<meta property="og:title" content="' . esc_attr( $title ) . '" />' . "\n";
			echo '<meta name="twitter:title" content="' . esc_attr( $title ) . '" />' . "\n";
		}
		if ( is_singular() && ( $url = get_permalink( get_queried_object_id() ) ) ) {
			echo '<link rel="canonical" href="' . esc_url( $url ) . '" />' . "\n";
		}
	}

	// ── Third-party SEO plugin filters (shared across Yoast / RankMath / AIOSEO) ─

	public function filter_title( $title ) {
		$lgsc = $this->get_lgsc_title();
		return $lgsc !== '' ? $lgsc : $title;
	}

	public function filter_description( $desc ) {
		$lgsc = $this->get_lgsc_description();
		return $lgsc !== '' ? $lgsc : $desc;
	}

	/**
	 * Returns the detected mode — useful for Settings page badge.
	 *
	 * @return string
	 */
	public function get_mode() {
		return $this->mode;
	}
}
