<?php
/**
 * API functionality for WP Post Sync
 *
 * @package WP_Post_Sync
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * API class for WP Post Sync
 */
class WP_Post_Sync_API {
    
    /**
     * Database instance
     */
    private $db;
    
    /**
     * Constructor
     * 
     * @param WP_Post_Sync_DB $db Database instance
     */
    public function __construct($db) {
        $this->db = $db;
        
        // Register REST API endpoints
        add_action('rest_api_init', array($this, 'register_rest_routes'));
    }
    
    /**
     * Register REST API routes
     */
    public function register_rest_routes() {
        register_rest_route('wp-post-sync/v1', '/receive-post', array(
            'methods' => 'POST',
            'callback' => array($this, 'receive_post'),
            'permission_callback' => array($this, 'check_api_permission')
        ));
        
        register_rest_route('wp-post-sync/v1', '/receive-media', array(
            'methods' => 'POST',
            'callback' => array($this, 'receive_media'),
            'permission_callback' => array($this, 'check_api_permission')
        ));
    }
    
    /**
     * Check API permission
     * 
     * @param WP_REST_Request $request Request object
     * @return bool Permission
     */
    public function check_api_permission($request) {
        $auth_header = $request->get_header('authorization');
        if (!$auth_header) {
            return false;
        }
        
        $api_key = str_replace('Bearer ', '', $auth_header);
        return $api_key === get_option('wp_post_sync_api_key');
    }
    
    /**
     * Receive post from admin site
     * 
     * @param WP_REST_Request $request Request object
     * @return WP_REST_Response Response
     */
    public function receive_post($request) {
        // Enable error logging
        error_log('WP Post Sync: Received post sync request');
        
        $data = json_decode($request->get_body(), true);
        
        if (!$data || !isset($data['post'])) {
            error_log('WP Post Sync: Invalid post data received');
            return rest_ensure_response(new WP_Error('invalid_data', 'Invalid post data'));
        }
        
        $post_data = $data['post'];
        $meta_data = isset($data['meta']) ? $data['meta'] : array();
        $taxonomies = isset($data['taxonomies']) ? $data['taxonomies'] : array();
        
        error_log('WP Post Sync: Processing post ID ' . $post_data['ID'] . ' with title: ' . $post_data['post_title']);
        
        // Preserve post ID for proper syncing
        $preserve_post_id = $post_data['ID'];
        
        // Check if post exists
        $existing_post = get_post($preserve_post_id);
        
        if ($existing_post) {
            error_log('WP Post Sync: Updating existing post ID ' . $preserve_post_id);
            // Update existing post
            $result = wp_update_post($post_data, true);
        } else {
            error_log('WP Post Sync: Creating new post with ID ' . $preserve_post_id);
            
            // Use direct database insertion to preserve the post ID
            global $wpdb;
            
            // First check if the ID is already in use
            $id_exists = $wpdb->get_var($wpdb->prepare("SELECT ID FROM {$wpdb->posts} WHERE ID = %d", $preserve_post_id));
            
            if ($id_exists) {
                error_log('WP Post Sync: Post ID ' . $preserve_post_id . ' already exists, using wp_insert_post instead');
                // If ID exists, fall back to standard insertion
                $result = wp_insert_post($post_data, true);
            } else {
                // Prepare post data for direct insertion
                $wpdb->insert(
                    $wpdb->posts,
                    array(
                        'ID' => $preserve_post_id,
                        'post_author' => $post_data['post_author'],
                        'post_date' => $post_data['post_date'],
                        'post_date_gmt' => $post_data['post_date_gmt'],
                        'post_content' => $post_data['post_content'],
                        'post_title' => $post_data['post_title'],
                        'post_excerpt' => $post_data['post_excerpt'],
                        'post_status' => $post_data['post_status'],
                        'comment_status' => $post_data['comment_status'],
                        'ping_status' => $post_data['ping_status'],
                        'post_password' => $post_data['post_password'],
                        'post_name' => $post_data['post_name'],
                        'to_ping' => $post_data['to_ping'],
                        'pinged' => $post_data['pinged'],
                        'post_modified' => $post_data['post_modified'],
                        'post_modified_gmt' => $post_data['post_modified_gmt'],
                        'post_content_filtered' => $post_data['post_content_filtered'],
                        'post_parent' => $post_data['post_parent'],
                        'guid' => str_replace(basename($post_data['guid']), basename($post_data['guid']), get_option('home') . '/?p=' . $preserve_post_id),
                        'menu_order' => $post_data['menu_order'],
                        'post_type' => $post_data['post_type'],
                        'post_mime_type' => $post_data['post_mime_type'],
                        'comment_count' => $post_data['comment_count']
                    )
                );
                
                if ($wpdb->insert_id) {
                    error_log('WP Post Sync: Successfully inserted post with ID ' . $preserve_post_id . ' directly');
                    $result = $preserve_post_id;
                    
                    // Clean the post cache
                    clean_post_cache($preserve_post_id);
                    
                    // Fire necessary hooks
                    do_action('wp_insert_post', $preserve_post_id, get_post($preserve_post_id), true);
                } else {
                    error_log('WP Post Sync: Failed to insert post directly: ' . $wpdb->last_error);
                    return rest_ensure_response(new WP_Error('db_insert_error', 'Failed to insert post: ' . $wpdb->last_error));
                }
            }
        }
        
        if (is_wp_error($result)) {
            error_log('WP Post Sync: Error creating/updating post: ' . $result->get_error_message());
            return rest_ensure_response($result);
        }
        
        error_log('WP Post Sync: Post created/updated successfully with ID: ' . $result);
        
        // Update meta
        if (!empty($meta_data)) {
            foreach ($meta_data as $key => $values) {
                delete_post_meta($result, $key);
                foreach ($values as $value) {
                    add_post_meta($result, $key, $value);
                }
            }
            error_log('WP Post Sync: Updated post meta for post ID ' . $result);
        }
        
        // Update taxonomies
        if (!empty($taxonomies)) {
            foreach ($taxonomies as $taxonomy => $terms) {
                $term_ids = array();
                foreach ($terms as $term) {
                    $existing_term = get_term_by('slug', $term['slug'], $taxonomy);
                    if (!$existing_term) {
                        $new_term = wp_insert_term($term['name'], $taxonomy, array('slug' => $term['slug']));
                        if (!is_wp_error($new_term)) {
                            $term_ids[] = $new_term['term_id'];
                        }
                    } else {
                        $term_ids[] = $existing_term->term_id;
                    }
                }
                wp_set_post_terms($result, $term_ids, $taxonomy);
            }
            error_log('WP Post Sync: Updated taxonomies for post ID ' . $result);
        }
        
        return rest_ensure_response(array('success' => true, 'post_id' => $result));
    }
    
    /**
     * Receive media from admin site
     * 
     * @param WP_REST_Request $request Request object
     * @return WP_REST_Response Response
     */
    public function receive_media($request) {
        // Increase memory limit for this request
        @ini_set('memory_limit', '256M');
        
        $data = json_decode($request->get_body(), true);
        
        if (!$data || !isset($data['attachment'])) {
            return rest_ensure_response(new WP_Error('invalid_data', 'Invalid media data'));
        }
        
        $attachment_data = $data['attachment'];
        $meta_data = $data['meta'];
        
        // Process file data in chunks to avoid memory issues
        $file_content = base64_decode($data['file']);
        
        // Free up memory
        unset($data['file']);
        
        // Save file to uploads directory
        $upload_dir = wp_upload_dir();
        $filename = basename($attachment_data['guid']);
        $file_path = $upload_dir['path'] . '/' . $filename;
        
        if (file_put_contents($file_path, $file_content) === false) {
            return rest_ensure_response(new WP_Error('file_save_error', 'Could not save file'));
        }
        
        // Free up memory
        unset($file_content);
        
        // Create attachment
        $attachment = array(
            'ID' => $attachment_data['ID'],
            'post_title' => $attachment_data['post_title'],
            'post_content' => $attachment_data['post_content'],
            'post_status' => 'inherit',
            'post_mime_type' => $attachment_data['post_mime_type'],
            'guid' => $upload_dir['url'] . '/' . $filename
        );
        
        $attach_id = wp_insert_attachment($attachment, $file_path);
        
        if (is_wp_error($attach_id)) {
            return rest_ensure_response($attach_id);
        }
        
        // Update only essential meta
        if (isset($meta_data['_wp_attachment_metadata'])) {
            update_post_meta($attach_id, '_wp_attachment_metadata', $meta_data['_wp_attachment_metadata']);
        }
        
        if (isset($meta_data['_wp_attached_file'])) {
            update_post_meta($attach_id, '_wp_attached_file', $meta_data['_wp_attached_file']);
        }
        
        // Generate thumbnails
        require_once(ABSPATH . 'wp-admin/includes/image.php');
        $attach_data = wp_generate_attachment_metadata($attach_id, $file_path);
        wp_update_attachment_metadata($attach_id, $attach_data);
        
        return rest_ensure_response(array('success' => true, 'attachment_id' => $attach_id));
    }
    
    /**
     * Sync post to public site
     * 
     * @param int $post_id Post ID
     * @return array Result
     */
    public function sync_post($post_id) {
        $target_url = get_option('wp_post_sync_target_url');
        $api_key = get_option('wp_post_sync_api_key');
        
        if (empty($target_url) || empty($api_key)) {
            return array('success' => false, 'message' => 'Missing configuration');
        }
        
        $post = get_post($post_id);
        if (!$post) {
            return array('success' => false, 'message' => 'Post not found');
        }
        
        // Get post meta
        $meta = get_post_meta($post_id);
        
        // Get taxonomies
        $taxonomies = array();
        $post_taxonomies = get_object_taxonomies($post->post_type);
        foreach ($post_taxonomies as $taxonomy) {
            $terms = wp_get_post_terms($post_id, $taxonomy);
            if (!is_wp_error($terms)) {
                $taxonomies[$taxonomy] = $terms;
            }
        }
        
        // Prepare data
        $data = array(
            'post' => $post,
            'meta' => $meta,
            'taxonomies' => $taxonomies
        );
        
        // Send to target site
        $response = wp_remote_post($target_url . '/wp-json/wp-post-sync/v1/receive-post', array(
            'body' => json_encode($data),
            'headers' => array(
                'Content-Type' => 'application/json',
                'Authorization' => 'Bearer ' . $api_key
            ),
            'timeout' => 30
        ));
        
        if (is_wp_error($response)) {
            return array('success' => false, 'message' => $response->get_error_message());
        }
        
        $code = wp_remote_retrieve_response_code($response);
        if ($code !== 200) {
            return array('success' => false, 'message' => 'HTTP ' . $code);
        }
        
        return array('success' => true, 'message' => 'Post synced successfully');
    }
    
    /**
     * Sync media to public site
     * 
     * @param int $attachment_id Attachment ID
     * @return array Result
     */
    public function sync_media($attachment_id) {
        $target_url = get_option('wp_post_sync_target_url');
        $api_key = get_option('wp_post_sync_api_key');
        
        if (empty($target_url) || empty($api_key)) {
            return array('success' => false, 'message' => 'Missing configuration');
        }
        
        $attachment = get_post($attachment_id);
        if (!$attachment) {
            return array('success' => false, 'message' => 'Attachment not found');
        }
        
        $file_path = get_attached_file($attachment_id);
        if (!file_exists($file_path)) {
            return array('success' => false, 'message' => 'File not found');
        }
        
        // Get file size - skip large files
        $file_size = filesize($file_path);
        if ($file_size > 10 * 1024 * 1024) { // 10MB limit
            return array('success' => false, 'message' => 'File too large for sync: ' . size_format($file_size));
        }
        
        // Get only essential attachment meta
        $meta = array();
        $essential_meta_keys = array('_wp_attachment_metadata', '_wp_attached_file');
        foreach ($essential_meta_keys as $key) {
            $meta[$key] = get_post_meta($attachment_id, $key, true);
        }
        
        // Read file in chunks to avoid memory issues
        $file_content = file_get_contents($file_path);
        if ($file_content === false) {
            return array('success' => false, 'message' => 'Could not read file');
        }
        
        $data = array(
            'attachment' => $attachment,
            'meta' => $meta,
            'file' => base64_encode($file_content)
        );
        
        // Free up memory
        unset($file_content);
        
        // Send to target site
        $response = wp_remote_post($target_url . '/wp-json/wp-post-sync/v1/receive-media', array(
            'body' => json_encode($data),
            'headers' => array(
                'Content-Type' => 'application/json',
                'Authorization' => 'Bearer ' . $api_key
            ),
            'timeout' => 60,
            'httpversion' => '1.1',
            'sslverify' => false
        ));
        
        // Free up memory
        unset($data);
        
        if (is_wp_error($response)) {
            return array('success' => false, 'message' => $response->get_error_message());
        }
        
        $code = wp_remote_retrieve_response_code($response);
        if ($code !== 200) {
            return array('success' => false, 'message' => 'HTTP ' . $code);
        }
        
        return array('success' => true, 'message' => 'Media synced successfully');
    }
    
    /**
     * Process a sync item
     * 
     * @param object $item Item to sync
     * @return array Result
     */
    public function sync_item($item) {
        if ($item->post_type === 'attachment') {
            return $this->sync_media($item->post_id);
        } else {
            return $this->sync_post($item->post_id);
        }
    }
}
