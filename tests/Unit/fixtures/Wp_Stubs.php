<?php
/**
 * Minimal WordPress class stand-ins for unit tests.
 *
 * The real WordPress classes live in core source files that are unavailable in
 * the unit-test runtime. The stubs below define the bare surface the tests
 * need so that type hints resolve and tests run without a WordPress install.
 *
 * Loaded via tests/Pest.php — kept out of the PSR-4 autoload path so PHPStan
 * (which already has the real classes via its WordPress stubs) never sees them.
 *
 * @package Kntnt\Photo_Drop
 * @since   0.1.0
 */

declare( strict_types = 1 );

// phpcs:disable WordPress.Files.FileName.InvalidClassFileName -- fixtures hold several minimal stubs.

if ( ! class_exists( 'WP_Block_Editor_Context' ) ) {
	/**
	 * Minimal stand-in for WordPress's WP_Block_Editor_Context class.
	 *
	 * Defined only when the real class is not loaded (i.e. during unit tests).
	 * The block-category callback type-hints against it but reads nothing from
	 * it, so an empty shell suffices.
	 *
	 * @since 0.1.0
	 */
	class WP_Block_Editor_Context {}
}

if ( ! class_exists( 'WP_Block' ) ) {
	/**
	 * Minimal stand-in for WordPress's WP_Block class.
	 *
	 * The dynamic-block render handlers type-hint their third argument against it
	 * but read nothing from it, so an empty shell suffices for the unit tests.
	 *
	 * @since 0.5.0
	 */
	class WP_Block {}
}

if ( ! class_exists( 'WP_Error' ) ) {
	/**
	 * Minimal stand-in for WordPress's WP_Error class.
	 *
	 * The REST upload controller returns these from its permission and handler
	 * paths; the tests read back the code, message, and the `status` data the
	 * controller attaches. Only the single-error surface the controller uses is
	 * modelled — enough to assert the HTTP status and error code.
	 *
	 * @since 0.4.0
	 */
	class WP_Error {

		/**
		 * The error code passed at construction.
		 *
		 * @var string
		 */
		public string $code;

		/**
		 * The human-readable error message.
		 *
		 * @var string
		 */
		public string $message;

		/**
		 * The arbitrary error data (carries the `status` key the controller sets).
		 *
		 * @var mixed
		 */
		public mixed $data;

		/**
		 * Records the error code, message, and data.
		 *
		 * @param string $code    The machine error code.
		 * @param string $message The human-readable message.
		 * @param mixed  $data    The error data, typically `[ 'status' => int ]`.
		 */
		public function __construct( string $code = '', string $message = '', mixed $data = '' ) {
			$this->code    = $code;
			$this->message = $message;
			$this->data    = $data;
		}

		/**
		 * Returns the error code.
		 *
		 * @return string
		 */
		public function get_error_code(): string {
			return $this->code;
		}

		/**
		 * Returns the error message.
		 *
		 * @return string
		 */
		public function get_error_message(): string {
			return $this->message;
		}

		/**
		 * Returns the error data.
		 *
		 * @return mixed
		 */
		public function get_error_data(): mixed {
			return $this->data;
		}
	}
}

if ( ! class_exists( 'WP_REST_Response' ) ) {
	/**
	 * Minimal stand-in for WordPress's WP_REST_Response class.
	 *
	 * The controller returns one of these on a per-file outcome; the tests read
	 * the body and status back. Only the data/status surface is modelled.
	 *
	 * @since 0.4.0
	 */
	class WP_REST_Response {

		/**
		 * The response body.
		 *
		 * @var mixed
		 */
		private mixed $data;

		/**
		 * The HTTP status code.
		 *
		 * @var int
		 */
		private int $status;

		/**
		 * Records the body and status.
		 *
		 * @param mixed $data   The response body.
		 * @param int   $status The HTTP status code.
		 */
		public function __construct( mixed $data = null, int $status = 200 ) {
			$this->data   = $data;
			$this->status = $status;
		}

		/**
		 * Returns the response body.
		 *
		 * @return mixed
		 */
		public function get_data(): mixed {
			return $this->data;
		}

		/**
		 * Returns the HTTP status code.
		 *
		 * @return int
		 */
		public function get_status(): int {
			return $this->status;
		}
	}
}

if ( ! class_exists( 'WP_REST_Request' ) ) {
	/**
	 * Minimal stand-in for WordPress's WP_REST_Request class.
	 *
	 * Drives the upload controller in unit tests without a REST server: the test
	 * sets headers, params, and file params, and the controller reads them back
	 * through the same `get_header()` / `get_param()` / `get_file_params()`
	 * surface the real request exposes. Only that read surface plus the test-side
	 * setters is modelled.
	 *
	 * @since 0.4.0
	 */
	class WP_REST_Request {

		/**
		 * Case-insensitive header map (header name => value).
		 *
		 * @var array<string,string>
		 */
		private array $headers = [];

		/**
		 * Flat parameter map (route + body params merged for the tests).
		 *
		 * @var array<string,mixed>
		 */
		private array $params = [];

		/**
		 * The `$_FILES`-shaped multipart file params.
		 *
		 * @var array<string,mixed>
		 */
		private array $file_params = [];

		/**
		 * Sets a header value the controller can read back.
		 *
		 * @param string $name  The header name.
		 * @param string $value The header value.
		 * @return void
		 */
		public function set_header( string $name, string $value ): void {
			$this->headers[ strtolower( $name ) ] = $value;
		}

		/**
		 * Sets a parameter value the controller can read back.
		 *
		 * @param string $key   The parameter name.
		 * @param mixed  $value The parameter value.
		 * @return void
		 */
		public function set_param( string $key, mixed $value ): void {
			$this->params[ $key ] = $value;
		}

		/**
		 * Sets the multipart file params the controller can read back.
		 *
		 * @param array<string,mixed> $files The `$_FILES`-shaped file params.
		 * @return void
		 */
		public function set_file_params( array $files ): void {
			$this->file_params = $files;
		}

		/**
		 * Returns a header value, or null when unset (mirrors core's contract).
		 *
		 * @param string $key The header name.
		 * @return string|null
		 */
		public function get_header( string $key ): ?string {
			return $this->headers[ strtolower( $key ) ] ?? null;
		}

		/**
		 * Returns a parameter value, or null when unset.
		 *
		 * @param string $key The parameter name.
		 * @return mixed
		 */
		public function get_param( string $key ): mixed {
			return $this->params[ $key ] ?? null;
		}

		/**
		 * Returns the multipart file params.
		 *
		 * @return array<string,mixed>
		 */
		public function get_file_params(): array {
			return $this->file_params;
		}
	}
}
