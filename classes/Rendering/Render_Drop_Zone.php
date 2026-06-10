<?php
/**
 * Server-side render for the Photo Drop Zone block — the capability gate.
 *
 * The Drop Zone is a capability-gated front-end uploader bound to one collection.
 * This render handler is the first of the two defences in depth ADR-0006 names:
 * it emits the uploader **and** the `wp_rest` nonce only for a user who holds the
 * upload capability (`upload_files`, filter `kntnt_photo_drop_upload_capability`).
 * For anyone else it renders nothing and, crucially, emits no nonce — so a page a
 * visitor can read never carries an upload credential. The REST endpoint
 * (`Upload_Controller`) re-checks the same capability and the nonce on every
 * file, so this gate is a defence in depth, not the only one.
 *
 * The block's editable appearance is its inner blocks. WordPress hands this
 * handler the serialised inner-block markup as `$content`; for a capable user the
 * handler replaces the `{kntnt-drop-zone-collection}` placeholder with the
 * selected collection's display name, then wraps the result in the native
 * drag-drop + click-to-browse drop surface. It hands the view module everything
 * it needs as a JSON `data-wp-context` island: the slug, the contract (max width
 * and quality) that configures the client-side Canvas downscale and the
 * `canvas.toBlob(…, 'image/webp', quality)` encode, the REST URL to POST to, the
 * nonce, and the pre-translated UI strings (the view module cannot reach
 * `@wordpress/i18n`). The client optimisation is a bandwidth optimisation only;
 * the server re-enforces the contract.
 *
 * @package Kntnt\Photo_Drop
 * @since   0.5.0
 */

declare( strict_types = 1 );

namespace Kntnt\Photo_Drop\Rendering;

use Kntnt\Photo_Drop\Collection\Repository;
use Kntnt\Photo_Drop\Storage\Descriptor;

/**
 * Renders the Photo Drop Zone block's front-end markup.
 *
 * The single public method `render()` matches the dynamic-block render-callback
 * contract. It owns the capability gate, the collection resolution, the
 * placeholder replacement, and the assembly of the Interactivity-API context the
 * `view.ts` module mounts the native drop surface against. The collaborators are
 * resolved through a fresh `Repository` because the filesystem is the source of
 * truth and a render reflects the directory as it is at request time.
 *
 * @package Kntnt\Photo_Drop
 * @since 0.5.0
 */
final class Render_Drop_Zone {

	/**
	 * The default capability required to upload, overridable via filter.
	 *
	 * Mirrors `Upload_Controller::DEFAULT_CAPABILITY` (ADR-0006): the same core
	 * capability gates both the rendered UI and the REST write path, kept in step
	 * through the shared `kntnt_photo_drop_upload_capability` filter so a site that
	 * narrows one narrows both.
	 *
	 * @since 0.5.0
	 * @var string
	 */
	private const DEFAULT_CAPABILITY = 'upload_files';

	/**
	 * The REST route template the view module POSTs each file to.
	 *
	 * The `%s` is filled with the resolved collection slug. Mirrors
	 * `Upload_Controller`'s `collections/<slug>/images` route under the shared
	 * `kntnt-photo-drop/v1` namespace.
	 *
	 * @since 0.5.0
	 * @var string
	 */
	private const UPLOAD_ROUTE_TEMPLATE = 'kntnt-photo-drop/v1/collections/%s/images';

	/**
	 * The literal placeholder replaced with the collection's display name.
	 *
	 * Authored into the default inner-block template (and kept in sync with
	 * `edit.tsx`'s `COLLECTION_PLACEHOLDER`). The replacement is a plain string
	 * substitution over the inner-block markup: a builder who deletes or edits the
	 * token simply forgoes the substitution, which is the documented behaviour —
	 * a removed placeholder is never replaced.
	 *
	 * @since 0.4.0
	 * @var string
	 */
	private const COLLECTION_PLACEHOLDER = '{kntnt-drop-zone-collection}';

	/**
	 * Returns the block's front-end HTML, or an empty string when nothing renders.
	 *
	 * Renders nothing — and emits no nonce — for a user without the upload
	 * capability (the defence-in-depth gate, ADR-0006) and for an empty or
	 * dangling `collection` attribute (the public sees nothing for a collection
	 * that is gone). For a capable user with a resolvable collection it reads the
	 * descriptor, replaces the placeholder in the inner-block markup, and returns
	 * that markup wrapped in the native drop surface and the Interactivity-API
	 * directives the view module mounts against, carrying the slug, the contract,
	 * the REST URL, the nonce, and the pre-translated UI strings.
	 *
	 * @since 0.5.0
	 *
	 * @param array<string,mixed> $attributes Block attributes; only `collection` (the slug) is read.
	 * @param string              $content    The block's inner-block HTML (the editable drop surface).
	 * @param \WP_Block           $block      Block instance (unused).
	 * @return string Escaped HTML for the block, or '' when nothing should render.
	 */
	public static function render( array $attributes, string $content, \WP_Block $block ): string {

		// Defence in depth (ADR-0006): a user who cannot upload sees nothing and,
		// critically, is never handed a nonce — so a page a visitor can read
		// carries no upload credential. The REST endpoint re-checks both gates.
		if ( ! current_user_can( self::upload_capability() ) ) {
			return '';
		}

		// Resolve the selected slug to a real collection; an empty or dangling
		// reference renders nothing for the public (an editor notice is the
		// editor component's job, not the front end's).
		$slug = self::read_slug( $attributes );
		if ( $slug === '' ) {
			return '';
		}
		$repository      = new Repository();
		$collection_path = $repository->resolve_slug( $slug );
		if ( $collection_path === null ) {
			return '';
		}

		// Read the descriptor to fix the contract the client optimisation is
		// configured from; an unreadable descriptor is a degraded collection we
		// decline to render rather than offering an uploader with no known
		// contract.
		$descriptor = Descriptor::read( $collection_path );
		if ( $descriptor === null ) {
			return '';
		}

		return self::render_uploader( $slug, $descriptor, $content );

	}

	/**
	 * Builds the drop surface and the Interactivity-API context for a capable user.
	 *
	 * Replaces the collection placeholder in the inner-block markup, then assembles
	 * the per-block context the view module reads via `getContext()`: the slug, the
	 * contract (`maxWidth`/`quality`) the client downscale and WebP encode use, the
	 * absolute REST `uploadUrl`, the `wp_rest` `nonce`, the admin-ajax URL the
	 * nonce-refresh action lives behind, and the pre-translated `i18n` strings the
	 * module surfaces (it cannot import `@wordpress/i18n`). The context is emitted
	 * as an escaped JSON island on the wrapper; the whole wrapper is the native
	 * drop-and-browse surface — the placeholder-replaced inner blocks, a hidden
	 * file input the surface click triggers, a native "Select folder" picker, and
	 * the per-file status list and live summary the module writes to. Everything is
	 * escaped at the point of output and every visible string is translatable; the
	 * inner-block markup is already sanitised by `the_content`-equivalent block
	 * serialisation and is passed through unescaped here by design.
	 *
	 * @since 0.5.0
	 *
	 * @param string     $slug       The resolved collection slug.
	 * @param Descriptor $descriptor The collection's contract and display name.
	 * @param string     $content    The block's inner-block HTML.
	 * @return string The uploader HTML.
	 */
	private static function render_uploader( string $slug, Descriptor $descriptor, string $content ): string {

		// Replace the placeholder with the collection's display name. A builder who
		// removed or edited the token leaves no placeholder to replace, which is
		// the documented behaviour; the name is escaped because it lands in the
		// visible inner-block markup.
		$surface = str_replace(
			self::COLLECTION_PLACEHOLDER,
			esc_html( $descriptor->name ),
			$content
		);

		// Assemble the per-block context the view module mounts the drop surface
		// from. The nonce is the `wp_rest` token the REST endpoint verifies; the
		// ajaxUrl is where the module refreshes an expired nonce (core's
		// `rest-nonce` admin-ajax action) so a long upload session survives nonce
		// expiry; the contract drives the client downscale and the `canvas.toBlob`
		// quality. The i18n map is pre-translated here because view-script modules
		// cannot reach `@wordpress/i18n`.
		$context = [
			'slug'       => $slug,
			'maxWidth'   => $descriptor->max_width,
			'quality'    => $descriptor->quality,
			'uploadUrl'  => rest_url( sprintf( self::UPLOAD_ROUTE_TEMPLATE, $slug ) ),
			'nonce'      => wp_create_nonce( 'wp_rest' ),
			'ajaxUrl'    => admin_url( 'admin-ajax.php' ),
			'collection' => $descriptor->name,
			'i18n'       => self::translations(),
		];
		$context_json = wp_json_encode( $context );
		if ( $context_json === false ) {
			return '';
		}

		// Build the wrapper through core's helper so editor affordances (anchor,
		// extra classes, spacing block supports) reach the front end, adding the
		// project class the stylesheet targets.
		$wrapper = get_block_wrapper_attributes( [ 'class' => 'kntnt-photo-drop-drop-zone' ] );

		// Compose the native drop surface: the whole wrapper is the drop zone and a
		// click-to-browse trigger (the view module wires the hidden loose-file
		// input and the drag handlers). The inner-block surface carries the visible
		// appearance and is itself the keyboard-operable browse trigger — it takes
		// `tabindex="0"`, `role="button"`, and an `aria-label` so a keyboard user can
		// Tab to it and Enter/Space to open the picker, not only a pointer user. The
		// folder input carries `webkitdirectory` so a directory selection preserves
		// each file's `webkitRelativePath`; the summary line is the single live region
		// (the per-file list would be far too chatty for a screen reader at batch
		// scale). A `data-wp-init` hook hands the element to the view module.
		$folder_label  = esc_html__( 'Select folder', 'kntnt-photo-drop' );
		$surface_label = esc_attr__( 'Add photos — drop them here or activate to browse', 'kntnt-photo-drop' );

		return sprintf(
			'<div %1$s'
				. ' data-wp-interactive=\'{"namespace":"kntnt-photo-drop/drop-zone"}\''
				. ' data-wp-context=\'%2$s\''
				. ' data-wp-init="callbacks.init">'
				// phpcs:ignore Generic.Files.LineLength.TooLong -- The loose-file input is a single coherent input declaration.
				. '<input type="file" class="kntnt-photo-drop-drop-zone__file-input" multiple accept="image/*" hidden />'
				// phpcs:ignore Generic.Files.LineLength.TooLong -- The keyboard-operable browse surface is a single coherent element declaration.
				. '<div class="kntnt-photo-drop-drop-zone__surface" data-wp-ignore tabindex="0" role="button" aria-label="%5$s">%3$s</div>'
				. '<p class="kntnt-photo-drop-drop-zone__folder">'
				. '<label class="kntnt-photo-drop-drop-zone__folder-label">'
				. '<span>%4$s</span>'
				// phpcs:ignore Generic.Files.LineLength.TooLong -- The webkitdirectory attribute set is a single coherent input declaration.
				. '<input type="file" class="kntnt-photo-drop-drop-zone__folder-input" multiple accept="image/*" webkitdirectory directory />'
				. '</label>'
				. '</p>'
				. '<p class="kntnt-photo-drop-drop-zone__summary" data-wp-ignore aria-live="polite"></p>'
				. '<ul class="kntnt-photo-drop-drop-zone__status" data-wp-ignore></ul>'
				. '</div>',
			$wrapper,
			esc_attr( $context_json ),
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $surface is serialised inner-block HTML, already sanitised by the block serializer; the substituted name is escaped above.
			$surface,
			$folder_label,
			$surface_label,
		);

	}

	/**
	 * Returns the pre-translated UI strings the view module surfaces.
	 *
	 * The view-script module runs as an ES module and cannot import
	 * `@wordpress/i18n`, so every visitor-facing string the module shows at
	 * runtime is translated here and passed through the context. The map covers
	 * the module's own status rows and summary (inserted via `textContent`, never
	 * `innerHTML`, so no output escaping is applied here) and the `window.confirm`
	 * folder warning. The `summaryTemplate` tokens and the `statusUploadingPercent`
	 * `%d` token are replaced with live counts and the live upload percentage in the
	 * browser.
	 *
	 * @since 0.5.0
	 *
	 * @return array<string,string> The string map keyed by the identifier the module reads.
	 */
	private static function translations(): array {
		return [
			// phpcs:ignore Generic.Files.LineLength.TooLong -- A single translator literal must not be split per WordPress.WP.I18n.
			'folderWarningBody'      => __( 'Dropping a folder uploads only its top-level images, not its sub-folders. Use “Select folder” to include sub-folders. Continue with the top-level images?', 'kntnt-photo-drop' ),
			'outcomeStored'          => __( 'Uploaded', 'kntnt-photo-drop' ),
			'outcomeReencoded'       => __( 'Uploaded (re-encoded)', 'kntnt-photo-drop' ),
			'outcomeSkipped'         => __( 'Skipped — already present', 'kntnt-photo-drop' ),
			'outcomeRejected'        => __( 'Rejected', 'kntnt-photo-drop' ),
			'uploadFailed'           => __( 'Upload failed', 'kntnt-photo-drop' ),
			// phpcs:ignore Generic.Files.LineLength.TooLong -- A single translator literal must not be split per WordPress.WP.I18n.
			'uploadStalled'          => __( 'Upload stalled — check your connection and try again.', 'kntnt-photo-drop' ),
			'skippedNotImage'        => __( 'Skipped — not an image', 'kntnt-photo-drop' ),
			'fileUnreadable'         => __( 'Could not be read', 'kntnt-photo-drop' ),
			'statusQueued'           => __( 'Queued', 'kntnt-photo-drop' ),
			'statusConverting'       => __( 'Converting…', 'kntnt-photo-drop' ),
			'statusUploading'        => __( 'Uploading…', 'kntnt-photo-drop' ),
			/* translators: %d is replaced with the live upload percentage in the browser. */
			'statusUploadingPercent' => __( 'Uploading… %d%%', 'kntnt-photo-drop' ),
			/* translators: {uploaded}, {skipped} and {failed} are replaced with live file counts in the browser. */
			'summaryTemplate'        => __( '{uploaded} uploaded · {skipped} skipped · {failed} failed', 'kntnt-photo-drop' ), // phpcs:ignore Generic.Files.LineLength.TooLong -- A single translator literal must not be split per WordPress.WP.I18n.
		];
	}

	/**
	 * Resolves the upload capability through the shared filter.
	 *
	 * Defaults to `upload_files` and is passed through
	 * `kntnt_photo_drop_upload_capability`, the same filter `Upload_Controller`
	 * uses, so the rendered gate and the REST gate never diverge. A filter that
	 * returns a non-string or empty value is a misuse and falls back to the
	 * default rather than rendering an uploader behind an empty capability check.
	 *
	 * @since 0.5.0
	 *
	 * @return string The capability the current user must hold to see the uploader.
	 */
	private static function upload_capability(): string {

		// Harden the filter's return: a non-string or empty result falls back to
		// the default so a buggy filter can never open the gate.
		$filtered = apply_filters( 'kntnt_photo_drop_upload_capability', self::DEFAULT_CAPABILITY );
		return is_string( $filtered ) && $filtered !== '' ? $filtered : self::DEFAULT_CAPABILITY;

	}

	/**
	 * Reads and sanitises the `collection` slug attribute.
	 *
	 * The attribute is the only persisted block state; everything about the
	 * contract is read live from the descriptor. A non-string or absent value
	 * yields the empty string, which `render()` treats as "no collection
	 * selected" and renders nothing for.
	 *
	 * @since 0.5.0
	 *
	 * @param array<string,mixed> $attributes The block attributes.
	 * @return string The sanitised slug, or '' when absent.
	 */
	private static function read_slug( array $attributes ): string {
		$raw = $attributes['collection'] ?? '';
		return is_string( $raw ) ? sanitize_text_field( $raw ) : '';
	}

}
