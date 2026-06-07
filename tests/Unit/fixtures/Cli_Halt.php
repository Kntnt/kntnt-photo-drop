<?php
/**
 * Catchable stand-in for WP-CLI's halt-on-error / declined-confirm behaviour.
 *
 * The real `WP_CLI::error()` and a declined `WP_CLI::confirm()` terminate the
 * process; the unit-test double throws this exception instead so a test can
 * catch it, assert the recorded message, and verify that nothing the command
 * would have done *after* the halt actually happened.
 *
 * @package Kntnt\Photo_Drop
 * @since   0.2.0
 */

declare( strict_types = 1 );

namespace Tests\Unit\Fixtures;

use RuntimeException;

/**
 * Thrown by the WP_CLI test double to unwind a command on error or decline.
 *
 * @since 0.2.0
 */
final class Cli_Halt extends RuntimeException {}
