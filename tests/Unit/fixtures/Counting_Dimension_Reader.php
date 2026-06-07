<?php
/**
 * Test double: a dimension reader that counts how often it is asked to measure.
 *
 * Injected into Index_Store so a test can assert the cache-hit path measures no
 * image (`$calls === 0`) and a rebuild measures each main image exactly once. It
 * reads real dimensions through `getimagesize()` so recorded sizes match the
 * on-disk fixtures, while the call counter proves *when* a read happened.
 *
 * Loaded via tests/Pest.php and kept out of the PSR-4 autoload path, alongside
 * the other unit-test fixtures.
 *
 * @package Kntnt\Photo_Drop
 * @since   0.1.0
 */

declare( strict_types = 1 );

use Kntnt\Photo_Drop\Storage\Dimension_Reader;

if ( ! class_exists( 'Counting_Dimension_Reader' ) ) {
	/**
	 * Counts dimension reads while returning the fixture's real dimensions.
	 *
	 * @since 0.1.0
	 */
	final class Counting_Dimension_Reader implements Dimension_Reader {

		/**
		 * The number of times dimensions() has been invoked.
		 *
		 * @since 0.1.0
		 * @var int
		 */
		public int $calls = 0;

		/**
		 * Records a call and returns the file's real dimensions.
		 *
		 * @since 0.1.0
		 *
		 * @param string $file_path Absolute path to the image file.
		 * @return array{0:int,1:int}|null `[ $width, $height ]`, or null when unreadable.
		 */
		public function dimensions( string $file_path ): ?array {

			// Count the read, then measure the real fixture so stored dimensions
			// are faithful.
			++$this->calls;
			$size = getimagesize( $file_path );
			if ( ! is_array( $size ) ) {
				return null;
			}

			return [ (int) $size[0], (int) $size[1] ];

		}

	}
}
