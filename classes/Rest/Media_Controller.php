<?php
/**
 * RED skeleton — the gallery add-to-media REST write-path (ADR-0015).
 *
 * Intentionally incomplete so the MediaControllerTest assertions fail for real
 * before the implementation lands. Replaced wholesale in the GREEN step.
 *
 * @package Kntnt\Photo_Drop
 * @since   0.12.0
 */

declare( strict_types = 1 );

namespace Kntnt\Photo_Drop\Rest;

use Kntnt\Photo_Drop\Collection\Repository;

/**
 * RED skeleton for the add-to-media controller.
 *
 * @since 0.12.0
 */
class Media_Controller {

	/**
	 * Constructs the controller with its collection repository.
	 *
	 * @since 0.12.0
	 *
	 * @param Repository $repository The collection read/resolve side.
	 */
	public function __construct( private readonly Repository $repository ) {}

	/**
	 * RED skeleton: registers no real route.
	 *
	 * @since 0.12.0
	 *
	 * @return void
	 */
	public function register_routes(): void {}

	/**
	 * RED skeleton: always allows.
	 *
	 * @since 0.12.0
	 *
	 * @param \WP_REST_Request $request The incoming REST request.
	 * @return true|\WP_Error Always true in the skeleton.
	 */
	public function check_permission( \WP_REST_Request $request ): bool|\WP_Error {
		return true;
	}

	/**
	 * RED skeleton: always 404.
	 *
	 * @since 0.12.0
	 *
	 * @param \WP_REST_Request $request The incoming REST request.
	 * @return \WP_REST_Response|\WP_Error Always a 404 in the skeleton.
	 */
	public function add_to_media( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		return new \WP_Error( 'kntnt_photo_drop_not_implemented', 'Not implemented.', [ 'status' => 404 ] );
	}

	/**
	 * RED skeleton: never reached.
	 *
	 * @since 0.12.0
	 *
	 * @param string $main_path The absolute path to the confined main image.
	 * @return int|\WP_Error A stub id in the skeleton.
	 */
	protected function sideload( string $main_path ): int|\WP_Error {
		return 0;
	}

}
