<?php
/**
 * Sanitise-and-confine helper for attacker-controlled relative paths.
 *
 * The Drop Zone REST endpoint and `image import` both accept a caller-supplied
 * relative path and recreate sub-directories under a collection root. This
 * class is the trust boundary between that hostile input and the filesystem:
 * given a relative path it returns a safe absolute path strictly inside the
 * root, or rejects the input. The confinement check is on the *resolved*
 * (realpath) path, so a symlink whose target escapes the root is rejected even
 * when the lexical path looks benign.
 *
 * @package Kntnt\Photo_Drop
 * @since   0.1.0
 */

declare( strict_types = 1 );

namespace Kntnt\Photo_Drop\Collection;

/**
 * Confines a relative path to a fixed collection root.
 *
 * Constructed with the collection root once, then queried many times — one
 * guard per collection. The external interface is a single deep method,
 * `resolve()`, that hides percent-decoding, character-class rejection,
 * lexical traversal removal, and realpath confinement behind a `string|null`
 * return. A `null` result is a rejection and means the caller must not write;
 * a non-null result is an absolute path guaranteed to sit inside the root.
 *
 * The guard does not create directories or files; it only computes and vets
 * the target path. Callers own the actual filesystem mutation.
 *
 * @since 0.1.0
 */
final class Path_Guard {

	/**
	 * The canonical absolute path of the collection root.
	 *
	 * Resolved with realpath() at construction so every confinement comparison
	 * is against a canonical, symlink-free anchor.
	 *
	 * @since 0.1.0
	 * @var string
	 */
	private readonly string $root;

	/**
	 * Creates a guard anchored at a collection root.
	 *
	 * The root must be an existing directory; its realpath is taken once and
	 * cached. An invalid root is itself a programming error rather than
	 * hostile input — it is the plugin, not the attacker, that supplies the
	 * root — so the constructor throws rather than returning a sentinel.
	 *
	 * @since 0.1.0
	 *
	 * @param string $root Absolute path to an existing collection root directory.
	 * @throws \InvalidArgumentException When the root does not resolve to a directory.
	 */
	public function __construct( string $root ) {

		// Canonicalise the root once so confinement compares like with like.
		$resolved_root = realpath( $root );
		if ( $resolved_root === false || ! is_dir( $resolved_root ) ) {
			throw new \InvalidArgumentException( 'Path_Guard requires an existing root directory.' );
		}

		$this->root = $resolved_root;

	}

	/**
	 * Resolves a caller-supplied relative path to a safe absolute path.
	 *
	 * Returns an absolute path strictly inside the root (or the root itself for
	 * an empty or `.` input), or `null` when the input is hostile or escapes
	 * the root. The target need not exist yet: directory creation for uploads
	 * happens after this returns. To stay safe against symlink escape for the
	 * not-yet-existing tail, confinement is checked against the realpath of the
	 * deepest ancestor that *does* exist, then the remaining segments are
	 * appended lexically.
	 *
	 * @since 0.1.0
	 *
	 * @param string $relative_path The untrusted relative path from the caller.
	 * @return string|null Absolute path inside the root, or null when rejected.
	 */
	public function resolve( string $relative_path ): ?string {

		// Percent-decode first so encoded traversal (`%2e%2e%2f`), double-encoded
		// sequences, and overlong forms are unmasked before any check runs. A
		// single pass over a fully decoded string would still hide a
		// double-encoded payload, so decode repeatedly until the string is
		// stable (a fixed point), capping the loop to defeat pathological input.
		$decoded = $this->fully_decode( $relative_path );
		if ( $decoded === null ) {
			return null;
		}

		// Reject NUL bytes and control characters outright: a NUL truncates the
		// path in the underlying C calls, and control characters have no place
		// in a legitimate relative path.
		if ( preg_match( '/[\x00-\x1f\x7f]/', $decoded ) === 1 ) {
			return null;
		}

		// Reject anything carrying a URI scheme (`file://`, `php://`, …); a
		// legitimate relative path never contains a scheme separator.
		if ( preg_match( '#^[a-zA-Z][a-zA-Z0-9+.\-]*://#', $decoded ) === 1 ) {
			return null;
		}

		// Reject backslashes wholesale. They are the Windows separator and the
		// UNC prefix (`\\server\share`); on the case-sensitive Linux target a
		// backslash is never a directory separator, so its only role here would
		// be to smuggle a separator past a forward-slash-only check.
		if ( str_contains( $decoded, '\\' ) ) {
			return null;
		}

		// Reject absolute paths: a leading slash means "from the filesystem
		// root", which by definition is not relative to the collection root.
		if ( str_starts_with( $decoded, '/' ) ) {
			return null;
		}

		// Split on forward slashes and drop empty and single-dot segments
		// (collapsing `a//b` and `a/./b`); reject the moment a parent reference
		// appears, since no `..` is ever legitimate in a confined relative path.
		$safe_segments = [];
		foreach ( explode( '/', $decoded ) as $segment ) {
			if ( $segment === '' || $segment === '.' ) {
				continue;
			}
			if ( $segment === '..' ) {
				return null;
			}
			$safe_segments[] = $segment;
		}

		// An empty result (empty input, `.`, `./`, or only redundant separators)
		// resolves to the root itself.
		if ( $safe_segments === [] ) {
			return $this->root;
		}

		// Confine against the deepest existing ancestor. Walk the segments,
		// extending the existing-prefix realpath while the next level exists; as
		// soon as a level is missing, the rest is a not-yet-created tail that is
		// appended lexically. At every existing step the realpath must still be
		// inside the root, which catches a symlink that redirects outside.
		$resolved = $this->root;
		$tail     = [];
		foreach ( $safe_segments as $segment ) {

			// Once we have left the existing tree, every remaining segment is
			// part of the tail to be created; no realpath exists to check yet.
			if ( $tail !== [] ) {
				$tail[] = $segment;
				continue;
			}

			// Probe the next level. If it exists, canonicalise it and re-check
			// confinement; if it does not, start the lexical tail here.
			$candidate      = $resolved . '/' . $segment;
			$candidate_real = realpath( $candidate );
			if ( $candidate_real === false ) {
				$tail[] = $segment;
				continue;
			}
			if ( ! $this->is_inside_root( $candidate_real ) ) {
				return null;
			}
			$resolved = $candidate_real;

		}

		// Assemble the final absolute path from the confined existing prefix and
		// the lexical tail, and confine once more in case the existing prefix
		// resolved to the root itself with no tail.
		$final = $tail === [] ? $resolved : $resolved . '/' . implode( '/', $tail );
		if ( ! $this->is_inside_root( $final ) ) {
			return null;
		}

		return $final;

	}

	/**
	 * Returns the collection root this guard is anchored to.
	 *
	 * Exposed so callers that need the canonical root (for logging or for a
	 * relative display path) read the same value the guard confines against.
	 *
	 * @since 0.1.0
	 *
	 * @return string The canonical absolute root path.
	 */
	public function get_root(): string {
		return $this->root;
	}

	/**
	 * Repeatedly percent-decodes until the string stops changing.
	 *
	 * A single `rawurldecode()` leaves a double-encoded payload (`%252e`)
	 * still encoded, so decoding is iterated to a fixed point. The loop is
	 * capped to bound the work for adversarial input; exceeding the cap is
	 * treated as hostile and rejected.
	 *
	 * @since 0.1.0
	 *
	 * @param string $value The raw, possibly multiply-encoded input.
	 * @return string|null The fully decoded string, or null when the cap is hit.
	 */
	private function fully_decode( string $value ): ?string {

		// Decode up to a small fixed number of times; legitimate input is
		// decoded in one pass, so anything still shrinking after several passes
		// is an attack and is rejected.
		$current = $value;
		for ( $pass = 0; $pass < 8; $pass++ ) {
			$next = rawurldecode( $current );
			if ( $next === $current ) {
				return $current;
			}
			$current = $next;
		}

		return null;

	}

	/**
	 * Reports whether an absolute path lies at or inside the root.
	 *
	 * The path is inside when it equals the root exactly, or begins with the
	 * root followed by a separator. Comparing against `root + '/'` prevents a
	 * sibling whose name merely shares the root as a prefix (`/srv/rootkit`
	 * versus `/srv/root`) from being mistaken for a descendant.
	 *
	 * @since 0.1.0
	 *
	 * @param string $path The absolute path to test.
	 * @return bool True when the path is the root or a descendant of it.
	 */
	private function is_inside_root( string $path ): bool {
		return $path === $this->root || str_starts_with( $path, $this->root . '/' );
	}

}
