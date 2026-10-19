<?php
if (!getenv('SHIELDPRESS_LAB_DIR')) exit('SHIELDPRESS_LAB_DIR is required');
$_SERVER['HTTP_HOST']='127.0.0.1:18773'; $_SERVER['REQUEST_METHOD']='GET'; $_SERVER['REQUEST_URI']='/';
require rtrim(getenv('SHIELDPRESS_LAB_DIR'),'/').'/wordpress/wp-load.php';
if(wp_get_environment_type()!=='local' || get_bloginfo('name')!=='ShieldPress isolated lab') exit('Lab only');
require_once ABSPATH.'wp-admin/includes/upgrade.php';
global $wpdb;
foreach ($wpdb->ms_global_tables as $table) $wpdb->$table=$wpdb->base_prefix.$table;
install_network();
$r=populate_network(1,'127.0.0.1','lab-admin@example.invalid','ShieldPress lab network','/',false);
if(is_wp_error($r)) { echo implode('; ',$r->get_error_messages()); exit(1); }
echo "Network tables installed in lab database\n";
