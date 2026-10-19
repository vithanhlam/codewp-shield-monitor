<?php
defined( 'ABSPATH' ) || exit;

class Shield_Monitor_Media_Optimization {
	private $settings;

	public function __construct( $settings ) {
		$this->settings = $settings;
	}

	public function register_hooks() {
		// Disable extra image sizes.
		if ( $this->settings->get( 'disable_image_sizes' ) ) {
			add_action( 'init', array( $this, 'remove_image_sizes' ) );
			add_filter( 'intermediate_image_sizes_advanced', array( $this, 'filter_image_sizes' ) );
			add_filter( 'big_image_size_threshold', '__return_false' );
		}

		// WebP conversion on upload.
		if ( $this->settings->get( 'webp_convert_enabled' ) ) {
			add_filter( 'wp_handle_upload', array( $this, 'convert_to_webp' ) );
		}

		// Upload file size limit.
		if ( $this->settings->get( 'upload_limit_enabled' ) ) {
			add_filter( 'wp_handle_upload_prefilter', array( $this, 'check_upload_size' ) );
		}

		// Auto-resize large images on upload.
		if ( $this->settings->get( 'image_resize_enabled' ) ) {
			add_filter( 'wp_handle_upload', array( $this, 'resize_on_upload' ) );
		}
	}

	// ─────────────────────────────────────────────────────────────────
	// Media Library bypass detection
	// ─────────────────────────────────────────────────────────────────

	/**
	 * Check if the current upload is coming from the Media Library page.
	 *
	 * Uploads from Media → Add New should be exempt from resize/WebP/limit
	 * so editors can upload banners, full-size originals, etc.
	 *
	 * Uploads from post editor, Gutenberg, WooCommerce, etc. are still processed.
	 */
	private function is_media_library_upload() {
		// Check referer for media-new.php or upload.php.
		$referer = wp_get_referer();
		if ( $referer ) {
			$path = wp_parse_url( $referer, PHP_URL_PATH );
			if ( $path && preg_match( '/(media-new\.php|upload\.php)/', $path ) ) {
				return true;
			}
		}

		// Check the action parameter.
		if ( isset( $_REQUEST['action'] ) && 'upload-attachment' === $_REQUEST['action'] ) {
			// Gutenberg/block editor also uses this action, check referer more carefully.
			if ( $referer && preg_match( '/media-new\.php/', wp_parse_url( $referer, PHP_URL_PATH ) ?? '' ) ) {
				return true;
			}
		}

		// Check for our custom skip flag (can be set via filter).
		if ( ! empty( $_REQUEST['shield_monitor_skip_optimize'] ) ) {
			return true;
		}

		return apply_filters( 'shield_monitor_skip_media_optimization', false );
	}

	// ─────────────────────────────────────────────────────────────────
	// Upload Size Limit
	// ─────────────────────────────────────────────────────────────────

	/**
	 * Reject uploads that exceed the configured max file size.
	 * Runs BEFORE the file is saved to the uploads directory.
	 *
	 * Skipped when uploading from Media Library page.
	 */
	public function check_upload_size( $file ) {
		// Only apply to images.
		if ( empty( $file['type'] ) || strpos( $file['type'], 'image/' ) !== 0 ) {
			return $file;
		}

		// Skip for Media Library uploads.
		if ( $this->is_media_library_upload() ) {
			return $file;
		}

		$max_kb   = absint( $this->settings->get( 'upload_max_size_kb' ) );
		if ( $max_kb < 1 ) {
			return $file;
		}

		$max_bytes = $max_kb * 1024;
		$file_size = isset( $file['size'] ) ? absint( $file['size'] ) : 0;

		if ( $file_size > $max_bytes ) {
			$file['error'] = sprintf(
				/* translators: 1: file size, 2: max allowed size */
				__( 'This image is too large (%1$s). Maximum allowed size is %2$s. Please compress or resize the image before uploading.', 'codewp-shield-monitor' ),
				size_format( $file_size ),
				size_format( $max_bytes )
			);
		}

		return $file;
	}

	// ─────────────────────────────────────────────────────────────────
	// Auto-Resize on Upload (width only, height auto)
	// ─────────────────────────────────────────────────────────────────

	/**
	 * Resize uploaded images if width exceeds the configured max.
	 * Height is calculated automatically to maintain aspect ratio.
	 *
	 * Skipped when uploading from Media Library page.
	 */
	public function resize_on_upload( $upload ) {
		if ( ! isset( $upload['file'] ) || ! isset( $upload['type'] ) ) {
			return $upload;
		}

		// Only resize JPEG and PNG images.
		$resizable = array( 'image/jpeg', 'image/png' );
		if ( ! in_array( $upload['type'], $resizable, true ) ) {
			return $upload;
		}

		// Skip for Media Library uploads.
		if ( $this->is_media_library_upload() ) {
			return $upload;
		}

		$file      = $upload['file'];
		$max_width = absint( $this->settings->get( 'image_max_width' ) );

		if ( $max_width < 200 ) {
			return $upload;
		}

		// Get current image dimensions.
		$size = @getimagesize( $file );
		if ( ! $size ) {
			return $upload;
		}

		$orig_width = $size[0];

		// Only resize if width exceeds the max.
		if ( $orig_width <= $max_width ) {
			return $upload;
		}

		// Use WordPress image editor for safe resizing.
		$editor = wp_get_image_editor( $file );
		if ( is_wp_error( $editor ) ) {
			return $upload;
		}

		// Resize by width only — height=0 means auto (proportional).
		$resized = $editor->resize( $max_width, 0, false );
		if ( is_wp_error( $resized ) ) {
			return $upload;
		}

		// Verify new dimensions.
		$new_size = $editor->get_size();
		if ( ! $new_size || $new_size['width'] < 1 || $new_size['height'] < 1 ) {
			return $upload;
		}

		// Set compression quality.
		if ( 'image/jpeg' === $upload['type'] ) {
			$editor->set_quality( 90 );
		}

		// Save over the original file.
		$saved = $editor->save( $file );
		if ( is_wp_error( $saved ) ) {
			return $upload;
		}

		// If the editor saved to a different path, move it back.
		if ( isset( $saved['path'] ) && $saved['path'] !== $file ) {
			if ( file_exists( $saved['path'] ) ) {
				@unlink( $file );
				@rename( $saved['path'], $file );
			}
		}

		return $upload;
	}

	// ─────────────────────────────────────────────────────────────────
	// WebP Conversion on Upload
	// ─────────────────────────────────────────────────────────────────

	/**
	 * Convert uploaded image to WebP format.
	 *
	 * Skipped when uploading from Media Library page.
	 */
	public function convert_to_webp( $upload ) {
		if ( ! isset( $upload['file'] ) || ! isset( $upload['type'] ) ) {
			return $upload;
		}

		$convertible = array( 'image/jpeg', 'image/png', 'image/gif' );
		if ( ! in_array( $upload['type'], $convertible, true ) ) {
			return $upload;
		}

		// Skip for Media Library uploads.
		if ( $this->is_media_library_upload() ) {
			return $upload;
		}

		// Check GD or Imagick support.
		if ( ! $this->can_convert_webp() ) {
			return $upload;
		}

		$source_path = $upload['file'];
		$webp_path   = preg_replace( '/\.(jpe?g|png|gif)$/i', '.webp', $source_path );

		if ( $webp_path === $source_path ) {
			return $upload;
		}

		$quality = (int) ( $this->settings->get( 'webp_quality' ) ?: 82 );
		$quality = max( 1, min( 100, $quality ) );

		$converted = $this->do_convert( $source_path, $webp_path, $upload['type'], $quality );

		if ( ! $converted || ! file_exists( $webp_path ) ) {
			return $upload;
		}

		$keep_original = $this->settings->get( 'webp_keep_original' );

		if ( ! $keep_original ) {
			wp_delete_file( $source_path );
		}

		$upload['file'] = $webp_path;
		$upload['url']  = preg_replace( '/\.(jpe?g|png|gif)$/i', '.webp', $upload['url'] );
		$upload['type'] = 'image/webp';

		return $upload;
	}

	// ─────────────────────────────────────────────────────────────────
	// Disable Image Sizes
	// ─────────────────────────────────────────────────────────────────

	/**
	 * Remove default registered image sizes.
	 */
	public function remove_image_sizes() {
		$keep = array_map( 'sanitize_key', (array) $this->settings->get( 'keep_image_sizes' ) );

		$sizes_to_remove = array( 'thumbnail', 'medium', 'medium_large', 'large', '1536x1536', '2048x2048' );
		foreach ( $sizes_to_remove as $size ) {
			if ( ! in_array( $size, $keep, true ) ) {
				remove_image_size( $size );
			}
		}

		if ( ! in_array( 'thumbnail', $keep, true ) ) {
			update_option( 'thumbnail_size_w', 0 );
			update_option( 'thumbnail_size_h', 0 );
		}
		if ( ! in_array( 'medium', $keep, true ) ) {
			update_option( 'medium_size_w', 0 );
			update_option( 'medium_size_h', 0 );
		}
		if ( ! in_array( 'large', $keep, true ) ) {
			update_option( 'large_size_w', 0 );
			update_option( 'large_size_h', 0 );
		}
	}

	/**
	 * Filter intermediate sizes, keeping only those selected.
	 */
	public function filter_image_sizes( $sizes ) {
		$keep = array_map( 'sanitize_key', (array) $this->settings->get( 'keep_image_sizes' ) );

		if ( empty( $keep ) ) {
			return array();
		}

		return array_intersect_key( $sizes, array_flip( $keep ) );
	}

	// ─────────────────────────────────────────────────────────────────
	// Image Conversion Utilities
	// ─────────────────────────────────────────────────────────────────

	private function can_convert_webp() {
		if ( function_exists( 'imagewebp' ) ) {
			return true;
		}
		if ( extension_loaded( 'imagick' ) && class_exists( 'Imagick' ) ) {
			return in_array( 'WEBP', \Imagick::queryFormats( 'WEBP' ), true );
		}
		return false;
	}

	private function do_convert( $source, $dest, $mime, $quality ) {
		if ( function_exists( 'imagewebp' ) ) {
			return $this->convert_gd( $source, $dest, $mime, $quality );
		}
		if ( extension_loaded( 'imagick' ) && class_exists( 'Imagick' ) ) {
			return $this->convert_imagick( $source, $dest, $quality );
		}
		return false;
	}

	private function convert_gd( $source, $dest, $mime, $quality ) {
		switch ( $mime ) {
			case 'image/jpeg':
				$image = imagecreatefromjpeg( $source );
				break;
			case 'image/png':
				$image = imagecreatefrompng( $source );
				if ( $image ) {
					imagepalettetotruecolor( $image );
					imagealphablending( $image, true );
					imagesavealpha( $image, true );
				}
				break;
			case 'image/gif':
				$image = imagecreatefromgif( $source );
				break;
			default:
				return false;
		}

		if ( ! $image ) {
			return false;
		}

		$result = imagewebp( $image, $dest, $quality );
		imagedestroy( $image );
		return $result;
	}

	private function convert_imagick( $source, $dest, $quality ) {
		try {
			$imagick = new \Imagick( $source );
			$imagick->setImageFormat( 'webp' );
			$imagick->setImageCompressionQuality( $quality );
			$result = $imagick->writeImage( $dest );
			$imagick->destroy();
			return $result;
		} catch ( \Exception $e ) {
			return false;
		}
	}
}
