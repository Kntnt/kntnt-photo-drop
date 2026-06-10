<?php
/**
 * Shared harness for the PHP integration suite.
 *
 * The integration tests run on the host (plain Pest, no Brain Monkey) against
 * the live `@wordpress/env` instance: WP-CLI commands execute inside the `cli`
 * container via `npx wp-env run`, HTTP requests hit http://localhost:8888, and
 * filesystem assertions read the collections root directly through the bind
 * mount under the wp-env install path. Everything environment-shaped lives
 * here — container shelling with noise filtering, install-path resolution,
 * GD-built image fixtures, cookie/nonce authentication, and collection/page
 * seeding — so the test files stay declarative.
 *
 * @package Kntnt\Photo_Drop
 * @since   0.3.0
 */

declare( strict_types = 1 );

namespace Tests\Integration;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- The harness runs on the host with no WordPress loaded (esc_html() does not exist); exception messages surface only in the Pest console, never in HTML.

/**
 * The base URL of the running `@wordpress/env` development site.
 *
 * @since 0.3.0
 * @var string
 */
const SITE_URL = 'http://localhost:8888';

/**
 * The admin credentials `@wordpress/env` provisions by default.
 *
 * @since 0.3.0
 * @var string
 */
const ADMIN_USER = 'admin';

/**
 * The admin password `@wordpress/env` provisions by default.
 *
 * @since 0.3.0
 * @var string
 */
const ADMIN_PASSWORD = 'password';

/**
 * Fails fast when the wp-env instance is not reachable.
 *
 * Every test in this suite needs the live WordPress, so the first touch of the
 * harness (and the load of this file) probes http://localhost:8888 once and
 * raises an actionable error when nothing answers. The probe result is cached
 * for the process — a site that was up at suite start is assumed to stay up.
 *
 * @since 0.3.0
 *
 * @throws \RuntimeException When the wp-env site does not answer.
 */
function ensure_wp_env(): void {

	// Probe the site root once per process; any HTTP answer counts as alive.
	static $alive = null;
	if ( $alive === null ) {
		$handle = curl_init( SITE_URL . '/' );
		curl_setopt_array( $handle, [
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_NOBODY         => true,
			CURLOPT_TIMEOUT        => 5,
		] );
		$alive = curl_exec( $handle ) !== false;
	}

	// Surface the one actionable remedy rather than letting every test fail
	// with an opaque curl or docker error.
	if ( ! $alive ) {
		throw new \RuntimeException( 'wp-env is not running — run `npm run test:integration`.' );
	}

}

/**
 * Returns the repository root the npx toolchain runs from.
 *
 * @since 0.3.0
 *
 * @return string The absolute path of the plugin repository.
 */
function project_root(): string {
	return dirname( __DIR__, 2 );
}

/**
 * Runs a host shell command and captures its combined output and exit code.
 *
 * Standard error is folded into standard output (`2>&1`) so a single pipe
 * carries everything and no pipe-buffer deadlock is possible; callers filter
 * the combined stream as needed. The command runs from the repository root so
 * `npx` resolves the project-local `wp-env`.
 *
 * @since 0.3.0
 *
 * @param array<int,string> $arguments The command and its arguments, unescaped.
 * @return array{output:string,exit_code:int} The combined output and the exit code.
 * @throws \RuntimeException When the process cannot be started.
 */
function run_shell( array $arguments ): array {

	// Compose one safely-escaped command line with stderr folded into stdout.
	$command = implode( ' ', array_map( 'escapeshellarg', $arguments ) ) . ' 2>&1';

	// Launch from the repository root and drain the single output pipe.
	$descriptors = [ 1 => [ 'pipe', 'w' ] ];
	// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_proc_open -- The harness must drive wp-env/WP-CLI in the container; there is no WordPress API on the host side of this suite.
	$process = proc_open( $command, $descriptors, $pipes, project_root() );
	if ( ! is_resource( $process ) ) {
		throw new \RuntimeException( "Cannot start the command: {$command}" );
	}
	$output = stream_get_contents( $pipes[1] );
	fclose( $pipes[1] );
	$exit_code = proc_close( $process );

	return [
		'output'    => is_string( $output ) ? $output : '',
		'exit_code' => $exit_code,
	];

}

/**
 * Strips wp-env decoration and PHP deprecation noise from container output.
 *
 * The container emits WordPress-core PHP 8.4 deprecation notices, and wp-env
 * frames every run with its own status lines (`ℹ Starting …`, `✔ Ran …`,
 * `✖/Command failed …`). Both are noise to an assertion; WP-CLI's own
 * `Success:`/`Warning:`/`Error:` lines and table rows are the signal and pass
 * through untouched.
 *
 * @since 0.3.0
 *
 * @param string $output The raw combined output of a container run.
 * @return string The filtered output, trimmed.
 */
function filter_cli_noise( string $output ): string {

	// Drop ANSI colour codes first so the line filters see plain text.
	$plain = preg_replace( '/\x1b\[[0-9;]*m/', '', $output ) ?? $output;

	// Keep every line that is neither wp-env decoration nor a PHP runtime
	// deprecation/notice; bare `Warning:` lines are WP-CLI signal and stay.
	$kept = array_filter(
		explode( "\n", $plain ),
		static fn ( string $line ): bool => preg_match(
			'/^\s*(?:[ℹ✔✖⚠]|Command failed with exit code|(?:PHP )?Deprecated:|PHP (?:Notice|Warning):)/u',
			$line,
		) !== 1,
	);

	return trim( implode( "\n", $kept ) );

}

/**
 * Runs an arbitrary command inside the wp-env `cli` container.
 *
 * The output comes back with wp-env decoration and PHP deprecation noise
 * already filtered, and the container's exit code is preserved, so a test can
 * assert both the words and the scriptability of a command.
 *
 * The shared wp-env instance may be restarted under this suite's feet by a
 * concurrently running e2e session; during that window `wp-env run` fails
 * with `Environment not initialized` or with bare docker noise and no command
 * output at all. Those transient shapes are retried with a pause — a genuine
 * WP-CLI failure always carries an `Error:` line and is never retried.
 *
 * @since 0.3.0
 *
 * @param array<int,string> $arguments The full in-container command tokens.
 * @return array{output:string,exit_code:int} The filtered output and the exit code.
 */
function run_in_container( array $arguments ): array {

	// Guard first so a stopped environment fails with the remedy, not a
	// docker stack trace.
	ensure_wp_env();

	// Hand the tokens through wp-env's `run cli` (the `--` keeps wp-env's own
	// option parser away from the command's flags), riding out a concurrent
	// environment restart with bounded retries.
	$attempts = 6;
	$result   = [
		'output'    => '',
		'exit_code' => 1,
	];
	for ( $attempt = 1; $attempt <= $attempts; $attempt++ ) {
		$raw      = run_shell( [ 'npx', 'wp-env', 'run', 'cli', '--', ...$arguments ] );
		$filtered = filter_cli_noise( $raw['output'] );
		$result   = [
			'output'    => $filtered,
			'exit_code' => $raw['exit_code'],
		];
		if ( $raw['exit_code'] === 0 || ! is_transient_container_failure( $raw['output'], $filtered ) ) {
			break;
		}
		sleep( 5 );
	}

	return $result;

}

/**
 * Decides whether a failed container run is environment-transient.
 *
 * Transient means the wp-env stack itself was unavailable — being restarted
 * or torn down by another session — rather than the command failing on its
 * own merits. A genuine command failure always leaves real output (WP-CLI
 * prints `Error:`); an empty filtered stream or wp-env's not-initialized
 * message identifies the infrastructure window worth retrying.
 *
 * @since 0.3.0
 *
 * @param string $raw_output      The unfiltered output of the failed run.
 * @param string $filtered_output The output after decoration/noise filtering.
 * @return bool True when the failure should be retried.
 */
function is_transient_container_failure( string $raw_output, string $filtered_output ): bool {
	return $filtered_output === '' || str_contains( $raw_output, 'Environment not initialized' );
}

/**
 * Runs a WP-CLI command inside the wp-env `cli` container.
 *
 * The `wp` prefix is added here; pass only the subcommand tokens.
 *
 * @since 0.3.0
 *
 * @param array<int,string> $arguments The WP-CLI tokens after `wp`.
 * @return array{output:string,exit_code:int} The filtered output and the exit code.
 */
function run_cli( array $arguments ): array {
	return run_in_container( [ 'wp', ...$arguments ] );
}

/**
 * Copies a file from inside the container, given two host paths.
 *
 * An out-of-band write must be visible to the WordPress containers
 * immediately, but on macOS the bind mount caches directory attributes per
 * direction: a file written host-side appears in listings while the
 * directory's *mtime* stays stale inside the VM, which would silently defeat
 * any mtime-validated cache under test. Copying via the container makes the
 * mutation on the VM side, so the mtime bump is real for the code under test
 * — while still bypassing every plugin ingestion path.
 *
 * @since 0.3.0
 *
 * @param string $source_host_path The source file, as an absolute host path under the install.
 * @param string $target_host_path The target file, as an absolute host path under the install.
 * @throws \RuntimeException When the in-container copy fails.
 */
function copy_in_container( string $source_host_path, string $target_host_path ): void {

	// Map both endpoints to their in-container locations and copy there.
	$result = run_in_container(
		[ 'cp', to_container_path( $source_host_path ), to_container_path( $target_host_path ) ],
	);
	if ( $result['exit_code'] !== 0 ) {
		throw new \RuntimeException( "In-container copy failed: {$result['output']}" );
	}

}

/**
 * Resolves the host path of the wp-env WordPress installation.
 *
 * Tries `wp-env install-path` first and falls back to parsing the
 * `install path:` line of `wp-env status` (newer wp-env versions only expose
 * the path there). The result is cached per process — resolving it shells out,
 * and the path cannot change while the suite runs.
 *
 * @since 0.3.0
 *
 * @return string The absolute host path of the wp-env work directory.
 * @throws \RuntimeException When the install path cannot be resolved.
 */
function install_path(): string {

	// Serve the cached resolution; the environment is fixed for the process.
	static $path = null;
	if ( is_string( $path ) ) {
		return $path;
	}
	ensure_wp_env();

	// Ask wp-env directly; some versions print the path, others print nothing.
	$direct    = run_shell( [ 'npx', 'wp-env', 'install-path' ] );
	$candidate = trim( filter_cli_noise( $direct['output'] ) );
	if ( $candidate !== '' && is_dir( $candidate ) ) {
		$path = $candidate;
		return $path;
	}

	// Fall back to the status report, which always carries the path.
	$status = run_shell( [ 'npx', 'wp-env', 'status' ] );
	if ( preg_match( '/install path:\s*(\S+)/', filter_cli_noise( $status['output'] ), $matches ) === 1
		&& is_dir( $matches[1] ) ) {
		$path = $matches[1];
		return $path;
	}

	throw new \RuntimeException( 'Cannot resolve the wp-env install path from `install-path` or `status`.' );

}

/**
 * Returns the host path of the wp-env uploads directory.
 *
 * @since 0.3.0
 *
 * @return string The absolute host path of `wp-content/uploads`.
 */
function uploads_root(): string {
	return install_path() . '/WordPress/wp-content/uploads';
}

/**
 * Returns the host path of the plugin's collections root.
 *
 * @since 0.3.0
 *
 * @return string The absolute host path of `uploads/kntnt-photo-drop`.
 */
function collections_root(): string {
	return uploads_root() . '/kntnt-photo-drop';
}

/**
 * Returns the host path of one collection's root directory.
 *
 * @since 0.3.0
 *
 * @param string $slug The collection slug.
 * @return string The absolute host path of the collection root.
 */
function collection_path( string $slug ): string {
	return collections_root() . '/' . $slug;
}

/**
 * Maps a host path under the WordPress install to its in-container path.
 *
 * The wp-env `cli` container mounts the install's `WordPress` directory at
 * `/var/www/html`, so a fixture written on the host below that directory is
 * addressable inside the container by swapping the prefix.
 *
 * @since 0.3.0
 *
 * @param string $host_path An absolute host path below the WordPress install.
 * @return string The equivalent absolute path inside the container.
 */
function to_container_path( string $host_path ): string {
	return str_replace( install_path() . '/WordPress', '/var/www/html', $host_path );
}

/**
 * Returns a fresh, unique collection slug for this suite.
 *
 * Slugs are prefixed `it-` so the concurrently running e2e suite (and any
 * human-made collection such as `smoke`) can never collide with one of ours,
 * and so leftovers from a crashed run are recognisable.
 *
 * @since 0.3.0
 *
 * @return string A unique slug matching the plugin's slug grammar.
 */
function unique_slug(): string {
	return 'it-' . bin2hex( random_bytes( 4 ) );
}

/**
 * Creates a collection through the CLI, failing loudly on error.
 *
 * Seeding is setup, not subject-under-test, so a failure here throws with the
 * CLI's own words instead of letting later assertions fail confusingly.
 *
 * @since 0.3.0
 *
 * @param string      $slug      The collection slug.
 * @param string      $max_width The contract ceiling in pixels, or "none".
 * @param int         $quality   The WebP quality (0–100).
 * @param string|null $name      Optional display name; null humanises the slug.
 * @throws \RuntimeException When the CLI refuses to create the collection.
 */
function create_collection( string $slug, string $max_width = '1200', int $quality = 70, ?string $name = null ): void {

	// Assemble the create command, adding --name only when the caller set one.
	$arguments = [
		'kntnt-photo-drop',
		'collection',
		'create',
		$slug,
		"--max-width={$max_width}",
		"--quality={$quality}",
	];
	if ( $name !== null ) {
		$arguments[] = "--name={$name}";
	}

	// Run it and surface any failure as a hard setup error.
	$result = run_cli( $arguments );
	if ( $result['exit_code'] !== 0 ) {
		throw new \RuntimeException( "Cannot seed collection '{$slug}': {$result['output']}" );
	}

}

/**
 * Deletes a collection through the CLI, best-effort.
 *
 * Used by teardown, where the collection may already be gone (a delete test)
 * or half-broken (a failed test); either way cleanup must not throw.
 *
 * @since 0.3.0
 *
 * @param string $slug The collection slug.
 */
function delete_collection( string $slug ): void {
	run_cli( [ 'kntnt-photo-drop', 'collection', 'delete', $slug, '--yes' ] );
}

/**
 * Imports source images into a collection through the CLI.
 *
 * @since 0.3.0
 *
 * @param string            $slug    The target collection slug.
 * @param array<int,string> $sources In-container absolute source paths.
 * @return array{output:string,exit_code:int} The filtered output and the exit code.
 */
function import_images( string $slug, array $sources ): array {
	return run_cli( [ 'kntnt-photo-drop', 'image', 'import', $slug, ...$sources ] );
}

/**
 * Runs the collection doctor through the CLI.
 *
 * @since 0.3.0
 *
 * @param string            $slug  The collection slug.
 * @param array<int,string> $flags Extra flags such as `--repair` or `--force`.
 * @return array{output:string,exit_code:int} The filtered output and the exit code.
 */
function run_doctor( string $slug, array $flags = [] ): array {
	return run_cli( [ 'kntnt-photo-drop', 'collection', 'doctor', $slug, ...$flags ] );
}

/**
 * Reads and decodes a collection's `collection.json` from the host filesystem.
 *
 * @since 0.3.0
 *
 * @param string $slug The collection slug.
 * @return array<string,mixed>|null The decoded descriptor, or null when unreadable.
 */
function read_descriptor( string $slug ): ?array {

	// Read straight through the bind mount; a missing file is a soft null the
	// test asserts on.
	$file = collection_path( $slug ) . '/collection.json';
	$raw  = is_file( $file ) ? file_get_contents( $file ) : false;
	if ( $raw === false ) {
		return null;
	}

	$decoded = json_decode( $raw, true );

	return is_array( $decoded ) ? $decoded : null;

}

/**
 * Reads and decodes a content folder's `index.json` from the host filesystem.
 *
 * @since 0.3.0
 *
 * @param string $slug   The collection slug.
 * @param string $folder The content folder relative to the root, '' for the root itself.
 * @return array<string,mixed>|null The decoded index, or null when unreadable.
 */
function read_index( string $slug, string $folder = '' ): ?array {

	// The index hides inside the folder's .kntnt-thumbnails directory.
	$base = collection_path( $slug ) . ( $folder === '' ? '' : "/{$folder}" );
	$file = $base . '/.kntnt-thumbnails/index.json';
	$raw  = is_file( $file ) ? file_get_contents( $file ) : false;
	if ( $raw === false ) {
		return null;
	}

	$decoded = json_decode( $raw, true );

	return is_array( $decoded ) ? $decoded : null;

}

/**
 * Creates a unique fixture directory under the uploads bind mount.
 *
 * Fixtures must be readable inside the container, so they live below the
 * uploads directory (mounted on both sides) rather than in the host's
 * temp dir. The caller removes it with `remove_tree()` when done.
 *
 * @since 0.3.0
 *
 * @return string The absolute host path of the new directory.
 * @throws \RuntimeException When the directory cannot be created.
 */
function make_fixture_dir(): string {

	// A unique name keeps concurrent suites and crashed leftovers apart.
	$dir = uploads_root() . '/it-fixtures-' . bin2hex( random_bytes( 4 ) );
	if ( ! mkdir( $dir, 0755, true ) ) {
		throw new \RuntimeException( "Cannot create the fixture directory {$dir}." );
	}

	return $dir;

}

/**
 * Removes a directory tree (or single file), best-effort.
 *
 * Teardown code must not throw, so every failure is silently absorbed; a
 * leftover tree is visible by its `it-` prefix and harmless.
 *
 * @since 0.3.0
 *
 * @param string $path The file or directory to remove.
 */
function remove_tree( string $path ): void {

	// A plain file (or symlink) needs no recursion.
	if ( ! is_dir( $path ) ) {
		@unlink( $path );
		return;
	}

	// Depth-first removal of the directory's children, then the directory.
	$entries = scandir( $path );
	foreach ( $entries === false ? [] : $entries as $entry ) {
		if ( $entry === '.' || $entry === '..' ) {
			continue;
		}
		remove_tree( "{$path}/{$entry}" );
	}
	@rmdir( $path );

}

/**
 * Writes a real JPEG fixture with GD.
 *
 * The image carries vertical colour bands rather than a flat fill so the
 * encoder produces a realistic, non-degenerate file.
 *
 * @since 0.3.0
 *
 * @param string $path   The absolute target path.
 * @param int    $width  The pixel width.
 * @param int    $height The pixel height.
 * @throws \RuntimeException When GD cannot produce the file.
 */
function write_jpeg( string $path, int $width = 1600, int $height = 900 ): void {
	$image = paint_fixture( $width, $height );
	if ( ! imagejpeg( $image, $path, 85 ) ) {
		throw new \RuntimeException( "Cannot write the JPEG fixture {$path}." );
	}
}

/**
 * Writes a real WebP fixture with GD.
 *
 * @since 0.3.0
 *
 * @param string $path   The absolute target path.
 * @param int    $width  The pixel width.
 * @param int    $height The pixel height.
 * @throws \RuntimeException When GD cannot produce the file.
 */
function write_webp( string $path, int $width = 800, int $height = 600 ): void {
	$image = paint_fixture( $width, $height );
	if ( ! imagewebp( $image, $path, 80 ) ) {
		throw new \RuntimeException( "Cannot write the WebP fixture {$path}." );
	}
}

/**
 * Writes a file that claims to be an image but is not decodable.
 *
 * @since 0.3.0
 *
 * @param string $path The absolute target path.
 */
function write_corrupt_image( string $path ): void {
	file_put_contents( $path, 'this is not an image at all' );
}

/**
 * Paints the shared fixture canvas: vertical colour bands.
 *
 * @since 0.3.0
 *
 * @param int $width  The pixel width.
 * @param int $height The pixel height.
 * @return \GdImage The painted canvas.
 * @throws \RuntimeException When the canvas cannot be allocated.
 */
function paint_fixture( int $width, int $height ): \GdImage {

	// Allocate the canvas; a failure here means GD itself is unusable.
	$image = imagecreatetruecolor( $width, $height );
	if ( $image === false ) {
		throw new \RuntimeException( 'GD cannot allocate the fixture canvas.' );
	}

	// Paint eight coloured bands so the encoded file has real structure.
	$bands = 8;
	$step  = (int) ceil( $width / $bands );
	for ( $band = 0; $band < $bands; $band++ ) {
		$color = imagecolorallocate( $image, 30 * $band, 255 - 30 * $band, 60 + 20 * $band );
		imagefilledrectangle( $image, $band * $step, 0, ( $band + 1 ) * $step - 1, $height - 1, (int) $color );
	}

	return $image;

}

/**
 * Logs a user in over HTTP and returns a cookie jar plus a fresh REST nonce.
 *
 * Mirrors a real browser session: the WordPress test cookie is pre-set so
 * `wp-login.php` accepts the POST, the session cookies land in a private jar,
 * and `admin-ajax.php?action=rest-nonce` mints a `wp_rest` nonce bound to that
 * session. The jar file lives in the host temp dir and is process-private.
 *
 * @since 0.3.0
 *
 * @param string $username The login name.
 * @param string $password The password.
 * @return array{jar:string,nonce:string} The cookie-jar path and the nonce.
 * @throws \RuntimeException When the login or the nonce mint fails.
 */
function login_session( string $username, string $password ): array {

	// POST the login form with the pre-set test cookie, capturing the session
	// cookies into a fresh jar.
	$jar    = (string) tempnam( sys_get_temp_dir(), 'it-jar-' );
	$handle = curl_init( SITE_URL . '/wp-login.php' );
	curl_setopt_array(
		$handle,
		[
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_POST           => true,
			CURLOPT_POSTFIELDS     => http_build_query( [
				'log'        => $username,
				'pwd'        => $password,
				'testcookie' => '1',
			] ),
			CURLOPT_COOKIE         => 'wordpress_test_cookie=WP Cookie check',
			CURLOPT_COOKIEJAR      => $jar,
			CURLOPT_COOKIEFILE     => $jar,
			CURLOPT_TIMEOUT        => 15,
		],
	);
	curl_exec( $handle );
	unset( $handle ); // curl flushes the cookie jar only when the handle is destroyed.

	// A successful login leaves a logged-in cookie in the jar; anything else
	// means the credentials or the site are broken — a hard setup error.
	$cookies = (string) file_get_contents( $jar );
	if ( ! str_contains( $cookies, 'wordpress_logged_in' ) ) {
		throw new \RuntimeException( "Login as '{$username}' failed: no logged-in cookie was set." );
	}

	// Mint a fresh `wp_rest` nonce bound to the session in the jar.
	$nonce = rest_nonce( $jar );

	return [
		'jar'   => $jar,
		'nonce' => $nonce,
	];

}

/**
 * Mints a fresh `wp_rest` nonce for an existing cookie session.
 *
 * @since 0.3.0
 *
 * @param string $jar The cookie-jar path of a logged-in session.
 * @return string The ten-character nonce.
 * @throws \RuntimeException When the endpoint does not return a nonce.
 */
function rest_nonce( string $jar ): string {

	// Ask the core ajax endpoint for a nonce tied to the jar's session.
	$handle = curl_init( SITE_URL . '/wp-admin/admin-ajax.php?action=rest-nonce' );
	curl_setopt_array(
		$handle,
		[
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_COOKIEFILE     => $jar,
			CURLOPT_COOKIEJAR      => $jar,
			CURLOPT_TIMEOUT        => 15,
		],
	);
	$response = curl_exec( $handle );
	unset( $handle ); // curl flushes the cookie jar only when the handle is destroyed.

	// The endpoint answers with the bare nonce as plain text; anything else
	// (a `0`, an HTML error page) is a failed mint.
	$nonce = is_string( $response ) ? trim( $response ) : '';
	if ( preg_match( '/^[a-f0-9]{10}$/', $nonce ) !== 1 ) {
		throw new \RuntimeException( "The rest-nonce endpoint returned no usable nonce: '{$nonce}'." );
	}

	return $nonce;

}

/**
 * Returns the cached admin session (cookie jar plus nonce).
 *
 * Logging in costs two HTTP round-trips, and one admin session serves the
 * whole process, so it is created lazily and cached.
 *
 * @since 0.3.0
 *
 * @return array{jar:string,nonce:string} The cookie-jar path and the nonce.
 */
function admin_session(): array {

	// Log in once per process; the nonce stays valid far longer than a run.
	static $session = null;
	if ( $session === null ) {
		$session = login_session( ADMIN_USER, ADMIN_PASSWORD );
	}

	return $session;

}

/**
 * POSTs one file to the plugin's REST upload endpoint as multipart form data.
 *
 * The cookie jar and the nonce are independently optional so a test can model
 * every authentication shape: both present (the happy path), no nonce (CSRF
 * rejection), or a low-privilege session (capability rejection).
 *
 * @since 0.3.0
 *
 * @param string      $slug          The target collection slug.
 * @param string      $file_path     The absolute host path of the file to upload.
 * @param string      $relative_path The `relativePath` form field, verbatim.
 * @param string|null $jar           A cookie-jar path, or null for an anonymous request.
 * @param string|null $nonce         A `wp_rest` nonce, or null to omit the header.
 * @return array{status:int,body:array<string,mixed>|null} The HTTP status and the decoded JSON body.
 */
function rest_upload( string $slug, string $file_path, string $relative_path, ?string $jar, ?string $nonce ): array {

	// Build the multipart POST against the collection's upload route.
	$url    = SITE_URL . '/wp-json/kntnt-photo-drop/v1/collections/' . rawurlencode( $slug ) . '/images';
	$handle = curl_init( $url );
	curl_setopt_array(
		$handle,
		[
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_POST           => true,
			CURLOPT_POSTFIELDS     => [
				'file'         => new \CURLFile( $file_path, 'application/octet-stream', basename( $file_path ) ),
				'relativePath' => $relative_path,
			],
			CURLOPT_TIMEOUT        => 30,
		],
	);

	// Attach the session and the nonce only when the caller supplied them.
	if ( $jar !== null ) {
		curl_setopt( $handle, CURLOPT_COOKIEFILE, $jar );
	}
	if ( $nonce !== null ) {
		curl_setopt( $handle, CURLOPT_HTTPHEADER, [ "X-WP-Nonce: {$nonce}" ] );
	}

	// Fire and decode; a non-JSON body decodes to null, which the test treats
	// as its own failure signal.
	$response = curl_exec( $handle );
	$status   = (int) curl_getinfo( $handle, CURLINFO_RESPONSE_CODE );
	$body     = is_string( $response ) ? json_decode( $response, true ) : null;

	return [
		'status' => $status,
		'body'   => is_array( $body ) ? $body : null,
	];

}

/**
 * Fetches a URL anonymously and returns the status and body.
 *
 * @since 0.3.0
 *
 * @param string $url The absolute URL to fetch.
 * @return array{status:int,body:string} The HTTP status and the response body.
 */
function http_get( string $url ): array {

	$handle = curl_init( $url );
	curl_setopt_array( $handle, [
		CURLOPT_RETURNTRANSFER => true,
		CURLOPT_FOLLOWLOCATION => true,
		CURLOPT_TIMEOUT        => 30,
	] );
	$response = curl_exec( $handle );
	$status   = (int) curl_getinfo( $handle, CURLINFO_RESPONSE_CODE );

	return [
		'status' => $status,
		'body'   => is_string( $response ) ? $response : '',
	];

}

/**
 * Publishes a page carrying the gallery block for one collection.
 *
 * @since 0.3.0
 *
 * @param string $collection_slug The collection the gallery block selects.
 * @return array{id:int,url:string} The page id and its permalink.
 * @throws \RuntimeException When the page cannot be created or resolved.
 */
function create_gallery_page( string $collection_slug ): array {

	// Publish a page whose content is exactly one gallery block bound to the
	// collection; --porcelain reduces the output to the new post id.
	$content = '<!-- wp:kntnt-photo-drop/gallery {"collection":"' . $collection_slug . '"} /-->';
	$created = run_cli(
		[
			'post',
			'create',
			'--post_type=page',
			'--post_status=publish',
			"--post_title=Integration gallery {$collection_slug}",
			"--post_content={$content}",
			'--porcelain',
		],
	);
	if ( $created['exit_code'] !== 0 || preg_match( '/(\d+)\s*$/', $created['output'], $id_match ) !== 1 ) {
		throw new \RuntimeException( "Cannot create the gallery page: {$created['output']}" );
	}
	$id = (int) $id_match[1];

	// Resolve the permalink the suite curls; pretty permalinks are active in
	// the wp-env instance, so this is a real path URL. The trailing newline in
	// the eval keeps wp-env's own status line off the URL's line, and the
	// character class keeps any residual decoration out of the match.
	$permalink = run_cli( [ 'eval', "echo get_permalink( {$id} ), \"\\n\";" ] );
	if ( preg_match( '~https?://[A-Za-z0-9.:/_%-]+~', $permalink['output'], $url_match ) !== 1 ) {
		throw new \RuntimeException( "Cannot resolve the permalink of page {$id}: {$permalink['output']}" );
	}

	return [
		'id'  => $id,
		'url' => $url_match[0],
	];

}

/**
 * Deletes a page, best-effort.
 *
 * @since 0.3.0
 *
 * @param int $page_id The page id to remove permanently.
 */
function delete_page( int $page_id ): void {
	run_cli( [ 'post', 'delete', (string) $page_id, '--force' ] );
}

/**
 * Creates a subscriber user (no `upload_files` capability) for gate tests.
 *
 * @since 0.3.0
 *
 * @param string $username The login name.
 * @param string $password The password.
 * @throws \RuntimeException When the user cannot be created.
 */
function create_subscriber( string $username, string $password ): void {

	// A subscriber holds `read` only, which is exactly the wrong side of the
	// upload_files gate the REST tests probe.
	$result = run_cli(
		[ 'user', 'create', $username, "{$username}@example.com", '--role=subscriber', "--user_pass={$password}" ],
	);
	if ( $result['exit_code'] !== 0 ) {
		throw new \RuntimeException( "Cannot create the subscriber '{$username}': {$result['output']}" );
	}

}

/**
 * Deletes a user, best-effort.
 *
 * @since 0.3.0
 *
 * @param string $username The login name to remove.
 */
function delete_user( string $username ): void {
	run_cli( [ 'user', 'delete', $username, '--yes' ] );
}

// Guard the whole suite at load time: every integration test needs the live
// wp-env instance, so an unreachable site fails the run immediately with the
// one actionable remedy instead of erroring test by test.
ensure_wp_env();
