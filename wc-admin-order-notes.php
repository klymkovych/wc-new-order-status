<?php
/**
 * Plugin Name: WC Admin Order Notes
 * Plugin URI: https://github.com/klymkovych/wc-new-order-status
 * Description: Додає колонку з нотатками до замовлень у списку замовлень WooCommerce в адмінці. Використовує REST API для сучасної комунікації з сервером. За замовчуванням показує лише коментарі, залишені людиною, виключаючи автоматичні системні повідомлення.
 * Version: 2.2.1
 * Author: Klymkovych
 * Author URI: https://github.com/klymkovych
 * Text Domain: wc-admin-order-notes
 * Domain Path: /languages
 * Requires at least: 5.0
 * Tested up to: 6.4
 * WC requires at least: 7.1
 * WC tested up to: 8.5
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Requires PHP: 7.4
 * 
 * НАЛАШТУВАННЯ ФІЛЬТРАЦІЇ:
 * Для налаштування відображення нотаток додайте одну з констант у файл wp-config.php:
 * 
 * // Показувати лише коментарі людей (за замовчуванням) - виключає автоматичні повідомлення
 * define('WC_ADMIN_ORDER_NOTES_FILTER_SYSTEM', true);
 * 
 * // Показувати всі нотатки, включаючи системні повідомлення
 * define('WC_ADMIN_ORDER_NOTES_FILTER_SYSTEM', false);
 * 
 * Фільтр виключає такі типи автоматичних повідомлень:
 * - Зміни статусу замовлення
 * - Автоматичні email повідомлення
 * - Платіжні нотатки (завершення, отримання платежу тощо)
 * - Нотатки про доставку
 * - Повернення коштів
 * - Інші системні події
 */

namespace WCAdminOrderNotes;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
define('WC_ADMIN_ORDER_NOTES_VERSION', '2.2.1');
define('WC_ADMIN_ORDER_NOTES_PLUGIN_URL', plugin_dir_url(__FILE__));
define('WC_ADMIN_ORDER_NOTES_PLUGIN_PATH', plugin_dir_path(__FILE__));
define('WC_ADMIN_ORDER_NOTES_PLUGIN_FILE', __FILE__);

// Налаштування: показувати лише коментарі людей (true) або всі нотатки (false)
// Configuration: show only human comments (true) or all notes (false)
if (!defined('WC_ADMIN_ORDER_NOTES_FILTER_SYSTEM')) {
    define('WC_ADMIN_ORDER_NOTES_FILTER_SYSTEM', true);
}

// Load required classes
require_once WC_ADMIN_ORDER_NOTES_PLUGIN_PATH . 'includes/class-cache-manager.php';
require_once WC_ADMIN_ORDER_NOTES_PLUGIN_PATH . 'includes/class-security-handler.php';
require_once WC_ADMIN_ORDER_NOTES_PLUGIN_PATH . 'includes/class-notes-manager.php';
require_once WC_ADMIN_ORDER_NOTES_PLUGIN_PATH . 'includes/class-rest-api-handler.php';
require_once WC_ADMIN_ORDER_NOTES_PLUGIN_PATH . 'includes/class-admin-interface-handler.php';
require_once WC_ADMIN_ORDER_NOTES_PLUGIN_PATH . 'includes/class-plugin.php';

// Initialize the plugin
add_action('plugins_loaded', function() {
    Plugin::get_instance();
}, 5);

// Activation hook
register_activation_hook(WC_ADMIN_ORDER_NOTES_PLUGIN_FILE, function() {
    // Flush rewrite rules for REST API
    flush_rewrite_rules();
});

// Deactivation hook
register_deactivation_hook(WC_ADMIN_ORDER_NOTES_PLUGIN_FILE, function() {
    // Clear all cached data
    wp_cache_flush();
    
    // Flush rewrite rules
    flush_rewrite_rules();
}); 