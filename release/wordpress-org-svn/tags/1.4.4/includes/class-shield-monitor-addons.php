<?php
defined( 'ABSPATH' ) || exit;

class Shield_Monitor_Addons {
	const ADDONS_DIR = 'addons';

	private $license;
	private $loaded_addons = array();

	public function __construct( $license ) {
		$this->license = $license;
	}

	public function register_hooks() {
		add_action( 'plugins_loaded', array( $this, 'load_addons' ), 20 );
	}

	/**
	 * Load all active addons from the addons directory.
	 */
	public function load_addons() {
		$addons_path = SHIELD_MONITOR_PATH . self::ADDONS_DIR;
		if ( ! is_dir( $addons_path ) ) {
			return;
		}

		$addon_dirs = glob( $addons_path . '/*/addon.php' );
		if ( ! is_array( $addon_dirs ) ) {
			return;
		}

		foreach ( $addon_dirs as $addon_file ) {
			$this->maybe_load_addon( $addon_file );
		}
	}

	/**
	 * Load a single addon if license allows it.
	 */
	private function maybe_load_addon( $addon_file ) {
		if ( ! is_readable( $addon_file ) ) {
			return;
		}

		// Read addon header.
		$headers = $this->get_addon_headers( $addon_file );
		if ( empty( $headers['slug'] ) ) {
			return;
		}

		$slug = sanitize_key( $headers['slug'] );

		// Check if license allows this addon.
		if ( ! $this->license->is_feature_allowed( $slug ) ) {
			return;
		}

		require_once $addon_file;
		$this->loaded_addons[ $slug ] = $headers;
	}

	/**
	 * Parse addon header from addon.php file.
	 *
	 * Expected header format:
	 * <?php
	 * /**
	 *  * Addon Name: My Addon
	 *  * Addon Slug: my-addon
	 *  * Requires Plan: pro
	 *  * Description: Does something cool.
	 *  * Version: 1.0.0
	 *  *\/
	 */
	private function get_addon_headers( $file ) {
		$content = file_get_contents( $file, false, null, 0, 2048 );
		if ( false === $content ) {
			return array();
		}

		$headers = array(
			'name'          => '',
			'slug'          => '',
			'requires_plan' => 'pro',
			'description'   => '',
			'version'       => '1.0.0',
		);

		$patterns = array(
			'name'          => '/Addon\s*Name:\s*(.+)/i',
			'slug'          => '/Addon\s*Slug:\s*(.+)/i',
			'requires_plan' => '/Requires\s*Plan:\s*(.+)/i',
			'description'   => '/Description:\s*(.+)/i',
			'version'       => '/Version:\s*(.+)/i',
		);

		foreach ( $patterns as $key => $pattern ) {
			if ( preg_match( $pattern, $content, $m ) ) {
				$headers[ $key ] = trim( $m[1] );
			}
		}

		return $headers;
	}

	/**
	 * Get all available addons (loaded or not).
	 */
	public function get_available_addons() {
		$addons_path = SHIELD_MONITOR_PATH . self::ADDONS_DIR;
		$addons      = array();

		if ( ! is_dir( $addons_path ) ) {
			return $addons;
		}

		$addon_files = glob( $addons_path . '/*/addon.php' );
		if ( ! is_array( $addon_files ) ) {
			return $addons;
		}

		foreach ( $addon_files as $file ) {
			$headers = $this->get_addon_headers( $file );
			if ( ! empty( $headers['slug'] ) ) {
				$slug = sanitize_key( $headers['slug'] );
				$addons[ $slug ] = array(
					'name'          => sanitize_text_field( $headers['name'] ),
					'slug'          => $slug,
					'requires_plan' => sanitize_key( $headers['requires_plan'] ),
					'description'   => sanitize_text_field( $headers['description'] ),
					'version'       => sanitize_text_field( $headers['version'] ),
					'active'        => isset( $this->loaded_addons[ $slug ] ),
				);
			}
		}

		return $addons;
	}

	/**
	 * Get loaded addons.
	 */
	public function get_loaded_addons() {
		return $this->loaded_addons;
	}
}
