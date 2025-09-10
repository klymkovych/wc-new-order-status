<?php
/**
 * Security Handler
 * 
 * Handles security, validation, and permissions for the WC Admin Order Notes plugin.
 * 
 * @package WCAdminOrderNotes
 * @since 2.2.1
 */

namespace WCAdminOrderNotes;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

class SecurityHandler {
    
    /**
     * @var int Maximum note content length
     */
    private const MAX_NOTE_LENGTH = 1000;
    
    /**
     * Validate order ID parameter
     * 
     * @param mixed $param
     * @return bool
     */
    public function validate_order_id($param): bool {
        // Ensure parameter is numeric
        if (!is_numeric($param)) {
            return false;
        }
        
        $order_id = absint($param);
        if ($order_id <= 0) {
            return false;
        }
        
        // Validate order ID is within reasonable bounds
        if ($order_id > 999999999) {
            return false;
        }
        
        $order = wc_get_order($order_id);
        if (!$order) {
            return false;
        }
        
        // Verify order exists and user can access it
        if (!$order->exists()) {
            return false;
        }
        
        // Additional permission check for order access
        return apply_filters('wc_admin_order_notes_can_access_order', true, $order_id, get_current_user_id());
    }
    
    /**
     * Validate note content parameter
     * 
     * @param mixed $param
     * @return bool
     */
    public function validate_note_content($param): bool {
        if (!is_string($param)) {
            return false;
        }
        
        $content = trim($param);
        
        // Check if content is empty after trimming
        if (empty($content)) {
            return false;
        }
        
        // Check length limit
        if (strlen($content) > self::MAX_NOTE_LENGTH) {
            return false;
        }
        
        // Check for minimum content length
        if (strlen($content) < 1) {
            return false;
        }
        
        // Remove HTML tags and check if content becomes empty
        $clean_content = strip_tags($content);
        if (empty(trim($clean_content))) {
            return false;
        }
        
        // Check for potentially malicious patterns
        $dangerous_patterns = [
            '/<script\b[^<]*(?:(?!<\/script>)<[^<]*)*<\/script>/mi',
            '/javascript:/i',
            '/on\w+\s*=/i',
            '/<iframe\b[^>]*>/i',
            '/<object\b[^>]*>/i',
            '/<embed\b[^>]*>/i',
            '/<link\b[^>]*>/i',
            '/<meta\b[^>]*>/i',
            '/<form\b[^>]*>/i',
            '/<input\b[^>]*>/i',
            '/<button\b[^>]*>/i',
            '/<select\b[^>]*>/i',
            '/<textarea\b[^>]*>/i',
            '/<style\b[^<]*(?:(?!<\/style>)<[^<]*)*<\/style>/mi',
            '/<link\b[^>]*>/i',
            '/<base\b[^>]*>/i'
        ];
        
        foreach ($dangerous_patterns as $pattern) {
            if (preg_match($pattern, $content)) {
                return false;
            }
        }
        
        // Check for SQL injection patterns
        $sql_patterns = [
            '/(\bunion\b.*\bselect\b)/i',
            '/(\bselect\b.*\bfrom\b)/i',
            '/(\binsert\b.*\binto\b)/i',
            '/(\bupdate\b.*\bset\b)/i',
            '/(\bdelete\b.*\bfrom\b)/i',
            '/(\bdrop\b.*\btable\b)/i',
            '/(\balter\b.*\btable\b)/i',
            '/(\bcreate\b.*\btable\b)/i'
        ];
        
        foreach ($sql_patterns as $pattern) {
            if (preg_match($pattern, $content)) {
                return false;
            }
        }
        
        return true;
    }
    
    /**
     * Sanitize note content parameter
     * 
     * @param mixed $param
     * @return string
     */
    public function sanitize_note_content($param): string {
        if (!is_string($param)) {
            return '';
        }
        
        // Remove HTML tags completely
        $content = strip_tags($param);
        
        // Trim whitespace
        $content = trim($content);
        
        // Limit length
        if (strlen($content) > self::MAX_NOTE_LENGTH) {
            $content = substr($content, 0, self::MAX_NOTE_LENGTH);
        }
        
        // Additional sanitization for WordPress
        $content = sanitize_textarea_field($content);
        
        return $content;
    }
    
    /**
     * Add security headers
     */
    public function add_security_headers(): void {
        // Only add headers on admin pages where our plugin is active
        $screen = get_current_screen();
        if (!$screen || $screen->id !== $this->get_orders_screen_id()) {
            return;
        }
        
        // Verify user has proper capabilities
        if (!current_user_can('edit_shop_orders')) {
            return;
        }
        
        // Verify nonce for additional security
        if (!wp_verify_nonce($_REQUEST['_wpnonce'] ?? '', 'admin_security')) {
            return;
        }
        
        // Prevent clickjacking
        header('X-Frame-Options: SAMEORIGIN');
        
        // Prevent MIME type sniffing
        header('X-Content-Type-Options: nosniff');
        
        // Enable XSS protection
        header('X-XSS-Protection: 1; mode=block');
        
        // Referrer policy
        header('Referrer-Policy: strict-origin-when-cross-origin');
        
        // Content Security Policy
        header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline'; style-src 'self' 'unsafe-inline';");
    }
    
    /**
     * Add admin nonce for security
     */
    public function add_admin_nonce(): void {
        // Only add nonce on orders page
        $screen = get_current_screen();
        if (!$screen || $screen->id !== $this->get_orders_screen_id()) {
            return;
        }
        
        // Add nonce to admin page
        wp_nonce_field('admin_security', '_wpnonce', true, true);
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
