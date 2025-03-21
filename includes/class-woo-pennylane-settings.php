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
        add_action('wp_ajax_woo_pennylane_analyze_customers', array($this, 'analyze_customers'));
        add_action('wp_ajax_woo_pennylane_sync_customers', array($this, 'sync_customers'));
        
        // Nouvelles actions AJAX pour les produits
        add_action('wp_ajax_woo_pennylane_analyze_products', array($this, 'analyze_products'));
        add_action('wp_ajax_woo_pennylane_sync_products', array($this, 'sync_products'));
        add_action('wp_ajax_woo_pennylane_sync_single_product', array($this, 'sync_single_product'));

        error_log('WooPennylane: Constructeur de Settings initialisé');
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
        register_setting('woo_pennylane_settings', 'woo_pennylane_auto_sync');
        register_setting('woo_pennylane_settings', 'woo_pennylane_sync_status');
        register_setting('woo_pennylane_settings', 'woo_pennylane_journal_code');
        register_setting('woo_pennylane_settings', 'woo_pennylane_account_number');
        register_setting('woo_pennylane_settings', 'woo_pennylane_auto_sync_products');
        register_setting('woo_pennylane_settings', 'woo_pennylane_product_ledger_account');
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
        // CSS spécifique à l'onglet historique
        $current_tab = isset($_GET['tab']) ? sanitize_key($_GET['tab']) : 'settings';
        if ($current_tab === 'history') {
            wp_enqueue_style(
                'woo-pennylane-history',
                WOO_PENNYLANE_PLUGIN_URL . 'assets/css/history.css',
                array('woo-pennylane-admin'),
                WOO_PENNYLANE_VERSION
        );
    }

        wp_enqueue_script(
            'woo-pennylane-admin',
            WOO_PENNYLANE_PLUGIN_URL . 'assets/js/admin.js',
            array('jquery'),
            WOO_PENNYLANE_VERSION,
            true
        );

        // Ajoutez ceci pour déboguer
        $debug_info = array(
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('woo_pennylane_nonce'),
            'debug' => true,
            'i18n' => array(
                'syncing' => __('Synchronisation...', 'woo-pennylane'),
                'synced' => __('Synchronisé', 'woo-pennylane'),
                'pennylane_id' => __('ID Pennylane:', 'woo-pennylane'),
                'last_synced' => __('Dernière synchronisation:', 'woo-pennylane'),
                'sync_completed' => __('Synchronisation terminée', 'woo-pennylane'),
                'sync_error' => __('Erreur de synchronisation', 'woo-pennylane')
            )
        );
        
        wp_localize_script(
            'woo-pennylane-admin',
            'wooPennylaneParams',
            $debug_info
        );
        
        // Debug dans la console
        echo '<script>console.log("WooPennylane: Paramètres", ' . json_encode($debug_info) . ');</script>';
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
                    <a href="?page=woo-pennylane-settings&tab=customers" 
                       class="nav-tab <?php echo $this->active_tab === 'customers' ? 'nav-tab-active' : ''; ?>">
                        <?php _e('Clients', 'woo-pennylane'); ?>
                    </a>
                    <a href="?page=woo-pennylane-settings&tab=products" 
                       class="nav-tab <?php echo $this->active_tab === 'products' ? 'nav-tab-active' : ''; ?>">
                        <?php _e('Produits', 'woo-pennylane'); ?>
                    </a>
                    <a href="?page=woo-pennylane-settings&tab=history" 
                       class="nav-tab <?php echo $this->active_tab === 'history' ? 'nav-tab-active' : ''; ?>">
                        <?php _e('Historique', 'woo-pennylane'); ?>
                    </a>
                </nav>
                <?php
                    if ($this->active_tab === 'customers') {
                        include WOO_PENNYLANE_PLUGIN_DIR . 'templates/admin-customers.php';
                    } else if ($this->active_tab === 'sync') {
                        include WOO_PENNYLANE_PLUGIN_DIR . 'templates/admin-sync.php';
                    } else if ($this->active_tab === 'products') {
                        include WOO_PENNYLANE_PLUGIN_DIR . 'templates/admin-products.php';
                    } else if ($this->active_tab === 'history') {
                        include WOO_PENNYLANE_PLUGIN_DIR . 'templates/admin-history.php';
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
    
    /**
     * Analyse les clients WooCommerce
     */
    public function analyze_customers() {
        check_ajax_referer('woo_pennylane_nonce', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(__('Permission refusée', 'woo-pennylane'));
        }

        try {
            // Requête pour compter les clients
            $args = array(
                'role' => 'customer',
                'fields' => 'ID'
            );

            $customers = get_users($args);
            $total = count($customers);

            // Compte des clients déjà synchronisés
            $synced = 0;
            foreach ($customers as $customer_id) {
                if (get_user_meta($customer_id, '_pennylane_synced', true) === 'yes') {
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

    /**
     * Synchronise les clients vers Pennylane
     */
    public function sync_customers() {
        check_ajax_referer('woo_pennylane_nonce', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(__('Permission refusée', 'woo-pennylane'));
        }

        $offset = isset($_POST['offset']) ? intval($_POST['offset']) : 0;
        $batch_size = 10; // Nombre de clients à traiter par lot

        try {
            // Initialise le synchroniseur
            require_once WOO_PENNYLANE_PLUGIN_DIR . 'includes/class-woo-pennylane-customer-sync.php';
            $synchronizer = new WooPennylane_Customer_Sync();

            // Récupération des clients pour ce lot
            $args = array(
                'role' => 'customer',
                'fields' => 'ID',
                'number' => $batch_size,
                'offset' => $offset
            );

            $customers = get_users($args);
            $results = array();
            $processed = 0;

            foreach ($customers as $customer_id) {
                // Vérifie si le client n'est pas déjà synchronisé
                if (get_user_meta($customer_id, '_pennylane_synced', true) !== 'yes') {
                    try {
                        $customer_data = new WC_Customer($customer_id);
                        if ($customer_data && $customer_data->get_billing_email()) {
                            $synchronizer->sync_customer($customer_id);
                            $results[] = array(
                                'status' => 'success',
                                'message' => sprintf(
                                    __('Client "%s" synchronisé avec succès', 'woo-pennylane'), 
                                    $customer_data->get_billing_first_name() . ' ' . $customer_data->get_billing_last_name()
                                )
                            );
                        } else {
                            $results[] = array(
                                'status' => 'error',
                                'message' => sprintf(__('Client #%d ignoré - données incomplètes', 'woo-pennylane'), $customer_id)
                            );
                        }
                    } catch (Exception $e) {
                        $results[] = array(
                            'status' => 'error',
                            'message' => sprintf(__('Erreur pour le client #%d : %s', 'woo-pennylane'), $customer_id, $e->getMessage())
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

    /**
     * Analyse les produits WooCommerce
     */
    public function analyze_products() {
        check_ajax_referer('woo_pennylane_nonce', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(__('Permission refusée', 'woo-pennylane'));
        }

        try {
            // Requête pour compter les produits
            $args = array(
                'status' => 'publish',
                'limit' => -1,
                'return' => 'ids',
            );

            $products = wc_get_products($args);
            $total = count($products);

            // Compte des produits déjà synchronisés
            $synced = 0;
            foreach ($products as $product_id) {
                if (get_post_meta($product_id, '_pennylane_product_synced', true) === 'yes') {
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

    /**
     * Synchronise les produits vers Pennylane
     */
    public function sync_products() {
        check_ajax_referer('woo_pennylane_nonce', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(__('Permission refusée', 'woo-pennylane'));
        }

        $offset = isset($_POST['offset']) ? intval($_POST['offset']) : 0;
        $batch_size = 10; // Nombre de produits à traiter par lot

        try {
            // Initialise le synchroniseur
            require_once WOO_PENNYLANE_PLUGIN_DIR . 'includes/class-woo-pennylane-product-sync.php';
            $synchronizer = new WooPennylane_Product_Sync();

            // Récupération des produits pour ce lot
            $args = array(
                'status' => 'publish',
                'limit' => $batch_size,
                'offset' => $offset,
                'return' => 'ids',
            );

            $products = wc_get_products($args);
            $results = array();
            $processed = 0;

            foreach ($products as $product_id) {
                // Vérifie si le produit n'est pas déjà synchronisé ou exclu
                if (get_post_meta($product_id, '_pennylane_product_synced', true) !== 'yes' && 
                    get_post_meta($product_id, '_pennylane_product_exclude', true) !== 'yes') {
                    try {
                        $product = wc_get_product($product_id);
                        $synchronizer->sync_product($product_id);
                        $results[] = array(
                            'status' => 'success',
                            'message' => sprintf(
                                __('Produit "%s" synchronisé avec succès', 'woo-pennylane'), 
                                $product->get_name()
                            )
                        );
                    } catch (Exception $e) {
                        $results[] = array(
                            'status' => 'error',
                            'message' => sprintf(__('Erreur pour le produit #%d : %s', 'woo-pennylane'), $product_id, $e->getMessage())
                        );
                    }
                } else {
                    $results[] = array(
                        'status' => 'skipped',
                        'message' => sprintf(__('Produit #%d ignoré - déjà synchronisé ou exclu', 'woo-pennylane'), $product_id)
                    );
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

    /**
     * Synchronise un produit individuel via AJAX
     */
    public function sync_single_product() {
        check_ajax_referer('woo_pennylane_nonce', 'nonce');

        if (!current_user_can('edit_products')) {
            wp_send_json_error(__('Permission refusée', 'woo-pennylane'));
        }

        $product_id = isset($_POST['product_id']) ? intval($_POST['product_id']) : 0;

        if (!$product_id) {
            wp_send_json_error(__('ID de produit invalide', 'woo-pennylane'));
        }

        try {
            // Initialise le synchroniseur
            require_once WOO_PENNYLANE_PLUGIN_DIR . 'includes/class-woo-pennylane-product-sync.php';
            $synchronizer = new WooPennylane_Product_Sync();

            // Synchronise le produit
            $synchronizer->sync_product($product_id);

            // Récupère les informations mises à jour
            $product = wc_get_product($product_id);
            $pennylane_id = get_post_meta($product_id, '_pennylane_product_id', true);
            $last_sync = get_post_meta($product_id, '_pennylane_product_last_sync', true);

            wp_send_json_success(array(
                'message' => sprintf(__('Produit "%s" synchronisé avec succès', 'woo-pennylane'), $product->get_name()),
                'pennylane_id' => $pennylane_id,
                'last_sync' => $last_sync,
                'human_time' => human_time_diff(strtotime($last_sync), current_time('timestamp'))
            ));

        } catch (Exception $e) {
            wp_send_json_error(array(
                'message' => $e->getMessage()
            ));
        }
    }
}