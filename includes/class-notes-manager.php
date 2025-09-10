<?php
/**
 * Notes Manager
 * 
 * Handles notes retrieval, filtering, and formatting for the WC Admin Order Notes plugin.
 * 
 * @package WCAdminOrderNotes
 * @since 2.2.1
 */

namespace WCAdminOrderNotes;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

class NotesManager {
    
    /**
     * @var CacheManager
     */
    private $cache_manager;
    
    /**
     * Constructor
     * 
     * @param CacheManager $cache_manager
     */
    public function __construct(CacheManager $cache_manager) {
        $this->cache_manager = $cache_manager;
    }
    
    /**
     * Get cached order notes
     * 
     * @param int $order_id
     * @param int $limit
     * @return array
     */
    public function get_order_notes_cached(int $order_id, int $limit = -1): array {
        return $this->cache_manager->get_order_notes_cached($order_id, $limit);
    }
    
    /**
     * Get fresh order notes (bypassing cache)
     * 
     * @param int $order_id
     * @param int $limit
     * @return array
     */
    public function get_order_notes_fresh(int $order_id, int $limit = -1): array {
        $fetch_limit = WC_ADMIN_ORDER_NOTES_FILTER_SYSTEM ? -1 : $limit;
        
        // Get fresh notes directly from WooCommerce
        $args = [
            'order_id' => $order_id,
            'limit' => $fetch_limit,
            'orderby' => 'date_created',
            'order' => 'DESC',
            'type' => '', // Get all types of notes (customer and admin)
            'approve' => 'approve'
        ];
        
        // Use WooCommerce function for better security and compatibility
        $notes = wc_get_order_notes($args);
        
        // Filter human notes if enabled
        if (WC_ADMIN_ORDER_NOTES_FILTER_SYSTEM) {
            $notes = $this->filter_human_notes($notes);
            
            // Apply limit after filtering
            if ($limit > 0) {
                $notes = array_slice($notes, 0, $limit);
            }
        }
        
        return $notes;
    }
    
    /**
     * Clear order notes cache
     * 
     * @param int $note_id
     * @param int|\WC_Order $order_id_or_order
     */
    public function clear_order_notes_cache(int $note_id, $order_id_or_order): void {
        $this->cache_manager->clear_order_notes_cache($note_id, $order_id_or_order);
    }
    
    /**
     * Format notes for response
     * 
     * @param array $notes
     * @return array
     */
    public function format_notes_for_response(array $notes): array {
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
     * Filter out system status change messages from order notes.
     *
     * @param array $notes The array of notes to filter.
     * @return array The filtered array of notes.
     */
    private function filter_human_notes(array $notes): array {
        if (empty($notes)) {
            return [];
        }
        
        $filtered_notes = [];
        
        // Pre-compiled regex patterns for better performance
        static $compiled_patterns = null;
        if ($compiled_patterns === null) {
            $compiled_patterns = [
                // Order status changes
                '/status changed from/i',
                '/статус замовлення змінено з/iu',
                '/статус змінено з/iu',
                
                // Email notifications
                '/email sent to/i',
                '/електронний лист надіслано/iu',
                '/email надіслано/iu',
                
                // Payment systems
                '/payment complete/i',
                '/payment received/i',
                '/payment authorized/i',
                '/платіж завершено/iu',
                '/платіж отримано/iu',
                '/платіж авторизовано/iu',
                
                // Shipping and logistics
                '/shipped via/i',
                '/tracking number/i',
                '/відправлено через/iu',
                '/номер відстеження/iu',
                
                // Automatic WooCommerce actions
                '/refund/i',
                '/повернення коштів/iu',
                '/відшкодування/iu',
                
                // Other system events
                '/automatically generated/i',
                '/автоматично створено/iu',
                '/system:/i',
                '/система:/iu',
            ];
        }
        
        // Pre-compiled status keywords for short content check
        static $status_keywords = null;
        if ($status_keywords === null) {
            $status_keywords = [
                'pending', 'processing', 'on-hold', 'completed', 'cancelled', 'refunded', 'failed',
                'очікування', 'обробка', 'утримання', 'завершено', 'скасовано', 'повернено', 'помилка'
            ];
        }
        
        foreach ($notes as $note) {
            $is_system_note = false;
            $content = $note->content ?? '';
            
            // Skip empty content
            if (empty(trim($content))) {
                continue;
            }
            
            // Check if note contains system patterns
            foreach ($compiled_patterns as $pattern) {
                if (preg_match($pattern, $content)) {
                    $is_system_note = true;
                    break;
                }
            }
            
            // Additional check: if note is very short and contains only status
            if (!$is_system_note && strlen(trim($content)) < 10) {
                $short_content = strtolower(trim($content));
                
                foreach ($status_keywords as $keyword) {
                    if (strpos($short_content, $keyword) !== false) {
                        $is_system_note = true;
                        break;
                    }
                }
            }
            
            // If note is not system note, add it to result
            if (!$is_system_note) {
                $filtered_notes[] = $note;
            }
        }
        
        return $filtered_notes;
    }
}
