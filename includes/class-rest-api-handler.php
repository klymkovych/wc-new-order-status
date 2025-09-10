<?php
/**
 * REST API Handler
 * 
 * Handles all REST API endpoints and validation for the WC Admin Order Notes plugin.
 * 
 * @package WCAdminOrderNotes
 * @since 2.2.1
 */

namespace WCAdminOrderNotes;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

class RestApiHandler {
    
    /**
     * @var SecurityHandler
     */
    private $security_handler;
    
    /**
     * @var NotesManager
     */
    private $notes_manager;
    
    /**
     * @var int Rate limit requests per hour
     */
    private const RATE_LIMIT_REQUESTS = 100;
    
    /**
     * @var int Maximum note content length
     */
    private const MAX_NOTE_LENGTH = 1000;
    
    /**
     * Constructor
     * 
     * @param SecurityHandler $security_handler
     * @param NotesManager $notes_manager
     */
    public function __construct(SecurityHandler $security_handler, NotesManager $notes_manager) {
        $this->security_handler = $security_handler;
        $this->notes_manager = $notes_manager;
    }
    
    /**
     * Register REST API routes
     */
    public function register_routes(): void {
        register_rest_route('wc-admin-order-notes/v1', '/notes/(?P<order_id>\d+)', [
            'methods' => 'GET',
            'callback' => [$this, 'get_order_notes_rest'],
            'permission_callback' => [$this, 'check_rest_permissions'],
            'args' => [
                'order_id' => [
                    'required' => true,
                    'validate_callback' => [$this, 'validate_order_id'],
                    'sanitize_callback' => 'absint'
                ],
            ],
        ]);
        
        register_rest_route('wc-admin-order-notes/v1', '/notes/(?P<order_id>\d+)', [
            'methods' => 'POST',
            'callback' => [$this, 'add_order_note_rest'],
            'permission_callback' => [$this, 'check_rest_permissions'],
            'args' => [
                'order_id' => [
                    'required' => true,
                    'validate_callback' => [$this, 'validate_order_id'],
                    'sanitize_callback' => 'absint'
                ],
                'note_content' => [
                    'required' => true,
                    'validate_callback' => [$this, 'validate_note_content'],
                    'sanitize_callback' => [$this, 'sanitize_note_content'],
                ],
            ],
        ]);
    }
    
    /**
     * Check REST API permissions with rate limiting and CSRF protection
     * 
     * @return bool|\WP_Error
     */
    public function check_rest_permissions() {
        // Check basic capability
        if (!current_user_can('edit_shop_orders')) {
            return new \WP_Error('insufficient_permissions', __('You do not have permission to access order notes.', 'wc-admin-order-notes'), ['status' => 403]);
        }
        
        // CSRF Protection - Verify nonce
        $nonce = '';
        if (isset($_SERVER['HTTP_X_WP_NONCE'])) {
            $nonce = sanitize_text_field(wp_unslash($_SERVER['HTTP_X_WP_NONCE']));
        } elseif (isset($_REQUEST['_wpnonce'])) {
            $nonce = sanitize_text_field(wp_unslash($_REQUEST['_wpnonce']));
        }
        
        if (empty($nonce) || !wp_verify_nonce($nonce, 'wp_rest')) {
            return new \WP_Error('invalid_nonce', __('Security check failed. Please refresh the page and try again.', 'wc-admin-order-notes'), ['status' => 403]);
        }
        
        // Rate limiting with enhanced validation
        $user_id = get_current_user_id();
        
        // Validate user ID
        if (!$user_id || $user_id <= 0) {
            return new \WP_Error('invalid_user', __('Invalid user session.', 'wc-admin-order-notes'), ['status' => 401]);
        }
        
        // Additional user validation
        $user = get_user_by('id', $user_id);
        if (!$user || !$user->exists()) {
            return new \WP_Error('invalid_user', __('User not found.', 'wc-admin-order-notes'), ['status' => 401]);
        }
        
        $cache_key = "wc_notes_rate_limit_{$user_id}";
        $requests = wp_cache_get($cache_key, 'wc_notes_rate_limit') ?: 0;
        
        if ($requests >= self::RATE_LIMIT_REQUESTS) {
            return new \WP_Error('rate_limit_exceeded', __('Too many requests. Please try again later.', 'wc-admin-order-notes'), ['status' => 429]);
        }
        
        wp_cache_set($cache_key, $requests + 1, 'wc_notes_rate_limit', 3600);
        return true;
    }
    
    /**
     * Validate order ID parameter
     * 
     * @param mixed $param
     * @return bool
     */
    public function validate_order_id($param): bool {
        return $this->security_handler->validate_order_id($param);
    }
    
    /**
     * Validate note content parameter
     * 
     * @param mixed $param
     * @return bool
     */
    public function validate_note_content($param): bool {
        return $this->security_handler->validate_note_content($param);
    }
    
    /**
     * Sanitize note content parameter
     * 
     * @param mixed $param
     * @return string
     */
    public function sanitize_note_content($param): string {
        return $this->security_handler->sanitize_note_content($param);
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
        
        // Get notes with optimized caching strategy
        if ($no_cache) {
            $this->notes_manager->clear_order_notes_cache(0, $order_id);
            $notes = $this->notes_manager->get_order_notes_fresh($order_id, -1);
        } else {
            $notes = $this->notes_manager->get_order_notes_cached($order_id, -1);
        }
        
        // Set no-cache headers if requested
        if ($no_cache) {
            $response = rest_ensure_response([
                'notes' => $this->notes_manager->format_notes_for_response($notes),
                'order_number' => $order->get_order_number(),
                'timestamp' => time()
            ]);
            
            $response->header('Cache-Control', 'no-cache, no-store, must-revalidate');
            $response->header('Pragma', 'no-cache');
            $response->header('Expires', '0');
            
            return $response;
        }
        
        return rest_ensure_response([
            'notes' => $this->notes_manager->format_notes_for_response($notes),
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
        
        // Validate order ID
        if ($order_id <= 0) {
            return new \WP_Error('invalid_order_id', __('Invalid order ID.', 'wc-admin-order-notes'), ['status' => 400]);
        }
        
        $order = wc_get_order($order_id);
        if (!$order) {
            return new \WP_Error('order_not_found', __('Order not found.', 'wc-admin-order-notes'), ['status' => 404]);
        }
        
        // Additional validation
        if (empty(trim($note_content))) {
            return new \WP_Error('invalid_content', __('Note content cannot be empty.', 'wc-admin-order-notes'), ['status' => 400]);
        }
        
        if (strlen($note_content) > self::MAX_NOTE_LENGTH) {
            return new \WP_Error('content_too_long', __('Note content is too long.', 'wc-admin-order-notes'), ['status' => 400]);
        }
        
        // Additional security check - ensure user can edit this specific order
        if (!apply_filters('wc_admin_order_notes_can_access_order', true, $order_id, get_current_user_id())) {
            return new \WP_Error('access_denied', __('You do not have permission to add notes to this order.', 'wc-admin-order-notes'), ['status' => 403]);
        }
        
        try {
            $note_id = $order->add_order_note($note_content, false, false);
            
            if ($note_id) {
                // Clear cache entries for this order
                $this->notes_manager->clear_order_notes_cache($note_id, $order_id);
                
                // Log the action for audit purposes
                do_action('wc_admin_order_notes_note_added', $note_id, $order_id, get_current_user_id(), $note_content);
                
                // Enhanced logging
                error_log(sprintf(
                    'WC Admin Order Notes: Note added successfully - Order ID: %d, Note ID: %d, User ID: %d, Content Length: %d',
                    $order_id,
                    $note_id,
                    get_current_user_id(),
                    strlen($note_content)
                ));
                
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
            
            error_log('WC Admin Order Notes: Failed to add note - Order ID: ' . $order_id . ', User ID: ' . get_current_user_id());
            return new \WP_Error('add_note_failed', __('Failed to add note.', 'wc-admin-order-notes'), ['status' => 500]);
            
        } catch (\Exception $e) {
            // Enhanced error logging
            error_log(sprintf(
                'WC Admin Order Notes: Exception while adding note - Order ID: %d, User ID: %d, Error: %s, Trace: %s',
                $order_id,
                get_current_user_id(),
                $e->getMessage(),
                $e->getTraceAsString()
            ));
            
            return new \WP_Error('add_note_failed', __('An unexpected error occurred while adding the note.', 'wc-admin-order-notes'), ['status' => 500]);
        } catch (\Error $e) {
            // Log PHP errors
            error_log(sprintf(
                'WC Admin Order Notes: Fatal error while adding note - Order ID: %d, User ID: %d, Error: %s, Trace: %s',
                $order_id,
                get_current_user_id(),
                $e->getMessage(),
                $e->getTraceAsString()
            ));
            
            return new \WP_Error('add_note_failed', __('A system error occurred while adding the note.', 'wc-admin-order-notes'), ['status' => 500]);
        }
    }
}
