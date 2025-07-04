<?php
/**
 * Database functionality for WP Post Sync
 *
 * @package WP_Post_Sync
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Database class for WP Post Sync
 */
class WP_Post_Sync_DB {
    
    /**
     * Table names
     */
    public $sync_queue_table = 'wp_post_sync_queue';
    public $sync_log_table = 'wp_post_sync_log';
    
    /**
     * Constructor
     */
    public function __construct() {
        // Nothing to do here
    }
    
    /**
     * Activation hook
     */
    public function activate() {
        $this->create_sync_tables();
        $this->create_sync_options();
    }
    
    /**
     * Create database tables
     */
    public function create_sync_tables() {
        global $wpdb;
        
        $charset_collate = $wpdb->get_charset_collate();
        
        // Sync queue table
        $sql = "CREATE TABLE {$this->sync_queue_table} (
            id int(11) NOT NULL AUTO_INCREMENT,
            post_id bigint(20) NOT NULL,
            post_type varchar(20) NOT NULL,
            action varchar(20) NOT NULL,
            priority int(11) DEFAULT 1,
            attempts int(11) DEFAULT 0,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY post_id (post_id),
            KEY priority (priority)
        ) $charset_collate;";
        
        // Sync log table
        $sql2 = "CREATE TABLE {$this->sync_log_table} (
            id int(11) NOT NULL AUTO_INCREMENT,
            post_id bigint(20) NOT NULL,
            action varchar(20) NOT NULL,
            status varchar(20) NOT NULL,
            message text,
            synced_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY post_id (post_id)
        ) $charset_collate;";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
        dbDelta($sql2);
    }
    
    /**
     * Create default options
     */
    public function create_sync_options() {
        // Default options
        add_option('wp_post_sync_target_url', '');
        add_option('wp_post_sync_api_key', '');
        add_option('wp_post_sync_site_role', 'admin'); // 'admin' or 'public'
        add_option('wp_post_sync_sync_delay', 5); // minutes
        add_option('wp_post_sync_max_attempts', 3);
    }
    
    /**
     * Queue a post for syncing
     * 
     * @param int $post_id Post ID
     * @param object $post Post object
     * @return bool Success
     */
    public function queue_post_sync($post_id, $post) {
        // Sync published, scheduled, pending, private, and trashed posts
        $syncable_statuses = ['publish', 'future', 'pending', 'private', 'trash'];
        
        if (!in_array($post->post_status, $syncable_statuses)) {
            return false;
        }
        
        // Skip if this is public site
        if (get_option('wp_post_sync_site_role') === 'public') {
            return false;
        }
        
        global $wpdb;
        
        // Check if already queued
        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$this->sync_queue_table} WHERE post_id = %d AND action = 'sync'",
            $post_id
        ));
        
        if (!$existing) {
            $wpdb->insert(
                $this->sync_queue_table,
                array(
                    'post_id' => $post_id,
                    'post_type' => $post->post_type,
                    'action' => 'sync',
                    'priority' => 1
                )
            );
            return true;
        }
        
        return false;
    }
    
    /**
     * Queue a post status change for syncing
     * 
     * @param int $post_id Post ID
     * @param string $old_status Old post status
     * @param string $new_status New post status
     * @return bool Success
     */
    public function queue_status_change($post_id, $old_status, $new_status) {
        // Skip if this is public site
        if (get_option('wp_post_sync_site_role') === 'public') {
            return false;
        }
        
        // Only track specific status changes
        $tracked_statuses = ['publish', 'future', 'pending', 'private', 'trash'];
        
        if (!in_array($new_status, $tracked_statuses)) {
            return false;
        }
        
        global $wpdb;
        $post = get_post($post_id);
        
        if (!$post) {
            return false;
        }
        
        // Check if already queued
        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$this->sync_queue_table} WHERE post_id = %d AND action = 'sync'",
            $post_id
        ));
        
        if (!$existing) {
            $wpdb->insert(
                $this->sync_queue_table,
                array(
                    'post_id' => $post_id,
                    'post_type' => $post->post_type,
                    'action' => 'sync',
                    'priority' => 1
                )
            );
            return true;
        }
        
        return false;
    }
    
    /**
     * Queue media for syncing
     * 
     * @param int $attachment_id Attachment ID
     * @return bool Success
     */
    public function queue_media_sync($attachment_id) {
        // Skip if this is public site
        if (get_option('wp_post_sync_site_role') === 'public') {
            return false;
        }
        
        global $wpdb;
        
        $wpdb->insert(
            $this->sync_queue_table,
            array(
                'post_id' => $attachment_id,
                'post_type' => 'attachment',
                'action' => 'sync',
                'priority' => 2
            )
        );
        
        return true;
    }
    
    /**
     * Get items to sync
     * 
     * @param int $limit Maximum number of items
     * @param bool $ignore_delay Whether to ignore the sync delay
     * @return array Items to sync
     */
    public function get_items_to_sync($limit = 10, $ignore_delay = false) {
        global $wpdb;
        
        $max_attempts = get_option('wp_post_sync_max_attempts', 3);
        $sync_delay = get_option('wp_post_sync_sync_delay', 5);
        
        if ($ignore_delay) {
            // Get items without delay (for manual sync)
            $items = $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM {$this->sync_queue_table} 
                 WHERE attempts < %d 
                 ORDER BY priority ASC, created_at ASC 
                 LIMIT %d",
                $max_attempts,
                $limit
            ));
        } else {
            // Get items with delay (for cron sync)
            $items = $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM {$this->sync_queue_table} 
                 WHERE attempts < %d 
                 AND created_at <= DATE_SUB(NOW(), INTERVAL %d MINUTE)
                 ORDER BY priority ASC, created_at ASC 
                 LIMIT %d",
                $max_attempts,
                $sync_delay,
                $limit
            ));
        }
        
        return $items;
    }
    
    /**
     * Remove item from sync queue
     * 
     * @param int $item_id Item ID
     * @return bool Success
     */
    public function remove_from_queue($item_id) {
        global $wpdb;
        return $wpdb->delete($this->sync_queue_table, array('id' => $item_id));
    }
    
    /**
     * Increment sync attempts
     * 
     * @param int $item_id Item ID
     * @param int $current_attempts Current attempts
     * @return bool Success
     */
    public function increment_sync_attempts($item_id, $current_attempts) {
        global $wpdb;
        return $wpdb->update(
            $this->sync_queue_table,
            array('attempts' => $current_attempts + 1),
            array('id' => $item_id)
        );
    }
    
    /**
     * Log sync result
     * 
     * @param int $post_id Post ID
     * @param string $action Action
     * @param string $status Status
     * @param string $message Message
     * @return bool Success
     */
    public function log_sync($post_id, $action, $status, $message) {
        global $wpdb;
        
        return $wpdb->insert(
            $this->sync_log_table,
            array(
                'post_id' => $post_id,
                'action' => $action,
                'status' => $status,
                'message' => $message
            )
        );
    }
    
    /**
     * Get pending sync count
     * 
     * @return int Count
     */
    public function get_pending_sync_count() {
        global $wpdb;
        return (int) $wpdb->get_var("SELECT COUNT(*) FROM {$this->sync_queue_table}");
    }
    
    /**
     * Get recent sync logs
     * 
     * @param int $limit Maximum number of logs
     * @return array Logs
     */
    public function get_recent_logs($limit = 10) {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$this->sync_log_table} ORDER BY synced_at DESC LIMIT %d",
            $limit
        ));
    }
    
    /**
     * Get sync status for a post
     * 
     * @param int $post_id Post ID
     * @return object Status
     */
    public function get_post_sync_status($post_id) {
        global $wpdb;
        
        $queued = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$this->sync_queue_table} WHERE post_id = %d",
            $post_id
        ));
        
        $last_sync = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->sync_log_table} WHERE post_id = %d ORDER BY synced_at DESC LIMIT 1",
            $post_id
        ));
        
        return (object) array(
            'queued' => $queued > 0,
            'last_sync' => $last_sync
        );
    }
}
