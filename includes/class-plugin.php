<?php
/**
 * Core Plugin Class
 * 
 * Main plugin class that coordinates all functionality for the WC Admin Order Notes plugin.
 * 
 * @package WCAdminOrderNotes
 * @since 2.2.1
 */

namespace WCAdminOrderNotes;

use Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController;
use Automattic\WooCommerce\Utilities\OrderUtil;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

class Plugin {
    
    /**
     * @var Plugin|null
     */
    private static ?Plugin $instance = null;
    
    /**
     * @var RestApiHandler
     */
    private $rest_api_handler;
    
    /**
     * @var AdminInterfaceHandler
     */
    private $admin_interface_handler;
    
    /**
     * @var NotesManager
     */
    private $notes_manager;
    
    /**
     * @var SecurityHandler
     */
    private $security_handler;
    
    /**
     * @var CacheManager
     */
    private $cache_manager;
    
    /**
     * Get singleton instance
     * 
     * @return Plugin
     */
    public static function get_instance(): Plugin {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * Private constructor to prevent direct instantiation
     */
    private function __construct() {
        $this->init();
    }
    
    /**
     * Initialize the plugin
     */
    private function init(): void {
        // Initialize component classes
        $this->initialize_components();
        
        // Check if WooCommerce is active
        add_action('plugins_loaded', [$this, 'check_woocommerce']);
        
        // Load text domain
        add_action('init', [$this, 'load_textdomain']);
        
        // Initialize hooks only if WooCommerce is active
        add_action('init', [$this, 'init_hooks']);
        
        // Register REST API endpoints
        add_action('rest_api_init', [$this, 'register_rest_routes']);
        
        // Declare HPOS compatibility
        add_action('before_woocommerce_init', [$this, 'declare_hpos_compatibility']);
        
        // Add security headers
        add_action('admin_init', [$this, 'add_security_headers']);
    }
    
    /**
     * Initialize component classes
     */
    private function initialize_components(): void {
        // Initialize in dependency order
        $this->cache_manager = new CacheManager();
        $this->security_handler = new SecurityHandler();
        $this->notes_manager = new NotesManager($this->cache_manager);
        $this->rest_api_handler = new RestApiHandler($this->security_handler, $this->notes_manager);
        $this->admin_interface_handler = new AdminInterfaceHandler($this->notes_manager, $this->security_handler);
    }
    
    /**
     * Declare HPOS compatibility
     */
    public function declare_hpos_compatibility(): void {
        if (class_exists('\Automattic\WooCommerce\Utilities\FeaturesUtil')) {
            \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
                'custom_order_tables',
                WC_ADMIN_ORDER_NOTES_PLUGIN_FILE,
                true
            );
        }
    }
    
    /**
     * Add security headers
     */
    public function add_security_headers(): void {
        $this->security_handler->add_security_headers();
    }
    
    /**
     * Check if WooCommerce is active
     */
    public function check_woocommerce(): void {
        if (!$this->is_woocommerce_active()) {
            add_action('admin_notices', [$this, 'woocommerce_missing_notice']);
            return;
        }
        
        // Check WooCommerce version for HPOS support
        if (version_compare(WC()->version, '7.1', '<')) {
            add_action('admin_notices', [$this, 'woocommerce_version_notice']);
        }
    }
    
    /**
     * Check if WooCommerce is active
     * 
     * @return bool
     */
    private function is_woocommerce_active(): bool {
        // Check if WooCommerce class exists
        if (class_exists('WooCommerce')) {
            return true;
        }
        
        // Check in active plugins (for multisite compatibility)
        if (!function_exists('is_plugin_active')) {
            include_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        
        return is_plugin_active('woocommerce/woocommerce.php');
    }
    
    /**
     * Display admin notice if WooCommerce is not active
     */
    public function woocommerce_missing_notice(): void {
        ?>
        <div class="notice notice-error">
            <p><?php esc_html_e('WC Admin Order Notes requires WooCommerce to be installed and active.', 'wc-admin-order-notes'); ?></p>
        </div>
        <?php
    }
    
    /**
     * Display admin notice if WooCommerce version is too old
     */
    public function woocommerce_version_notice(): void {
        ?>
        <div class="notice notice-warning">
            <p><?php esc_html_e('WC Admin Order Notes requires WooCommerce 7.1 or higher for full compatibility.', 'wc-admin-order-notes'); ?></p>
        </div>
        <?php
    }
    
    /**
     * Load plugin text domain
     */
    public function load_textdomain(): void {
        load_plugin_textdomain(
            'wc-admin-order-notes',
            false,
            dirname(plugin_basename(WC_ADMIN_ORDER_NOTES_PLUGIN_FILE)) . '/languages'
        );
    }
    
    /**
     * Initialize hooks
     */
    public function init_hooks(): void {
        if (!$this->is_woocommerce_active()) {
            return;
        }
        
        // Initialize admin interface
        $this->admin_interface_handler->init_hooks();
    }
    
    /**
     * Register REST API routes
     */
    public function register_rest_routes(): void {
        $this->rest_api_handler->register_routes();
    }
    
    /**
     * Get component instances (for testing and external access)
     * 
     * @return array
     */
    public function get_components(): array {
        return [
            'rest_api_handler' => $this->rest_api_handler,
            'admin_interface_handler' => $this->admin_interface_handler,
            'notes_manager' => $this->notes_manager,
            'security_handler' => $this->security_handler,
            'cache_manager' => $this->cache_manager,
        ];
    }
}
