<?php
/**
 * Plugin Name: Roxy Show Tickets (WooCommerce)
 * Description: Show-specific ticketing with per-showing hidden products (avoids cart collisions), capacity controls, and subscriber tickets per show (based on active subscriptions).
 * Version: 0.2.10.37
 * Author: Newport Roxy (AI Team)
 * Update URI: https://github.com/Tototex/roxy-show-tickets
 */

if (!defined('ABSPATH')) exit;

define('ROXY_ST_VER', '0.2.10.37');
define('ROXY_ST_PATH', plugin_dir_path(__FILE__));
define('ROXY_ST_URL', plugin_dir_url(__FILE__));

define('ROXY_ST_LOG_SOURCE', 'roxy-st');

define('ROXY_ST_META_SHOWING_ID', '_roxy_showing_id');
define('ROXY_ST_META_TICKET_TYPE', '_roxy_ticket_type');

require_once ROXY_ST_PATH . 'includes/class-roxy-st-cpt.php';
require_once ROXY_ST_PATH . 'includes/class-roxy-st-log.php';
require_once ROXY_ST_PATH . 'includes/class-roxy-st-settings.php';
require_once ROXY_ST_PATH . 'includes/class-roxy-st-sales.php';
require_once ROXY_ST_PATH . 'includes/class-roxy-st-tickets.php';
require_once ROXY_ST_PATH . 'includes/class-roxy-st-products.php';
require_once ROXY_ST_PATH . 'includes/class-roxy-st-capacity.php';
require_once ROXY_ST_PATH . 'includes/class-roxy-st-frontend.php';
require_once ROXY_ST_PATH . 'includes/class-roxy-st-updater.php';

register_activation_hook(__FILE__, function () {
  if (!class_exists('WooCommerce')) {
    deactivate_plugins(plugin_basename(__FILE__));
    wp_die('Roxy Show Tickets requires WooCommerce to be installed and active.');
  }

  \RoxyST\CPT::register();
  flush_rewrite_rules();
});

register_deactivation_hook(__FILE__, function () {
  flush_rewrite_rules();
});

add_action('plugins_loaded', function () {
  if (!class_exists('WooCommerce')) return;

  \RoxyST\Updater::init([
    'plugin_file' => plugin_basename(__FILE__),
    'version'     => ROXY_ST_VER,
    'github_repo' => 'Tototex/roxy-show-tickets',
    'slug'        => 'roxy-show-tickets',
    'name'        => 'Roxy Show Tickets (WooCommerce)',
  ]);

  \RoxyST\Settings::init();
  \RoxyST\Sales::init();
  \RoxyST\Tickets::init();
  \RoxyST\CPT::init();
  \RoxyST\Products::init();
  \RoxyST\Capacity::init();
  \RoxyST\Frontend::init();
});
