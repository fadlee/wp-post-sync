<?php
/**
 * Common functionality for WP Post Sync
 *
 * @package WP_Post_Sync
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Common class for WP Post Sync
 */
class WP_Post_Sync_Common {
    
    /**
     * Database instance
     */
    private $db;
    
    /**
     * API instance
     */
    private $api;
    
    /**
     * Constructor
     * 
     * @param WP_Post_Sync_DB $db Database instance
     * @param WP_Post_Sync_API $api API instance
     */
    public function __construct($db, $api) {
        $this->db = $db;
        $this->api = $api;
        
        // Register hooks
        $this->register_hooks();
    }
    
    /**
     * Register hooks
     */
    private function register_hooks() {
        // Register cron schedule
        add_filter('cron_schedules', array($this, 'add_cron_interval'));
        
        // Schedule cron event if not already scheduled
        if (!wp_next_scheduled('wp_post_sync_check')) {
            wp_schedule_event(time(), 'every_minute', 'wp_post_sync_check');
        }
        
        // Hook into cron event
        add_action('wp_post_sync_check', array($this, 'process_sync_queue'));
        
        // Hook into post save
        add_action('save_post', array($this, 'on_save_post'), 10, 3);
        
        // Hook into media upload
        add_action('add_attachment', array($this, 'on_add_attachment'));
        add_action('edit_attachment', array($this, 'on_add_attachment'));
    }
    
    /**
     * Add custom cron interval
     * 
     * @param array $schedules Schedules
     * @return array Modified schedules
     */
    public function add_cron_interval($schedules) {
        $schedules['every_minute'] = array(
            'interval' => 60,
            'display' => __('Every Minute')
        );
        return $schedules;
    }
    
    /**
     * Handle post save
     * 
     * @param int $post_id Post ID
     * @param object $post Post object
     * @param bool $update Whether this is an update
     */
    public function on_save_post($post_id, $post, $update) {
        // Skip revisions and auto-saves
        if (wp_is_post_revision($post_id) || wp_is_post_autosave($post_id)) {
            return;
        }
        
        // Skip if this is not a post type we want to sync
        $post_types = apply_filters('wp_post_sync_post_types', array('post', 'page'));
        if (!in_array($post->post_type, $post_types)) {
            return;
        }
        
        $this->db->queue_post_sync($post_id, $post);
    }
    
    /**
     * Handle attachment upload
     * 
     * @param int $attachment_id Attachment ID
     */
    public function on_add_attachment($attachment_id) {
        $this->db->queue_media_sync($attachment_id);
    }
    
    /**
     * Process sync queue
     * 
     * @param bool $ignore_delay Whether to ignore the sync delay
     * @return array Results
     */
    public function process_sync_queue($ignore_delay = false) {
        // Skip if this is public site
        if (get_option('wp_post_sync_site_role') === 'public') {
            return array();
        }
        
        $results = array();
        $items = $this->db->get_items_to_sync(10, $ignore_delay);
        
        foreach ($items as $item) {
            $result = $this->api->sync_item($item);
            
            if ($result['success']) {
                // Remove from queue on success
                $this->db->remove_from_queue($item->id);
                $this->db->log_sync($item->post_id, $item->action, 'success', $result['message']);
            } else {
                // Increment attempts on failure
                $this->db->increment_sync_attempts($item->id, $item->attempts);
                $this->db->log_sync($item->post_id, $item->action, 'error', $result['message']);
            }
            
            $results[] = array(
                'post_id' => $item->post_id,
                'post_type' => $item->post_type,
                'action' => $item->action,
                'success' => $result['success'],
                'message' => $result['message']
            );
        }
        
        return $results;
    }
}

// Common class will be initialized by the main plugin class
