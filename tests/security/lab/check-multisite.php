<?php
if (!getenv('SHIELDPRESS_LAB_DIR')) exit('SHIELDPRESS_LAB_DIR is required');
define('MULTISITE',true);
define('SUBDOMAIN_INSTALL',false);
define('DOMAIN_CURRENT_SITE','127.0.0.1');
define('PATH_CURRENT_SITE','/');
define('SITE_ID_CURRENT_SITE',1);
define('BLOG_ID_CURRENT_SITE',1);
$_SERVER['HTTP_HOST']='127.0.0.1'; $_SERVER['REQUEST_METHOD']='GET'; $_SERVER['REQUEST_URI']='/';
require rtrim(getenv('SHIELDPRESS_LAB_DIR'),'/').'/wordpress/wp-load.php';
if(wp_get_environment_type()!=='local') exit('Lab only');
wp_set_current_user(1);
// Ensure the same plugin is loaded on this lab site when booted as a network.
if(!class_exists('Shield_Monitor_Advanced_Hardening')) require WP_PLUGIN_DIR.'/shieldpress/shield-monitor.php';
$original=get_option('shield_monitor_settings');
$results=[];
$results[]=['name'=>'actual multisite bootstrap','passed'=>is_multisite()];
$results[]=['name'=>'actual network super admin','passed'=>is_super_admin(1)];
$GLOBALS['lab_hardening'] = new Shield_Monitor_Advanced_Hardening(new Shield_Monitor_Settings,new Shield_Monitor_Audit_Log);
// Remove only the real registered map_meta_cap callback to reproduce the old gap.
global $wp_filter;
$removed=[];
foreach (($wp_filter['map_meta_cap']->callbacks[10] ?? []) as $callback) {
 $f=$callback['function'];
 if(is_array($f) && $f[0] instanceof Shield_Monitor_Advanced_Hardening && $f[1]==='restrict_software_meta_caps') { $removed[]=$f; remove_filter('map_meta_cap',$f,10); }
}
update_option('shield_monitor_settings',array_merge($original,['disable_software_installation'=>1]));
$results[]=['name'=>'old user_has_cap-only lock bypassed by super admin','passed'=>current_user_can('install_plugins')];
foreach($removed as $callback) add_filter('map_meta_cap',$callback,10,4);
$results[]=['name'=>'map_meta_cap fix denies super admin','passed'=>!current_user_can('install_plugins')];
foreach([0,1] as $lock) {
 update_option('shield_monitor_settings',array_merge($original,['disable_software_installation'=>$lock]));
 foreach(['install_plugins','upload_plugins','delete_plugins','install_themes','upload_themes','delete_themes'] as $cap) $results[]=['name'=>$cap.' lock='.$lock,'passed'=>current_user_can($cap)===(bool)!$lock];
 $results[]=['name'=>'read lock='.$lock,'passed'=>current_user_can('read')];
}
update_option('shield_monitor_settings',$original);
$report=['checks'=>count($results),'failures'=>count(array_filter($results,function($r){return !$r['passed'];})),'results'=>$results];
file_put_contents(getenv('SHIELDPRESS_LAB_DIR').'/multisite-checks.json',json_encode($report,JSON_PRETTY_PRINT));
echo json_encode($report,JSON_PRETTY_PRINT)."\n";
exit($report['failures']?1:0);
