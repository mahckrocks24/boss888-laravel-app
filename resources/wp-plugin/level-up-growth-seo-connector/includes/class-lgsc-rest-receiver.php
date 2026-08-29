<?php
/**
 * LGSC REST Receiver.
 *
 * Receives push updates from LevelUp Growth so scores can refresh
 * even when the editor isn't open.
 *
 * Endpoint: POST /wp-json/lgsc/v1/update-meta
 *
 * @package LevelUp_Growth_SEO_Connector
 */

defined( 'ABSPATH' ) || exit;

class LGSC_Rest_Receiver {

	public function register() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	public function register_routes() {
		register_rest_route( 'lgsc/v1', '/update-meta', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'handle_update_meta' ),
			'permission_callback' => array( $this, 'check_secret' ),
			'args'                => array(
				'url'              => array( 'type' => 'string', 'required' => true ),
				'meta_title'       => array( 'type' => 'string' ),
				'meta_description' => array( 'type' => 'string' ),
				'score'            => array( 'type' => 'integer' ),
				'secret'           => array( 'type' => 'string', 'required' => true ),
			),
		) );

		// v1.1.0 — LevelUp Growth publishes INTO this site. Secret via X-LGSC-Secret header or body.
		register_rest_route( 'lgsc/v1', '/create-post', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'handle_create_post' ),
			'permission_callback' => array( $this, 'check_secret' ),
		) );
		register_rest_route( 'lgsc/v1', '/attach-image', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'handle_attach_image' ),
			'permission_callback' => array( $this, 'check_secret' ),
		) );

		register_rest_route( 'lgsc/v1', '/health', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'handle_health' ),
			'permission_callback' => '__return_true',
		) );
	}

	public function check_secret( $request ) {
		$expected = (string) get_option( 'lgsc_webhook_secret', '' );
		if ( '' === $expected ) {
			return new WP_Error( 'lgsc_no_secret', 'Webhook secret not configured', array( 'status' => 401 ) );
		}
		$provided = (string) $request->get_param( 'secret' );
		if ( '' === $provided ) {
			$provided = (string) $request->get_header( 'x_lgsc_secret' ); // v1.1.0 header form
		}
		if ( '' === $provided || ! hash_equals( $expected, $provided ) ) {
			return new WP_Error( 'lgsc_bad_secret', 'Invalid webhook secret', array( 'status' => 403 ) );
		}
		return true;
	}

	public function handle_update_meta( $request ) {
		$url   = (string) $request->get_param( 'url' );
		$title = (string) $request->get_param( 'meta_title' );
		$desc  = (string) $request->get_param( 'meta_description' );
		$score = (int) $request->get_param( 'score' );

		if ( '' === $url ) {
			return new WP_Error( 'lgsc_no_url', 'url is required', array( 'status' => 400 ) );
		}

		$post_id = url_to_postid( $url );
		if ( ! $post_id ) {
			return new WP_Error( 'lgsc_no_post', 'No matching post for url', array( 'status' => 404 ) );
		}

		if ( '' !== $title ) {
			update_post_meta( $post_id, LGSC_Meta_Box::META_TITLE, sanitize_text_field( $title ) );
		}
		if ( '' !== $desc ) {
			update_post_meta( $post_id, LGSC_Meta_Box::META_DESC, sanitize_textarea_field( $desc ) );
		}
		if ( $score > 0 ) {
			update_post_meta( $post_id, LGSC_Meta_Box::META_SCORE, max( 0, min( 100, $score ) ) );
		}
		update_post_meta( $post_id, LGSC_Meta_Box::META_UPDATED, current_time( 'mysql' ) );

		return rest_ensure_response( array(
			'success' => true,
			'post_id' => $post_id,
		) );
	}

	/**
	 * POST /wp-json/lgsc/v1/create-post — create (or update by levelup_article_id) a post.
	 * Returns {success, post_id, url, status}. Never claims success without a real post id.
	 */
	public function handle_create_post( $request ) {
		$title   = sanitize_text_field( (string) $request->get_param( 'title' ) );
		$content = (string) $request->get_param( 'content' );
		if ( '' === $title || '' === trim( wp_strip_all_tags( $content ) ) ) {
			return new WP_Error( 'lgsc_bad_post', 'title and content are required', array( 'status' => 400 ) );
		}
		$status = (string) $request->get_param( 'status' );
		if ( ! in_array( $status, array( 'publish', 'draft', 'future' ), true ) ) {
			$status = 'publish';
		}
		$article_id = (int) $request->get_param( 'levelup_article_id' );
		$existing   = 0;
		if ( $article_id > 0 ) {
			$found = get_posts( array( 'post_type' => 'post', 'post_status' => 'any', 'meta_key' => '_lgsc_article_id', 'meta_value' => $article_id, 'numberposts' => 1, 'fields' => 'ids' ) );
			$existing = $found ? (int) $found[0] : 0;
		}
		$postarr = array(
			'post_title'   => $title,
			'post_content' => wp_kses_post( $content ),
			'post_status'  => $status,
			'post_type'    => 'post',
			'post_author'  => $this->author_id(),
		);
		$scheduled = (string) $request->get_param( 'scheduled_at' );
		if ( 'future' === $status && '' !== $scheduled ) {
			$postarr['post_date']     = get_date_from_gmt( gmdate( 'Y-m-d H:i:s', strtotime( $scheduled ) ) );
			$postarr['post_date_gmt'] = gmdate( 'Y-m-d H:i:s', strtotime( $scheduled ) );
		}
		if ( $existing > 0 ) {
			$postarr['ID'] = $existing;
			$post_id = wp_update_post( $postarr, true );
		} else {
			$post_id = wp_insert_post( $postarr, true );
		}
		if ( is_wp_error( $post_id ) || ! $post_id ) {
			$msg = is_wp_error( $post_id ) ? $post_id->get_error_message() : 'insert failed';
			return new WP_Error( 'lgsc_insert_failed', $msg, array( 'status' => 500 ) );
		}
		$post_id = (int) $post_id;
		if ( $article_id > 0 ) {
			update_post_meta( $post_id, '_lgsc_article_id', $article_id );
		}
		$meta_title = sanitize_text_field( (string) $request->get_param( 'meta_title' ) );
		$meta_desc  = sanitize_textarea_field( (string) $request->get_param( 'meta_description' ) );
		if ( '' !== $meta_title ) { update_post_meta( $post_id, LGSC_Meta_Box::META_TITLE, $meta_title ); }
		if ( '' !== $meta_desc )  { update_post_meta( $post_id, LGSC_Meta_Box::META_DESC, $meta_desc ); }
		$cats = $request->get_param( 'categories' );
		if ( is_array( $cats ) && $cats ) {
			$ids = array();
			foreach ( $cats as $c ) {
				$c = sanitize_text_field( (string) $c ); if ( '' === $c ) { continue; }
				$term = term_exists( $c, 'category' );
				if ( ! $term ) { $term = wp_insert_term( $c, 'category' ); }
				if ( is_array( $term ) && ! empty( $term['term_id'] ) ) { $ids[] = (int) $term['term_id']; }
			}
			if ( $ids ) { wp_set_post_categories( $post_id, $ids ); }
		}
		$tags = $request->get_param( 'tags' );
		if ( is_array( $tags ) && $tags ) { wp_set_post_tags( $post_id, array_map( 'sanitize_text_field', $tags ) ); }

		$thumb = 0;
		$img = (string) $request->get_param( 'featured_image_url' );
		if ( '' !== $img ) {
			$thumb = $this->sideload( $post_id, $img, (string) $request->get_param( 'featured_image_alt' ) );
		}
		return rest_ensure_response( array(
			'success'      => true,
			'post_id'      => $post_id,
			'url'          => get_permalink( $post_id ),
			'status'       => get_post_status( $post_id ),
			'thumbnail_id' => $thumb ?: null,
			'updated'      => $existing > 0,
		) );
	}

	/** POST /wp-json/lgsc/v1/attach-image — sideload an image as the featured image. */
	public function handle_attach_image( $request ) {
		$post_id = (int) $request->get_param( 'post_id' );
		$img     = (string) $request->get_param( 'image_url' );
		if ( $post_id <= 0 || '' === $img || ! get_post( $post_id ) ) {
			return new WP_Error( 'lgsc_bad_attach', 'post_id and image_url are required', array( 'status' => 400 ) );
		}
		$id = $this->sideload( $post_id, $img, (string) $request->get_param( 'alt' ) );
		if ( ! $id ) {
			return new WP_Error( 'lgsc_attach_failed', 'image could not be sideloaded', array( 'status' => 502 ) );
		}
		return rest_ensure_response( array( 'success' => true, 'attachment_id' => $id ) );
	}

	private function sideload( $post_id, $url, $alt = '' ) {
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';
		$id = media_sideload_image( esc_url_raw( $url ), $post_id, $alt ? sanitize_text_field( $alt ) : null, 'id' );
		if ( is_wp_error( $id ) || ! $id ) { return 0; }
		set_post_thumbnail( $post_id, (int) $id );
		if ( $alt ) { update_post_meta( (int) $id, '_wp_attachment_image_alt', sanitize_text_field( $alt ) ); }
		return (int) $id;
	}

	private function author_id() {
		$admins = get_users( array( 'role' => 'administrator', 'number' => 1, 'orderby' => 'ID', 'order' => 'ASC', 'fields' => 'ID' ) );
		return $admins ? (int) $admins[0] : 1;
	}

	public function handle_health() {
		return rest_ensure_response( array(
			'success' => true,
			'plugin'  => 'level-up-growth-seo-connector',
			'version' => defined( 'LGSC_VERSION' ) ? LGSC_VERSION : '1.0.0',
			'site'    => get_site_url(),
		) );
	}
}
