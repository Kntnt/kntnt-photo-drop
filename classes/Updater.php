<?php
/**
 * GitHub-based plugin update checker.
 *
 * Hooks into the WordPress update transient to check for new releases on the
 * plugin's GitHub repository and present them in the admin UI. This is the
 * only external request the plugin makes, and it is admin-side only — there
 * is no visitor-facing embed to gate (see docs/design.md § Distribution and
 * privacy).
 *
 * @package Kntnt\Photo_Drop
 * @since   0.1.0
 */

declare( strict_types = 1 );

namespace Kntnt\Photo_Drop;

/**
 * Handles checking for plugin updates from GitHub.
 *
 * Hooks into the WordPress update process to check the GitHub repository for
 * new releases and present them in the WordPress admin area. The update asset
 * is identified by its content_type, not its filename, so the release ZIP can
 * keep a stable, version-less name across releases.
 *
 * @package Kntnt\Photo_Drop
 * @since 0.1.0
 */
final class Updater {

	/**
	 * The site-transient key under which the latest GitHub release is cached.
	 *
	 * Public so the tests and the uninstall handler's purge logic name the
	 * same key as this class. The cache exists because the
	 * `pre_set_site_transient_update_plugins` filter fires several times per
	 * admin load while unauthenticated GitHub allows only 60 requests per
	 * hour per IP.
	 *
	 * @since 0.2.0
	 * @var string
	 */
	public const RELEASE_TRANSIENT = 'kntnt_photo_drop_release';

	/**
	 * Cache lifetime for a successfully fetched release: six hours, in seconds.
	 *
	 * @since 0.2.0
	 * @var int
	 */
	private const RELEASE_CACHE_TTL = 21600;

	/**
	 * Cache lifetime for a failed lookup: fifteen minutes, in seconds.
	 *
	 * Deliberately much shorter than the success TTL: a rate-limited or
	 * unreachable GitHub should be retried soon, but not hammered on every
	 * admin load in the meantime.
	 *
	 * @since 0.2.0
	 * @var int
	 */
	private const FAILURE_CACHE_TTL = 900;

	/**
	 * Sentinel cached in place of release data after a failed lookup.
	 *
	 * On read, any cached value that is not an array is treated as this
	 * marker, so the exact string is an implementation detail.
	 *
	 * @since 0.2.0
	 * @var string
	 */
	private const FAILURE_SENTINEL = 'unavailable';

	/**
	 * Checks for new plugin releases on GitHub.
	 *
	 * This is the callback for 'pre_set_site_transient_update_plugins'. It
	 * compares the installed version with the latest release tag on GitHub.
	 *
	 * The parameter type is intentionally `mixed` rather than `\stdClass`:
	 * the filter fires from `set_site_transient()` with whatever the caller
	 * passed as the value, and although WordPress core always passes a
	 * stdClass for the update_plugins transient, third-party code can
	 * legitimately call `set_site_transient( 'update_plugins', false )` to
	 * clear the transient. A narrower signature would throw a fatal
	 * TypeError in that case.
	 *
	 * @since 0.1.0
	 *
	 * @param mixed $transient The update transient passed by the filter.
	 *                          Normally a stdClass; possibly false during a
	 *                          reset.
	 * @return mixed The (potentially modified) transient.
	 */
	public function check_for_updates( mixed $transient ): mixed {

		// Pass non-object payloads straight through — only stdClass values
		// have the structure this updater expects to mutate.
		if ( ! ( $transient instanceof \stdClass ) ) {
			return $transient;
		}

		// If WordPress hasn't checked recently, don't check again.
		if ( empty( $transient->checked ) ) {
			return $transient;
		}

		// Read the plugin header and extract the GitHub repository slug.
		$plugin_data = Plugin::get_plugin_data();
		$github_uri  = $this->str_field( $plugin_data, 'PluginURI' );
		$github_repo = $this->get_github_repo_from_uri( $github_uri );
		if ( $github_repo === null ) {
			return $transient;
		}

		// Fetch the latest release information from the GitHub API.
		$latest_release = $this->get_latest_github_release( $github_repo );
		if ( $latest_release === null ) {
			return $transient;
		}

		// Bail when the installed version is already current or newer.
		$current_version = $this->str_field( $plugin_data, 'Version' );
		$latest_version  = ltrim( $this->str_field( $latest_release, 'tag_name' ), 'v' );
		if ( ! version_compare( $current_version, $latest_version, '<' ) ) {
			return $transient;
		}

		// Bail when no ZIP asset is attached to the release.
		$package_url = $this->find_zip_asset_url( $latest_release );
		if ( $package_url === null ) {
			return $transient;
		}

		// Build the update record from the plugin header and release data. `tested`
		// means "tested up to", so it carries the running WordPress version, while
		// `requires` and `requires_php` carry the plugin header's floors so WordPress
		// never installs the release on a host that no longer qualifies.
		$plugin_slug_path = plugin_basename( Plugin::get_plugin_file() );
		$update_info = new \stdClass();
		$update_info->slug = dirname( $plugin_slug_path );
		$update_info->plugin = $plugin_slug_path;
		$update_info->new_version = $latest_version;
		$update_info->url = $this->str_field( $latest_release, 'html_url' );
		$update_info->package = $package_url;
		$update_info->tested = get_bloginfo( 'version' );
		$update_info->requires = $this->str_field( $plugin_data, 'RequiresWP' );
		$update_info->requires_php = $this->str_field( $plugin_data, 'RequiresPHP' );

		// Inject the update record into the transient's response array.
		if ( ! isset( $transient->response ) || ! is_array( $transient->response ) ) {
			$transient->response = [];
		}
		$transient->response[ $plugin_slug_path ] = $update_info;

		return $transient;

	}

	/**
	 * Short-circuits the "View version details" lookup for this plugin.
	 *
	 * This is the callback for the `plugins_api` filter. The update row links
	 * to the wordpress.org plugin-information modal, but this plugin is not
	 * hosted there, so without this handler the modal shows an error. For
	 * this plugin's slug it returns a minimal information object built from
	 * the plugin header and the cached GitHub release; every other request
	 * passes through untouched.
	 *
	 * @since 0.2.0
	 *
	 * @param mixed  $result The result so far; false when nothing answered yet.
	 * @param string $action The type of information requested.
	 * @param mixed  $args   Plugin API arguments; an object carrying a `slug`
	 *                       for plugin-information queries.
	 * @return mixed The information object for this plugin, or $result untouched.
	 */
	public function plugin_information( mixed $result, string $action, mixed $args ): mixed {

		// Only plugin-information queries are candidates; every other action
		// belongs to wordpress.org and passes through untouched.
		if ( $action !== 'plugin_information' || ! is_object( $args ) ) {
			return $result;
		}

		// Likewise pass through queries that target any other plugin's slug.
		$slug = dirname( plugin_basename( Plugin::get_plugin_file() ) );
		if ( ! isset( $args->slug ) || $args->slug !== $slug ) {
			return $result;
		}

		// Resolve the GitHub repository and the (cached) latest release; without
		// either there is nothing better to show than the default behaviour.
		$plugin_data = Plugin::get_plugin_data();
		$github_uri = $this->str_field( $plugin_data, 'PluginURI' );
		$github_repo = $this->get_github_repo_from_uri( $github_uri );
		$release = $github_repo !== null ? $this->get_latest_github_release( $github_repo ) : null;
		if ( $release === null ) {
			return $result;
		}

		// Build the minimal information object the modal renders: identity and
		// compatibility floors from the plugin header, version and download link
		// from the release, and the release body as the changelog section.
		$info = new \stdClass();
		$info->name = $this->str_field( $plugin_data, 'Name' );
		$info->slug = $slug;
		$info->version = ltrim( $this->str_field( $release, 'tag_name' ), 'v' );
		$info->author = $this->str_field( $plugin_data, 'Author' );
		$info->homepage = $github_uri;
		$info->requires = $this->str_field( $plugin_data, 'RequiresWP' );
		$info->requires_php = $this->str_field( $plugin_data, 'RequiresPHP' );
		$info->tested = get_bloginfo( 'version' );
		$info->download_link = $this->find_zip_asset_url( $release ) ?? '';
		$info->sections = [
			'changelog' => wp_kses_post( wpautop( $this->str_field( $release, 'body' ) ) ),
		];

		return $info;

	}

	/**
	 * Fetches the latest release data from the GitHub API, cached site-wide.
	 *
	 * The decoded release is cached in a site transient because the update
	 * filter fires several times per admin load and unauthenticated GitHub
	 * allows only 60 requests per hour per IP. A failed lookup caches a
	 * short-lived sentinel so a rate-limited host is not hammered while the
	 * updater still recovers quickly.
	 *
	 * Returns an associative array on success so callers can access fields
	 * without triggering PHPStan's property-not-found errors on stdClass.
	 *
	 * @since 0.1.0
	 *
	 * @param string $repo The repository name in 'user/repo' format.
	 * @return array<mixed>|null Release data on success, null on failure.
	 */
	private function get_latest_github_release( string $repo ): ?array {

		// Serve from the cache when possible: a cached array is release data; any
		// other cached value is the failure sentinel still in force.
		$cached = get_site_transient( self::RELEASE_TRANSIENT );
		if ( is_array( $cached ) ) {
			return $cached;
		}
		if ( $cached !== false ) {
			return null;
		}

		// Fetch the latest release from the GitHub REST API with a tight timeout —
		// this call blocks an admin request, so failing fast beats waiting on a
		// slow API.
		$request_uri = "https://api.github.com/repos/{$repo}/releases/latest";
		$response = wp_remote_get(
			$request_uri,
			[
				'timeout' => 5,
				'headers' => [ 'Accept' => 'application/vnd.github+json' ],
			],
		);

		// A transport error or non-200 caches the short-lived failure sentinel and
		// logs once at debug level, naming the status for diagnosis.
		if ( is_wp_error( $response ) || wp_remote_retrieve_response_code( $response ) !== 200 ) {
			$status = is_wp_error( $response )
				? $response->get_error_message()
				: (string) wp_remote_retrieve_response_code( $response );
			Plugin::debug( "GitHub release lookup for {$repo} failed: {$status}." );
			set_site_transient( self::RELEASE_TRANSIENT, self::FAILURE_SENTINEL, self::FAILURE_CACHE_TTL );
			return null;
		}

		// Decode as an associative array so static analysis can reason about it; a
		// malformed payload is cached and logged like a failed request.
		$decoded = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $decoded ) || ! isset( $decoded['tag_name'], $decoded['zipball_url'] ) ) {
			Plugin::debug( "GitHub release lookup for {$repo} returned HTTP 200 but a malformed payload." );
			set_site_transient( self::RELEASE_TRANSIENT, self::FAILURE_SENTINEL, self::FAILURE_CACHE_TTL );
			return null;
		}

		// Cache the decoded release so subsequent admin loads skip the network.
		set_site_transient( self::RELEASE_TRANSIENT, $decoded, self::RELEASE_CACHE_TTL );

		return $decoded;

	}

	/**
	 * Locates the first ZIP asset URL in a release's asset list.
	 *
	 * Returns null when no ZIP asset is attached — the Updater will then skip
	 * advertising the update rather than offering a broken package URL. The
	 * asset is matched by content_type, not filename, so the release ZIP can
	 * keep a stable, version-less name.
	 *
	 * @since 0.1.0
	 *
	 * @param array<mixed> $release Decoded GitHub release data.
	 * @return string|null The download URL of the first ZIP asset, or null.
	 */
	private function find_zip_asset_url( array $release ): ?string {

		// Walk the assets array looking for the first application/zip entry.
		if ( empty( $release['assets'] ) || ! is_array( $release['assets'] ) ) {
			return null;
		}

		foreach ( $release['assets'] as $asset ) {
			if ( ! is_array( $asset ) ) {
				continue;
			}
			$is_zip = isset( $asset['content_type'] ) && $asset['content_type'] === 'application/zip';
			if ( $is_zip ) {
				return is_string( $asset['browser_download_url'] ) ? $asset['browser_download_url'] : null;
			}
		}

		return null;

	}

	/**
	 * Parses the GitHub repository slug from a URI.
	 *
	 * Extracts the 'user/repo' part from a full GitHub URL such as
	 * 'https://github.com/user/repo'.
	 *
	 * @since 0.1.0
	 *
	 * @param string $uri The full GitHub Plugin URI from the plugin header.
	 * @return string|null The 'user/repo' slug on success, or null if invalid.
	 */
	private function get_github_repo_from_uri( string $uri ): ?string {

		// Reject non-GitHub URIs quickly.
		if ( $uri === '' || ! str_contains( $uri, 'github.com' ) ) {
			return null;
		}

		// Extract the path component and split it into owner/repo segments.
		$path = wp_parse_url( $uri, PHP_URL_PATH );
		if ( ! is_string( $path ) || $path === '' ) {
			return null;
		}

		$parts = explode( '/', trim( $path, '/' ) );
		if ( count( $parts ) >= 2 ) {
			return "{$parts[0]}/{$parts[1]}";
		}

		return null;

	}

	/**
	 * Safely reads a string field from a mixed-typed array.
	 *
	 * Returns an empty string when the field is absent or not a string, so
	 * callers can inline the call without a ternary ladder.
	 *
	 * @since 0.1.0
	 *
	 * @param array<mixed> $data The source array.
	 * @param string       $key  The key to look up.
	 * @return string The string value, or '' if missing or non-string.
	 */
	private function str_field( array $data, string $key ): string {
		return isset( $data[ $key ] ) && is_string( $data[ $key ] ) ? $data[ $key ] : '';
	}

}
