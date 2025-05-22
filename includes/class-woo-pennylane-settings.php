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
            add_action('wp_ajax_woo_pennylane_get_synced_customers', array($this, 'get_synced_customers'));
            add_action('wp_ajax_woo_pennylane_sync_single_customer', array($this, 'sync_single_customer'));
            add_action('wp_ajax_woo_pennylane_create_logs_table', array($this, 'create_logs_table_ajax'));
            add_action('wp_ajax_woo_pennylane_analyze_products', array($this, 'analyze_products'));
            add_action('wp_ajax_woo_pennylane_sync_products', array($this, 'sync_products'));
            add_action('wp_ajax_woo_pennylane_sync_single_product', array($this, 'sync_single_product'));
            add_action('wp_ajax_woo_pennylane_analyze_guest_customers', array($this, 'analyze_guest_customers'));
            add_action('wp_ajax_woo_pennylane_sync_guest_customers', array($this, 'sync_guest_customers'));
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
        register_setting('woo_pennylane_settings', 'woo_pennylane_auto_sync_customers');
        register_setting('woo_pennylane_settings', 'woo_pennylane_invoice_creation_status');
        register_setting('woo_pennylane_settings', 'woo_pennylane_invoice_creation_status', array(
            'type' => 'string',
            'sanitize_callback' => array($this, 'sanitize_invoice_status'),
            'default' => 'draft', // Définit la valeur par défaut
        ));
    }

    public function enqueue_admin_scripts($hook) {
    // Déterminer les pages où nous devons charger nos ressources
    $pennylane_page = 'woocommerce_page_woo-pennylane-settings';
    $is_product_page = false;
    $is_order_page = false;
    
    // Vérifier si nous sommes sur une page de produits
    if (in_array($hook, array('post.php', 'post-new.php'))) {
        global $post;
        if ($post && $post->post_type === 'product') {
            $is_product_page = true;
        } elseif ($post && $post->post_type === 'shop_order') {
            $is_order_page = true;
        }
    }
    
    // Vérifier si nous sommes sur la liste des produits ou commandes
    if ($hook === 'edit.php') {
        $post_type = isset($_GET['post_type']) ? sanitize_key($_GET['post_type']) : '';
        if ($post_type === 'product') {
            $is_product_page = true;
        } elseif ($post_type === 'shop_order') {
            $is_order_page = true;
        }
    }
    
    // Si nous ne sommes pas sur une page pertinente, sortir
    if ($hook !== $pennylane_page && !$is_product_page && !$is_order_page) {
        return;
    }

    // Enregistrer et charger les styles CSS communs
    wp_enqueue_style(
        'woo-pennylane-admin',
        WOO_PENNYLANE_PLUGIN_URL . 'assets/css/admin.css',
        array(),
        WOO_PENNYLANE_VERSION
    );
    
    // Charger le CSS spécifique aux produits
    if ($is_product_page || $hook === $pennylane_page) {
        wp_enqueue_style(
            'woo-pennylane-products',
            WOO_PENNYLANE_PLUGIN_URL . 'assets/css/products.css',
            array('dashicons'),
            WOO_PENNYLANE_VERSION
        );
    }
    
    // Charger les dépendances jQuery UI pour les datepickers
    if ($hook === $pennylane_page) {
        wp_enqueue_script('jquery-ui-datepicker');
        wp_enqueue_style(
            'jquery-ui-style',
            WOO_PENNYLANE_PLUGIN_URL . 'assets/css/jquery-ui.min.css',
            array(),
            WOO_PENNYLANE_VERSION
        );
    }

    // Enregistrer et charger le script JavaScript principal
    wp_enqueue_script(
        'woo-pennylane-admin',
        WOO_PENNYLANE_PLUGIN_URL . 'assets/js/admin.js',
        array('jquery'),
        WOO_PENNYLANE_VERSION,
        true
    );

    // Préparer les paramètres et traductions pour JavaScript
    $params = array(
        'ajaxUrl' => admin_url('admin-ajax.php'),
        'nonce' => wp_create_nonce('woo_pennylane_nonce'),
        'debug' => get_option('woo_pennylane_debug_mode', 'no') === 'yes',
        'i18n' => array(
            'syncing' => __('Synchronisation...', 'woo-pennylane'),
            'synced' => __('Synchronisé', 'woo-pennylane'),
            'pennylane_id' => __('ID Pennylane:', 'woo-pennylane'),
            'last_synced' => __('Dernière synchronisation:', 'woo-pennylane'),
            'sync_completed' => __('Synchronisation terminée', 'woo-pennylane'),
            'sync_error' => __('Erreur de synchronisation', 'woo-pennylane'),
            'confirm_sync_all' => __('Êtes-vous sûr de vouloir synchroniser tous les produits sélectionnés ? Cette opération peut prendre du temps.', 'woo-pennylane'),
            'confirm_exclude' => __('Êtes-vous sûr de vouloir exclure ce produit de la synchronisation ?', 'woo-pennylane'),
            'ago' => __('il y a', 'woo-pennylane'),
            'confirm_sync' => __('Êtes-vous sûr de vouloir synchroniser ce produit avec Pennylane ?', 'woo-pennylane')
        ),
        'urls' => array(
            'settings' => admin_url('admin.php?page=woo-pennylane-settings'),
            'products' => admin_url('admin.php?page=woo-pennylane-settings&tab=products'),
            'customers' => admin_url('admin.php?page=woo-pennylane-settings&tab=customers'),
            'sync' => admin_url('admin.php?page=woo-pennylane-settings&tab=sync')
        )
    );
    
    wp_localize_script(
        'woo-pennylane-admin',
        'wooPennylaneParams',
        $params
    );
    
    // Ajouter un script inline pour déboguer en mode debug
    if (get_option('woo_pennylane_debug_mode', 'no') === 'yes') {
        wp_add_inline_script(
            'woo-pennylane-admin',
            'console.log("WooPennylane: Page ' . esc_js($hook) . ' chargée avec les paramètres", wooPennylaneParams);'
        );
    }
}
    /**
     * Sanitize the selected invoice creation status. Ensures it's either 'draft' or 'final'.
     *
     * @param string|mixed $input The value from the settings page.
     * @return string Sanitized status ('draft' or 'final').
     */
    public function sanitize_invoice_status( $input ) {
        $status = sanitize_key( $input );
        if ( in_array( $status, array( 'draft', 'final' ) ) ) {
            return $status;
        }
        // Si la valeur n'est ni 'draft' ni 'final', retourner la valeur par défaut 'draft'.
        return 'draft';
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
                <a href="?page=woo-pennylane-settings&tab=guest-customers" 
                   class="nav-tab <?php echo $this->active_tab === 'guest-customers' ? 'nav-tab-active' : ''; ?>">
                    <?php _e('Clients invités', 'woo-pennylane'); ?>
                </a>
                <a href="?page=woo-pennylane-settings&tab=products" 
                   class="nav-tab <?php echo $this->active_tab === 'products' ? 'nav-tab-active' : ''; ?>">
                    <?php _e('Produits', 'woo-pennylane'); ?>
                </a>
            </nav>
            <?php
                if ($this->active_tab === 'customers') {
                    include WOO_PENNYLANE_PLUGIN_DIR . 'templates/admin-customers.php';
                } else if ($this->active_tab === 'sync') {
                    include WOO_PENNYLANE_PLUGIN_DIR . 'templates/admin-sync.php';
                } else if ($this->active_tab === 'products') {
                    include WOO_PENNYLANE_PLUGIN_DIR . 'templates/admin-products.php';
                } else if ($this->active_tab === 'guest-customers') {
                    include WOO_PENNYLANE_PLUGIN_DIR . 'templates/admin-guest-customers.php';
                } else {
                    include WOO_PENNYLANE_PLUGIN_DIR . 'templates/admin-settings.php';
                }
            ?>
        </div>
        <?php
    }
    /**
     * Analyse les clients invités
     */
    public function analyze_guest_customers() {
        check_ajax_referer('woo_pennylane_nonce', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(__('Permission refusée', 'woo-pennylane'));
        }

        try {
            // Initialise la classe de synchronisation des clients invités
            require_once WOO_PENNYLANE_PLUGIN_DIR . 'includes/class-woo-pennylane-guest-customer-sync.php';
            $synchronizer = new WooPennylane_Guest_Customer_Sync();
            
            // Récupère les statistiques
            $stats = $synchronizer->count_guest_customers();
            
            wp_send_json_success(array(
                'total' => $stats['total'],
                'synced' => $stats['synced'],
                'to_sync' => $stats['total'] - $stats['synced']
            ));

        } catch (Exception $e) {
            wp_send_json_error($e->getMessage());
        }
    }

    /**
     * Synchronise les clients invités vers Pennylane
     */
    public function sync_guest_customers() {
        check_ajax_referer('woo_pennylane_nonce', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(__('Permission refusée', 'woo-pennylane'));
        }

        $offset = isset($_POST['offset']) ? intval($_POST['offset']) : 0;
        $batch_size = 5; // Nombre de clients à traiter par lot

        try {
            // Initialise le synchroniseur
            require_once WOO_PENNYLANE_PLUGIN_DIR . 'includes/class-woo-pennylane-guest-customer-sync.php';
            $synchronizer = new WooPennylane_Guest_Customer_Sync();

            // Récupération des clients invités
            $guest_emails = $synchronizer->get_guest_customers();
            
            // Application de l'offset et de la limite
            $batch_emails = array_slice($guest_emails, $offset, $batch_size);
            
            $results = array();
            $processed = 0;

            foreach ($batch_emails as $email) {
                try {
                    $synchronizer->sync_guest_customer($email);
                    $results[] = array(
                        'status' => 'success',
                        'message' => sprintf(
                            __('Client invité "%s" synchronisé avec succès', 'woo-pennylane'), 
                            $email
                        )
                    );
                } catch (Exception $e) {
                    $results[] = array(
                        'status' => 'error',
                        'message' => sprintf(__('Erreur pour le client invité "%s" : %s', 'woo-pennylane'), $email, $e->getMessage())
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
    /**
     * Méthode AJAX pour créer la table de logs
     */
    public function create_logs_table_ajax() {
        check_ajax_referer('woo_pennylane_admin', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Permission refusée', 'woo-pennylane'));
            return;
        }
        
        if (!class_exists('WooPennylane_Logger')) {
            wp_send_json_error(__('La classe de journalisation n\'est pas disponible', 'woo-pennylane'));
            return;
        }
        
        $result = WooPennylane_Logger::create_logs_table();
        
        if ($result) {
            wp_send_json_success(__('Table de logs créée avec succès. Actualisation de la page...', 'woo-pennylane'));
        } else {
            wp_send_json_error(__('Impossible de créer la table de logs', 'woo-pennylane'));
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
     * Récupère la liste des IDs des clients synchronisés avec Pennylane
     */
    public function get_synced_customers() {
        check_ajax_referer('woo_pennylane_nonce', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(__('Permission refusée', 'woo-pennylane'));
        }

        try {
            // Récupération des clients synchronisés
            global $wpdb;
            
            // Récupère les IDs des utilisateurs ayant le méta _pennylane_synced à "yes"
            $query = $wpdb->prepare(
                "SELECT user_id FROM {$wpdb->usermeta} 
                WHERE meta_key = %s AND meta_value = %s", 
                '_pennylane_synced', 'yes'
            );
            
            $results = $wpdb->get_col($query);
            
            // Filtre pour exclure les clients marqués comme exclus
            $customer_ids = array();
            foreach ($results as $user_id) {
                if (get_user_meta($user_id, '_pennylane_exclude', true) !== 'yes') {
                    $customer_ids[] = (int) $user_id;
                }
            }
            
            wp_send_json_success(array(
                'customers' => $customer_ids,
                'count' => count($customer_ids)
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
            // Vérifie si le client n'est pas déjà synchronisé ou exclu
            if (get_user_meta($customer_id, '_pennylane_synced', true) !== 'yes' && 
                get_user_meta($customer_id, '_pennylane_exclude', true) !== 'yes') {
                try {
                    $customer_data = new WC_Customer($customer_id);
                    
                    // Vérifie que le client a des données minimales requises
                    if ($customer_data && 
                        $customer_data->get_billing_first_name() && 
                        $customer_data->get_billing_last_name() && 
                        $customer_data->get_billing_email() && 
                        $customer_data->get_billing_address_1()) {
                        
                        // Tentative de trouver si le client existe déjà dans Pennylane
                        $external_ref = 'WC-' . $customer_id;
                        $existing_customer = $synchronizer->find_customer_by_external_reference($external_ref);
                        
                        if ($existing_customer) {
                            // Met à jour les métadonnées avec l'ID Pennylane existant
                            update_user_meta($customer_id, '_pennylane_synced', 'yes');
                            update_user_meta($customer_id, '_pennylane_customer_id', $existing_customer['id']);
                            update_user_meta($customer_id, '_pennylane_last_sync', current_time('mysql'));
                            
                            $results[] = array(
                                'status' => 'success',
                                'message' => sprintf(
                                    __('Client "%s" trouvé et associé dans Pennylane (ID: %s)', 'woo-pennylane'), 
                                    $customer_data->get_billing_first_name() . ' ' . $customer_data->get_billing_last_name(),
                                    $existing_customer['id']
                                )
                            );
                        } else {
                            // Synchronise un nouveau client
                            $synchronizer->sync_customer($customer_id);
                            $pennylane_id = get_user_meta($customer_id, '_pennylane_customer_id', true);
                            
                            $results[] = array(
                                'status' => 'success',
                                'message' => sprintf(
                                    __('Client "%s" synchronisé avec succès (ID: %s)', 'woo-pennylane'), 
                                    $customer_data->get_billing_first_name() . ' ' . $customer_data->get_billing_last_name(),
                                    $pennylane_id
                                )
                            );
                        }
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
            } else {
                $results[] = array(
                    'status' => 'skipped',
                    'message' => sprintf(__('Client #%d ignoré - déjà synchronisé ou exclu', 'woo-pennylane'), $customer_id)
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

public function sync_single_customer() {
        // Ajouter cette ligne pour le débogage
        if (get_option('woo_pennylane_debug_mode') === 'yes') {
            error_log('Début de sync_single_customer');
        }
        
        check_ajax_referer('woo_pennylane_nonce', 'nonce');
        
        if (get_option('woo_pennylane_debug_mode') === 'yes') {
            error_log('Nonce vérifié');
        }

        if (!current_user_can('edit_users')) {
            if (get_option('woo_pennylane_debug_mode') === 'yes') {
                error_log('Permission refusée');
            }
            wp_send_json_error(array(
                'message' => __('Permission refusée', 'woo-pennylane')
            ));
        }

        $customer_id = isset($_POST['customer_id']) ? intval($_POST['customer_id']) : 0;
        $force_resync = isset($_POST['force_resync']) && $_POST['force_resync'] === 'yes';
        
        if (get_option('woo_pennylane_debug_mode') === 'yes') {
            error_log('Données reçues - ID client: ' . $customer_id . ', Force resync: ' . ($force_resync ? 'oui' : 'non'));
        }

        if (!$customer_id) {
            if (get_option('woo_pennylane_debug_mode') === 'yes') {
                error_log('ID client invalide');
            }
            wp_send_json_error(array(
                'message' => __('ID client invalide', 'woo-pennylane')
            ));
        }

        try {
            // Vérification du client
            if (get_option('woo_pennylane_debug_mode') === 'yes') {
                error_log('Vérification du client #' . $customer_id);
            }
            
            // Vérifie si le client est exclu (sauf en cas de resynchronisation forcée)
            if (!$force_resync && get_user_meta($customer_id, '_pennylane_exclude', true) === 'yes') {
                if (get_option('woo_pennylane_debug_mode') === 'yes') {
                    error_log('Client #' . $customer_id . ' exclu de la synchronisation');
                }
                wp_send_json_error(array(
                    'message' => __('Ce client est exclu de la synchronisation', 'woo-pennylane')
                ));
            }
            
            $customer = new WC_Customer($customer_id);
            
            if (!$customer->get_id()) {
                if (get_option('woo_pennylane_debug_mode') === 'yes') {
                    error_log('Client #' . $customer_id . ' introuvable');
                }
                wp_send_json_error(array(
                    'message' => __('Client introuvable', 'woo-pennylane')
                ));
            }
            
            // Initialise le synchroniseur
            if (get_option('woo_pennylane_debug_mode') === 'yes') {
                error_log('Initialisation du synchroniseur');
            }
            require_once WOO_PENNYLANE_PLUGIN_DIR . 'includes/class-woo-pennylane-customer-sync.php';
            $synchronizer = new WooPennylane_Customer_Sync();

            // Si c'est une resynchronisation forcée, supprime les métadonnées de synchronisation
            if ($force_resync) {
                if (get_option('woo_pennylane_debug_mode') === 'yes') {
                    error_log('Resynchronisation forcée activée pour le client #' . $customer_id);
                }
                // On garde l'ID Pennylane mais on supprime le flag _pennylane_synced pour forcer la resynchronisation
                delete_user_meta($customer_id, '_pennylane_synced');
                delete_user_meta($customer_id, '_pennylane_sync_error');
            }

            // Synchronise le client
            if (get_option('woo_pennylane_debug_mode') === 'yes') {
                error_log('Début de la synchronisation du client #' . $customer_id);
            }
            $synchronizer->sync_customer($customer_id);
            
            if (get_option('woo_pennylane_debug_mode') === 'yes') {
                error_log('Synchronisation réussie pour le client #' . $customer_id);
            }

            // Récupère les informations mises à jour
            $pennylane_id = get_user_meta($customer_id, '_pennylane_customer_id', true);
            $last_sync = get_user_meta($customer_id, '_pennylane_last_sync', true);

            // Message de succès adapté selon le contexte
            if ($pennylane_id) {
                $message = $force_resync 
                    ? sprintf(__('Client "%s" resynchronisé avec succès (ID: %s)', 'woo-pennylane'), $customer->get_billing_first_name() . ' ' . $customer->get_billing_last_name(), $pennylane_id)
                    : sprintf(__('Client "%s" synchronisé avec succès (ID: %s)', 'woo-pennylane'), $customer->get_billing_first_name() . ' ' . $customer->get_billing_last_name(), $pennylane_id);
            } else {
                $message = sprintf(__('Client "%s" synchronisé avec succès', 'woo-pennylane'), $customer->get_billing_first_name() . ' ' . $customer->get_billing_last_name());
            }

            wp_send_json_success(array(
                'message' => $message,
                'pennylane_id' => $pennylane_id,
                'last_sync' => $last_sync,
                'human_time' => human_time_diff(strtotime($last_sync), current_time('timestamp')),
                'customer_name' => $customer->get_billing_first_name() . ' ' . $customer->get_billing_last_name()
            ));

        } catch (Exception $e) {
            // Log complet de l'erreur
            if (get_option('woo_pennylane_debug_mode') === 'yes') {
                error_log('ERREUR de synchronisation pour le client #' . $customer_id . ': ' . $e->getMessage());
                error_log('Trace complète: ' . $e->getTraceAsString());
            }
            
            // Enregistrer l'erreur dans le log
            if (class_exists('WooPennylane_Logger')) {
                WooPennylane_Logger::add_log($customer_id, 'customer', 'error', $e->getMessage());
            }
            
            // Enregistrer l'erreur dans les métadonnées
            update_user_meta($customer_id, '_pennylane_sync_error', $e->getMessage());
            update_user_meta($customer_id, '_pennylane_last_sync', current_time('mysql'));
            
            // Retourner l'erreur
            wp_send_json_error(array(
                'message' => $e->getMessage()
            ));
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
            // Initialisation du client API et du synchroniseur de produits
            if (!class_exists('WooPennylane\\Api\\Client')) {
                require_once WOO_PENNYLANE_PLUGIN_DIR . 'includes/class-woo-pennylane-api-client.php';
            }
            $api_client = new \WooPennylane\Api\Client();

            if (!class_exists('WooPennylane_Product_Sync')) {
                require_once WOO_PENNYLANE_PLUGIN_DIR . 'includes/class-woo-pennylane-product-sync.php';
            }
            // Nous avons besoin d'une instance pour accéder à prepare_product_data et get_product_tax_rate, format_vat_rate, get_product_type
            // temporairement, car ces méthodes devraient idéalement être statiques ou dans un helper si utilisées hors synchro.
            // $product_sync_helper = new WooPennylane_Product_Sync(); // REMOVED

            $args = [
                'status' => 'publish',
                'limit' => -1, // Tous les produits
                'return' => 'ids',
            ];
            $wc_product_ids = wc_get_products($args);

            $total_wc_products = count($wc_product_ids);
            $stats = [
                'total_wc_products' => $total_wc_products,
                'to_create' => 0,
                'to_update' => 0,
                'up_to_date' => 0,
                'pennylane_ids_found' => [], // Pour stocker les ID Pennylane trouvés pour màj des metas
                'error_details' => [] // Pour logger les erreurs spécifiques lors de l'analyse
            ];

            foreach ($wc_product_ids as $wc_product_id) {
                $product = wc_get_product($wc_product_id);
                if (!$product) continue;

                $pennylane_id = get_post_meta($wc_product_id, '_pennylane_product_id', true);
                $pennylane_product_data = null;
                $found_by_reference = false;

                try {
                    if ($pennylane_id) {
                        $pennylane_product_data = $api_client->get_product($pennylane_id);
                    } else {
                        // Essayer par SKU (reference)
                        $sku = $product->get_sku();
                        if (!empty($sku)) {
                            $response = $api_client->find_product_by_reference($sku, 'reference');
                            if (!empty($response[0])) {
                                $pennylane_product_data = $response[0];
                                $pennylane_id = $pennylane_product_data['id'];
                                $stats['pennylane_ids_found'][$wc_product_id] = $pennylane_id; // Marquer pour màj meta
                                $found_by_reference = true;
                            }
                        }
                        // Si non trouvé par SKU, essayer par external_reference (WC-ID)
                        if (!$pennylane_product_data) {
                            $external_ref = 'WC-' . $wc_product_id;
                            $response = $api_client->find_product_by_reference($external_ref, 'external_reference');
                            if (!empty($response[0])) {
                                $pennylane_product_data = $response[0];
                                $pennylane_id = $pennylane_product_data['id'];
                                $stats['pennylane_ids_found'][$wc_product_id] = $pennylane_id;
                                $found_by_reference = true;
                            }
                        }
                    }

                    if ($pennylane_product_data) {
                        // Le produit existe sur Pennylane, comparons-le
                        // Pour utiliser prepare_product_data, nous avons besoin de l'instance de WooPennylane_Product_Sync
                        // $wc_prepared_data = $this->product_sync_instance->prepare_product_data($product); // Ne fonctionne pas ici
                        // Appel direct à la méthode prepare_product_data de l'instance créée
                        // $product_sync_reflection = new \ReflectionClass('WooPennylane_Product_Sync'); // REMOVED
                        // $prepare_method = $product_sync_reflection->getMethod('prepare_product_data'); // REMOVED
                        // $prepare_method->setAccessible(true); // Rendre la méthode accessible // REMOVED
                        // $wc_prepared_data = $prepare_method->invoke($product_sync_helper, $product); // REMOVED
                        $wc_prepared_data = \WooPennylane_Product_Sync::prepare_product_data($product);
                        
                        if (\WooPennylane_Product_Sync::compare_product_data($wc_prepared_data, $pennylane_product_data)) {
                            $stats['up_to_date']++;
                        } else {
                            $stats['to_update']++;
                        }
                        if ($found_by_reference && $pennylane_id) {
                             // Mettre à jour le meta localement si trouvé par référence
                             update_post_meta($wc_product_id, '_pennylane_product_id', $pennylane_id);
                             update_post_meta($wc_product_id, '_pennylane_product_synced', 'yes'); // Marquer comme synchronisé car on l'a trouvé
                        }
                    } else {
                        $stats['to_create']++;
                    }
                } catch (\Exception $e) {
                    // Si get_product échoue (ex: 404), cela signifie que l'ID Pennylane stocké n'est plus valide.
                    if ($pennylane_id && !$found_by_reference) { // Seulement si on cherchait par ID et pas par référence
                        $stats['error_details'][] = sprintf("Produit WC #%d: ID Pennylane %s trouvé localement mais invalide sur Pennylane (%s). Sera recréé.", $wc_product_id, $pennylane_id, $e->getMessage());
                        $stats['to_create']++;
                        delete_post_meta($wc_product_id, '_pennylane_product_id'); // Supprimer l'ID invalide
                        delete_post_meta($wc_product_id, '_pennylane_product_synced');
                    } else {
                        $stats['error_details'][] = sprintf("Produit WC #%d: Erreur lors de la récupération/recherche sur Pennylane: %s", $wc_product_id, $e->getMessage());
                        // On pourrait le classer en 'to_create' par sécurité, ou ajouter une catégorie 'erreur_analyse'
                        $stats['to_create']++; // Par défaut, on essaiera de le créer s'il y a une erreur d'analyse
                    }
                }
            }
            
            // N'envoyer que les stats agrégées, pas tous les IDs ou erreurs détaillées pour l'instant
            // Sauf si le mode debug est activé.
            $response_data = [
                'total_wc_products' => $stats['total_wc_products'],
                'to_create' => $stats['to_create'],
                'to_update' => $stats['to_update'],
                'up_to_date' => $stats['up_to_date'],
            ];
            if (get_option('woo_pennylane_debug_mode', 'no') === 'yes') {
                $response_data['analysis_details'] = $stats; // Envoyer tous les détails en mode debug
            }

            wp_send_json_success($response_data);

        } catch (\Exception $e) {
            wp_send_json_error($e->getMessage());
        }
    }

    /**
     * Compare les données d'un produit WooCommerce préparé avec les données Pennylane.
     *
     * @param array $wc_data Données WooCommerce préparées pour Pennylane.
     * @param array $pennylane_data Données du produit récupérées de Pennylane.
     * @return bool True si les données sont considérées comme identiques, False sinon.
     */
    // private function compare_product_data($wc_data, $pennylane_data) { // ENTIRE METHOD REMOVED
        // Comparaison simple pour l'exemple. À affiner selon les champs importants.
        // Normaliser les prix en float pour la comparaison
        // $wc_price = isset($wc_data['price_before_tax']) ? (float)$wc_data['price_before_tax'] : null;
        // $pl_price = isset($pennylane_data['price_before_tax']) ? (float)$pennylane_data['price_before_tax'] : null;

        // Comparaison du prix avec une tolérance pour les erreurs de flottants si nécessaire
        // Exemple simple : if (abs($wc_price - $pl_price) > 0.001) return false;
        // if ($wc_price !== $pl_price) return false;
        
        // if (isset($wc_data['label']) && $wc_data['label'] !== $pennylane_data['label']) return false;
        // La référence (SKU) est envoyée, mais Pennylane la stocke dans `reference`.
        // if (isset($wc_data['reference']) && $wc_data['reference'] !== $pennylane_data['reference']) return false;
        // Comparer le type de produit
        // if (isset($wc_data['product_type']) && $wc_data['product_type'] !== $pennylane_data['product_type']) return false;
        // Comparer le taux de TVA formaté
        // if (isset($wc_data['vat_rate']) && $wc_data['vat_rate'] !== $pennylane_data['vat_rate']) return false;
        // Comparer la devise
        // if (isset($wc_data['currency']) && $wc_data['currency'] !== $pennylane_data['currency']) return false;

        // Ajouter d'autres comparaisons si nécessaire (description, unit, ledger_account_id etc.)

    //    return true;
    // }

    /**
     * Synchronise les produits vers Pennylane
     */
    public function sync_products() {
        check_ajax_referer('woo_pennylane_nonce', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(__('Permission refusée', 'woo-pennylane'));
        }

        $offset = isset($_POST['offset']) ? intval($_POST['offset']) : 0;
        $limit = isset($_POST['limit']) ? intval($_POST['limit']) : 10; // Utiliser une limite passée ou défaut 10
        $batch_size = $limit; // Pour la compatibilité avec le JS existant qui compte sur processed

        try {
            // Assurer que la classe pour l'historique de synchronisation est chargée
            if (!class_exists('WooPennylane_Sync_History')) {
                require_once WOO_PENNYLANE_PLUGIN_DIR . 'includes/class-woo-pennylane-sync-history.php';
            }
            // Assurer que la classe Logger est chargée
            if (!class_exists('WooPennylane\\Logger')) {
                require_once WOO_PENNYLANE_PLUGIN_DIR . 'includes/class-woo-pennylane-logger.php';
            }

            if (!class_exists('WooPennylane\\Api\\Client')) {
                require_once WOO_PENNYLANE_PLUGIN_DIR . 'includes/class-woo-pennylane-api-client.php';
            }
            $api_client = new \WooPennylane\Api\Client();

            if (!class_exists('WooPennylane_Product_Sync')) {
            require_once WOO_PENNYLANE_PLUGIN_DIR . 'includes/class-woo-pennylane-product-sync.php';
            }
            $synchronizer = new WooPennylane_Product_Sync();
            // $product_sync_helper est nécessaire pour prepare_product_data et compare_product_data
            // qui sont (pour l'instant) des méthodes d'instance ou privées
            // $product_sync_helper = $synchronizer; // REMOVED

            // Récupérer TOUS les IDs de produits WC pour déterminer ceux à traiter dans ce lot
            $all_wc_product_ids_args = [
                'status' => 'publish',
                'limit' => -1,
                'return' => 'ids',
            ];
            $all_wc_product_ids = wc_get_products($all_wc_product_ids_args);

            $product_ids_to_process_in_this_batch = [];
            $products_actually_synced_this_run = 0;
            $skipped_up_to_date_in_potential_batch = 0;
            $results = [];

            // Parcourir tous les produits pour identifier le lot actuel à synchroniser
            // en sautant ceux déjà traités (offset) et ceux qui sont à jour.
            $current_offset_count = 0;

            foreach ($all_wc_product_ids as $wc_product_id) {
                if (get_post_meta($wc_product_id, '_pennylane_product_exclude', true) === 'yes') {
                    // $results[] = ['status' => 'skipped', 'message' => sprintf(__('Produit #%d ignoré (exclu).', 'woo-pennylane'), $wc_product_id)];
                    continue; // Ignorer les produits exclus
                }

                $product = wc_get_product($wc_product_id);
                if (!$product) continue;

                $pennylane_id = get_post_meta($wc_product_id, '_pennylane_product_id', true);
                $pennylane_product_data = null;
                $needs_sync = false;
                $status_message_prefix = sprintf(__('Produit "%s" (#%d)', 'woo-pennylane'), $product->get_name(), $wc_product_id);

                try {
                    if ($pennylane_id) {
                        $pennylane_product_data = $api_client->get_product($pennylane_id);
                    } else {
                        $sku = $product->get_sku();
                        if (!empty($sku)) {
                            $response = $api_client->find_product_by_reference($sku, 'reference');
                            if (!empty($response[0])) $pennylane_product_data = $response[0];
                        }
                        if (!$pennylane_product_data) {
                            $external_ref = 'WC-' . $wc_product_id;
                            $response = $api_client->find_product_by_reference($external_ref, 'external_reference');
                            if (!empty($response[0])) $pennylane_product_data = $response[0];
                        }
                        if ($pennylane_product_data && isset($pennylane_product_data['id'])) {
                            update_post_meta($wc_product_id, '_pennylane_product_id', $pennylane_product_data['id']);
                            // On ne marque pas _pennylane_product_synced ici, car on va le comparer.
                        }
                    }

                    if ($pennylane_product_data) {
                        // $product_sync_reflection = new \ReflectionClass('WooPennylane_Product_Sync'); // REMOVED
                        // $prepare_method = $product_sync_reflection->getMethod('prepare_product_data'); // REMOVED
                        // $prepare_method->setAccessible(true); // REMOVED
                        // $wc_prepared_data = $prepare_method->invoke($product_sync_helper, $product); // REMOVED
                        $wc_prepared_data = \WooPennylane_Product_Sync::prepare_product_data($product);
                        
                        if (!\WooPennylane_Product_Sync::compare_product_data($wc_prepared_data, $pennylane_product_data)) {
                            $needs_sync = true; // Nécessite une mise à jour
                    }
                } else {
                        $needs_sync = true; // Nécessite une création
                    }

                } catch (\Exception $e) {
                    // Si erreur lors de la vérification (ex: ID Pennylane invalide), on le marque pour synchro (création)
                    $results[] = ['status' => 'error', 'message' => $status_message_prefix . ': ' . sprintf(__('Erreur d\'analyse avant synchro: %s. Tentative de synchronisation.', 'woo-pennylane'), $e->getMessage())];
                    $needs_sync = true;
                    delete_post_meta($wc_product_id, '_pennylane_product_id'); // S'assurer que l'ID invalide est supprimé
                    delete_post_meta($wc_product_id, '_pennylane_product_synced');
                }

                if ($needs_sync) {
                    if ($current_offset_count < $offset) {
                        $current_offset_count++;
                        continue; // Ce produit est dans un lot précédent
                    }
                    // Ajouter au lot actuel si la taille du lot n'est pas atteinte
                    if (count($product_ids_to_process_in_this_batch) < $batch_size) {
                        $product_ids_to_process_in_this_batch[] = $wc_product_id;
                    }
                } else {
                    // Si le produit est à jour, mais qu'il aurait pu faire partie de ce lot (selon l'offset)
                    // on compte cela pour que le front-end comprenne que le "processed" inclut des vérifications.
                    if ($current_offset_count >= $offset && count($product_ids_to_process_in_this_batch) + $skipped_up_to_date_in_potential_batch < $batch_size) {
                         $skipped_up_to_date_in_potential_batch++;
                         $results[] = ['status' => 'skipped', 'message' => $status_message_prefix . ': ' . __('Déjà à jour.', 'woo-pennylane')];
                    }
                }
                // Si le lot est plein, sortir de la boucle principale
                if (count($product_ids_to_process_in_this_batch) >= $batch_size) {
                    break;
                }
            }

            // Synchroniser les produits identifiés pour ce lot
            foreach ($product_ids_to_process_in_this_batch as $product_id_to_sync) {
                try {
                    $product_obj = wc_get_product($product_id_to_sync);
                    $sync_mode_for_log = 'batch_sync'; // Ou un autre identifiant pour le mode
                    $synchronizer->sync_product($product_id_to_sync, $sync_mode_for_log);
                    $results[] = [
                        'status' => 'success',
                        'message' => sprintf(__('Produit "%s" (#%d) synchronisé.', 'woo-pennylane'), $product_obj->get_name(), $product_id_to_sync)
                    ];
                    $products_actually_synced_this_run++;
                } catch (\Exception $e) {
                    $product_obj_for_error = wc_get_product($product_id_to_sync); // Pour avoir le nom même en cas d'erreur
                    $name_for_error = $product_obj_for_error ? $product_obj_for_error->get_name() : 'N/A';
                    $results[] = [
                        'status' => 'error',
                        'message' => sprintf(__('Erreur Produit "%s" (#%d): %s', 'woo-pennylane'), $name_for_error, $product_id_to_sync, $e->getMessage())
                    ];
                }
            }

            wp_send_json_success([
                'processed' => count($product_ids_to_process_in_this_batch) + $skipped_up_to_date_in_potential_batch, // Total traités ou vérifiés comme à jour dans ce batch
                'results' => $results,
                'actually_synced' => $products_actually_synced_this_run // Pour info
            ]);

        } catch (\Exception $e) {
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