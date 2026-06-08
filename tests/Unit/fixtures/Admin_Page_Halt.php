<?php
/**
 * Catchable stand-in for WordPress's `wp_die()` in admin-page unit tests.
 *
 * The real `wp_die()` terminates the request; the admin-page tests stub it to
 * throw this exception instead, so a capability or nonce failure can be caught
 * and asserted, and the test can verify that nothing the handler would have done
 * after the halt actually happened.
 *
 * @package Kntnt\Photo_Drop
 * @since   0.5.0
 */

declare( strict_types = 1 );

namespace Tests\Unit\Fixtures;

use RuntimeException;

/**
 * Thrown by the admin-page tests' `wp_die()` stub to unwind a refused request.
 *
 * @since 0.5.0
 */
final class Admin_Page_Halt extends RuntimeException {}
