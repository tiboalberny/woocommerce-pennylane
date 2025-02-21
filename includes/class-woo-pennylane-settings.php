<?php
if (!defined('ABSPATH')) {
    exit;
}

class WooPennylane_Settings {
    private $settings_page = 'woocommerce_page_woo-pennylane-settings';
    private $option_group = 'woo_pennylane_settings';

    public function __construct() {
        // Hooks pour le menu et les paramètres
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_init', array($this, 'register_settings'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_scripts'));

        // Hook pour le test de connexion API
        add_action('wp_ajax_woo_pennylane_test_connection', array($this, 'test_api_connection'));
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
        // API Settings
        register_setting($this->option_group, 'woo_pennylane_api_key', array(
            'type' => 'string',
            'sanitize_callback' => 'sanitize_text_field',
            'default' => ''
        ));

        register_setting($this->option_group, 'woo_pennylane_journal_code', array(
            'type' => 'string',
            'sanitize_callback' => 'sanitize_text_field',
            'default' => ''
        ));

        register_setting($this->option_group, 'woo_pennylane_account_number', array(
            'type' => 'string',
            'sanitize_callback' => 'sanitize_text_field',
            'default' => ''
        ));

        // Sync Settings
        register_setting($this->option_group, 'woo_pennylane_auto_sync', array(
            'type' => 'string',
            'sanitize_callback' => 'sanitize_text_field',
            'default' => 'yes'
        ));

        register_setting($this->option_group, 'woo_pennylane_sync_status', array(
            'type' => 'array',
            'sanitize_callback' => array($this, 'sanitize_sync_status'),
            'default' => array('completed')
        ));

        // Debug Settings
        register_setting($this->option_group, 'woo_pennylane_debug_mode', array(
            'type' => 'string',
            'sanitize_callback' => 'sanitize_text_field',
            'default' => 'no'
        ));
    }

    public function sanitize_sync_status($input) {
        if (!is_array($input)) {
            return array('completed');
        }
        
        $valid_statuses = array_keys(wc_get_order_statuses());
        return array_intersect($input, $valid_statuses);
    }

    public function enqueue_admin_scripts($hook) {
        if ($this->settings_page !== $hook) {
            return;
        }

        // CSS
        wp_enqueue_style(
            'woo-pennylane-admin',
            WOO_PENNYLANE_PLUGIN_URL . 'assets/css/admin.css',
            array(),
            WOO_PENNYLANE_VERSION
        );

        // JavaScript
        wp_enqueue_script(
            'woo-pennylane-admin',
            WOO_PENNYLANE_PLUGIN_URL . 'assets/js/admin.js',
            array('jquery'),
            WOO_PENNYLANE_VERSION,
            true
        );

        wp_localize_script(
            'woo-pennylane-admin',
            'wooPennylaneParams',
            array(
                'ajaxUrl' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('woo_pennylane_nonce'),
                'errorMessage' => __('Une erreur est survenue', 'woo-pennylane'),
                'requiredFieldsMessage' => __('Tous les champs requis doivent être remplis', 'woo-pennylane'),
                'connectionErrorMessage' => __('Erreur de connexion à l\'API', 'woo-pennylane'),
                'connectionSuccessMessage' => __('Connexion réussie à l\'API', 'woo-pennylane'),
                'hideText' => __('Masquer', 'woo-pennylane'),
                'showText' => __('Afficher', 'woo-pennylane'),
                'savingMessage' => __('Enregistrement...', 'woo-pennylane'),
                'savedMessage' => __('Paramètres enregistrés', 'woo-pennylane')
            )
        );
    }

    public function test_api_connection() {
        check_ajax_referer('woo_pennylane_nonce', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(__('Permission refusée', 'woo-pennylane'));
        }

        $api_key = isset($_POST['api_key']) ? sanitize_text_field($_POST['api_key']) : '';
        
        if (empty($api_key)) {
            $api_key = get_option('woo_pennylane_api_key');
        }

        if (empty($api_key)) {
            wp_send_json_error(__('Clé API manquante', 'woo-pennylane'));
        }

        try {
            // Test de connexion à l'API
            $response = wp_remote_get('https://api.pennylane.tech/api/v1/ping', array(
                'headers' => array(
                    'Authorization' => 'Bearer ' . $api_key,
                    'Accept' => 'application/json'
                ),
                'timeout' => 30
            ));

            if (is_wp_error($response)) {
                throw new Exception($response->get_error_message());
            }

            $response_code = wp_remote_retrieve_response_code($response);
            
            if ($response_code !== 200) {
                throw new Exception(__('Erreur de connexion à l\'API (HTTP ' . $response_code . ')', 'woo-pennylane'));
            }

            wp_send_json_success(__('Connexion à l\'API réussie', 'woo-pennylane'));

        } catch (Exception $e) {
            wp_send_json_error($e->getMessage());
        }
    }

    public function render_settings_page() {
        if (!current_user_can('manage_woocommerce')) {
            wp_die(__('Vous n\'avez pas les permissions suffisantes pour accéder à cette page.', 'woo-pennylane'));
        }

        // Affiche les messages d'erreur/succès
        settings_errors('woo_pennylane_messages');

        // Charge le template des paramètres
        include WOO_PENNYLANE_PLUGIN_DIR . 'templates/admin-settings.php';
    }

    public function get_debug_info() {
        return array(
            'plugin_version' => WOO_PENNYLANE_VERSION,
            'wp_version' => get_bloginfo('version'),
            'wc_version' => WC()->version,
            'php_version' => phpversion(),
            'api_configured' => !empty(get_option('woo_pennylane_api_key')),
            'auto_sync' => get_option('woo_pennylane_auto_sync'),
            'sync_statuses' => get_option('woo_pennylane_sync_status'),
            'debug_mode' => get_option('woo_pennylane_debug_mode')
        );
    }
}