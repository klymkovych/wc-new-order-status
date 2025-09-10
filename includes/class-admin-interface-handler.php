<?php
/**
 * Admin Interface Handler
 * 
 * Handles admin interface functionality for the WC Admin Order Notes plugin.
 * 
 * @package WCAdminOrderNotes
 * @since 2.2.1
 */

namespace WCAdminOrderNotes;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

class AdminInterfaceHandler {
    
    /**
     * @var NotesManager
     */
    private $notes_manager;
    
    /**
     * @var SecurityHandler
     */
    private $security_handler;
    
    /**
     * @var int Maximum note content length
     */
    private const MAX_NOTE_LENGTH = 1000;
    
    /**
     * Constructor
     * 
     * @param NotesManager $notes_manager
     * @param SecurityHandler $security_handler
     */
    public function __construct(NotesManager $notes_manager, SecurityHandler $security_handler) {
        $this->notes_manager = $notes_manager;
        $this->security_handler = $security_handler;
    }
    
    /**
     * Initialize admin interface hooks
     */
    public function init_hooks(): void {
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
        
        // Add admin nonce for security
        add_action('admin_init', [$this, 'add_admin_nonce']);
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
            'maxNoteLength' => self::MAX_NOTE_LENGTH,
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
                'contentTooLong' => __('Note content is too long.', 'wc-admin-order-notes'),
                'invalidOrderId' => __('Invalid order ID.', 'wc-admin-order-notes'),
                'noOrderSelected' => __('No order selected.', 'wc-admin-order-notes'),
                'networkError' => __('Network error. Please check your connection.', 'wc-admin-order-notes'),
                'rateLimitExceeded' => __('Too many requests. Please try again later.', 'wc-admin-order-notes'),
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
        
        $notes = $this->notes_manager->get_order_notes_cached($order_id, 1);
        
        if (!empty($notes)) {
            $latest_note = $notes[0];
            $note_content = esc_html(wp_trim_words($latest_note->content, 10, '...'));
            $note_date = esc_html(date_i18n(get_option('date_format'), strtotime($latest_note->date_created)));
            
            printf(
                '<div class="order-notes-cell" data-order-id="%s">
                    <div class="note-preview" data-order-id="%s">
                        <div class="note-content">%s
                            <small class="note-date">%s</small>
                        </div>
                    </div>
                </div>',
                esc_attr($order_id),
                esc_attr($order_id),
                $note_content,
                $note_date
            );
        } else {
            printf(
                '<div class="order-notes-cell" data-order-id="%s">
                    <div class="note-preview no-notes" data-order-id="%s">
                        <em>%s</em>
                    </div>
                </div>',
                esc_attr($order_id),
                esc_attr($order_id),
                esc_html__('No notes', 'wc-admin-order-notes')
            );
        }
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
                            maxlength="1000"
                        ></textarea>
                        <div class="character-count" id="character-count">0 / 1000</div>
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
     * Clear order notes cache
     * 
     * @param int $note_id
     * @param int|\WC_Order $order_id_or_order
     */
    public function clear_order_notes_cache(int $note_id, $order_id_or_order): void {
        $this->notes_manager->clear_order_notes_cache($note_id, $order_id_or_order);
    }
    
    /**
     * Add admin nonce for security
     */
    public function add_admin_nonce(): void {
        $this->security_handler->add_admin_nonce();
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
     * Check if HPOS is enabled
     * 
     * @return bool
     */
    private function is_hpos_enabled(): bool {
        if (class_exists('\Automattic\WooCommerce\Utilities\OrderUtil')) {
            return \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
        }
        
        return false;
    }
}
