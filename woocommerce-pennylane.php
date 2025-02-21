<?php
/**
 * Plugin Name: WooCommerce Pennylane Integration
 * Plugin URI: https://votre-site.com/woo-pennylane
 * Description: Intégration entre WooCommerce et Pennylane pour la synchronisation des factures
 * Version: 1.0.0
 * Author: Tibo
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

// Protection contre l'accès direct
if (!defined('ABSPATH')) {
    exit;
}

// Définition des constantes
define('WOO_PENNYLANE_VERSION', '1.0.0');
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
            'includes/class-woo-pennylane-settings.php'
        );

        // Chargement des fichiers
        foreach ($files as $file) {
            $file_path = WOO_PENNYLANE_PLUGIN_DIR . $file;
            if (file_exists($file_path)) {
                require_once $file_path;
            }
        }

        // Initialisation des classes
        if (class_exists('WooPennylane_Settings')) {
            $this->settings = new WooPennylane_Settings();
        }
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

        // Crée les tables
        $this->create_tables();

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
            'woo_pennylane_sync_status' => array('completed')
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
    private function log_error($message) {
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