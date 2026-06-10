<?php
/**
 * Tests for the Updater class.
 *
 * Brain Monkey stubs the WordPress HTTP API and helper functions so the
 * Updater can run without WordPress, with no live GitHub call. Plugin's two
 * static helpers (get_plugin_data, get_plugin_file) are seeded via reflection
 * on the private static properties — reaching for reflection is preferred over
 * mocking a final class.
 *
 * @package Kntnt\Photo_Drop
 * @since   0.1.0
 */

declare( strict_types = 1 );

use Brain\Monkey\Functions;
use Kntnt\Photo_Drop\Plugin;
use Kntnt\Photo_Drop\Updater;

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

/**
 * Seeds Plugin's static state without going through get_instance().
 *
 * Sets both the plugin file path and the cached plugin-data array so that
 * Plugin::get_plugin_file() and Plugin::get_plugin_data() return the supplied
 * values for the rest of the current test.
 *
 * @param string                $plugin_file Absolute path to seed.
 * @param array<string, string> $plugin_data Plugin-header array to seed.
 */
function seed_plugin_state( string $plugin_file, array $plugin_data ): void {

	$reflection = new \ReflectionClass( Plugin::class );

	$file_prop = $reflection->getProperty( 'plugin_file' );
	$file_prop->setValue( null, $plugin_file );

	$data_prop = $reflection->getProperty( 'plugin_data' );
	$data_prop->setValue( null, $plugin_data );
}

/**
 * Restores Plugin's static state to the empty defaults so seeding in one test
 * does not leak into the next.
 */
function reset_plugin_state(): void {

	$reflection = new \ReflectionClass( Plugin::class );

	$file_prop = $reflection->getProperty( 'plugin_file' );
	$file_prop->setValue( null, '' );

	$data_prop = $reflection->getProperty( 'plugin_data' );
	$data_prop->setValue( null, null );
}

/**
 * Wires the Brain Monkey stubs for the WordPress HTTP API helpers that the
 * Updater calls. All four return their canned values from the supplied
 * response payload, mimicking core's behaviour closely enough for the
 * Updater's narrow API surface.
 *
 * @param array{response_code:int, body:string}|\WP_Error $response Canned API response.
 */
function bind_http_api_stubs( array|\WP_Error $response ): void {

	Functions\when( 'wp_remote_get' )->alias(
		static fn ( string $url ) => $response,
	);

	Functions\when( 'is_wp_error' )->alias(
		static fn ( mixed $thing ): bool => $thing instanceof \WP_Error,
	);

	Functions\when( 'wp_remote_retrieve_response_code' )->alias(
		static function ( mixed $r ): int {
			if ( is_array( $r ) && isset( $r['response_code'] ) && is_int( $r['response_code'] ) ) {
				return $r['response_code'];
			}
			return 0;
		},
	);

	Functions\when( 'wp_remote_retrieve_body' )->alias(
		static function ( mixed $r ): string {
			if ( is_array( $r ) && isset( $r['body'] ) && is_string( $r['body'] ) ) {
				return $r['body'];
			}
			return '';
		},
	);
}

/**
 * Wires the helper-function stubs that the Updater uses indirectly.
 *
 * The stubbed running WordPress version (6.8.1) deliberately differs from the
 * seeded RequiresWP header (6.5) so the tests can tell `tested` (the running
 * version) and `requires` (the header floor) apart.
 */
function bind_misc_helper_stubs(): void {

	Functions\when( 'plugin_basename' )->alias(
		static function ( string $path ): string {
			return 'kntnt-photo-drop/' . basename( $path );
		},
	);

	Functions\when( 'get_bloginfo' )->alias(
		static fn ( string $key ): string => $key === 'version' ? '6.8.1' : '',
	);

	Functions\when( 'wp_parse_url' )->alias(
		static fn ( string $uri, int $component = -1 ): mixed => parse_url( $uri, $component ),
	);
}

/**
 * Stubs the site-transient release cache as empty and silently writable.
 *
 * The default for tests that exercise the fetch path: every read misses so
 * the Updater always goes to the (stubbed) network, and writes are absorbed.
 * Cache-behaviour tests wire their own expectations instead of calling this.
 */
function bind_release_cache_stubs(): void {

	Functions\when( 'get_site_transient' )->justReturn( false );
	Functions\when( 'set_site_transient' )->justReturn( true );
}

/**
 * Builds a minimal $transient->checked array so the Updater's empty-checked
 * guard does not short-circuit it.
 */
function checked_transient(): \stdClass {
	$t           = new \stdClass();
	$t->checked  = [ 'kntnt-photo-drop/kntnt-photo-drop.php' => '0.1.0' ];
	$t->response = [];
	return $t;
}

beforeEach( function (): void {
	reset_plugin_state();
} );

afterEach( function (): void {
	reset_plugin_state();
} );

// ---------------------------------------------------------------------------
// Empty-checked guard
// ---------------------------------------------------------------------------

it( 'returns the transient unchanged when checked is empty', function (): void {

	bind_http_api_stubs( [
		'response_code' => 200,
		'body'          => '{}',
	] );
	bind_misc_helper_stubs();
	bind_release_cache_stubs();
	seed_plugin_state(
		'/var/www/wp-content/plugins/kntnt-photo-drop/kntnt-photo-drop.php',
		[
			'Version'    => '0.1.0',
			'PluginURI'  => 'https://github.com/Kntnt/kntnt-photo-drop',
			'RequiresWP' => '6.5',
		],
	);

	$transient          = new \stdClass();
	$transient->checked = [];

	$result = ( new Updater() )->check_for_updates( $transient );

	expect( $result )->toBe( $transient )
		->and( $result->response ?? null )->toBeNull();
} );

// ---------------------------------------------------------------------------
// Non-GitHub Plugin URI
// ---------------------------------------------------------------------------

it( 'leaves the transient untouched when the plugin URI is not a GitHub URL', function (): void {

	bind_http_api_stubs( [
		'response_code' => 200,
		'body'          => '{}',
	] );
	bind_misc_helper_stubs();
	bind_release_cache_stubs();
	seed_plugin_state(
		'/path/to/kntnt-photo-drop.php',
		[
			'Version'    => '0.1.0',
			'PluginURI'  => 'https://example.com/plugin',
			'RequiresWP' => '6.5',
		],
	);

	$transient = checked_transient();

	$result = ( new Updater() )->check_for_updates( $transient );

	expect( $result->response )->toBe( [] );
} );

// ---------------------------------------------------------------------------
// Network failure
// ---------------------------------------------------------------------------

it( 'leaves the transient untouched when wp_remote_get returns a WP_Error', function (): void {

	bind_http_api_stubs( new \WP_Error() );
	bind_misc_helper_stubs();
	bind_release_cache_stubs();
	seed_plugin_state(
		'/path/to/kntnt-photo-drop.php',
		[
			'Version'    => '0.1.0',
			'PluginURI'  => 'https://github.com/Kntnt/kntnt-photo-drop',
			'RequiresWP' => '6.5',
		],
	);

	$transient = checked_transient();

	$result = ( new Updater() )->check_for_updates( $transient );

	expect( $result->response )->toBe( [] );
} );

// ---------------------------------------------------------------------------
// No ZIP asset attached
// ---------------------------------------------------------------------------

it( 'does not advertise an update when the release has no application/zip asset', function (): void {

	$body = json_encode( [
		'tag_name'    => 'v0.2.0',
		'html_url'    => 'https://github.com/Kntnt/kntnt-photo-drop/releases/tag/v0.2.0',
		'zipball_url' => 'https://api.github.com/repos/Kntnt/kntnt-photo-drop/zipball/v0.2.0',
		'assets'      => [
			[
				'content_type'         => 'text/plain',
				'browser_download_url' => 'https://example.com/notes.txt',
			],
		],
	] );

	bind_http_api_stubs( [
		'response_code' => 200,
		'body'          => $body,
	] );
	bind_misc_helper_stubs();
	bind_release_cache_stubs();
	seed_plugin_state(
		'/path/to/kntnt-photo-drop.php',
		[
			'Version'    => '0.1.0',
			'PluginURI'  => 'https://github.com/Kntnt/kntnt-photo-drop',
			'RequiresWP' => '6.5',
		],
	);

	$transient = checked_transient();

	$result = ( new Updater() )->check_for_updates( $transient );

	expect( $result->response )->toBe( [] );
} );

// ---------------------------------------------------------------------------
// Released version not greater than installed
// ---------------------------------------------------------------------------

it( 'does not advertise an update when the released version is not newer', function (): void {

	$body = json_encode( [
		'tag_name'    => 'v0.1.0',
		'html_url'    => 'https://github.com/Kntnt/kntnt-photo-drop/releases/tag/v0.1.0',
		'zipball_url' => 'https://api.github.com/repos/Kntnt/kntnt-photo-drop/zipball/v0.1.0',
		'assets'      => [
			[
				'content_type'         => 'application/zip',
				'browser_download_url' => 'https://github.com/Kntnt/kntnt-photo-drop/releases/download/v0.1.0/'
					. 'kntnt-photo-drop.zip',
			],
		],
	] );

	bind_http_api_stubs( [
		'response_code' => 200,
		'body'          => $body,
	] );
	bind_misc_helper_stubs();
	bind_release_cache_stubs();
	seed_plugin_state(
		'/path/to/kntnt-photo-drop.php',
		[
			'Version'    => '0.1.0',
			'PluginURI'  => 'https://github.com/Kntnt/kntnt-photo-drop',
			'RequiresWP' => '6.5',
		],
	);

	$transient = checked_transient();

	$result = ( new Updater() )->check_for_updates( $transient );

	expect( $result->response )->toBe( [] );
} );

// ---------------------------------------------------------------------------
// Newer version + ZIP asset → injects update (asset chosen by content_type,
// owner/repo parsed from the Plugin URI)
// ---------------------------------------------------------------------------

it( 'injects an update record when a newer release with a ZIP asset is available', function (): void {

	$package = 'https://github.com/Kntnt/kntnt-photo-drop/releases/download/v1.2.0/kntnt-photo-drop.zip';
	$body    = json_encode( [
		'tag_name'    => 'v1.2.0',
		'html_url'    => 'https://github.com/Kntnt/kntnt-photo-drop/releases/tag/v1.2.0',
		'zipball_url' => 'https://api.github.com/repos/Kntnt/kntnt-photo-drop/zipball/v1.2.0',
		'assets'      => [
			// A non-zip asset precedes the ZIP so the test proves selection is by
			// content_type, not by position or by filename.
			[
				'content_type'         => 'text/markdown',
				'browser_download_url' => 'https://example.com/CHANGELOG.md',
			],
			[
				'content_type'         => 'application/zip',
				'browser_download_url' => $package,
			],
		],
	] );

	bind_http_api_stubs( [
		'response_code' => 200,
		'body'          => $body,
	] );
	bind_misc_helper_stubs();
	bind_release_cache_stubs();
	seed_plugin_state(
		'/var/www/wp-content/plugins/kntnt-photo-drop/kntnt-photo-drop.php',
		[
			'Version'     => '0.1.0',
			'PluginURI'   => 'https://github.com/Kntnt/kntnt-photo-drop',
			'RequiresWP'  => '6.5',
			'RequiresPHP' => '8.4',
		],
	);

	$transient = checked_transient();

	$result = ( new Updater() )->check_for_updates( $transient );

	$plugin_key = 'kntnt-photo-drop/kntnt-photo-drop.php';
	expect( $result->response )->toHaveKey( $plugin_key );

	// `tested` carries the running WordPress version (it means "tested up to"),
	// while `requires`/`requires_php` carry the plugin header's floors.
	$update = $result->response[ $plugin_key ];
	expect( $update )->toBeInstanceOf( \stdClass::class )
		->and( $update->slug )->toBe( 'kntnt-photo-drop' )
		->and( $update->plugin )->toBe( $plugin_key )
		->and( $update->new_version )->toBe( '1.2.0' )
		->and( $update->package )->toBe( $package )
		->and( $update->url )->toBe( 'https://github.com/Kntnt/kntnt-photo-drop/releases/tag/v1.2.0' )
		->and( $update->tested )->toBe( '6.8.1' )
		->and( $update->requires )->toBe( '6.5' )
		->and( $update->requires_php )->toBe( '8.4' );
} );

// ---------------------------------------------------------------------------
// Non-200 response
// ---------------------------------------------------------------------------

it( 'does not advertise an update when the GitHub API returns a non-200 status', function (): void {

	bind_http_api_stubs( [
		'response_code' => 404,
		'body'          => '{}',
	] );
	bind_misc_helper_stubs();
	bind_release_cache_stubs();
	seed_plugin_state(
		'/path/to/kntnt-photo-drop.php',
		[
			'Version'    => '0.1.0',
			'PluginURI'  => 'https://github.com/Kntnt/kntnt-photo-drop',
			'RequiresWP' => '6.5',
		],
	);

	$transient = checked_transient();

	$result = ( new Updater() )->check_for_updates( $transient );

	expect( $result->response )->toBe( [] );
} );

// ---------------------------------------------------------------------------
// Non-stdClass transient values — defensive against set_site_transient resets
// ---------------------------------------------------------------------------

it( 'returns false unchanged when the transient is being reset to false', function (): void {

	$result = ( new Updater() )->check_for_updates( false );

	expect( $result )->toBeFalse();
} );

it( 'returns null unchanged when the transient value is null', function (): void {

	$result = ( new Updater() )->check_for_updates( null );

	expect( $result )->toBeNull();
} );

it( 'returns an array unchanged when a third-party caller passes an array', function (): void {

	$payload = [ 'unexpected' => 'shape' ];

	$result = ( new Updater() )->check_for_updates( $payload );

	expect( $result )->toBe( $payload );
} );

// ---------------------------------------------------------------------------
// Release cache — a site transient keeps the updater off the GitHub API,
// which allows only 60 unauthenticated requests per hour per IP while the
// update filter fires several times per admin load.
// ---------------------------------------------------------------------------

/**
 * Builds a decoded v1.2.0 release array carrying a ZIP asset and a body,
 * shaped like the cached payload get_latest_github_release() stores.
 *
 * @return array<mixed> The canned release.
 */
function canned_release(): array {
	return [
		'tag_name'    => 'v1.2.0',
		'html_url'    => 'https://github.com/Kntnt/kntnt-photo-drop/releases/tag/v1.2.0',
		'zipball_url' => 'https://api.github.com/repos/Kntnt/kntnt-photo-drop/zipball/v1.2.0',
		'body'        => 'Fixed things.',
		'assets'      => [
			[
				'content_type'         => 'application/zip',
				'browser_download_url' => 'https://github.com/Kntnt/kntnt-photo-drop/releases/download/v1.2.0/'
					. 'kntnt-photo-drop.zip',
			],
		],
	];
}

it( 'serves the release from the site-transient cache without calling GitHub', function (): void {

	// Any network call fails the test — the cached release must suffice.
	Functions\expect( 'wp_remote_get' )->never();
	Functions\when( 'get_site_transient' )->justReturn( canned_release() );
	bind_misc_helper_stubs();
	seed_plugin_state(
		'/var/www/wp-content/plugins/kntnt-photo-drop/kntnt-photo-drop.php',
		[
			'Version'     => '0.1.0',
			'PluginURI'   => 'https://github.com/Kntnt/kntnt-photo-drop',
			'RequiresWP'  => '6.5',
			'RequiresPHP' => '8.4',
		],
	);

	$result = ( new Updater() )->check_for_updates( checked_transient() );

	expect( $result->response )->toHaveKey( 'kntnt-photo-drop/kntnt-photo-drop.php' );
} );

it( 'caches the decoded release for six hours after a successful fetch', function (): void {

	bind_http_api_stubs( [
		'response_code' => 200,
		'body'          => (string) json_encode( canned_release() ),
	] );
	bind_misc_helper_stubs();
	Functions\when( 'get_site_transient' )->justReturn( false );
	Functions\expect( 'set_site_transient' )
		->once()
		->with(
			Updater::RELEASE_TRANSIENT,
			\Mockery::on(
				static fn ( mixed $value ): bool => is_array( $value ) && ( $value['tag_name'] ?? null ) === 'v1.2.0',
			),
			21600,
		)
		->andReturn( true );
	seed_plugin_state(
		'/var/www/wp-content/plugins/kntnt-photo-drop/kntnt-photo-drop.php',
		[
			'Version'     => '0.1.0',
			'PluginURI'   => 'https://github.com/Kntnt/kntnt-photo-drop',
			'RequiresWP'  => '6.5',
			'RequiresPHP' => '8.4',
		],
	);

	$result = ( new Updater() )->check_for_updates( checked_transient() );

	expect( $result->response )->toHaveKey( 'kntnt-photo-drop/kntnt-photo-drop.php' );
} );

it( 'caches a short-lived failure marker when GitHub returns a non-200 status', function (): void {

	bind_http_api_stubs( [
		'response_code' => 403,
		'body'          => '',
	] );
	bind_misc_helper_stubs();
	Functions\when( 'get_site_transient' )->justReturn( false );

	// The failure marker is any non-array value, cached far shorter (15 min)
	// than a successful lookup so a rate-limited host recovers quickly.
	Functions\expect( 'set_site_transient' )
		->once()
		->with(
			Updater::RELEASE_TRANSIENT,
			\Mockery::on( static fn ( mixed $value ): bool => ! is_array( $value ) && $value !== false ),
			900,
		)
		->andReturn( true );
	seed_plugin_state(
		'/path/to/kntnt-photo-drop.php',
		[
			'Version'    => '0.1.0',
			'PluginURI'  => 'https://github.com/Kntnt/kntnt-photo-drop',
			'RequiresWP' => '6.5',
		],
	);

	$result = ( new Updater() )->check_for_updates( checked_transient() );

	expect( $result->response )->toBe( [] );
} );

it( 'skips the GitHub call while a cached failure marker is in force', function (): void {

	// The cached sentinel must short-circuit the lookup: no network request, no
	// cache write, and no advertised update.
	Functions\expect( 'wp_remote_get' )->never();
	Functions\expect( 'set_site_transient' )->never();
	Functions\when( 'get_site_transient' )->justReturn( 'unavailable' );
	bind_misc_helper_stubs();
	seed_plugin_state(
		'/path/to/kntnt-photo-drop.php',
		[
			'Version'    => '0.1.0',
			'PluginURI'  => 'https://github.com/Kntnt/kntnt-photo-drop',
			'RequiresWP' => '6.5',
		],
	);

	$result = ( new Updater() )->check_for_updates( checked_transient() );

	expect( $result->response )->toBe( [] );
} );

// ---------------------------------------------------------------------------
// plugins_api — the "View version details" modal is answered locally, since
// the slug does not exist on wordpress.org.
// ---------------------------------------------------------------------------

it( 'short-circuits plugins_api with release-backed details for this plugin', function (): void {

	// The cached release feeds the modal; no network call is allowed.
	Functions\expect( 'wp_remote_get' )->never();
	Functions\when( 'get_site_transient' )->justReturn( canned_release() );
	bind_misc_helper_stubs();
	Functions\when( 'wpautop' )->alias(
		static fn ( string $text ): string => '<p>' . $text . '</p>',
	);
	Functions\when( 'wp_kses_post' )->returnArg( 1 );
	seed_plugin_state(
		'/var/www/wp-content/plugins/kntnt-photo-drop/kntnt-photo-drop.php',
		[
			'Name'        => 'Kntnt Photo Drop',
			'Version'     => '0.1.0',
			'PluginURI'   => 'https://github.com/Kntnt/kntnt-photo-drop',
			'Author'      => 'Thomas Barregren',
			'RequiresWP'  => '6.5',
			'RequiresPHP' => '8.4',
		],
	);

	$args       = new \stdClass();
	$args->slug = 'kntnt-photo-drop';

	$result = ( new Updater() )->plugin_information( false, 'plugin_information', $args );

	$package = 'https://github.com/Kntnt/kntnt-photo-drop/releases/download/v1.2.0/kntnt-photo-drop.zip';
	expect( $result )->toBeInstanceOf( \stdClass::class )
		->and( $result->name )->toBe( 'Kntnt Photo Drop' )
		->and( $result->slug )->toBe( 'kntnt-photo-drop' )
		->and( $result->version )->toBe( '1.2.0' )
		->and( $result->author )->toBe( 'Thomas Barregren' )
		->and( $result->homepage )->toBe( 'https://github.com/Kntnt/kntnt-photo-drop' )
		->and( $result->requires )->toBe( '6.5' )
		->and( $result->requires_php )->toBe( '8.4' )
		->and( $result->tested )->toBe( '6.8.1' )
		->and( $result->download_link )->toBe( $package )
		->and( $result->sections )->toBe( [ 'changelog' => '<p>Fixed things.</p>' ] );
} );

it( 'passes plugins_api through untouched for another plugin slug', function (): void {

	bind_misc_helper_stubs();
	seed_plugin_state(
		'/path/to/kntnt-photo-drop.php',
		[
			'Version'   => '0.1.0',
			'PluginURI' => 'https://github.com/Kntnt/kntnt-photo-drop',
		],
	);

	$args       = new \stdClass();
	$args->slug = 'akismet';

	$result = ( new Updater() )->plugin_information( false, 'plugin_information', $args );

	expect( $result )->toBeFalse();
} );

it( 'passes plugins_api through untouched for another action', function (): void {

	$existing = new \stdClass();

	$result = ( new Updater() )->plugin_information( $existing, 'query_plugins', new \stdClass() );

	expect( $result )->toBe( $existing );
} );
