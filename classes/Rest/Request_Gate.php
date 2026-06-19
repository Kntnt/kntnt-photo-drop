<?php
/**
 * Shared request-reading and capability-resolution helpers for REST controllers.
 *
 * @package Kntnt\Photo_Drop
 * @since   0.15.0
 */

declare( strict_types = 1 );

namespace Kntnt\Photo_Drop\Rest;

/**
 * Leaf helpers shared by every collection REST controller.
 *
 * Each write controller reads the same two request fields the same way — the
 * `wp_rest` nonce (header first, parameter fallback) and the collection slug —
 * and resolves its gating capability through a filter with the identical
 * hardening rule. Centralising them here keeps that rule in one place: a filter
 * that returns a non-string or empty value is a misuse and falls back to the
 * default rather than silently disabling the gate. The trait holds no state; it
 * only factors out duplicated leaf logic, so the controllers' deep external
 * interface (their two callbacks) is unchanged.
 *
 * @since 0.15.0
 */
trait Request_Gate {

	/**
	 * Reads the `wp_rest` nonce from the request, header first.
	 *
	 * Prefers the canonical `X-WP-Nonce` header that `wp.apiFetch` and the
	 * plugin's fetch paths send, falling back to a `_wpnonce` parameter. The
	 * value is sanitised before it reaches `wp_verify_nonce()`.
	 *
	 * @since 0.15.0
	 *
	 * @param \WP_REST_Request $request The incoming REST request.
	 * @return string The nonce string, or '' when none was supplied.
	 */
	private function read_nonce( \WP_REST_Request $request ): string {

		// Take the header value first, then the parameter fallback; sanitise
		// either way so only a clean token string reaches the verifier.
		$header = $request->get_header( 'X-WP-Nonce' );
		$raw    = is_string( $header ) && $header !== '' ? $header : $request->get_param( '_wpnonce' );
		return is_string( $raw ) ? sanitize_text_field( $raw ) : '';

	}

	/**
	 * Reads and sanitises the addressed collection slug.
	 *
	 * The slug comes from the matched route segment; it is sanitised here as
	 * defence in depth, though the `Repository` re-validates it strictly before
	 * any filesystem access.
	 *
	 * @since 0.15.0
	 *
	 * @param \WP_REST_Request $request The incoming REST request.
	 * @return string The sanitised slug, or '' when absent.
	 */
	private function read_slug( \WP_REST_Request $request ): string {
		$raw = $request->get_param( 'slug' );
		return is_string( $raw ) ? sanitize_text_field( $raw ) : '';
	}

	/**
	 * Resolves a gating capability through its filter, hardening the result.
	 *
	 * Applies the named `kntnt_photo_drop_*_capability` filter to the default
	 * and rejects any non-string or empty return back to the default, so a buggy
	 * filter can never open the gate.
	 *
	 * @since 0.15.0
	 *
	 * @param non-empty-string $filter             The capability filter name.
	 * @param string           $default_capability The default capability when the filter is unused or misused.
	 * @return string The capability string to check.
	 */
	private static function resolve_capability( string $filter, string $default_capability ): string {

		// Apply the filter and harden its return: a non-string or empty result
		// is rejected back to the default, so a buggy filter can never open the
		// gate.
		$filtered = apply_filters( $filter, $default_capability );
		return is_string( $filtered ) && $filtered !== '' ? $filtered : $default_capability;

	}

}
