<?php
/**
 * Admin functionality for WP Post Sync
 *
 * @package WP_Post_Sync
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Admin class for WP Post Sync
 */
class WP_Post_Sync_Admin {

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
        // Admin menu
        add_action('admin_menu', array($this, 'add_admin_menu'));

        // Register settings
        add_action('admin_init', array($this, 'register_settings'));

        if (get_option('wp_post_sync_site_role') === 'public') {
            return;
        }

        // Add sync status column to post list
        add_filter('manage_posts_columns', array($this, 'add_sync_status_column'));
        add_action('manage_posts_custom_column', array($this, 'show_sync_status_column'), 10, 2);

        add_filter('manage_pages_columns', array($this, 'add_sync_status_column'));
        add_action('manage_pages_custom_column', array($this, 'show_sync_status_column'), 10, 2);

        // Add admin bar sync button
        add_action('admin_bar_menu', array($this, 'add_sync_now_button'), 100);
        add_action('admin_footer', array($this, 'sync_now_js'));
        add_action('wp_ajax_process_sync_now', array($this, 'process_sync_now'));
    }

    /**
     * Add admin menu
     */
    public function add_admin_menu() {
        add_management_page(
            'WP Post Sync Settings',
            'WP Post Sync',
            'manage_options',
            'wp-post-sync',
            array($this, 'admin_page')
        );
    }

    /**
     * Register settings
     */
    public function register_settings() {
        register_setting('wp_post_sync', 'wp_post_sync_target_url');
        register_setting('wp_post_sync', 'wp_post_sync_api_key');
        register_setting('wp_post_sync', 'wp_post_sync_site_role');
        register_setting('wp_post_sync', 'wp_post_sync_sync_delay');
    }

    /**
     * Admin page
     */
    public function admin_page() {
        ?>
        <div class="wrap">
            <h1>WP Post Sync Settings</h1>
            <form method="post" action="options.php">
                <?php settings_fields('wp_post_sync'); ?>
                <?php do_settings_sections('wp_post_sync'); ?>
                <table class="form-table">
                    <tr valign="top">
                        <th scope="row">Site Role</th>
                        <td>
                            <select name="wp_post_sync_site_role">
                                <option value="admin" <?php selected(get_option('wp_post_sync_site_role'), 'admin'); ?>>Admin (Source)</option>
                                <option value="public" <?php selected(get_option('wp_post_sync_site_role'), 'public'); ?>>Public (Target)</option>
                            </select>
                            <p class="description">Role of this site in the sync process</p>
                        </td>
                    </tr>
                    <?php if (get_option('wp_post_sync_site_role') !== 'public'): // Hide Target URL for public (target) sites ?>
                    <tr valign="top">
                        <th scope="row">Target URL</th>
                        <td>
                            <input type="text" name="wp_post_sync_target_url" value="<?php echo esc_attr(get_option('wp_post_sync_target_url')); ?>" class="regular-text" />
                            <p class="description">URL of the target site (e.g. https://example.com)</p>
                        </td>
                    </tr>
                    <?php endif; ?>
                    <tr valign="top">
                        <th scope="row">API Key</th>
                        <td>
                            <input type="text" name="wp_post_sync_api_key" value="<?php echo esc_attr(get_option('wp_post_sync_api_key')); ?>" class="regular-text" />
                            <p class="description">API key for authentication</p>
                        </td>
                    </tr>
                    <tr valign="top">
                        <th scope="row">Sync Delay (minutes)</th>
                        <td>
                            <input type="number" name="wp_post_sync_sync_delay" value="<?php echo esc_attr(get_option('wp_post_sync_sync_delay', 5)); ?>" class="small-text" min="0" />
                            <p class="description">Delay before syncing posts (0 for immediate)</p>
                        </td>
                    </tr>
                </table>
                <?php submit_button(); ?>
            </form>

            <h2>Sync Status</h2>
            <p>Pending items: <?php echo $this->db->get_pending_sync_count(); ?></p>

            <h3>Recent Sync Logs</h3>
            <table class="widefat">
                <thead>
                    <tr>
                        <th>Post ID</th>
                        <th>Action</th>
                        <th>Status</th>
                        <th>Message</th>
                        <th>Time</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    $logs = $this->db->get_recent_logs(10);
                    foreach ($logs as $log) {
                        $status_class = $log->status === 'success' ? 'updated' : 'error';
                        echo '<tr class="' . $status_class . '">';
                        echo '<td>' . $log->post_id . '</td>';
                        echo '<td>' . $log->action . '</td>';
                        echo '<td>' . $log->status . '</td>';
                        echo '<td>' . $log->message . '</td>';
                        echo '<td>' . $log->synced_at . '</td>';
                        echo '</tr>';
                    }
                    if (empty($logs)) {
                        echo '<tr><td colspan="5">No logs found</td></tr>';
                    }
                    ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    /**
     * Add sync status column
     *
     * @param array $columns Columns
     * @return array Modified columns
     */
    public function add_sync_status_column($columns) {
        $columns['sync_status'] = 'Sync Status';
        return $columns;
    }

    /**
     * Show sync status column
     *
     * @param string $column Column name
     * @param int $post_id Post ID
     */
    public function show_sync_status_column($column, $post_id) {
        if ($column !== 'sync_status') {
            return;
        }

        $status = $this->db->get_post_sync_status($post_id);

        if ($status->queued) {
            echo '<span class="dashicons dashicons-clock" title="Queued for sync"></span> Queued';
        } elseif ($status->last_sync) {
            if ($status->last_sync->status === 'success') {
                echo '<span class="dashicons dashicons-yes" title="Synced successfully"></span> Synced';
            } else {
                echo '<span class="dashicons dashicons-no" title="Sync failed: ' . esc_attr($status->last_sync->message) . '"></span> Failed';
            }
        } else {
            echo '<span class="dashicons dashicons-minus"></span> Not synced';
        }
    }

    /**
     * Add sync now button to admin bar
     *
     * @param WP_Admin_Bar $admin_bar Admin bar object
     */
    public function add_sync_now_button($admin_bar) {
        // Only show on admin site
        if (get_option('wp_post_sync_site_role') !== 'admin') {
            return;
        }

        // Only show if there are pending items
        $pending_count = $this->db->get_pending_sync_count();
        if ($pending_count <= 0) {
            return;
        }

        $admin_bar->add_node(array(
            'id'    => 'sync-now',
            'title' => 'Sync Now (' . $pending_count . ')',
            'href'  => '#',
            'meta'  => array(
                'title' => 'Process sync queue now',
                'class' => 'sync-now-button'
            ),
        ));
    }

    /**
     * Add JavaScript for sync now button
     */
    public function sync_now_js() {
        // Only show on admin site
        if (get_option('wp_post_sync_site_role') !== 'admin') {
            return;
        }

        // Only show if there are pending items
        $pending_count = $this->db->get_pending_sync_count();
        if ($pending_count <= 0) {
            return;
        }

        ?>
        <script type="text/javascript">
        jQuery(document).ready(function($) {
            $('#wp-admin-bar-sync-now .ab-item').on('click', function(e) {
                e.preventDefault();

                var $button = $(this);
                var originalText = $button.text();

                $button.text('Syncing...');

                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'process_sync_now',
                        nonce: '<?php echo wp_create_nonce('wp_post_sync_now'); ?>'
                    },
                    success: function(response) {
                        if (response.success) {
                            $button.text('Sync Complete');
                            setTimeout(function() {
                                location.reload();
                            }, 1000);
                        } else {
                            $button.text('Sync Failed');
                            console.error('Sync failed:', response.data);
                            setTimeout(function() {
                                $button.text(originalText);
                            }, 2000);
                        }
                    },
                    error: function(xhr, status, error) {
                        $button.text('Sync Failed');
                        console.error('Sync error:', error);
                        setTimeout(function() {
                            $button.text(originalText);
                        }, 2000);
                    }
                });
            });
        });
        </script>
        <?php
    }

    /**
     * Process sync now AJAX request
     */
    public function process_sync_now() {
        // Check nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'wp_post_sync_now')) {
            wp_send_json_error('Invalid nonce');
        }

        // Check permissions
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Permission denied');
        }

        // Process queue
        $common = new WP_Post_Sync_Common($this->db, $this->api);
        $results = $common->process_sync_queue(true); // Ignore delay

        wp_send_json_success(array(
            'message' => 'Sync processed',
            'results' => $results
        ));
    }
}
