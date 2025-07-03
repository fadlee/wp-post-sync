<?php
/**
 * Public site functionality for WP Post Sync
 *
 * @package WP_Post_Sync
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Public class for WP Post Sync
 */
class WP_Post_Sync_Public {

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
        // Only apply restrictions on public site
        if (get_option('wp_post_sync_site_role') === 'public') {
            // Add admin notice about editing restrictions
            add_action('admin_notices', array($this, 'show_public_site_notice'));
        }
    }

    /**
     * Show public site notice
     */
    public function show_public_site_notice() {
        $screen = get_current_screen();
        if ($screen->base === 'edit' || $screen->base === 'post') {
            ?>
            <div class="notice notice-warning">
                <p><strong>WP Post Sync:</strong> This is a public site. Content is synced from the admin site and cannot be edited here.</p>
            </div>
            <?php
        }
    }
}
