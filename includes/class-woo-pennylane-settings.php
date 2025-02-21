<?php
namespace WooPennylane\Admin;

class Settings {
    public function __construct() {
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_init', array($this, 'register_settings'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_scripts'));
    }

    public function add_admin_menu() {
        add_submenu_page(
            'woocommerce',
            __('Pennylane Integration', 'woo-pennylane'),
            __('Pennylane', 'woo-pennylane'),
            'manage_woocommerce',
            'woo-pennylane-settings',
            array($this, 'render_settings_page')
        );
    }

    public function register_settings() {
        register_setting('woo_pennylane_settings', 'woo_pennylane_api_key', array(
            'type' => 'string',
            'sanitize_callback' => 'sanitize_text_field',
            'default' => ''
        ));

        register_setting('woo_pennylane_settings', 'woo_pennylane_journal_code', array(
            'type' => 'string',
            'sanitize_callback' => 'sanitize_text_field',
            'default' => ''
        ));

        register_setting('woo_pennylane_settings', 'woo_pennylane_account_number', array(
            'type' => 'string',
            'sanitize_callback' => 'sanitize_text_field',
            'default' => ''
        ));

        register_setting('woo_pennylane_settings', 'woo_pennylane_debug_mode', array(
            'type' => 'string',
            'sanitize_callback' => 'sanitize_text_field',
            'default' => 'no'
        ));
    }

    public function enqueue_admin_scripts($hook) {
        if ('woocommerce_page_woo-pennylane-settings' !== $hook) {
            return;
        }

        wp_enqueue_style(
            'woo-pennylane-admin',
            WOO_PENNYLANE_PLUGIN_URL . 'assets/css/admin.css',
            array(),
            WOO_PENNYLANE_VERSION
        );

        wp_enqueue_script(
            'woo-pennylane-admin',
            WOO_PENNYLANE_PLUGIN_URL . 'assets/js/admin.js',
            array('jquery'),
            WOO_PENNYLANE_VERSION,
            true
        );
    }

    public function render_settings_page() {
        if (!current_user_can('manage_woocommerce')) {
            return;
        }

        // Messages de succès/erreur
        if (isset($_GET['settings-updated'])) {
            add_settings_error(
                'woo_pennylane_messages',
                'woo_pennylane_message',
                __('Settings Saved', 'woo-pennylane'),
                'updated'
            );
        }

        settings_errors('woo_pennylane_messages');
        
        ?>
        <div class="wrap">
            <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
            
            <form action="options.php" method="post">
                <?php
                settings_fields('woo_pennylane_settings');
                do_settings_sections('woo_pennylane_settings');
                ?>
                
                <table class="form-table">
                    <tr>
                        <th scope="row">
                            <label for="woo_pennylane_api_key">
                                <?php _e('API Key', 'woo-pennylane'); ?>
                            </label>
                        </th>
                        <td>
                            <input type="password" 
                                   id="woo_pennylane_api_key" 
                                   name="woo_pennylane_api_key" 
                                   value="<?php echo esc_attr(get_option('woo_pennylane_api_key')); ?>" 
                                   class="regular-text">
                            <p class="description">
                                <?php _e('Your Pennylane API key', 'woo-pennylane'); ?>
                            </p>
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row">
                            <label for="woo_pennylane_journal_code">
                                <?php _e('Journal Code', 'woo-pennylane'); ?>
                            </label>
                        </th>
                        <td>
                            <input type="text" 
                                   id="woo_pennylane_journal_code" 
                                   name="woo_pennylane_journal_code"
                                   value="<?php echo esc_attr(get_option('woo_pennylane_journal_code')); ?>" 
                                   class="regular-text">
                            <p class="description">
                                <?php _e('Sales journal code in Pennylane', 'woo-pennylane'); ?>
                            </p>
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row">
                            <label for="woo_pennylane_account_number">
                                <?php _e('Account Number', 'woo-pennylane'); ?>
                            </label>
                        </th>
                        <td>
                            <input type="text" 
                                   id="woo_pennylane_account_number" 
                                   name="woo_pennylane_account_number"
                                   value="<?php echo esc_attr(get_option('woo_pennylane_account_number')); ?>" 
                                   class="regular-text">
                            <p class="description">
                                <?php _e('Sales account number in Pennylane', 'woo-pennylane'); ?>
                            </p>
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row">
                            <label for="woo_pennylane_debug_mode">
                                <?php _e('Debug Mode', 'woo-pennylane'); ?>
                            </label>
                        </th>
                        <td>
                            <label>
                                <input type="checkbox" 
                                       id="woo_pennylane_debug_mode" 
                                       name="woo_pennylane_debug_mode"
                                       value="yes" 
                                       <?php checked(get_option('woo_pennylane_debug_mode'), 'yes'); ?>>
                                <?php _e('Enable debug logging', 'woo-pennylane'); ?>
                            </label>
                            <p class="description">
                                <?php _e('Log sync operations for debugging', 'woo-pennylane'); ?>
                            </p>
                        </td>
                    </tr>
                </table>
                
                <?php submit_button(); ?>
            </form>
        </div>
        <?php
    }
}