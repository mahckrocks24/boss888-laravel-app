<?php
/**
 * LGSC API Client.
 *
 * Thin REST client to LevelUp Growth Laravel API.
 * Holds NO business logic — every decision happens server-side.
 *
 * @package LevelUp_Growth_SEO_Connector
 */

defined( 'ABSPATH' ) || exit;

class LGSC_API_Client {

	/** @var string */
	private $api_key;

	/** @var int */
	private $workspace_id;

	/** @var string */
	private $api_base;

	public function __construct() {
		$this->api_key      = (string) get_option( 'lgsc_api_key', '' );
		$this->workspace_id = (int) get_option( 'lgsc_workspace_id', 0 );
		$this->api_base     = lgsc_api_base();
	}

	/**
	 * @return bool true when both key and workspace id are configured.
	 */
	public function is_configured() {
		return ( '' !== $this->api_key ) && ( $this->workspace_id > 0 );
	}

	/**
	 * Generic request to the LevelUp Growth API.
	 * Returns associative array. On error returns ['error' => message, 'http_code' => N].
	 *
	 * @param string $method
	 * @param string $endpoint  Path (with leading slash), e.g. '/connector/ping'.
	 * @param array  $data      Body or query params.
	 * @return array
	 */
	public function request( $method, $endpoint, $data = array() ) {
		if ( ! $this->is_configured() ) {
			return array( 'error' => 'not_configured', 'http_code' => 0 );
		}

		$method = strtoupper( $method );
		$url    = $this->api_base . '/' . ltrim( $endpoint, '/' );

		// v1.0.2: switched from `Authorization: Bearer {jwt}` (12h expiry, broke every install)
		// to `X-API-KEY: lgs_*` (long-lived, server-issued, revocable). Workspace_id is now
		// resolved from the api_keys row server-side; X-Workspace-ID is sent for context only.
		// X-Timestamp enables optional anti-replay (server accepts if within ±300s).
		$args = array(
			'method'  => $method,
			'timeout' => 30,
			'headers' => array(
				'X-API-KEY'       => $this->api_key,
				'Content-Type'    => 'application/json',
				'Accept'          => 'application/json',
				'X-Workspace-ID'  => (string) $this->workspace_id,
				'X-Timestamp'     => (string) time(),
				'X-LGSC-Version'  => defined( 'LGSC_VERSION' ) ? LGSC_VERSION : '1.0.0',
				'User-Agent'      => 'LevelUp-Growth-Connector/' . ( defined( 'LGSC_VERSION' ) ? LGSC_VERSION : '1.0.0' ),
			),
		);

		if ( 'GET' === $method ) {
			if ( ! empty( $data ) ) {
				$url = add_query_arg( $data, $url );
			}
		} else {
			$args['body'] = wp_json_encode( $data );
		}

		$response = wp_remote_request( $url, $args );

		if ( is_wp_error( $response ) ) {
			return array(
				'error'     => $response->get_error_message(),
				'http_code' => 0,
			);
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = wp_remote_retrieve_body( $response );
		$json = json_decode( $body, true );

		if ( $code < 200 || $code >= 300 ) {
			$msg = is_array( $json ) && isset( $json['message'] ) ? $json['message'] : ( 'http_' . $code );
			return array(
				'error'     => $msg,
				'http_code' => $code,
				'body'      => is_array( $json ) ? $json : array( 'raw' => $body ),
			);
		}

		return is_array( $json ) ? $json : array( 'raw' => $body );
	}

	/**
	 * Send page content for analysis. POST /seo/analyze-page.
	 *
	 * @param array $page_data {url, title, meta_description, content, h1, h2s, word_count, images, internal_links}
	 * @return array
	 */
	public function analyze_page( $page_data ) {
		$payload = array(
			'url'              => isset( $page_data['url'] ) ? (string) $page_data['url'] : '',
			'title'            => isset( $page_data['title'] ) ? (string) $page_data['title'] : '',
			'meta_description' => isset( $page_data['meta_description'] ) ? (string) $page_data['meta_description'] : '',
			'content'          => isset( $page_data['content'] ) ? (string) $page_data['content'] : '',
			'h1'               => isset( $page_data['h1'] ) ? (string) $page_data['h1'] : '',
			'h2s'              => isset( $page_data['h2s'] ) && is_array( $page_data['h2s'] ) ? array_values( $page_data['h2s'] ) : array(),
			'word_count'       => isset( $page_data['word_count'] ) ? (int) $page_data['word_count'] : 0,
			'images'           => isset( $page_data['images'] ) && is_array( $page_data['images'] ) ? array_values( $page_data['images'] ) : array(),
			'internal_links'   => isset( $page_data['internal_links'] ) && is_array( $page_data['internal_links'] ) ? array_values( $page_data['internal_links'] ) : array(),
		);

		// v1.0.2: route migrated from /seo/analyze-page → /connector/analyze-page (api-key-auth path).
		return $this->request( 'POST', '/connector/analyze-page', $payload );
	}

	/**
	 * GET /seo/quick-wins?url=...
	 *
	 * @param string $url
	 * @return array
	 */
	public function get_quick_wins( $url ) {
		// v1.0.2: route migrated from /seo/quick-wins → /connector/quick-wins (URL-filtered server-side).
		return $this->request( 'GET', '/connector/quick-wins', array(
			'url' => (string) $url,
		) );
	}

	/**
	 * PATCH /seo/indexed-content/meta — save meta title/description back to the indexed page.
	 *
	 * @param string $url
	 * @param string $meta_title
	 * @param string $meta_description
	 * @return array
	 */
	public function save_meta( $url, $meta_title, $meta_description ) {
		// v1.0.2: route migrated from /seo/connector/save-meta → /connector/save-meta.
		return $this->request( 'PATCH', '/connector/save-meta', array(
			'url'              => (string) $url,
			'meta_title'       => (string) $meta_title,
			'meta_description' => (string) $meta_description,
		) );
	}

	/**
	 * GET /seo/link-opportunities?source_url=...
	 *
	 * @param string $url
	 * @return array
	 */
	public function get_link_suggestions( $url ) {
		// v1.0.2: route migrated from /seo/link-opportunities → /connector/link-opportunities (source_url filter now respected).
		return $this->request( 'GET', '/connector/link-opportunities', array(
			'source_url' => (string) $url,
		) );
	}

	/**
	 * GET /seo/pages/score?url=...
	 *
	 * @param string $url
	 * @return array
	 */
	public function get_page_score( $url ) {
		// v1.0.2: route migrated from /seo/pages/score (didn't exist on Laravel) → /connector/pages/score (now wired).
		return $this->request( 'GET', '/connector/pages/score', array(
			'url' => (string) $url,
		) );
	}

	/**
	 * GET /seo/connector/ping — verify credentials.
	 *
	 * @return array {success, workspace_name, plan, credits_remaining, seo_pages_indexed}
	 */
	/**
	 * v1.1.0 — POST /connector/register-site: tells LevelUp Growth which site this key belongs
	 * to and hands over the webhook secret so LevelUp can publish INTO this site
	 * (lgsc/v1/create-post). Called from "Test connection" so a passing test IS a registration.
	 */
	public function register_site() {
		global $wp_version;
		return $this->request( 'POST', '/connector/register-site', array(
			'site_url'       => get_site_url(),
			'site_name'      => get_bloginfo( 'name' ),
			'webhook_secret' => (string) get_option( 'lgsc_webhook_secret', '' ),
			'plugin_version' => defined( 'LGSC_VERSION' ) ? LGSC_VERSION : '1.1.0',
			'wp_version'     => isset( $wp_version ) ? (string) $wp_version : '',
		) );
	}

	public function verify_connection() {
		// v1.0.2: route migrated from /seo/connector/ping → /connector/ping (api-key-auth path).
		return $this->request( 'GET', '/connector/ping' );
	}

	// ── v1.0.4: feature-parity reads ────────────────────────────────────────

	/**
	 * GET /connector/audits — list of recent audits + summary aggregates.
	 *
	 * @param int $page
	 * @param int $per_page
	 * @return array
	 */
	public function get_audits( $page = 1, $per_page = 10 ) {
		return $this->request( 'GET', '/connector/audits', array(
			'page'     => max( 1, (int) $page ),
			'per_page' => max( 1, min( 50, (int) $per_page ) ),
		) );
	}

	/**
	 * GET /connector/indexed-content — paginated indexed-page list.
	 *
	 * @param int    $page
	 * @param int    $per_page
	 * @param string $filter   Optional filter slug (low_score|missing_meta|thin_content|no_h1).
	 * @param string $q        Optional URL/title search.
	 * @return array
	 */
	public function get_indexed_content( $page = 1, $per_page = 25, $filter = '', $q = '' ) {
		$params = array(
			'page'     => max( 1, (int) $page ),
			'per_page' => max( 1, min( 100, (int) $per_page ) ),
		);
		if ( $filter !== '' ) $params['filter'] = (string) $filter;
		if ( $q !== '' )       $params['q']      = (string) $q;
		return $this->request( 'GET', '/connector/indexed-content', $params );
	}

	/**
	 * GET /connector/keywords — paginated keyword list.
	 *
	 * @param int $page
	 * @param int $per_page
	 * @return array
	 */
	public function get_keywords( $page = 1, $per_page = 25 ) {
		return $this->request( 'GET', '/connector/keywords', array(
			'page'     => max( 1, (int) $page ),
			'per_page' => max( 1, min( 100, (int) $per_page ) ),
		) );
	}

	/**
	 * GET /connector/keywords/{id} — single keyword detail.
	 *
	 * @param int $id
	 * @return array
	 */
	public function get_keyword( $id ) {
		return $this->request( 'GET', '/connector/keywords/' . (int) $id );
	}

	/**
	 * GET /connector/competitors — workspace's currently-tracked competitor domains.
	 *
	 * @return array
	 */
	public function get_competitors() {
		return $this->request( 'GET', '/connector/competitors' );
	}

	/**
	 * GET /connector/reports — aggregated workspace SEO report.
	 *
	 * @return array
	 */
	public function get_reports() {
		return $this->request( 'GET', '/connector/reports' );
	}

	/**
	 * POST /connector/assistant/message — SEO AI assistant.
	 *
	 * @param string $message
	 * @param array  $context Optional WP-side context (post_id, current_url, etc.).
	 * @return array {success, data: {response, suggestions[]}, meta}
	 */
	public function assistant_message( $message, $context = array() ) {
		return $this->request( 'POST', '/connector/assistant/message', array(
			'message' => (string) $message,
			'context' => is_array( $context ) ? $context : array(),
		) );
	}
}
