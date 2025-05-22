<?php
/**
 * Plugin Name: WooCommerce Pennylane Integration
 * Plugin URI: https://lespetitschaudrons.fr
 * Description: Intégration entre WooCommerce et Pennylane pour la synchronisation des factures, clients et produits
 * Version: 1.5.0
 * Author: Tibo
 * Author URI: https://hostophoto.fr
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: woo-pennylane
 * Domain Path: /languages
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * WC requires at least: 5.0
 * WC tested up to: 8.0
 */

// Protection contre l'accès direct
if (!defined('ABSPATH')) {
    exit;
}

// Définition des constantes
define('WOO_PENNYLANE_VERSION', '1.5.0');
define('WOO_PENNYLANE_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('WOO_PENNYLANE_PLUGIN_URL', plugin_dir_url(__FILE__));
define('WOO_PENNYLANE_PLUGIN_BASENAME', plugin_basename(__FILE__));

/**
 * Classe principale du plugin
 */
class WooPennylane {
    /**
     * Instance unique de la classe
     */
    private static $instance = null;

    /**
     * Instance de la classe de paramètres
     */
    private $settings = null;

    /**
     * Retourne l'instance unique de la classe
     */
    public static function get_instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Constructeur
     */
    private function __construct() {
        // Hooks d'activation/désactivation
        register_activation_hook(__FILE__, array($this, 'activate'));
        register_deactivation_hook(__FILE__, array($this, 'deactivate'));

        // Initialisation du plugin
        add_action('plugins_loaded', array($this, 'init'));

        // Ajout des notices admin
        add_action('admin_notices', array($this, 'admin_notices'));
    }

    /**
     * Initialisation du plugin
     */
    public function init() {
        // Vérification des dépendances
        if (!$this->check_dependencies()) {
            return;
        }

        // Chargement des traductions
        $this->load_textdomain();

        // Chargement des composants
        $this->load_classes();
    }

    /**
     * Vérifie que toutes les dépendances sont présentes
     */
    private function check_dependencies() {
        if (!class_exists('WooCommerce')) {
            add_action('admin_notices', array($this, 'woocommerce_missing_notice'));
            return false;
        }
        return true;
    }

    /**
     * Charge les fichiers de traduction
     */
    private function load_textdomain() {
        load_plugin_textdomain(
            'woo-pennylane',
            false,
            dirname(WOO_PENNYLANE_PLUGIN_BASENAME) . '/languages'
        );
    }
/**
 * Charge les classes nécessaires
 */
private function load_classes() {
    // Chemins des fichiers
    $files = array(
        'includes/class-woo-pennylane-logger.php',      // Logger en premier pour être disponible pour les autres classes
        'includes/class-woo-pennylane-settings.php',
        'includes/class-woo-pennylane-synchronizer.php',
        'includes/class-woo-pennylane-customer-sync.php',
        'includes/class-woo-pennylane-product-sync.php',
        'includes/class-woo-pennylane-user-profile.php',
        'includes/class-woo-pennylane-guest-customer-sync.php',
        'includes/class-woo-pennylane-customer-hooks.php',
        'includes/class-woo-pennylane-api-client.php'
    );
    
    // Chargement des fichiers
    foreach ($files as $file) {
        $file_path = WOO_PENNYLANE_PLUGIN_DIR . $file;
        if (file_exists($file_path)) {
            require_once $file_path;
        }
    }
    
    // Vérification et création de la table de logs si elle n'existe pas
    if (class_exists('WooPennylane_Logger') && !WooPennylane_Logger::is_history_module_ready()) {
        WooPennylane_Logger::create_logs_table();
    }
    
    // Initialisation des classes
    if (class_exists('WooPennylane_Settings')) {
        $this->settings = new WooPennylane_Settings();
    }

    // Initialisation de la synchronisation des produits
    if (class_exists('WooPennylane_Product_Sync')) {
        $product_sync = new WooPennylane_Product_Sync();
        
        // Hooks pour les actions de produits
        add_action('add_meta_boxes', array($product_sync, 'add_product_metabox'));
        add_action('save_post_product', array($product_sync, 'save_product_metabox'));
        add_action('woocommerce_update_product', array($product_sync, 'maybe_sync_on_update'));
        add_action('woocommerce_create_product', array($product_sync, 'maybe_sync_on_update'));
        
        // Hooks pour la liste des produits
        add_filter('manage_edit-product_columns', array($product_sync, 'add_product_list_column'));
        add_action('manage_product_posts_custom_column', array($product_sync, 'render_product_list_column'), 10, 2);
        
        // Hooks pour les actions en masse
        add_filter('bulk_actions-edit-product', array($product_sync, 'add_bulk_actions'));
        add_filter('handle_bulk_actions-edit-product', array($product_sync, 'handle_bulk_actions'), 10, 3);
        add_action('admin_notices', array($product_sync, 'bulk_action_admin_notice'));
    }
    
    // Ajout de colonnes aux listes d'utilisateurs
    add_filter('manage_users_columns', array($this, 'add_pennylane_user_column'));
    add_filter('manage_users_custom_column', array($this, 'render_pennylane_user_column'), 10, 3);
}
    
    /**
     * Ajoute une colonne Pennylane à la liste des utilisateurs
     */
    public function add_pennylane_user_column($columns) {
        $columns['pennylane_sync'] = __('Pennylane', 'woo-pennylane');
        return $columns;
    }
    
    /**
    * Affiche le bouton de synchronisation Pennylane dans la liste des utilisateurs
    */
    public function render_pennylane_user_column($value, $column_name, $user_id) {
        if ($column_name !== 'pennylane_sync') {
            return $value;
        }
        
        $pennylane_id = get_user_meta($user_id, '_pennylane_customer_id', true);
        $synced = get_user_meta($user_id, '_pennylane_synced', true);
        $excluded = get_user_meta($user_id, '_pennylane_exclude', true);
        
        $output = '';
        
        // Si le client est exclu, afficher un message
        if ($excluded === 'yes') {
            $output = '<span class="pennylane-status excluded">' . __('Exclu', 'woo-pennylane') . '</span>';
        } else {
            // Déterminer le type de bouton à afficher
            $button_text = '';
            $button_class = 'sync-pennylane-customer button button-small';
            
            if ($synced === 'yes' && $pennylane_id) {
                // Client déjà synchronisé
                $button_text = __('Resynchroniser', 'woo-pennylane');
                $button_class .= ' resync';
                
                // Afficher la dernière synchronisation
                $last_sync = get_user_meta($user_id, '_pennylane_last_sync', true);
                if ($last_sync) {
                    $output .= '<span class="last-sync">' . sprintf(__('Dernière: %s', 'woo-pennylane'), 
                        human_time_diff(strtotime($last_sync), current_time('timestamp'))) . '</span><br>';
                }
                
                // Afficher l'ID Pennylane
                $output .= '<span class="pennylane-id">ID: ' . esc_html($pennylane_id) . '</span><br>';
            } else {
                // Client pas encore synchronisé
                $button_text = __('Synchroniser', 'woo-pennylane');
                $button_class .= ' sync';
            }
            
            // Créer le bouton avec l'icône appropriée
            $output .= '<a href="#" class="' . esc_attr($button_class) . '" data-user-id="' . esc_attr($user_id) . '" data-nonce="' . wp_create_nonce('sync_customer_' . $user_id) . '">';
            $output .= '<span class="dashicons dashicons-update"></span> ' . $button_text;
            $output .= '</a>';
            $output .= '<span class="spinner" style="float:none;margin:0 2px;"></span>';
            $output .= '<span class="sync-result"></span>';
        }
        
        return $output;
    }

   /**
     * Activation du plugin
     */
    public function activate() {
        if (!current_user_can('activate_plugins')) {
            return;
        }

        // Vérifie WooCommerce
        if (!class_exists('WooCommerce')) {
            deactivate_plugins(WOO_PENNYLANE_PLUGIN_BASENAME);
            wp_die(
                esc_html__('WooCommerce doit être installé et activé pour utiliser cette extension.', 'woo-pennylane'),
                'Plugin Activation Error',
                array('back_link' => true)
            );
        }

        // Chargez d'abord le fichier de la classe Logger
        $logger_file = WOO_PENNYLANE_PLUGIN_DIR . 'includes/class-woo-pennylane-logger.php';
        if (file_exists($logger_file)) {
            require_once $logger_file;
        }

        // Crée les tables si la classe existe
        if (class_exists('WooPennylane_Logger')) {
            WooPennylane_Logger::create_logs_table();
        }
        // Crée la table pour les clients invités
         require_once WOO_PENNYLANE_PLUGIN_DIR . 'includes/class-woo-pennylane-guest-customer-sync.php';
         WooPennylane_Guest_Customer_Sync::create_guest_sync_table();

        // Ajoute les options par défaut
        $this->add_default_options();

        // Marque comme activé pour afficher la notice de bienvenue
        set_transient('woo_pennylane_activation', true, 30);

        // Flush les règles de réécriture
        flush_rewrite_rules();
    }

    /**
     * Désactivation du plugin
     */
    public function deactivate() {
        if (!current_user_can('activate_plugins')) {
            return;
        }

        flush_rewrite_rules();
    }

     /**
     * Création des tables de base de données
     */
    private function create_tables() {
        global $wpdb;
        
        $charset_collate = $wpdb->get_charset_collate();
        
        $sql = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}woo_pennylane_logs (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            entity_id bigint(20) NOT NULL,
            entity_type varchar(50) NOT NULL,
            status varchar(50) NOT NULL,
            message text NOT NULL,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY entity_id (entity_id),
            KEY entity_type (entity_type),
            KEY status (status)
        ) $charset_collate;";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
        
        // Vérifie si la table a été créée avec succès
        $table_name = $wpdb->prefix . 'woo_pennylane_logs';
        if ($wpdb->get_var("SHOW TABLES LIKE '$table_name'") != $table_name) {
            // La table n'existe pas, enregistre l'erreur
            error_log('Impossible de créer la table ' . $table_name);
        }
    }
    /**
     * Ajout des options par défaut
     */
    private function add_default_options() {
        $default_options = array(
            'woo_pennylane_api_key' => '',
            'woo_pennylane_journal_code' => '',
            'woo_pennylane_account_number' => '',
            'woo_pennylane_debug_mode' => 'no',
            'woo_pennylane_auto_sync' => 'yes',
            'woo_pennylane_sync_status' => array('completed'),
            'woo_pennylane_auto_sync_products' => 'no',
            'woo_pennylane_auto_sync_customers' => 'no',
            'woo_pennylane_product_ledger_account' => '707' // Compte de vente de marchandises par défaut
        );

        foreach ($default_options as $option_name => $default_value) {
            add_option($option_name, $default_value);
        }
    }

    /**
     * Affiche les notices d'administration
     */
    public function admin_notices() {
        // Notice de bienvenue après activation
        if (get_transient('woo_pennylane_activation')) {
            ?>
            <div class="notice notice-info is-dismissible">
                <p>
                    <?php
                    echo sprintf(
                        __('Merci d\'avoir installé WooCommerce Pennylane Integration v%s. <a href="%s">Configurez le plugin</a> pour commencer à synchroniser vos données.', 'woo-pennylane'),
                        WOO_PENNYLANE_VERSION,
                        admin_url('admin.php?page=woo-pennylane-settings')
                    );
                    ?>
                </p>
            </div>
            <?php
            delete_transient('woo_pennylane_activation');
        }
    }

    /**
     * Affiche la notice WooCommerce manquant
     */
    public function woocommerce_missing_notice() {
        if (current_user_can('activate_plugins')) {
            ?>
            <div class="error">
                <p>
                    <?php
                    _e('WooCommerce Pennylane Integration nécessite WooCommerce. Veuillez installer et activer WooCommerce.', 'woo-pennylane');
                    ?>
                </p>
            </div>
            <?php
        }
    }

    /**
     * Logger les erreurs en mode debug
     */
    public function log_error($message) {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('[WooPennylane] ' . $message);
        }
    }
}

/**
 * Retourne l'instance unique du plugin
 */
function woo_pennylane() {
    return WooPennylane::get_instance();
}

// Démarrage du plugin
woo_pennylane();