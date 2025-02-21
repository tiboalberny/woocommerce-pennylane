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

// Protection contre l'accès direct
if (!defined('ABSPATH')) {
    exit;
}

// Définition des constantes
if (!defined('WOO_PENNYLANE_VERSION')) {
    define('WOO_PENNYLANE_VERSION', '1.0.0');
}
if (!defined('WOO_PENNYLANE_PLUGIN_DIR')) {
    define('WOO_PENNYLANE_PLUGIN_DIR', plugin_dir_path(__FILE__));
}
if (!defined('WOO_PENNYLANE_PLUGIN_URL')) {
    define('WOO_PENNYLANE_PLUGIN_URL', plugin_dir_url(__FILE__));
}
if (!defined('WOO_PENNYLANE_PLUGIN_FILE')) {
    define('WOO_PENNYLANE_PLUGIN_FILE', __FILE__);
}

class WooPennylane {
    private static $instance = null;
    private $settings = null;

    public static function instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        // Hooks d'activation et désactivation
        register_activation_hook(WOO_PENNYLANE_PLUGIN_FILE, array($this, 'activate'));
        register_deactivation_hook(WOO_PENNYLANE_PLUGIN_FILE, array($this, 'deactivate'));

        // Hook d'initialisation
        add_action('plugins_loaded', array($this, 'init'));

        // Hook pour les notices d'administration
        add_action('admin_notices', array($this, 'admin_notices'));

        // Hook pour les scripts admin
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_scripts'));
    }

    public function init() {
        // Vérification des dépendances
        if (!$this->check_dependencies()) {
            return;
        }

        // Chargement des traductions
        load_plugin_textdomain(
            'woo-pennylane',
            false,
            dirname(plugin_basename(WOO_PENNYLANE_PLUGIN_FILE)) . '/languages'
        );

        // Chargement et initialisation des composants
        $this->init_components();
    }

    private function check_dependencies() {
        if (!class_exists('WooCommerce')) {
            add_action('admin_notices', array($this, 'woocommerce_missing_notice'));
            return false;
        }
        return true;
    }

    private function init_components() {
        try {
            // Chargement des fichiers
            require_once WOO_PENNYLANE_PLUGIN_DIR . 'includes/class-woo-pennylane-settings.php';
            
            // Initialisation des classes
            if (class_exists('\WooPennylane\Admin\Settings')) {
                $this->settings = new \WooPennylane\Admin\Settings();
            }
        } catch (\Exception $e) {
            $this->log_error($e->getMessage());
        }
    }

    public function activate() {
        if (!current_user_can('activate_plugins')) {
            return;
        }

        try {
            // Vérification de WooCommerce
            if (!class_exists('WooCommerce')) {
                throw new \Exception(
                    __('WooCommerce doit être installé et activé pour utiliser cette extension.', 'woo-pennylane')
                );
            }

            // Création des tables
            $this->create_tables();
            
            // Ajout des options par défaut
            $this->add_default_options();

            // Marquer l'activation pour afficher la notice de bienvenue
            set_transient('woo_pennylane_activation', true, 30);

        } catch (\Exception $e) {
            deactivate_plugins(plugin_basename(WOO_PENNYLANE_PLUGIN_FILE));
            wp_die(
                esc_html($e->getMessage()),
                'Plugin Activation Error',
                array('back_link' => true)
            );
        }
    }

    public function deactivate() {
        if (!current_user_can('activate_plugins')) {
            return;
        }

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
        $default_options = array(
            'woo_pennylane_api_key' => '',
            'woo_pennylane_journal_code' => '',
            'woo_pennylane_account_number' => '',
            'woo_pennylane_debug_mode' => 'no',
            'woo_pennylane_auto_sync' => 'yes',
            'woo_pennylane_sync_status' => array('completed')
        );

        foreach ($default_options as $option_name => $default_value) {
            if (get_option($option_name) === false) {
                add_option($option_name, $default_value);
            }
        }
    }

    public function enqueue_admin_scripts($hook) {
        if ('woocommerce_page_woo-pennylane-settings' !== $hook) {
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

        // Paramètres localisés
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
                'showText' => __('Afficher', 'woo-pennylane')
            )
        );
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

    private function log_error($message) {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('WooPennylane Error: ' . $message);
        }
    }
}

// Fonction d'accès global au plugin
function woo_pennylane() {
    return WooPennylane::instance();
}

// Initialisation du plugin
add_action('plugins_loaded', 'woo_pennylane', 10);