<?php
if (!getenv('SHIELDPRESS_LAB_DIR')) exit('SHIELDPRESS_LAB_DIR is required');
$_SERVER['HTTP_HOST'] = '127.0.0.1:18773';
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REQUEST_URI'] = '/';
require rtrim( getenv( 'SHIELDPRESS_LAB_DIR' ), '/' ) . '/wordpress/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php';
if (wp_get_environment_type() !== 'local' || get_bloginfo('name') !== 'ShieldPress isolated lab') exit('Lab only');
$action = $argv[1] ?? 'state';
if ($action === 'generate') {
 $keys = [];
 foreach (['site', 'sender'] as $side) {
  $key = openssl_pkey_new(['private_key_bits'=>2048, 'private_key_type'=>OPENSSL_KEYTYPE_RSA]);
  openssl_pkey_export($key, $private);
  $keys[$side.'_private'] = $private;
  $keys[$side.'_public'] = openssl_pkey_get_details($key)['key'];
 }
 file_put_contents(getenv('SHIELDPRESS_LAB_DIR') . '/keys.json', json_encode($keys));
 chmod(getenv('SHIELDPRESS_LAB_DIR') . '/keys.json', 0600);
 echo "Generated isolated lab keys\n";
} elseif ($action === 'setup') {
 $dir = WP_PLUGIN_DIR . '/shieldpress';
 if (!is_dir($dir)) mkdir($dir);
 $r = activate_plugin('shieldpress/shield-monitor.php');
 if (is_wp_error($r)) throw new RuntimeException($r->get_error_message());
 $settings = (new Shield_Monitor_Settings)->defaults();
 foreach (['file_monitoring','malware_scanning','email_alerts','alert_vulnerability','login_protection'] as $key) $settings[$key] = 0;
 update_option('shield_monitor_settings', $settings);
 echo "ShieldPress activated in lab\n";
} elseif ($action === 'settings') {
 $settings = get_option('shield_monitor_settings', []);
 foreach (json_decode($argv[2], true) as $key=>$value) $settings[$key] = $value;
 update_option('shield_monitor_settings', $settings);
 echo "Updated lab settings\n";
} elseif ($action === 'keys') {
 if (($argv[2] ?? '') === 'none') {
  delete_option('updraft_central_localkeys');
  delete_site_option('updraft_central_localkeys');
  delete_option('updraft_migrator_localkeys');
  echo "Removed lab keys\n";
 } else {
  $keys = json_decode(file_get_contents(getenv('SHIELDPRESS_LAB_DIR') . '/keys.json'), true);
  $local = ['audit'=>['key'=>$keys['site_private'], 'publickey_remote'=>$keys['sender_public'], 'extra_info'=>['user_id'=>1, 'mothership'=>'https://lab.invalid']]];
  update_option('updraft_central_localkeys', $local);
  update_site_option('updraft_central_localkeys', $local);
  echo "Set lab-only authorized key\n";
 }
} elseif ($action === 'message') {
 global $updraftcentral_host_plugin;
 $keys = json_decode(file_get_contents(getenv('SHIELDPRESS_LAB_DIR') . '/keys.json'), true);
 $rpc = $updraftcentral_host_plugin->get_udrpc('audit.central.updraftplus.com');
 $rpc->set_key_local($keys['sender_private']);
 $rpc->set_key_remote($keys['site_public']);
 $command = $argv[2]; $data = json_decode($argv[3] ?? '{}', true);
 echo json_encode($rpc->create_message($command, $data));
} elseif ($action === 'decode') {
 global $updraftcentral_host_plugin;
 $keys = json_decode(file_get_contents(getenv('SHIELDPRESS_LAB_DIR') . '/keys.json'), true);
 $rpc = $updraftcentral_host_plugin->get_udrpc('audit.central.updraftplus.com');
 $rpc->set_key_local($keys['sender_private']);
 $raw = json_decode(stream_get_contents(STDIN), true);
 echo $rpc->decrypt_message($raw['udrpc_message']);
} elseif ($action === 'cookie') {
 echo LOGGED_IN_COOKIE . '=' . wp_generate_auth_cookie(1, time()+3600, 'logged_in') . '; ' . AUTH_COOKIE . '=' . wp_generate_auth_cookie(1, time()+3600, 'auth');
} elseif ($action === 'state') {
 $found = [];
 foreach (glob(WP_PLUGIN_DIR . '/shieldpress-audit-*/*.php') ?: [] as $file) $found[] = ['file'=>basename(dirname($file)).'/'.basename($file), 'active'=>is_plugin_active(basename(dirname($file)).'/'.basename($file))];
 echo json_encode(['wordpress'=>get_bloginfo('version'), 'plugins'=>$found, 'activation_markers'=>get_option('shieldpress_lab_activated', []), 'has_keys'=>!empty(get_option('updraft_central_localkeys')), 'waf_log'=>get_transient('shield_monitor_waf_blocked_log')]);
}
