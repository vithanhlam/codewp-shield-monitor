<?php
if (!getenv('SHIELDPRESS_LAB_DIR')) exit('SHIELDPRESS_LAB_DIR is required');
$_SERVER['HTTP_HOST'] = '127.0.0.1:18773';
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REQUEST_URI'] = '/';
require rtrim( getenv( 'SHIELDPRESS_LAB_DIR' ), '/' ) . '/wordpress/wp-load.php';
if ( wp_get_environment_type() !== 'local' || get_bloginfo('name') !== 'ShieldPress isolated lab' ) exit('Lab only');
require_once ABSPATH . 'wp-admin/includes/plugin.php';
$checks = [];
function verify_release($name, $condition) { global $checks; $checks[] = ['name'=>$name,'passed'=>(bool)$condition]; }
$settings = new Shield_Monitor_Settings; $logger = new Shield_Monitor_Audit_Log;
$original = get_option('shield_monitor_settings');
verify_release('release version 1.5.0', SHIELD_MONITOR_VERSION === '1.5.0');
foreach (['Shield_Monitor_License', 'Shield_Monitor_License_Page', 'Shield_Monitor_Addons', 'Shield_Monitor_Addon_Pages', 'Shield_Monitor_Push'] as $class) verify_release('retired class not loaded: '.$class, !class_exists($class));

update_option('shieldpress_license_key', 'lab-dummy-not-a-real-key');
update_option('shieldpress_license_net_failures', 2);
set_transient('shieldpress_license_data', ['plan'=>'pro'], 100);
wp_schedule_single_event(time()+3600, 'shield_monitor_push_metrics');
delete_option('shield_monitor_premium_retired');
Shield_Monitor_Activator::retire_premium();
verify_release('legacy key removed', get_option('shieldpress_license_key', false) === false);
verify_release('legacy failure state removed', get_option('shieldpress_license_net_failures', false) === false);
verify_release('license cache removed', get_transient('shieldpress_license_data') === false);
verify_release('obsolete metrics job removed', wp_next_scheduled('shield_monitor_push_metrics') === false);
verify_release('daily license verification unhooked', !has_action('shield_monitor_daily_cleanup', ['Shield_Monitor_License','daily_verify']));

$fixture_dir = WP_PLUGIN_DIR . '/breeze';
if (file_exists($fixture_dir)) throw new RuntimeException('Lab fixture directory already exists');
mkdir($fixture_dir);
file_put_contents($fixture_dir.'/breeze.php', "<?php\n/* Plugin Name: Lab inactive vulnerable version fixture\nVersion: 2.4.4\n*/\n");
wp_clean_plugins_cache(false);
$alerts = new Shield_Monitor_Vulnerability_Alert($settings, $logger);
$scan = new ReflectionMethod($alerts, 'scan_plugins');
$scan->setAccessible(true);
$findings = $scan->invoke($alerts);
$matches = array_filter($findings, function($v) { return $v['cve_id'] === 'CVE-2026-3844' && $v['fixed_in'] === '2.4.5'; });
verify_release('offline advisory found through real installed-plugin scan', count($matches) === 1);
verify_release('current core is not incorrectly flagged', !array_filter($findings, function($v) { return ($v['component_type'] ?? '') === 'core'; }));
unlink($fixture_dir.'/breeze.php'); rmdir($fixture_dir); wp_clean_plugins_cache(false);

$routes = rest_get_server()->get_routes();
foreach (array_keys($routes) as $route) {
 if (strpos($route, '/codewp-shield-monitor/v1/') === 0) verify_release('no paid endpoint: '.$route, !preg_match('/license|addon|premium/', $route));
}
foreach (['pair','status','events','token/rotate','quick-login'] as $route) verify_release('App route remains: '.$route, isset($routes['/codewp-shield-monitor/v1/'.$route]));
wp_set_current_user(0);
$r = rest_do_request(new WP_REST_Request('GET', '/codewp-shield-monitor/v1/vulnerabilities'));
verify_release('anonymous CVE details denied', in_array($r->get_status(), [401,403], true));
wp_set_current_user(1);
$r = rest_do_request(new WP_REST_Request('GET', '/codewp-shield-monitor/v1/vulnerabilities'));
verify_release('administrator can read CVE details', $r->get_status() === 200);
wp_set_current_user(0);
$old_tokens = get_option(Shield_Monitor_API::TOKENS_OPTION, []);
$api = new Shield_Monitor_API(new Shield_Monitor_Status($settings,$logger),$logger);
$token = bin2hex(random_bytes(32));
$hash = new ReflectionMethod($api,'token_hash'); $hash->setAccessible(true);
update_option(Shield_Monitor_API::TOKENS_OPTION, ['lab'=>['hash'=>$hash->invoke($api,$token),'scopes'=>['status:read'],'expires_at'=>gmdate('c',time()+3600)]]);
$request = new WP_REST_Request('GET', '/codewp-shield-monitor/v1/vulnerabilities');
$request->set_header('Authorization','Bearer '.$token);
$r = rest_do_request($request);
verify_release('paired status:read token can read CVE details', $r->get_status() === 200);
update_option(Shield_Monitor_API::TOKENS_OPTION, ['lab'=>['hash'=>$hash->invoke($api,$token),'scopes'=>['events:read'],'expires_at'=>gmdate('c',time()+3600)]]);
$r = rest_do_request($request);
verify_release('token lacking status:read denied', $r->get_status() === 403);
update_option(Shield_Monitor_API::TOKENS_OPTION, $old_tokens);
update_option('shield_monitor_settings', $original);
$result = ['checks'=>count($checks),'failures'=>count(array_filter($checks,function($r){return !$r['passed'];})),'results'=>$checks];
file_put_contents(getenv('SHIELDPRESS_LAB_DIR').'/release-checks.json',json_encode($result,JSON_PRETTY_PRINT));
echo json_encode($result,JSON_PRETTY_PRINT)."\n";
exit($result['failures']?1:0);
