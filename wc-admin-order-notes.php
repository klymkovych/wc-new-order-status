<?php
/**
 * Plugin Name: WC Admin Order Notes
 * Plugin URI: https://github.com/klymkovych/wc-new-order-status
 * Description: Додає колонку з нотатками до замовлень у списку замовлень WooCommerce в адмінці. Використовує REST API для сучасної комунікації з сервером. За замовчуванням показує лише коментарі, залишені людиною, виключаючи автоматичні системні повідомлення.
 * Version: 2.2.0
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

use Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController;
use Automattic\WooCommerce\Utilities\OrderUtil;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
define('WC_ADMIN_ORDER_NOTES_VERSION', '2.2.0');
define('WC_ADMIN_ORDER_NOTES_PLUGIN_URL', plugin_dir_url(__FILE__));
define('WC_ADMIN_ORDER_NOTES_PLUGIN_PATH', plugin_dir_path(__FILE__));
define('WC_ADMIN_ORDER_NOTES_PLUGIN_FILE', __FILE__);

// Налаштування: показувати лише коментарі людей (true) або всі нотатки (false)
// Configuration: show only human comments (true) or all notes (false)
if (!defined('WC_ADMIN_ORDER_NOTES_FILTER_SYSTEM')) {
    define('WC_ADMIN_ORDER_NOTES_FILTER_SYSTEM', true);
}

// Main plugin class
class Plugin {
    
    /**
     * @var Plugin|null
     */
    private static ?Plugin $instance = null;
    
    /**
     * @var array Cache for order notes
     */
    private array $notes_cache = [];
    
    /**
     * @var string Cache group name
     */
    private const CACHE_GROUP = 'wc_admin_order_notes';
    
    /**
     * @var int Cache expiration in seconds
     */
    private const CACHE_EXPIRATION = 300; // 5 minutes
    
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
        
        // Add hooks for admin functionality
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_scripts']);
        
        // Use appropriate hooks based on HPOS status
        if ($this->is_hpos_enabled()) {
            // HPOS is enabled - use new hooks
            add_filter('manage_woocommerce_page_wc-orders_columns', [$this, 'add_order_notes_column']);
            add_action('manage_woocommerce_page_wc-orders_custom_column', [$this, 'display_order_notes_column_hpos'], 10, 2);
        } else {
            // Legacy post-based orders
            add_filter('manage_edit-shop_order_columns', [$this, 'add_order_notes_column']);
            add_action('manage_shop_order_posts_custom_column', [$this, 'display_order_notes_column_legacy'], 10, 2);
        }
        
        // Add modal HTML
        add_action('admin_footer', [$this, 'add_modal_html']);
        
        // Clear cache when order note is added
        add_action('woocommerce_order_note_added', [$this, 'clear_order_notes_cache'], 10, 2);
    }
    
    /**
     * Check if HPOS is enabled
     * 
     * @return bool
     */
    private function is_hpos_enabled(): bool {
        if (class_exists('\Automattic\WooCommerce\Utilities\OrderUtil')) {
            return OrderUtil::custom_orders_table_usage_is_enabled();
        }
        
        return false;
    }
    
    /**
     * Get current orders screen ID
     * 
     * @return string
     */
    private function get_orders_screen_id(): string {
        return $this->is_hpos_enabled() ? 'woocommerce_page_wc-orders' : 'edit-shop_order';
    }
    
    /**
     * Register REST API routes
     */
    public function register_rest_routes(): void {
        register_rest_route('wc-admin-order-notes/v1', '/notes/(?P<order_id>\d+)', [
            'methods' => 'GET',
            'callback' => [$this, 'get_order_notes_rest'],
            'permission_callback' => [$this, 'check_rest_permissions'],
            'args' => [
                'order_id' => [
                    'validate_callback' => function($param) {
                        return is_numeric($param);
                    }
                ],
            ],
        ]);
        
        register_rest_route('wc-admin-order-notes/v1', '/notes/(?P<order_id>\d+)', [
            'methods' => 'POST',
            'callback' => [$this, 'add_order_note_rest'],
            'permission_callback' => [$this, 'check_rest_permissions'],
            'args' => [
                'order_id' => [
                    'validate_callback' => function($param) {
                        return is_numeric($param);
                    }
                ],
                'note_content' => [
                    'required' => true,
                    'validate_callback' => function($param) {
                        return !empty(trim($param));
                    },
                    'sanitize_callback' => 'sanitize_textarea_field',
                ],
            ],
        ]);
    }
    
    /**
     * Check REST API permissions
     * 
     * @return bool
     */
    public function check_rest_permissions(): bool {
        return current_user_can('edit_shop_orders');
    }
    
    /**
     * Get order notes via REST API
     * 
     * @param \WP_REST_Request $request
     * @return \WP_REST_Response|\WP_Error
     */
    public function get_order_notes_rest(\WP_REST_Request $request) {
        $order_id = absint($request->get_param('order_id'));
        $order = wc_get_order($order_id);
        
        if (!$order) {
            return new \WP_Error('order_not_found', __('Order not found.', 'wc-admin-order-notes'), ['status' => 404]);
        }
        
        // Check for cache busting parameter
        $no_cache = $request->get_param('_') !== null;
        
        // For modal display, always get fresh data to ensure we have all notes
        $this->clear_all_order_caches($order_id);
        $notes = $this->get_order_notes_fresh($order_id, -1); // Explicitly get all notes
        
        // Force cache bust for modal requests
        $no_cache = true;
        

        
        // Set no-cache headers if requested
        if ($no_cache) {
            $response = rest_ensure_response([
                'notes' => $this->format_notes_for_response($notes),
                'order_number' => $order->get_order_number(),
                'timestamp' => time()
            ]);
            
            $response->header('Cache-Control', 'no-cache, no-store, must-revalidate');
            $response->header('Pragma', 'no-cache');
            $response->header('Expires', '0');
            
            return $response;
        }
        
        return rest_ensure_response([
            'notes' => $this->format_notes_for_response($notes),
            'order_number' => $order->get_order_number(),
            'timestamp' => time()
        ]);
    }
    
    /**
     * Add order note via REST API
     * 
     * @param \WP_REST_Request $request
     * @return \WP_REST_Response|\WP_Error
     */
    public function add_order_note_rest(\WP_REST_Request $request) {
        $order_id = absint($request->get_param('order_id'));
        $note_content = $request->get_param('note_content');
        
        $order = wc_get_order($order_id);
        if (!$order) {
            return new \WP_Error('order_not_found', __('Order not found.', 'wc-admin-order-notes'), ['status' => 404]);
        }
        
        $note_id = $order->add_order_note($note_content, false, false);
        
        if ($note_id) {
            // Clear all cache entries for this order immediately
            $this->clear_all_order_caches($order_id);
            
            $response = rest_ensure_response([
                'message' => __('Note added successfully.', 'wc-admin-order-notes'),
                'note_id' => $note_id,
                'timestamp' => time()
            ]);
            
            // Set no-cache headers
            $response->header('Cache-Control', 'no-cache, no-store, must-revalidate');
            $response->header('Pragma', 'no-cache');
            $response->header('Expires', '0');
            
            return $response;
        }
        
        return new \WP_Error('add_note_failed', __('Failed to add note.', 'wc-admin-order-notes'), ['status' => 500]);
    }
    
    /**
     * Enqueue admin scripts and styles
     * 
     * @param string $hook
     */
    public function enqueue_admin_scripts(string $hook): void {
        // Check if we're on the orders page
        $screen = get_current_screen();
        if (!$screen || $screen->id !== $this->get_orders_screen_id()) {
            return;
        }
        
        // Enqueue modern JavaScript
        wp_enqueue_script(
            'wc-admin-order-notes',
            WC_ADMIN_ORDER_NOTES_PLUGIN_URL . 'assets/js/admin-order-notes.js',
            [],
            WC_ADMIN_ORDER_NOTES_VERSION,
            true
        );
        
        wp_enqueue_style(
            'wc-admin-order-notes',
            WC_ADMIN_ORDER_NOTES_PLUGIN_URL . 'assets/css/admin-order-notes.css',
            [],
            WC_ADMIN_ORDER_NOTES_VERSION
        );
        
        // Localize script with REST API data
        wp_localize_script('wc-admin-order-notes', 'wcOrderNotes', [
            'restUrl' => rest_url('wc-admin-order-notes/v1'),
            'restNonce' => wp_create_nonce('wp_rest'),
            'isHposEnabled' => $this->is_hpos_enabled(),
            'strings' => [
                'loading' => __('Loading...', 'wc-admin-order-notes'),
                'error' => __('Error occurred', 'wc-admin-order-notes'),
                'noteAdded' => __('Note added successfully', 'wc-admin-order-notes'),
                'addNote' => __('Add Note', 'wc-admin-order-notes'),
                'close' => __('Close', 'wc-admin-order-notes'),
                'noNotes' => __('No notes found', 'wc-admin-order-notes'),
                'orderNotes' => __('Order Notes', 'wc-admin-order-notes'),
                'addNewNote' => __('Add New Note', 'wc-admin-order-notes'),
                'notePlaceholder' => __('Enter your note here...', 'wc-admin-order-notes'),
                'securityError' => __('Security check failed.', 'wc-admin-order-notes'),
            ]
        ]);
    }
    
    /**
     * Add order notes column
     * 
     * @param array $columns
     * @return array
     */
    public function add_order_notes_column(array $columns): array {
        $new_columns = [];
        
        foreach ($columns as $key => $column) {
            $new_columns[$key] = $column;
            if ($key === 'order_status') {
                $new_columns['order_notes'] = __('Notes', 'wc-admin-order-notes');
            }
        }
        
        // Add CSS to make the column non-clickable
        add_action('admin_print_styles', function() {
            echo '<style>
                .wp-list-table .column-order_notes {
                    position: relative;
                }
                .wp-list-table tbody tr .column-order_notes {
                    position: relative;
                    z-index: 10;
                }
                .wp-list-table tbody tr .column-order_notes .order-notes-cell {
                    position: relative;
                    z-index: 11;
                }
                /* Prevent row clicks in notes column */
                .wp-list-table tbody tr .column-order_notes * {
                    pointer-events: auto;
                }
                .wp-list-table tbody tr .column-order_notes .note-preview {
                    pointer-events: auto !important;
                    z-index: 15 !important;
                    position: relative !important;
                }
            </style>';
        });
        
        return $new_columns;
    }
    
    /**
     * Display order notes column content for HPOS
     * 
     * @param string $column
     * @param \WC_Order $order
     */
    public function display_order_notes_column_hpos(string $column, \WC_Order $order): void {
        if ($column !== 'order_notes') {
            return;
        }
        
        $this->render_order_notes_column($order->get_id());
    }
    
    /**
     * Display order notes column content for legacy orders
     * 
     * @param string $column
     * @param int $post_id
     */
    public function display_order_notes_column_legacy(string $column, int $post_id): void {
        if ($column !== 'order_notes') {
            return;
        }
        
        $this->render_order_notes_column($post_id);
    }
    
    /**
     * Render order notes column content
     * 
     * @param int $order_id
     */
    private function render_order_notes_column(int $order_id): void {
        $order = wc_get_order($order_id);
        if (!$order) {
            return;
        }
        
        $notes = $this->get_order_notes_cached($order_id, 1);
        
        if (!empty($notes)) {
            $latest_note = $notes[0];
            $note_content = wp_trim_words($latest_note->content, 10, '...');
            $note_date = date_i18n(get_option('date_format'), strtotime($latest_note->date_created));
            
            printf(
                '<div class="order-notes-cell" onclick="event.stopPropagation();">
                    <div class="note-preview" data-order-id="%s" onclick="event.preventDefault(); event.stopPropagation();">
                        <div class="note-content">%s
                            <small class="note-date">%s</small>
                        </div>
                    </div>
                </div>',
                esc_attr($order_id),
                esc_html($note_content),
                esc_html($note_date)
            );
        } else {
            printf(
                '<div class="order-notes-cell" onclick="event.stopPropagation();">
                    <div class="note-preview no-notes" data-order-id="%s" onclick="event.preventDefault(); event.stopPropagation();">
                        <em>%s</em>
                    </div>
                </div>',
                esc_attr($order_id),
                esc_html__('No notes', 'wc-admin-order-notes')
            );
        }
    }
    
    /**
     * Get cached order notes
     * 
     * @param int $order_id
     * @param int $limit
     * @return array
     */
    private function get_order_notes_cached(int $order_id, int $limit = -1): array {
        $cache_key = sprintf('order_notes_%d_%d_%s', $order_id, $limit, WC_ADMIN_ORDER_NOTES_FILTER_SYSTEM ? 'filtered' : 'all');
        $cached_notes = wp_cache_get($cache_key, self::CACHE_GROUP);
        
        if (false === $cached_notes) {
            $fetch_limit = WC_ADMIN_ORDER_NOTES_FILTER_SYSTEM ? -1 : $limit;
            
            $cached_notes = wc_get_order_notes([
                'order_id' => $order_id,
                'limit' => $fetch_limit, // Отримуємо всі нотатки для фільтрації або задану кількість
                'orderby' => 'date_created',
                'order' => 'DESC',
                'type' => '' // Get all types of notes (customer and admin)
            ]);
            
            // Фільтруємо лише коментарі людей (виключаємо системні нотатки), якщо увімкнено
            if (WC_ADMIN_ORDER_NOTES_FILTER_SYSTEM) {
                $cached_notes = $this->filter_human_notes($cached_notes);
                
                // Застосовуємо ліміт після фільтрації
                if ($limit > 0) {
                    $cached_notes = array_slice($cached_notes, 0, $limit);
                }
            }
            
            wp_cache_set($cache_key, $cached_notes, self::CACHE_GROUP, self::CACHE_EXPIRATION);
        }
        
        return $cached_notes;
    }
    
    /**
     * Clear order notes cache
     * 
     * @param int $note_id
     * @param int|\WC_Order $order_id_or_order
     */
    public function clear_order_notes_cache(int $note_id, $order_id_or_order): void {
        // Handle both int order ID and WC_Order object
        if (is_object($order_id_or_order) && method_exists($order_id_or_order, 'get_id')) {
            $order_id = $order_id_or_order->get_id();
        } elseif (is_numeric($order_id_or_order)) {
            $order_id = (int) $order_id_or_order;
        } else {
            error_log('WC Admin Order Notes: Invalid order parameter type in clear_order_notes_cache');
            return;
        }
        
        // Clear cache entries for both filtered and unfiltered variants
        $cache_variants = ['filtered', 'all'];
        $common_limits = [1, -1]; // Most commonly used limits
        
        foreach ($cache_variants as $variant) {
            foreach ($common_limits as $limit) {
                wp_cache_delete(sprintf('order_notes_%d_%d_%s', $order_id, $limit, $variant), self::CACHE_GROUP);
            }
        }
        
        // Також очищаємо старий формат кешу (для сумісності)
        wp_cache_delete(sprintf('order_notes_%d_1', $order_id), self::CACHE_GROUP);
        wp_cache_delete(sprintf('order_notes_%d_-1', $order_id), self::CACHE_GROUP);
    }
    
    /**
     * Format notes for response
     * 
     * @param array $notes
     * @return array
     */
    private function format_notes_for_response(array $notes): array {
        $formatted_notes = [];
        
        foreach ($notes as $note) {
            $formatted_notes[] = [
                'id' => $note->id,
                'content' => $note->content,
                'date' => date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($note->date_created)),
                'author' => $note->customer_note ? __('Customer', 'wc-admin-order-notes') : __('Admin', 'wc-admin-order-notes'),
                'type' => $note->customer_note ? 'customer' : 'admin'
            ];
        }
        
        return $formatted_notes;
    }
    
    /**
     * Add modal HTML
     */
    public function add_modal_html(): void {
        $screen = get_current_screen();
        
        if (!$screen || $screen->id !== $this->get_orders_screen_id()) {
            return;
        }
        ?>
        <div id="order-notes-modal" class="order-notes-modal" role="dialog" aria-labelledby="modal-title" aria-hidden="true">
            <div class="order-notes-modal-content" role="document">
                <div class="order-notes-modal-header">
                    <h3 id="modal-title"><?php esc_html_e('Order Notes', 'wc-admin-order-notes'); ?></h3>
                    <button type="button" class="order-notes-modal-close" aria-label="<?php esc_attr_e('Close', 'wc-admin-order-notes'); ?>">&times;</button>
                </div>
                <div class="order-notes-modal-body">
                    <div id="notes-list" class="notes-list" aria-live="polite">
                        <!-- Notes will be loaded here -->
                    </div>
                    <div class="add-note-section">
                        <h4><?php esc_html_e('Add New Note', 'wc-admin-order-notes'); ?></h4>
                        <textarea 
                            id="new-note-content" 
                            placeholder="<?php esc_attr_e('Enter your note here...', 'wc-admin-order-notes'); ?>"
                            aria-label="<?php esc_attr_e('New note content', 'wc-admin-order-notes'); ?>"
                        ></textarea>
                        <button id="add-note-btn" class="button button-primary">
                            <?php esc_html_e('Add Note', 'wc-admin-order-notes'); ?>
                        </button>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }
    
    /**
     * Get fresh order notes (bypassing cache)
     * 
     * @param int $order_id
     * @param int $limit
     * @return array
     */
    private function get_order_notes_fresh(int $order_id, int $limit = -1): array {
        $fetch_limit = WC_ADMIN_ORDER_NOTES_FILTER_SYSTEM ? -1 : $limit;
        
        // Get fresh notes directly from WooCommerce
        $args = [
            'order_id' => $order_id,
            'limit' => $fetch_limit, // Отримуємо всі нотатки для фільтрації або задану кількість
            'orderby' => 'date_created',
            'order' => 'DESC',
            'type' => '', // Get all types of notes (customer and admin)
            'approve' => 'approve'
        ];
        
        // Try to get notes with WooCommerce function
        $notes = wc_get_order_notes($args);
        
        // If we only got one note but limit is -1, try alternative approach
        if (count($notes) === 1 && $fetch_limit === -1) {
            // Try getting notes directly from comments
            global $wpdb;
            
            $direct_notes = $wpdb->get_results($wpdb->prepare("
                SELECT comment_ID as id, comment_content as content, comment_date as date_created,
                       comment_author as author, comment_approved as approved
                FROM {$wpdb->comments} 
                WHERE comment_post_ID = %d 
                AND comment_type = 'order_note'
                AND comment_approved = '1'
                ORDER BY comment_date_gmt DESC
                ", $order_id));
            
            if (count($direct_notes) > 1) {
                // Convert to WooCommerce note format
                $converted_notes = [];
                foreach ($direct_notes as $note) {
                    $note_obj = new \stdClass();
                    $note_obj->id = $note->id;
                    $note_obj->content = $note->content;
                    $note_obj->date_created = $note->date_created;
                    $note_obj->customer_note = (bool) get_comment_meta($note->id, 'is_customer_note', true);
                    $converted_notes[] = $note_obj;
                }
                $notes = $converted_notes;
            }
        }
        
        // Фільтруємо лише коментарі людей (виключаємо системні нотатки), якщо увімкнено
        if (WC_ADMIN_ORDER_NOTES_FILTER_SYSTEM) {
            $notes = $this->filter_human_notes($notes);
            
            // Застосовуємо ліміт після фільтрації
            if ($limit > 0) {
                $notes = array_slice($notes, 0, $limit);
            }
        }
        
        // Temporary debug to see what's happening
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log(sprintf('WC Admin Order Notes: Fetched %d filtered notes for order %d with args: %s', 
                count($notes), $order_id, json_encode($args)));
        }
        
        return $notes;
    }
    
    /**
     * Clear all cache entries for an order
     * 
     * @param int $order_id
     */
    private function clear_all_order_caches(int $order_id): void {
        // Clear all possible cache entries for this order
        $common_limits = [1, 5, 10, 20, 50, -1]; // Most common limits
        $cache_variants = ['filtered', 'all']; // Для фільтрованих і нефільтрованих нотаток
        
        foreach ($common_limits as $limit) {
            foreach ($cache_variants as $variant) {
                wp_cache_delete(sprintf('order_notes_%d_%d_%s', $order_id, $limit, $variant), self::CACHE_GROUP);
            }
            
            // Також очищаємо старий формат кешу (для сумісності)
            wp_cache_delete(sprintf('order_notes_%d_%d', $order_id, $limit), self::CACHE_GROUP);
        }
        
        // Also clear any WooCommerce-related caches
        if (function_exists('wc_delete_shop_order_transients')) {
            wc_delete_shop_order_transients($order_id);
        }
        
        // Clear object cache if available
        if (function_exists('wp_cache_flush_group')) {
            wp_cache_flush_group(self::CACHE_GROUP);
        }
    }

    /**
     * Filter out system status change messages from order notes.
     *
     * @param array $notes The array of notes to filter.
     * @return array The filtered array of notes.
     */
    private function filter_human_notes(array $notes): array {
        $filtered_notes = [];
        
        // Шаблони для визначення системних нотаток (англійська та українська)
        $system_patterns = [
            // Зміна статусу замовлення
            '/status changed from/i',
            '/статус замовлення змінено з/iu',
            '/статус змінено з/iu',
            
            // Email повідомлення
            '/email sent to/i',
            '/електронний лист надіслано/iu',
            '/email надіслано/iu',
            
            // Платіжні системи
            '/payment complete/i',
            '/payment received/i',
            '/payment authorized/i',
            '/платіж завершено/iu',
            '/платіж отримано/iu',
            '/платіж авторизовано/iu',
            
            // Доставка та логістика
            '/shipped via/i',
            '/tracking number/i',
            '/відправлено через/iu',
            '/номер відстеження/iu',
            
            // Автоматичні дії WooCommerce
            '/refund/i',
            '/повернення коштів/iu',
            '/відшкодування/iu',
            
            // Інші системні події
            '/automatically generated/i',
            '/автоматично створено/iu',
            '/system:/i',
            '/система:/iu',
        ];
        
        foreach ($notes as $note) {
            $is_system_note = false;
            
            // Перевіряємо чи містить нотатка системні шаблони
            foreach ($system_patterns as $pattern) {
                if (preg_match($pattern, $note->content)) {
                    $is_system_note = true;
                    break;
                }
            }
            
            // Додаткова перевірка: якщо нотатка дуже коротка і містить лише статус
            if (!$is_system_note && strlen(trim($note->content)) < 10) {
                $short_content = strtolower(trim($note->content));
                $status_keywords = [
                    'pending', 'processing', 'on-hold', 'completed', 'cancelled', 'refunded', 'failed',
                    'очікування', 'обробка', 'утримання', 'завершено', 'скасовано', 'повернено', 'помилка'
                ];
                
                foreach ($status_keywords as $keyword) {
                    if (strpos($short_content, $keyword) !== false) {
                        $is_system_note = true;
                        break;
                    }
                }
            }
            
            // Якщо нотатка не системна, додаємо її до результату
            if (!$is_system_note) {
                $filtered_notes[] = $note;
            }
        }
        
        return $filtered_notes;
    }
}

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