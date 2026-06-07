<?php
/**
 * PSR-4 autoloader bootstrap.
 *
 * Delegates all class loading to the Composer-generated autoloader so that
 * the main plugin file stays thin and the class map benefits from
 * --optimize-autoloader.
 *
 * @package Kntnt\Photo_Drop
 * @since   0.1.0
 */

declare( strict_types = 1 );

// Hand off to Composer's optimised class map.
require_once __DIR__ . '/vendor/autoload.php';
