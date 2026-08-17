<?php
/**
 * Extensible Threat Patterns Module
 *
 * Custom rules database for:
 * - File malware signatures (used by malware scanner)
 * - WAF request patterns (used by WAF)
 * - Database content patterns (used by malware scanner)
 *
 * Admin can add/edit/delete/import/export patterns.
 * Each pattern has: name, type, category, severity, regex, description, enabled.
 */
defined( 'ABSPATH' ) || exit;

class Shield_Monitor_Threat_Patterns {
	const OPTION      = 'shield_monitor_threat_patterns';
	const AJAX_ACTION = 'shield_monitor_threat_pattern';

	private static $instance;
	private $patterns;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function register_hooks() {
		add_action( 'wp_ajax_' . self::AJAX_ACTION . '_save', array( $this, 'ajax_save' ) );
		add_action( 'wp_ajax_' . self::AJAX_ACTION . '_delete', array( $this, 'ajax_delete' ) );
		add_action( 'wp_ajax_' . self::AJAX_ACTION . '_toggle', array( $this, 'ajax_toggle' ) );
		add_action( 'admin_post_shield_monitor_export_patterns', array( $this, 'export_patterns' ) );
		add_action( 'admin_post_shield_monitor_import_patterns', array( $this, 'import_patterns' ) );
	}

	/**
	 * Get all patterns.
	 */
	public function all() {
		if ( null === $this->patterns ) {
			$stored = get_option( self::OPTION, array() );
			$this->patterns = is_array( $stored ) ? $stored : array();
		}
		return $this->patterns;
	}

	/**
	 * Get enabled patterns by type.
	 *
	 * @param string $type One of: file_signature, waf_rule, database_pattern.
	 * @return array
	 */
	public function get_by_type( $type ) {
		$type = sanitize_key( $type );
		return array_filter( $this->all(), function ( $p ) use ( $type ) {
			return ! empty( $p['enabled'] ) && ( $p['type'] ?? '' ) === $type;
		} );
	}

	/**
	 * Get WAF rules formatted for Shield_Monitor_WAF.
	 */
	public function get_waf_rules() {
		$rules = array();
		foreach ( $this->get_by_type( 'waf_rule' ) as $pattern ) {
			if ( empty( $pattern['regex'] ) || ! $this->is_valid_regex( $pattern['regex'] ) ) {
				continue;
			}
			$rules[] = array(
				'id'       => sanitize_key( $pattern['id'] ?? 'custom_' . md5( $pattern['regex'] ) ),
				'category' => sanitize_key( $pattern['category'] ?? 'custom' ),
				'severity' => sanitize_key( $pattern['severity'] ?? 'medium' ),
				'regex'    => $pattern['regex'],
				'message'  => sanitize_text_field( $pattern['name'] ?? __( 'Custom WAF rule', 'codewp-shield-monitor' ) ),
			);
		}
		return $rules;
	}

	/**
	 * Get file scan patterns formatted for malware scanner.
	 */
	public function get_file_patterns() {
		$patterns = array();
		foreach ( $this->get_by_type( 'file_signature' ) as $pattern ) {
			if ( empty( $pattern['regex'] ) || ! $this->is_valid_regex( $pattern['regex'] ) ) {
				continue;
			}
			$patterns[] = array(
				'severity' => sanitize_key( $pattern['severity'] ?? 'medium' ),
				'id'       => sanitize_key( $pattern['id'] ?? 'custom_' . md5( $pattern['regex'] ) ),
				'regex'    => $pattern['regex'],
				'message'  => sanitize_text_field( $pattern['name'] ?? __( 'Custom signature match', 'codewp-shield-monitor' ) ),
			);
		}
		return $patterns;
	}

	/**
	 * Get database content patterns formatted for malware scanner.
	 */
	public function get_database_patterns() {
		$patterns = array();
		foreach ( $this->get_by_type( 'database_pattern' ) as $pattern ) {
			if ( empty( $pattern['regex'] ) || ! $this->is_valid_regex( $pattern['regex'] ) ) {
				continue;
			}
			$patterns[] = array(
				'severity' => sanitize_key( $pattern['severity'] ?? 'medium' ),
				'id'       => sanitize_key( $pattern['id'] ?? 'custom_' . md5( $pattern['regex'] ) ),
				'regex'    => $pattern['regex'],
				'message'  => sanitize_text_field( $pattern['name'] ?? __( 'Custom database pattern match', 'codewp-shield-monitor' ) ),
			);
		}
		return $patterns;
	}

	/**
	 * Save a single pattern (add or update).
	 */
	public function save( $data ) {
		$patterns = $this->all();
		$id       = ! empty( $data['id'] ) ? sanitize_key( $data['id'] ) : 'tp_' . wp_generate_uuid4();

		$pattern = array(
			'id'          => $id,
			'name'        => sanitize_text_field( $data['name'] ?? '' ),
			'type'        => sanitize_key( $data['type'] ?? 'file_signature' ),
			'category'    => sanitize_key( $data['category'] ?? 'custom' ),
			'severity'    => sanitize_key( $data['severity'] ?? 'medium' ),
			'regex'       => $data['regex'] ?? '',
			'description' => sanitize_textarea_field( $data['description'] ?? '' ),
			'enabled'     => ! empty( $data['enabled'] ) ? 1 : 0,
			'created_at'  => $data['created_at'] ?? gmdate( 'Y-m-d H:i:s' ),
			'updated_at'  => gmdate( 'Y-m-d H:i:s' ),
		);

		// Validate type.
		if ( ! in_array( $pattern['type'], array( 'file_signature', 'waf_rule', 'database_pattern' ), true ) ) {
			return new WP_Error( 'invalid_type', __( 'Invalid pattern type.', 'codewp-shield-monitor' ) );
		}

		// Validate severity.
		if ( ! in_array( $pattern['severity'], array( 'critical', 'high', 'medium', 'low' ), true ) ) {
			$pattern['severity'] = 'medium';
		}

		// Validate regex.
		if ( empty( $pattern['regex'] ) ) {
			return new WP_Error( 'empty_regex', __( 'Regular expression cannot be empty.', 'codewp-shield-monitor' ) );
		}
		if ( ! $this->is_valid_regex( $pattern['regex'] ) ) {
			return new WP_Error( 'invalid_regex', __( 'Invalid regular expression syntax.', 'codewp-shield-monitor' ) );
		}

		// Update existing or append.
		$found = false;
		foreach ( $patterns as $index => $existing ) {
			if ( ( $existing['id'] ?? '' ) === $id ) {
				$pattern['created_at'] = $existing['created_at'] ?? $pattern['created_at'];
				$patterns[ $index ] = $pattern;
				$found = true;
				break;
			}
		}
		if ( ! $found ) {
			$patterns[] = $pattern;
		}

		$this->patterns = $patterns;
		update_option( self::OPTION, $patterns, false );
		return $pattern;
	}

	/**
	 * Delete a pattern by ID.
	 */
	public function delete( $id ) {
		$id       = sanitize_key( $id );
		$patterns = $this->all();
		$patterns = array_values( array_filter( $patterns, function ( $p ) use ( $id ) {
			return ( $p['id'] ?? '' ) !== $id;
		} ) );
		$this->patterns = $patterns;
		update_option( self::OPTION, $patterns, false );
	}

	/**
	 * Toggle enable/disable.
	 */
	public function toggle( $id ) {
		$id       = sanitize_key( $id );
		$patterns = $this->all();
		foreach ( $patterns as &$pattern ) {
			if ( ( $pattern['id'] ?? '' ) === $id ) {
				$pattern['enabled'] = empty( $pattern['enabled'] ) ? 1 : 0;
				break;
			}
		}
		unset( $pattern );
		$this->patterns = $patterns;
		update_option( self::OPTION, $patterns, false );
	}

	/**
	 * Get built-in starter patterns (not auto-loaded — user can import them).
	 */
	public static function starter_patterns() {
		return array(
			// ── File Signatures ─────────────────────────────────────────
			array(
				'id'          => 'builtin_webshell_eval',
				'name'        => 'Web Shell: eval + base64_decode',
				'type'        => 'file_signature',
				'category'    => 'malware',
				'severity'    => 'critical',
				'regex'       => '/eval\s*\(\s*base64_decode\s*\(/i',
				'description' => 'Detects common PHP web shell pattern using eval with base64 decoding.',
				'enabled'     => 1,
			),
			array(
				'id'          => 'builtin_webshell_system',
				'name'        => 'Web Shell: system/exec/passthru',
				'type'        => 'file_signature',
				'category'    => 'malware',
				'severity'    => 'critical',
				'regex'       => '/\b(system|exec|passthru|shell_exec|popen|proc_open)\s*\(\s*\$_(GET|POST|REQUEST|COOKIE)/i',
				'description' => 'Detects direct command execution from user input — classic backdoor pattern.',
				'enabled'     => 1,
			),
			array(
				'id'          => 'builtin_backdoor_upload',
				'name'        => 'Backdoor: File upload handler',
				'type'        => 'file_signature',
				'category'    => 'malware',
				'severity'    => 'high',
				'regex'       => '/move_uploaded_file\s*\(\s*\$_FILES/i',
				'description' => 'Suspicious file upload handler outside standard WordPress upload flow.',
				'enabled'     => 1,
			),
			array(
				'id'          => 'builtin_obfuscation_chr',
				'name'        => 'Obfuscation: chr() string building',
				'type'        => 'file_signature',
				'category'    => 'malware',
				'severity'    => 'high',
				'regex'       => '/(chr\s*\(\s*\d+\s*\)\s*\.?\s*){8,}/i',
				'description' => 'Long chain of chr() calls used to obfuscate malicious code.',
				'enabled'     => 1,
			),
			array(
				'id'          => 'builtin_crypto_miner',
				'name'        => 'Crypto Miner: CoinHive/Coinimp',
				'type'        => 'file_signature',
				'category'    => 'malware',
				'severity'    => 'critical',
				'regex'       => '/(coinhive|coin-hive|coinimp|crypto-loot|cryptoloot)\.com/i',
				'description' => 'Browser-based cryptocurrency mining script injection.',
				'enabled'     => 1,
			),
			array(
				'id'          => 'builtin_dynamic_func_call',
				'name'        => 'Backdoor: Variable function invocation',
				'type'        => 'file_signature',
				'category'    => 'malware',
				'severity'    => 'critical',
				'regex'       => '/\$\w+\s*=\s*[\'"](\beval\b|\bassert\b|\bcreate_function\b|\bcall_user_func\b)[\'"];\s*\$\w+\s*\(/i',
				'description' => 'Function name stored in variable then called dynamically — common backdoor obfuscation (Sucuri 2024).',
				'enabled'     => 1,
			),
			array(
				'id'          => 'builtin_create_function',
				'name'        => 'Backdoor: create_function() code execution',
				'type'        => 'file_signature',
				'category'    => 'malware',
				'severity'    => 'critical',
				'regex'       => '/create_function\s*\(\s*[\'"][^\'"]*[\'"]\s*,/i',
				'description' => 'Deprecated create_function() used for dynamic code execution — widely used in obfuscated droppers (Sucuri 2024).',
				'enabled'     => 1,
			),
			array(
				'id'          => 'builtin_call_user_func_input',
				'name'        => 'Backdoor: call_user_func from user input',
				'type'        => 'file_signature',
				'category'    => 'malware',
				'severity'    => 'critical',
				'regex'       => '/call_user_func(_array)?\s*\(\s*\$_(GET|POST|REQUEST|COOKIE|SERVER)/i',
				'description' => 'Arbitrary function execution controlled by user input — enables complete RCE.',
				'enabled'     => 1,
			),
			array(
				'id'          => 'builtin_preg_replace_e',
				'name'        => 'Backdoor: preg_replace /e modifier',
				'type'        => 'file_signature',
				'category'    => 'malware',
				'severity'    => 'critical',
				'regex'       => '/preg_replace\s*\(\s*[\'"][^\'"]*\/e[\'"]/i',
				'description' => 'preg_replace() with /e modifier evaluates replacement as PHP code — removed in PHP 7.0 but still deployed in backdoors (Wordfence 2023).',
				'enabled'     => 1,
			),
			array(
				'id'          => 'builtin_hex_encoding',
				'name'        => 'Obfuscation: Hex-encoded function calls',
				'type'        => 'file_signature',
				'category'    => 'malware',
				'severity'    => 'high',
				'regex'       => '/(\\\\x[0-9a-fA-F]{2}){5,}/i',
				'description' => 'Hex escape sequences encoding function names (e.g. \\x65\\x76\\x61\\x6c = eval) to bypass signature scanners (Sucuri 2024).',
				'enabled'     => 1,
			),
			array(
				'id'          => 'builtin_xor_decrypt_loop',
				'name'        => 'Obfuscation: XOR decryption loop',
				'type'        => 'file_signature',
				'category'    => 'malware',
				'severity'    => 'high',
				'regex'       => '/for\s*\([^)]*\)\s*\{[^}]*\$\w+\[\$\w+\]\s*\^\s*\$/is',
				'description' => 'XOR decryption loop — hallmark of Sign1 malware campaign (39,000+ WordPress sites, 2024) and SocGholish variants.',
				'enabled'     => 1,
			),
			array(
				'id'          => 'builtin_reinfector_plugin',
				'name'        => 'Reinfector: file_put_contents to plugin path',
				'type'        => 'file_signature',
				'category'    => 'malware',
				'severity'    => 'critical',
				'regex'       => '/file_put_contents\s*\(\s*[^,]*(plugin|theme|wp-content)[^,]*,\s*\$/i',
				'description' => 'Writes PHP code to plugin/theme paths — PHP Reinfector malware spreads by injecting into active plugins (Sucuri Nov 2024).',
				'enabled'     => 1,
			),
			array(
				'id'          => 'builtin_phar_wrapper',
				'name'        => 'PHP Stream Wrapper: phar/zip/compress',
				'type'        => 'file_signature',
				'category'    => 'injection',
				'severity'    => 'critical',
				'regex'       => '/(phar|zip|glob|compress\.zlib|compress\.bzip2):\/\//i',
				'description' => 'PHP stream wrappers used for Local File Inclusion or include path bypass — exploited in CVE-2026-32537 and similar LFI attacks.',
				'enabled'     => 1,
			),
			array(
				'id'          => 'builtin_fake_wp_function',
				'name'        => 'Backdoor: Fake WordPress function with eval/exec',
				'type'        => 'file_signature',
				'category'    => 'malware',
				'severity'    => 'high',
				'regex'       => '/function\s+wp_[a-z_]{3,30}\s*\([^)]*\)\s*\{[^}]*(eval|base64_decode|system|exec|shell_exec)/is',
				'description' => 'Function with wp_ prefix embedding backdoor code — camouflaged as core WordPress function (Sucuri 2020-2024).',
				'enabled'     => 1,
			),
			array(
				'id'          => 'builtin_credential_harvest',
				'name'        => 'Credential Harvester: Write POST password to file',
				'type'        => 'file_signature',
				'category'    => 'malware',
				'severity'    => 'critical',
				'regex'       => '/(file_put_contents|fwrite)\s*\([^,]+,\s*[^)]*\$_(POST|GET|REQUEST)\s*\[[\'\"](password|pass|pwd|log|user)/i',
				'description' => 'Captures login credentials from POST data and writes to file — PHP Reinfector credential theft (Sucuri 2024).',
				'enabled'     => 1,
			),
			array(
				'id'          => 'builtin_unserialize_input',
				'name'        => 'PHP Object Injection: unserialize user input',
				'type'        => 'file_signature',
				'category'    => 'injection',
				'severity'    => 'critical',
				'regex'       => '/unserialize\s*\(\s*\$_(GET|POST|REQUEST|COOKIE|SERVER)/i',
				'description' => 'Direct deserialization of user input — enables PHP Object Injection RCE. CVE-2024-8353 (GiveWP), CVE-2025-7384 (CVSS 9.8).',
				'enabled'     => 1,
			),
			array(
				'id'          => 'builtin_direct_wpdb_users',
				'name'        => 'Backdoor: Direct $wpdb write to wp_users',
				'type'        => 'file_signature',
				'category'    => 'malware',
				'severity'    => 'critical',
				'regex'       => '/\$wpdb\s*->\s*(insert|query|replace)\s*\([^)]*wp_users/i',
				'description' => 'Direct database write to wp_users table outside core API — mu-plugins backdoor creates admin accounts this way (Sucuri July 2025).',
				'enabled'     => 1,
			),
			array(
				'id'          => 'builtin_rot13_fetch',
				'name'        => 'Backdoor: str_rot13 + remote fetch',
				'type'        => 'file_signature',
				'category'    => 'malware',
				'severity'    => 'critical',
				'regex'       => '/str_rot13\s*\([^)]+\).*\b(file_get_contents|curl_exec|wp_remote_get|fsockopen)\b/is',
				'description' => 'ROT13-obfuscated URL with remote code fetch — active mu-plugins backdoor campaign (Sucuri March-July 2025).',
				'enabled'     => 1,
			),
			array(
				'id'          => 'builtin_str_replace_func',
				'name'        => 'Obfuscation: str_replace function name assembly',
				'type'        => 'file_signature',
				'category'    => 'malware',
				'severity'    => 'high',
				'regex'       => '/\$\w+\s*=\s*str_replace\s*\([^)]+\)\s*;\s*\$\w+\s*\(/i',
				'description' => 'Constructs function name via str_replace then calls it — e.g. str_replace("_","","ev_al") (Wordfence "Know Your Malware" 2023).',
				'enabled'     => 1,
			),
			array(
				'id'          => 'builtin_gif_php_polyglot',
				'name'        => 'Polyglot: GIF header with PHP code',
				'type'        => 'file_signature',
				'category'    => 'malware',
				'severity'    => 'critical',
				'regex'       => '/GIF89a.*<\?php/is',
				'description' => 'File disguised as GIF image but contains PHP code — polyglot backdoor bypasses upload filters (Trustwave SpiderLabs).',
				'enabled'     => 1,
			),
			array(
				'id'          => 'builtin_alt_php_extension',
				'name'        => 'Suspicious: Alternative PHP file extension in uploads',
				'type'        => 'file_signature',
				'category'    => 'malware',
				'severity'    => 'high',
				'regex'       => '/\.(phtml|php5|php7|phar|phps|php\.suspected)$/i',
				'description' => 'Alternative PHP extensions that may execute as PHP — used to bypass .php upload blocks.',
				'enabled'     => 1,
			),

			// ── Database Patterns ───────────────────────────────────────
			array(
				'id'          => 'builtin_redirect_malware',
				'name'        => 'Malicious Redirect',
				'type'        => 'database_pattern',
				'category'    => 'malware',
				'severity'    => 'high',
				'regex'       => '/<script[^>]*>[^<]*(window\.location|document\.location|top\.location)\s*=\s*["\'][^"\']*["\'][^<]*<\/script>/is',
				'description' => 'JavaScript redirect injected into database content.',
				'enabled'     => 1,
			),
			array(
				'id'          => 'builtin_seo_spam',
				'name'        => 'SEO Spam: Hidden links',
				'type'        => 'database_pattern',
				'category'    => 'spam',
				'severity'    => 'medium',
				'regex'       => '/<(div|span|p)[^>]*(display\s*:\s*none|font-size\s*:\s*0|visibility\s*:\s*hidden)[^>]*>.*?<a\b[^>]+href/is',
				'description' => 'Hidden content with links — common SEO spam technique.',
				'enabled'     => 1,
			),
			array(
				'id'          => 'builtin_cc_skimmer_checkout',
				'name'        => 'Credit Card Skimmer: Checkout page activation',
				'type'        => 'database_pattern',
				'category'    => 'malware',
				'severity'    => 'critical',
				'regex'       => '/(checkout|cart|payment).*\b(XMLHttpRequest|fetch\s*\(|\.send\s*\()/is',
				'description' => 'Skimmer script activates on checkout pages and exfiltrates data via XHR/fetch — 39,000+ WooCommerce sites targeted (Sucuri Jan 2025).',
				'enabled'     => 1,
			),
			array(
				'id'          => 'builtin_cc_skimmer_fields',
				'name'        => 'Credit Card Skimmer: Payment field capture',
				'type'        => 'database_pattern',
				'category'    => 'malware',
				'severity'    => 'critical',
				'regex'       => '/\b(card.?number|ccnum|cvv|card.?cvv|expir|cc.?exp|billing)\b.*\b(keyup|input|change|blur)\b/is',
				'description' => 'Event listeners targeting payment field names — definitive credit card skimmer signature.',
				'enabled'     => 1,
			),
			array(
				'id'          => 'builtin_fromcharcode_array',
				'name'        => 'Obfuscation: String.fromCharCode array injection',
				'type'        => 'database_pattern',
				'category'    => 'malware',
				'severity'    => 'high',
				'regex'       => '/String\.fromCharCode\s*\((\s*\d+\s*,\s*){10,}/i',
				'description' => 'Long String.fromCharCode() array in DB content — Balada Injector signature (149,351 infections in 2024).',
				'enabled'     => 1,
			),
			array(
				'id'          => 'builtin_balada_hex_array',
				'name'        => 'Balada Injector: Obfuscator.io hex array',
				'type'        => 'database_pattern',
				'category'    => 'malware',
				'severity'    => 'high',
				'regex'       => '/var\s+_?0x\w+\s*=\s*\[[\'\"0x][^\]]{100,}\]/i',
				'description' => 'Obfuscator.io-generated hex array — distinctive Balada Injector #1 malware campaign signature 2024 (Darktrace).',
				'enabled'     => 1,
			),
			array(
				'id'          => 'builtin_tds_referrer_redirect',
				'name'        => 'TDS Redirect: Referrer-based conditional redirect',
				'type'        => 'database_pattern',
				'category'    => 'malware',
				'severity'    => 'high',
				'regex'       => '/\b(document\.referrer|navigator\.userAgent)\b.*\b(google|facebook|yahoo|bing)\b.*\b(window\.location|location\.href)\b/is',
				'description' => 'Only redirects visitors from search engines — Sign1, SocGholish, VexTrio Traffic Distribution System fingerprint (2024).',
				'enabled'     => 1,
			),
			array(
				'id'          => 'builtin_db_eval_payload',
				'name'        => 'Backdoor: PHP execution code in wp_options',
				'type'        => 'database_pattern',
				'category'    => 'malware',
				'severity'    => 'critical',
				'regex'       => '/(eval|assert|base64_decode|gzinflate)\s*\([^)]{30,}\)/i',
				'description' => 'PHP execution functions with large payloads stored in options table — mu-plugins backdoor stores next-stage payload in wp_options (Sucuri 2025).',
				'enabled'     => 1,
			),
			array(
				'id'          => 'builtin_btoa_exfil',
				'name'        => 'Exfiltration: btoa + fetch POST in DB content',
				'type'        => 'database_pattern',
				'category'    => 'malware',
				'severity'    => 'critical',
				'regex'       => '/\bbtoa\s*\(.*\bfetch\s*\([\'"][^"\']{10,}[\'"]\s*,\s*\{[^}]*method[^}]*POST/is',
				'description' => 'Base64-encodes stolen data then POSTs to attacker server via fetch — modern credit card skimmer exfiltration (Sucuri Jan 2025).',
				'enabled'     => 1,
			),

			// ── wp2shell Indicators (CVE-2026-63030 / CVE-2026-60137) ──
			array(
				'id'          => 'builtin_wp2shell_batch_payload',
				'name'        => 'wp2shell: Batch API malformed sub-request',
				'type'        => 'waf_rule',
				'category'    => 'wp2shell',
				'severity'    => 'critical',
				'regex'       => '/batch\/v\d.*"path"\s*:\s*"[^"]*[%\\\\]/i',
				'description' => 'REST API batch sub-request with encoded/malformed path — triggers route-confusion desync in WP_REST_Server::serve_batch_request_v1() (CVE-2026-63030).',
				'enabled'     => 1,
			),
			array(
				'id'          => 'builtin_wp2shell_author_notin_sqli',
				'name'        => 'wp2shell: author__not_in SQL injection',
				'type'        => 'waf_rule',
				'category'    => 'wp2shell',
				'severity'    => 'critical',
				'regex'       => '/author__not_in\s*=\s*[^&]*[\'";]/i',
				'description' => 'author__not_in parameter with SQL metacharacters — scalar string injection into WP_Query (CVE-2026-60137).',
				'enabled'     => 1,
			),
			array(
				'id'          => 'builtin_wp2shell_shell_upload',
				'name'        => 'wp2shell: Webshell plugin upload via batch API',
				'type'        => 'file_signature',
				'category'    => 'wp2shell',
				'severity'    => 'critical',
				'regex'       => '/<\?php\s*.{0,50}(wp2shell|WP-SHELLSTORM|batch.*exploit)/is',
				'description' => 'PHP file containing wp2shell/WP-SHELLSTORM indicators — webshell deployed via batch-route RCE chain.',
				'enabled'     => 1,
			),

			// ── WAF Rules ───────────────────────────────────────────────
			array(
				'id'          => 'builtin_waf_xml_entity',
				'name'        => 'WAF: XXE (XML External Entity)',
				'type'        => 'waf_rule',
				'category'    => 'xxe',
				'severity'    => 'critical',
				'regex'       => '/<!ENTITY\s+/i',
				'description' => 'XML External Entity injection attempt.',
				'enabled'     => 1,
			),
			array(
				'id'          => 'builtin_waf_php_self',
				'name'        => 'WAF: PHP_SELF manipulation',
				'type'        => 'waf_rule',
				'category'    => 'xss',
				'severity'    => 'high',
				'regex'       => '/%3Cscript/i',
				'description' => 'URL-encoded script tag, often used in PHP_SELF XSS attacks.',
				'enabled'     => 1,
			),
			array(
				'id'          => 'builtin_waf_php_object',
				'name'        => 'WAF: PHP Object Injection in request',
				'type'        => 'waf_rule',
				'category'    => 'injection',
				'severity'    => 'critical',
				'regex'       => '/O:\d+:"[A-Za-z_][A-Za-z0-9_]*":\d+:\{/i',
				'description' => 'Serialized PHP object in request parameter — CVE-2024-8353 (GiveWP), CVE-2025-7384 (CVSS 9.8). Patchstack top attack vector 2025.',
				'enabled'     => 1,
			),
			array(
				'id'          => 'builtin_waf_sqli_file',
				'name'        => 'WAF: SQL Injection LOAD_FILE/INTO OUTFILE',
				'type'        => 'waf_rule',
				'category'    => 'sqli',
				'severity'    => 'critical',
				'regex'       => '/\b(load_file|into\s+outfile|into\s+dumpfile)\b/i',
				'description' => 'MySQL file read/write via SQL injection — used to exfiltrate wp-config.php or write PHP shell to webroot.',
				'enabled'     => 1,
			),
			array(
				'id'          => 'builtin_waf_sqli_error',
				'name'        => 'WAF: SQL Injection extractvalue/updatexml',
				'type'        => 'waf_rule',
				'category'    => 'sqli',
				'severity'    => 'high',
				'regex'       => '/\b(extractvalue|updatexml)\s*\(\s*\d*\s*,\s*(\(|concat|0x)/i',
				'description' => 'Error-based SQL injection via MySQL XML functions — exploited in CVE-2024-27956, CVE-2025-5287 (sqlmap automated).',
				'enabled'     => 1,
			),
			array(
				'id'          => 'builtin_waf_ssrf_internal',
				'name'        => 'WAF: SSRF targeting internal network',
				'type'        => 'waf_rule',
				'category'    => 'ssrf',
				'severity'    => 'critical',
				'regex'       => '/(127\.0\.0\.1|localhost|0\.0\.0\.0|169\.254\.\d+\.\d+|10\.\d+\.\d+\.\d+|192\.168\.\d+\.\d+|172\.(1[6-9]|2\d|3[01])\.\d+\.\d+)/i',
				'description' => 'Internal/private IP addresses in request — SSRF targeting AWS metadata (169.254.169.254), localhost, or private networks.',
				'enabled'     => 1,
			),
			array(
				'id'          => 'builtin_waf_crlf_injection',
				'name'        => 'WAF: HTTP Header Injection / CRLF',
				'type'        => 'waf_rule',
				'category'    => 'injection',
				'severity'    => 'high',
				'regex'       => '/(%0d%0a|%0a%0d|%0d|%0a).*(Set-Cookie|Location|Content-Type)/i',
				'description' => 'CRLF injection in parameters to inject HTTP response headers — enables response splitting and cookie injection.',
				'enabled'     => 1,
			),
			array(
				'id'          => 'builtin_waf_xmlrpc_pingback',
				'name'        => 'WAF: XML-RPC Pingback SSRF/DDoS',
				'type'        => 'waf_rule',
				'category'    => 'ssrf',
				'severity'    => 'high',
				'regex'       => '/pingback\.ping/i',
				'description' => 'XML-RPC pingback method — used for DDoS amplification and blind SSRF (Sonar/Patchstack WordPress core SSRF).',
				'enabled'     => 1,
			),
		);
	}

	// ── AJAX handlers ────────────────────────────────────────────────────

	public function ajax_save() {
		$this->verify_ajax();
		$raw    = isset( $_POST['pattern'] ) ? wp_unslash( $_POST['pattern'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$data   = is_array( $raw ) ? array_map( 'sanitize_text_field', $raw ) : array();
		// Regex needs the raw value (sanitize_text_field strips slashes/tags needed in patterns).
		if ( ! empty( $raw['regex'] ) ) {
			$data['regex'] = wp_unslash( $raw['regex'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		}
		$result = $this->save( $data );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}
		wp_send_json_success( array( 'pattern' => $result, 'count' => count( $this->all() ) ) );
	}

	public function ajax_delete() {
		$this->verify_ajax();
		$id = isset( $_POST['pattern_id'] ) ? sanitize_key( wp_unslash( $_POST['pattern_id'] ) ) : '';
		if ( ! $id ) {
			wp_send_json_error( array( 'message' => __( 'Pattern ID is required.', 'codewp-shield-monitor' ) ) );
		}
		$this->delete( $id );
		wp_send_json_success( array( 'count' => count( $this->all() ) ) );
	}

	public function ajax_toggle() {
		$this->verify_ajax();
		$id = isset( $_POST['pattern_id'] ) ? sanitize_key( wp_unslash( $_POST['pattern_id'] ) ) : '';
		if ( ! $id ) {
			wp_send_json_error( array( 'message' => __( 'Pattern ID is required.', 'codewp-shield-monitor' ) ) );
		}
		$this->toggle( $id );
		wp_send_json_success();
	}

	public function export_patterns() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Access denied.', 'codewp-shield-monitor' ) );
		}
		check_admin_referer( 'shield_monitor_export_patterns' );

		$patterns = $this->all();
		$filename = 'shieldpress-threat-patterns-' . gmdate( 'Y-m-d' ) . '.json';

		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		echo wp_json_encode( $patterns, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE );
		exit;
	}

	public function import_patterns() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Access denied.', 'codewp-shield-monitor' ) );
		}
		check_admin_referer( 'shield_monitor_import_patterns' );

		if ( empty( $_FILES['patterns_file']['tmp_name'] ) ) {
			wp_safe_redirect( admin_url( 'admin.php?page=codewp-shield-monitor&sm_notice=import_no_file#threat-patterns' ) );
			exit;
		}

		$content  = file_get_contents( $_FILES['patterns_file']['tmp_name'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents,WordPress.Security.ValidatedSanitizedInput
		$imported = json_decode( $content, true );

		if ( ! is_array( $imported ) ) {
			wp_safe_redirect( admin_url( 'admin.php?page=codewp-shield-monitor&sm_notice=import_invalid#threat-patterns' ) );
			exit;
		}

		$count = 0;
		foreach ( $imported as $item ) {
			if ( ! is_array( $item ) || empty( $item['regex'] ) ) {
				continue;
			}
			// Generate new ID to avoid overwriting existing.
			$item['id'] = 'imported_' . wp_generate_uuid4();
			$result = $this->save( $item );
			if ( ! is_wp_error( $result ) ) {
				$count++;
			}
		}

		wp_safe_redirect( admin_url( 'admin.php?page=codewp-shield-monitor&sm_notice=imported&sm_count=' . $count . '#threat-patterns' ) );
		exit;
	}

	// ── Helpers ──────────────────────────────────────────────────────────

	private function verify_ajax() {
		if ( ! current_user_can( 'manage_options' ) || ! check_ajax_referer( 'shield_monitor_threat_pattern', '_nonce', false ) ) {
			wp_send_json_error( array( 'message' => __( 'Access denied.', 'codewp-shield-monitor' ) ) );
		}
	}

	private function is_valid_regex( $regex ) {
		if ( ! is_string( $regex ) || '' === $regex ) {
			return false;
		}
		set_error_handler( function () {}, E_WARNING ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler
		$result = preg_match( $regex, '' );
		restore_error_handler();
		return false !== $result;
	}

	private function __construct() {}
	private function __clone() {}
}
