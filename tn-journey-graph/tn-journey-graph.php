<?php
/**
 * Plugin Name: TN Journey Graph
 * Plugin URI: https://github.com/cchatterton/tn-journey-graph/releases/latest
 * Description: Front-end journey exploration for authorised users, powered by completed Independent Analytics sessions.
 * Version: 0.2.9
 * Requires at least: 6.0
 * Requires PHP: 8.1
 * Update URI: https://github.com/cchatterton/tn-journey-graph
 * Author: Techn
 * Author URI: https://techn.com.au
 * Text Domain: tn-journey-graph
 */

if (!defined('ABSPATH')) {
    exit;
}

define('TNJG_VERSION', '0.2.9');
define('TNJG_GRAPH_SCHEMA_VERSION', '12');
define('TNJG_PLUGIN_FILE', __FILE__);
define('TNJG_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('TNJG_PLUGIN_URL', plugin_dir_url(__FILE__));
define('TNJG_PLUGIN_BASENAME', plugin_basename(__FILE__));

require_once TNJG_PLUGIN_DIR . 'functions/helpers.php';
require_once TNJG_PLUGIN_DIR . 'functions/setup.php';
require_once TNJG_PLUGIN_DIR . 'functions/assets.php';
require_once TNJG_PLUGIN_DIR . 'functions/admin.php';
require_once TNJG_PLUGIN_DIR . 'functions/rest.php';
require_once TNJG_PLUGIN_DIR . 'functions/processor.php';
require_once TNJG_PLUGIN_DIR . 'functions/updater.php';

register_activation_hook(TNJG_PLUGIN_FILE, 'tnjg_activate');
register_deactivation_hook(TNJG_PLUGIN_FILE, 'tnjg_deactivate');

add_action('plugins_loaded', 'tnjg_boot');
