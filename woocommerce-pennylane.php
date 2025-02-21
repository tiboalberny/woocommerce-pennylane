<?php
/**
 * Plugin Name: WooCommerce Pennylane Integration
 * Plugin URI: https://votre-site.com/woo-pennylane
 * Description: Intégration entre WooCommerce et Pennylane pour la synchronisation des factures
 * Version: 1.0.0
 * Author: Votre Nom
 * Author URI: https://votre-site.com
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: woo-pennylane
 * Domain Path: /languages
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * WC requires at least: 5.0
 * WC tested up to: 8.0
 */

if (!defined('ABSPATH')) {
    exit;
}

// Définition des constantes
define('WOO_PENNYLANE_VERSION', '1.0.0');
define('WOO_PENNYLANE_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('WOO_PENNYLANE_PLUGIN_URL', plugin_dir_url(__FILE__));
define('WOO_PENNYLANE_PLUGIN_BASENAME', plugin_basename(__FILE__));

// Auto-chargement des classes
spl_autoload_register(function ($class_name) {
    $prefix = 'WooPennylane\\';
    $base_dir = WOO_PENNYLANE_PLUGIN_DIR . 'includes/';

    $len = strlen($prefix);
    if (strncmp($prefix, $class_name, $len) !== 0) {
        return;
    }

    $relative_class = substr($class_name, $len);
    $file = $base_dir . 'class-' . strtolower(str_replace('\\', '-', $relative_class)) . '.php';

    if (file_exists($file)) {
        require $file;
    }
});

// Classe principale du plugin
class WooPennylane {
    private static $instance = null;
    private $admin_page_hook = 'woocommerce_page_woo-pennylane-settings';

    public static function instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        // Hooks d'initialisation
        add_action('plugins_loaded', array($this, 'init'));
        
        // Hooks d'activation/désactivation
        register_activation_hook(__FILE__, array($this, 'activate'));
        register_deactivation_hook(__FILE__, array($this, 'deactivate'));
        
        // Hooks pour les assets
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_scripts'));
        
        // Hooks pour les notices d'admin
        add_action('admin_notices', array($this, 'admin_notices'));
    }

    public function init() {
        // Vérification des dépendances
        if (!$this->check_dependencies()) {
            return;
        }

        // Chargement des traductions
        $this->load_textdomain();

        // Initialisation des composants
        $this->init_components();
    }

    private function check_dependencies() {
        if (!class_exists('WooCommerce')) {
            add_action('admin_notices', array($this, 'woocommerce_missing_notice'));
            return false;
        }
        return true;
    }

    private function load_textdomain() {
        load_plugin_textdomain(
            'woo-pennylane',
            false,
            dirname(WOO_PENNYLANE_PLUGIN_BASENAME) . '/languages/'
        );
    }

    private function init_components() {
        new \WooPennylane\Admin\Settings();
        new \WooPennylane\Integration\Synchronizer();
        new \WooPennylane\Api\Client();
    }

    public function enqueue_admin_scripts($hook) {
        if ($hook !== $this->admin_page_hook) {
            return;
        }

        // Enregistrement et chargement du CSS
        wp_enqueue_style(
            'woo-pennylane-admin',
            WOO_PENNYLANE_PLUGIN_URL . 'assets/css/admin.css',
            array(),
            WOO_PENNYLANE_VERSION
        );

        // Enregistrement et chargement du JavaScript
        wp_enqueue_script(
            'woo-pennylane-admin',
            WOO_PENNYLANE_PLUGIN_URL . 'assets/js/admin.js',
            array('jquery'),
            WOO_PENNYLANE_VERSION,
            true
        );

        // Paramètres localisés pour JavaScript
        wp_localize_script(
            'woo-pennylane-admin',
            'wooPennylaneParams',
            array(
                'ajaxUrl' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('woo_pennylane_nonce'),
                'errorMessage' => __('Une erreur est survenue', 'woo-pennylane'),
                'requiredFieldsMessage' => __('Tous les champs requis doivent être remplis', 'woo-pennylane'),
                'connectionErrorMessage' => __('Erreur de connexion à l\'API', 'woo-pennylane'),
                'hideText' => __('Masquer', 'woo-pennylane'),
                'showText' => __('Afficher', 'woo-pennylane'),
                'syncSuccessMessage' => __('Synchronisation réussie', 'woo-pennylane'),
                'syncErrorMessage' => __('Échec de la synchronisation', 'woo-pennylane'),
                'savingMessage' => __('Enregistrement en cours...', 'woo-pennylane'),
                'savedMessage' => __('Paramètres enregistrés', 'woo-pennylane')
            )
        );
    }

    public function activate() {
        // Création des tables si nécessaire
        $this->create_tables();
        
        // Ajout des options par défaut
        $this->add_default_options();
        
        // Flush des règles de réécriture
        flush_rewrite_rules();

        // Marqueur d'activation pour afficher la notice de bienvenue
        set_transient('woo_pennylane_activation', true, 30);
    }

    public function deactivate() {
        // Nettoyage si nécessaire
        flush_rewrite_rules();
    }

    private function create_tables() {
        global $wpdb;
        
        $charset_collate = $wpdb->get_charset_collate();
        
        $sql = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}woo_pennylane_logs (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            order_id bigint(20) NOT NULL,
            status varchar(50) NOT NULL,
            message text NOT NULL,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY order_id (order_id),
            KEY status (status)
        ) $charset_collate;";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }

    private function add_default_options() {
        add_option('woo_pennylane_api_key', '');
        add_option('woo_pennylane_journal_code', '');
        add_option('woo_pennylane_account_number', '');
        add_option('woo_pennylane_debug_mode', 'no');
        add_option('woo_pennylane_auto_sync', 'yes');
        add_option('woo_pennylane_sync_status', array('completed', 'processing'));
    }

    public function admin_notices() {
        // Notice de bienvenue après activation
        if (get_transient('woo_pennylane_activation')) {
            ?>
            <div class="notice notice-info is-dismissible">
                <p>
                    <?php
                    echo sprintf(
                        __('Merci d\'avoir installé WooCommerce Pennylane Integration. <a href="%s">Configurez le plugin</a> pour commencer à synchroniser vos commandes.', 'woo-pennylane'),
                        admin_url('admin.php?page=woo-pennylane-settings')
                    );
                    ?>
                </p>
            </div>
            <?php
            delete_transient('woo_pennylane_activation');
        }
    }

    public function woocommerce_missing_notice() {
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

    public static function get_plugin_info() {
        return array(
            'version' => WOO_PENNYLANE_VERSION,
            'min_php' => '7.4',
            'min_wp' => '5.8',
            'min_wc' => '5.0'
        );
    }
}

// Initialisation du plugin
function woo_pennylane() {
    return WooPennylane::instance();
}

// Démarrage du plugin
woo_pennylane();