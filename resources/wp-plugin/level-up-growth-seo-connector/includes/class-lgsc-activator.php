<?php
/**
 * LGSC Activator.
 *
 * Activation / deactivation / uninstall hooks.
 *
 * @package LevelUp_Growth_SEO_Connector
 */

defined( 'ABSPATH' ) || exit;

class LGSC_Activator {

	/**
	 * Activation: set defaults, generate webhook secret, seed score meta
	 * for all published posts (defaulting to 0).
	 */
	public static function activate() {
		// Defaults — never overwrite if user has already configured.
		if ( false === get_option( 'lgsc_api_url' ) ) {
			add_option( 'lgsc_api_url', LGSC_API_BASE_DEFAULT );
		}
		if ( false === get_option( 'lgsc_auto_analyze' ) ) {
			add_option( 'lgsc_auto_analyze', '1' );
		}

		// Generate webhook secret if missing.
		$secret = (string) get_option( 'lgsc_webhook_secret', '' );
		if ( '' === $secret ) {
			update_option( 'lgsc_webhook_secret', wp_generate_password( 40, false, false ) );
		}

		// Seed _lgsc_score = 0 on all published posts that don't already have it.
		$published = get_posts( array(
			'post_type'      => array( 'post', 'page' ),
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'no_found_rows'  => true,
			'meta_query'     => array(
				array(
					'key'     => '_lgsc_score',
					'compare' => 'NOT EXISTS',
				),
			),
		) );

		if ( is_array( $published ) ) {
			foreach ( $published as $pid ) {
				add_post_meta( (int) $pid, '_lgsc_score', 0, true );
			}
		}
	}

	/**
	 * Deactivation: keep all options + meta intact (user may reactivate).
	 */
	public static function deactivate() {
		// Intentionally a no-op.
	}

	/**
	 * Uninstall: remove ALL plugin data — options + post meta.
	 */
	public static function uninstall() {
		// Remove options.
		delete_option( 'lgsc_api_url' );
		delete_option( 'lgsc_api_key' );
		delete_option( 'lgsc_workspace_id' );
		delete_option( 'lgsc_auto_analyze' );
		delete_option( 'lgsc_webhook_secret' );

		// Remove all _lgsc_* post meta from every post type.
		global $wpdb;
		if ( $wpdb instanceof wpdb ) {
			$wpdb->query( "DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE '\\_lgsc\\_%'" );
		}
	}
}
