<?php
/**
 * Cache Manager
 * 
 * Handles caching functionality for the WC Admin Order Notes plugin.
 * 
 * @package WCAdminOrderNotes
 * @since 2.2.1
 */

namespace WCAdminOrderNotes;

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

class CacheManager {
    
    /**
     * @var string Cache group name
     */
    private const CACHE_GROUP = 'wc_admin_order_notes';
    
    /**
     * @var int Cache expiration in seconds
     */
    private const CACHE_EXPIRATION = 300; // 5 minutes
    
    /**
     * Get cached order notes
     * 
     * @param int $order_id
     * @param int $limit
     * @return array
     */
    public function get_order_notes_cached(int $order_id, int $limit = -1): array {
        $cache_key = sprintf('order_notes_%d_%d_%s', $order_id, $limit, WC_ADMIN_ORDER_NOTES_FILTER_SYSTEM ? 'filtered' : 'all');
        $cached_notes = wp_cache_get($cache_key, self::CACHE_GROUP);
        
        if (false === $cached_notes) {
            $fetch_limit = WC_ADMIN_ORDER_NOTES_FILTER_SYSTEM ? -1 : $limit;
            
            $cached_notes = wc_get_order_notes([
                'order_id' => $order_id,
                'limit' => $fetch_limit,
                'orderby' => 'date_created',
                'order' => 'DESC',
                'type' => '' // Get all types of notes (customer and admin)
            ]);
            
            // Filter only human comments (exclude system notes) if enabled
            if (WC_ADMIN_ORDER_NOTES_FILTER_SYSTEM) {
                $cached_notes = $this->filter_human_notes($cached_notes);
                
                // Apply limit after filtering
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
        
        // Use optimized cache clearing
        $this->clear_all_order_caches($order_id);
    }
    
    /**
     * Clear all cache entries for an order (optimized)
     * 
     * @param int $order_id
     */
    private function clear_all_order_caches(int $order_id): void {
        // Pre-defined cache keys for better performance
        static $cache_keys = null;
        if ($cache_keys === null) {
            $cache_variants = ['filtered', 'all'];
            $common_limits = [1, 5, 10, 20, 50, -1];
            $cache_keys = [];
            
            foreach ($common_limits as $limit) {
                foreach ($cache_variants as $variant) {
                    $cache_keys[] = sprintf('order_notes_%d_%d_%s', $order_id, $limit, $variant);
                }
                // Legacy cache format for compatibility
                $cache_keys[] = sprintf('order_notes_%d_%d', $order_id, $limit);
            }
        }
        
        // Batch delete cache entries
        foreach ($cache_keys as $cache_key) {
            wp_cache_delete($cache_key, self::CACHE_GROUP);
        }
        
        // Clear WooCommerce-related caches
        if (function_exists('wc_delete_shop_order_transients')) {
            wc_delete_shop_order_transients($order_id);
        }
        
        // Clear rate limiting cache for this user
        $user_id = get_current_user_id();
        if ($user_id) {
            wp_cache_delete("wc_notes_rate_limit_{$user_id}", 'wc_notes_rate_limit');
        }
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
