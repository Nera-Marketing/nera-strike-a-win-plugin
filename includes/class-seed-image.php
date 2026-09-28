<?php
/**
 * Generated featured images for seeded competitions.
 *
 * WHY GENERATE RATHER THAN SHIP PHOTOS
 * ------------------------------------
 * The prototype's artboards carry real watch photography, and copying those into
 * the plugin would make a seeded demo look exactly like the design. It would also
 * put four images of unknown provenance into a plugin that ships to several
 * client sites, and "we exported them from a design tool" is not a licence.
 *
 * Drawing them instead costs nothing at run time, needs no network — which matters
 * on a staging box behind a firewall — and still verifies the things a layout test
 * is actually asking about: how the image crops at the card's aspect ratio, whether
 * the "From £N" pill stays legible over the bottom-right corner, and how a row of
 * cards reads when each image is a different weight.
 *
 * A site that wants real photography sets it on the product; nothing here overwrites
 * an existing featured image.
 *
 * @package Nera_Strikeawin
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Nera_SAW_Seed_Image
 */
class Nera_SAW_Seed_Image {

	/**
	 * Attachment meta marking an image this class made, so the demo teardown can
	 * find them without guessing from filenames.
	 */
	const MARKER = '_nera_saw_seed_image';

	/**
	 * Card images crop to roughly 3:2. Generating at that ratio means the crop the
	 * layout performs is the crop being tested.
	 */
	const WIDTH  = 1200;
	const HEIGHT = 800;

	/**
	 * Palettes, deliberately varied in weight.
	 *
	 * The price pill sits bottom-right over the image with a translucent cream
	 * background. A set of uniformly dark images would make it look fine
	 * everywhere and hide the case that actually breaks — a light one.
	 *
	 * @return array[] Each: [ top RGB, bottom RGB, accent RGB ].
	 */
	private static function palettes() {
		return array(
			array( array( 26, 32, 52 ), array( 72, 58, 94 ), array( 235, 88, 12 ) ),     // deep indigo
			array( array( 232, 226, 214 ), array( 190, 176, 156 ), array( 63, 127, 184 ) ), // light sand — the hard one
			array( array( 18, 42, 38 ), array( 46, 92, 74 ), array( 241, 196, 96 ) ),     // forest
			array( array( 58, 20, 28 ), array( 124, 44, 52 ), array( 240, 215, 190 ) ),   // burgundy
			array( array( 208, 214, 222 ), array( 142, 152, 168 ), array( 33, 26, 20 ) ), // steel
			array( array( 36, 30, 28 ), array( 92, 74, 62 ), array( 235, 88, 12 ) ),      // espresso
		);
	}

	/**
	 * Is image generation possible on this host?
	 *
	 * @return bool
	 */
	public static function available() {
		return function_exists( 'imagecreatetruecolor' ) && function_exists( 'imagejpeg' );
	}

	/**
	 * Give a product a generated featured image.
	 *
	 * Never replaces one that already exists — a demo competition somebody has
	 * since dressed with a real photo keeps it.
	 *
	 * @param int    $product_id Product ID.
	 * @param string $label      Text drawn on the image.
	 * @param int    $variant    Which palette to use; wraps.
	 * @return int Attachment ID, or 0.
	 */
	public static function attach( $product_id, $label, $variant = 0 ) {
		$product_id = (int) $product_id;
		if ( ! $product_id || get_post_thumbnail_id( $product_id ) ) {
			return 0;
		}
		if ( ! self::available() ) {
			return 0;
		}

		$binary = self::render( $label, $variant );
		if ( ! $binary ) {
			return 0;
		}

		$name = 'saw-demo-' . $product_id . '-' . wp_generate_password( 6, false, false ) . '.jpg';

		$upload = wp_upload_bits( $name, null, $binary );
		if ( ! empty( $upload['error'] ) ) {
			return 0;
		}

		$attachment_id = wp_insert_attachment(
			array(
				'post_mime_type' => 'image/jpeg',
				'post_title'     => $label,
				'post_status'    => 'inherit',
			),
			$upload['file'],
			$product_id
		);
		if ( is_wp_error( $attachment_id ) || ! $attachment_id ) {
			return 0;
		}

		require_once ABSPATH . 'wp-admin/includes/image.php';
		wp_update_attachment_metadata( $attachment_id, wp_generate_attachment_metadata( $attachment_id, $upload['file'] ) );

		// Alt text, because the card renders one and an empty alt on a decorative
		// -looking image that is actually the product is an accessibility failure.
		update_post_meta( $attachment_id, '_wp_attachment_image_alt', $label );
		update_post_meta( $attachment_id, self::MARKER, '1' );

		set_post_thumbnail( $product_id, $attachment_id );

		return (int) $attachment_id;
	}

	/**
	 * Draw the image.
	 *
	 * @param string $label   Text.
	 * @param int    $variant Palette index.
	 * @return string|null JPEG bytes.
	 */
	private static function render( $label, $variant = 0 ) {
		$palettes = self::palettes();
		$palette  = $palettes[ abs( (int) $variant ) % count( $palettes ) ];

		$img = imagecreatetruecolor( self::WIDTH, self::HEIGHT );
		if ( ! $img ) {
			return null;
		}

		// Vertical gradient.
		list( $top, $bottom, $accent ) = $palette;
		for ( $y = 0; $y < self::HEIGHT; $y++ ) {
			$t = $y / max( 1, self::HEIGHT - 1 );
			$c = imagecolorallocate(
				$img,
				(int) round( $top[0] + ( $bottom[0] - $top[0] ) * $t ),
				(int) round( $top[1] + ( $bottom[1] - $top[1] ) * $t ),
				(int) round( $top[2] + ( $bottom[2] - $top[2] ) * $t )
			);
			imageline( $img, 0, $y, self::WIDTH, $y, $c );
		}

		// Soft accent shapes. Not decoration for its own sake: a flat gradient
		// crops identically wherever you cut it, so it cannot show whether the
		// card's crop is losing anything important.
		if ( function_exists( 'imagefilledellipse' ) ) {
			$glow = imagecolorallocatealpha( $img, $accent[0], $accent[1], $accent[2], 96 );
			imagefilledellipse( $img, (int) ( self::WIDTH * 0.72 ), (int) ( self::HEIGHT * 0.34 ), 520, 520, $glow );
			$glow2 = imagecolorallocatealpha( $img, $accent[0], $accent[1], $accent[2], 112 );
			imagefilledellipse( $img, (int) ( self::WIDTH * 0.24 ), (int) ( self::HEIGHT * 0.78 ), 380, 380, $glow2 );
		}

		// The label, centred. Built-in font only — a bundled TTF would be one more
		// licensed file, and this text exists to identify a demo row, not to be
		// beautiful.
		$text  = mb_substr( wp_strip_all_tags( (string) $label ), 0, 34 );
		$font  = 5;
		$tw    = imagefontwidth( $font ) * strlen( $text );
		$th    = imagefontheight( $font );
		$x     = (int) max( 8, ( self::WIDTH - $tw ) / 2 );
		$y     = (int) ( ( self::HEIGHT - $th ) / 2 );

		// Shadow first so the label survives both the light and the dark palettes.
		$shadow = imagecolorallocatealpha( $img, 0, 0, 0, 40 );
		imagestring( $img, $font, $x + 2, $y + 2, $text, $shadow );
		$white = imagecolorallocate( $img, 255, 250, 244 );
		imagestring( $img, $font, $x, $y, $text, $white );

		ob_start();
		imagejpeg( $img, null, 82 );
		$binary = ob_get_clean();
		imagedestroy( $img );

		return $binary ? $binary : null;
	}

	/**
	 * Delete every image this class created.
	 *
	 * Called by the demo teardown. Attachments are found by the marker rather than
	 * by parent, so an image survives being detached from its product and is still
	 * cleaned up.
	 *
	 * @return int How many were deleted.
	 */
	public static function wipe() {
		$ids = get_posts(
			array(
				'post_type'      => 'attachment',
				'post_status'    => 'any',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'meta_key'       => self::MARKER, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value'     => '1',          // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			)
		);

		$deleted = 0;
		foreach ( (array) $ids as $id ) {
			if ( wp_delete_attachment( (int) $id, true ) ) {
				$deleted++;
			}
		}
		return $deleted;
	}
}
