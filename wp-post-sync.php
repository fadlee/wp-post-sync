<?php
/**
 * Plugin Name: WP Post Sync
 * Description: Sync posts from admin site to public site
 * Version: 1.0.0
 * Author: WordPress Developer
 * Text Domain: wp-post-sync
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
define('WP_POST_SYNC_VERSION', '1.0.0');
define('WP_POST_SYNC_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('WP_POST_SYNC_PLUGIN_URL', plugin_dir_url(__FILE__));

/**
 * The main plugin class that bootstraps the plugin
 */
class WP_Post_Sync_Plugin {

    /**
     * The single instance of this class
     */
    private static $instance = null;

    /**
     * Admin class instance
     */
    public $admin = null;

    /**
     * Public class instance
     */
    public $public = null;

    /**
     * Database class instance
     */
    public $db = null;

    /**
     * API class instance
     */
    public $api = null;

    /**
     * Main WP_Post_Sync_Plugin Instance
     *
     * Ensures only one instance of WP_Post_Sync_Plugin is loaded or can be loaded.
     *
     * @return WP_Post_Sync_Plugin - Main instance
     */
    public static function instance() {
        if (is_null(self::$instance)) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Constructor
     */
    private function __construct() {
        $this->load_dependencies();
        $this->define_hooks();
    }

    /**
     * Common class instance
     */
    public $common = null;

    /**
     * Load the required dependencies for this plugin.
     */
    private function load_dependencies() {
        // Include the database class
        require_once WP_POST_SYNC_PLUGIN_DIR . 'includes/class-db.php';
        $this->db = new WP_Post_Sync_DB();

        // Include the API class
        require_once WP_POST_SYNC_PLUGIN_DIR . 'includes/class-api.php';
        $this->api = new WP_Post_Sync_API($this->db);

        // Include admin and public classes based on site role
        $site_role = get_option('wp_post_sync_site_role', 'admin');

        // Always load the common functionality
        require_once WP_POST_SYNC_PLUGIN_DIR . 'includes/class-common.php';
        $this->common = new WP_Post_Sync_Common($this->db, $this->api);

        if (is_admin()) {
            require_once WP_POST_SYNC_PLUGIN_DIR . 'admin/class-admin.php';
            $this->admin = new WP_Post_Sync_Admin($this->db, $this->api);
        }

        require_once WP_POST_SYNC_PLUGIN_DIR . 'public/class-public.php';
        $this->public = new WP_Post_Sync_Public($this->db, $this->api);
    }

    /**
     * Define the hooks that are needed on both admin and public sites
     */
    private function define_hooks() {
        // Register activation and deactivation hooks
        register_activation_hook(__FILE__, array($this->db, 'activate'));
        register_deactivation_hook(__FILE__, array($this, 'deactivate'));
    }

    /**
     * Deactivation hook
     */
    public function deactivate() {
        wp_clear_scheduled_hook('wp_post_sync_check');
    }
}

/**
 * Returns the main instance of WP_Post_Sync_Plugin
 *
 * @return WP_Post_Sync_Plugin
 */
function WP_Post_Sync() {
    return WP_Post_Sync_Plugin::instance();
}

// Initialize the plugin
WP_Post_Sync();
