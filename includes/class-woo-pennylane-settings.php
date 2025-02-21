<?php
if (!defined('ABSPATH')) {
    exit;
}

class WooPennylane_Settings {
    private $active_tab = 'settings';

    public function __construct() {
        // Hooks pour le menu et les paramètres
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_init', array($this, 'register_settings'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_scripts'));
        
        // Actions AJAX
        add_action('wp_ajax_woo_pennylane_test_connection', array($this, 'test_api_connection'));
        add_action('wp_ajax_woo_pennylane_analyze_orders', array($this, 'analyze_orders'));
        add_action('wp_ajax_woo_pennylane_sync_orders', array($this, 'sync_orders'));
    }

    public function add_admin_menu() {
        add_submenu_page(
            'woocommerce',
            __('Pennylane Integration', 'woo-pennylane'),
            __('Pennylane', 'woo-pennylane'),
            'manage_woocommerce',
            'woo-pennylane-settings',
            array($this, 'render_admin_page')
        );
    }

    public function register_settings() {
        register_setting('woo_pennylane_settings', 'woo_pennylane_api_key');
        register_setting('woo_pennylane_settings', 'woo_pennylane_debug_mode');
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

        wp_localize_script(
            'woo-pennylane-admin',
            'wooPennylaneParams',
            array(
                'ajaxUrl' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('woo_pennylane_nonce')
            )
        );
    }

    public function render_admin_page() {
        $this->active_tab = isset($_GET['tab']) ? sanitize_key($_GET['tab']) : 'settings';
        ?>
        <div class="wrap">
            <h1><?php echo esc_html(get_admin_page_title()); ?></h1>

            <nav class="nav-tab-wrapper">
                <a href="?page=woo-pennylane-settings&tab=settings" 
                   class="nav-tab <?php echo $this->active_tab === 'settings' ? 'nav-tab-active' : ''; ?>">
                    <?php _e('Paramètres', 'woo-pennylane'); ?>
                </a>
                <a href="?page=woo-pennylane-settings&tab=sync" 
                   class="nav-tab <?php echo $this->active_tab === 'sync' ? 'nav-tab-active' : ''; ?>">
                    <?php _e('Synchronisation', 'woo-pennylane'); ?>
                </a>
            </nav>

            <?php
            if ($this->active_tab === 'sync') {
                include WOO_PENNYLANE_PLUGIN_DIR . 'templates/admin-sync.php';
            } else {
                include WOO_PENNYLANE_PLUGIN_DIR . 'templates/admin-settings.php';
            }
            ?>
        </div>
        <?php
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
            error_log('=== Début du test de connexion Pennylane ===');

            if (!function_exists('curl_init')) {
                throw new Exception('CURL n\'est pas installé sur ce serveur');
            }

            $curl = curl_init();
            
            $url = "https://app.pennylane.com/api/external/v2/ledger_accounts";
            $headers = [
                "accept: application/json",
                "authorization: Bearer " . $api_key
            ];

            curl_setopt_array($curl, [
                CURLOPT_URL => $url,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_ENCODING => "",
                CURLOPT_MAXREDIRS => 10,
                CURLOPT_TIMEOUT => 30,
                CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
                CURLOPT_CUSTOMREQUEST => "GET",
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_VERBOSE => true
            ]);

            $response = curl_exec($curl);
            $err = curl_error($curl);
            $http_code = curl_getinfo($curl, CURLINFO_HTTP_CODE);
            
            curl_close($curl);

            if ($err) {
                throw new Exception('Erreur CURL: ' . $err);
            }

            if ($http_code === 200) {
                wp_send_json_success(__('Connexion à l\'API réussie', 'woo-pennylane'));
                return;
            }

            wp_send_json_error(sprintf(
                __('Erreur API (HTTP %s): %s', 'woo-pennylane'),
                $http_code,
                $response
            ));

        } catch (Exception $e) {
            wp_send_json_error($e->getMessage());
        }
    }

    public function analyze_orders() {
        check_ajax_referer('woo_pennylane_nonce', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(__('Permission refusée', 'woo-pennylane'));
        }

        $date_start = isset($_POST['date_start']) ? sanitize_text_field($_POST['date_start']) : '';
        $date_end = isset($_POST['date_end']) ? sanitize_text_field($_POST['date_end']) : '';

        if (empty($date_start) || empty($date_end)) {
            wp_send_json_error(__('Dates manquantes', 'woo-pennylane'));
        }

        try {
            // Requête pour compter les commandes
            $args = array(
                'date_created' => $date_start . '...' . $date_end,
                'status' => array('completed'),
                'limit' => -1,
                'return' => 'ids',
            );

            $orders = wc_get_orders($args);
            $total = count($orders);

            // Compte des commandes déjà synchronisées
            $synced = 0;
            foreach ($orders as $order_id) {
                if (get_post_meta($order_id, '_pennylane_synced', true) === 'yes') {
                    $synced++;
                }
            }

            wp_send_json_success(array(
                'total' => $total,
                'synced' => $synced,
                'to_sync' => $total - $synced
            ));

        } catch (Exception $e) {
            wp_send_json_error($e->getMessage());
        }
    }

    public function sync_orders() {
        check_ajax_referer('woo_pennylane_nonce', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(__('Permission refusée', 'woo-pennylane'));
        }

        $date_start = isset($_POST['date_start']) ? sanitize_text_field($_POST['date_start']) : '';
        $date_end = isset($_POST['date_end']) ? sanitize_text_field($_POST['date_end']) : '';
        $offset = isset($_POST['offset']) ? intval($_POST['offset']) : 0;
        $batch_size = 5; // Nombre de commandes à traiter par lot

        if (empty($date_start) || empty($date_end)) {
            wp_send_json_error(__('Dates manquantes', 'woo-pennylane'));
        }

        try {
            // Récupération des commandes pour ce lot
            $args = array(
                'date_created' => $date_start . '...' . $date_end,
                'status' => array('completed'),
                'limit' => $batch_size,
                'offset' => $offset,
                'return' => 'ids',
            );

            $orders = wc_get_orders($args);
            $results = array();
            $processed = 0;

            foreach ($orders as $order_id) {
                // Vérifie si la commande n'est pas déjà synchronisée
                if (get_post_meta($order_id, '_pennylane_synced', true) !== 'yes') {
                    try {
                        // TODO: Implémenter la vraie synchronisation ici
                        // Pour le moment, on simule juste une synchronisation réussie
                        update_post_meta($order_id, '_pennylane_synced', 'yes');
                        
                        $results[] = array(
                            'status' => 'success',
                            'message' => sprintf(__('Commande #%d synchronisée avec succès', 'woo-pennylane'), $order_id)
                        );
                    } catch (Exception $e) {
                        $results[] = array(
                            'status' => 'error',
                            'message' => sprintf(__('Erreur pour la commande #%d : %s', 'woo-pennylane'), $order_id, $e->getMessage())
                        );
                    }
                }
                $processed++;
            }

            wp_send_json_success(array(
                'processed' => $processed,
                'results' => $results
            ));

        } catch (Exception $e) {
            wp_send_json_error($e->getMessage());
        }
    }
}