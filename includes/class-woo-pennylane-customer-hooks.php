<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Classe pour gérer la synchronisation automatique des clients lors d'événements WooCommerce
 */
class WooPennylane_Customer_Hooks {
    /**
     * Instance du synchroniseur de clients
     */
    private $customer_sync;
    
    /**
     * Constructeur
     */
    public function __construct() {
        // Vérifier si la synchronisation automatique est activée
        if (get_option('woo_pennylane_auto_sync_customers') !== 'yes') {
            return;
        }
        
        // Initialisation du synchroniseur
        require_once WOO_PENNYLANE_PLUGIN_DIR . 'includes/class-woo-pennylane-customer-sync.php';
        $this->customer_sync = new WooPennylane_Customer_Sync();
        
        // Hooks pour la création/mise à jour de client
        add_action('woocommerce_created_customer', array($this, 'sync_new_customer'), 10, 1);
        add_action('woocommerce_customer_save_address', array($this, 'sync_customer_on_address_update'), 10, 2);
        add_action('woocommerce_customer_object_updated_props', array($this, 'sync_customer_on_update'), 10, 2);
        
        // Hook pour la création d'une commande (peut contenir un nouveau client)
        add_action('woocommerce_checkout_update_order_meta', array($this, 'maybe_sync_customer_from_order'), 10, 1);
    }
    
    /**
     * Synchronise un nouveau client créé dans WooCommerce
     */
    public function sync_new_customer($customer_id) {
        if (!$customer_id) {
            return;
        }
        
        // Vérifie si le client n'est pas déjà synchronisé ou exclu
        if (get_user_meta($customer_id, '_pennylane_synced', true) === 'yes' || 
            get_user_meta($customer_id, '_pennylane_exclude', true) === 'yes') {
            return;
        }
        
        try {
            $this->customer_sync->sync_customer($customer_id);
            
            if (get_option('woo_pennylane_debug_mode') === 'yes') {
                error_log('Pennylane: Nouveau client #' . $customer_id . ' synchronisé automatiquement');
            }
        } catch (Exception $e) {
            if (get_option('woo_pennylane_debug_mode') === 'yes') {
                error_log('Pennylane: Erreur lors de la synchronisation automatique du nouveau client #' . $customer_id . ': ' . $e->getMessage());
            }
        }
    }
    
    /**
     * Synchronise un client lors de la mise à jour de son adresse
     */
    public function sync_customer_on_address_update($customer_id, $address_type) {
        if (!$customer_id) {
            return;
        }
        
        // Vérifie si le client n'est pas exclu
        if (get_user_meta($customer_id, '_pennylane_exclude', true) === 'yes') {
            return;
        }
        
        try {
            $customer = new WC_Customer($customer_id);
            
            // Vérifie si le client a les données minimales requises
            if (!$customer->get_billing_first_name() || !$customer->get_billing_last_name() || !$customer->get_billing_address_1()) {
                return;
            }
            
            $this->customer_sync->sync_customer($customer_id);
            
            if (get_option('woo_pennylane_debug_mode') === 'yes') {
                error_log('Pennylane: Client #' . $customer_id . ' synchronisé suite à la mise à jour de l\'adresse ' . $address_type);
            }
        } catch (Exception $e) {
            if (get_option('woo_pennylane_debug_mode') === 'yes') {
                error_log('Pennylane: Erreur lors de la synchronisation du client #' . $customer_id . ' après mise à jour d\'adresse: ' . $e->getMessage());
            }
        }
    }
    
    /**
     * Synchronise un client lors de la mise à jour de ses propriétés
     */
    public function sync_customer_on_update($customer, $updated_props) {
        if (!$customer || !$customer->get_id()) {
            return;
        }
        
        $customer_id = $customer->get_id();
        
        // Vérifie si le client n'est pas exclu
        if (get_user_meta($customer_id, '_pennylane_exclude', true) === 'yes') {
            return;
        }
        
        // Liste des propriétés qui devraient déclencher une synchronisation
        $sync_props = array(
            'billing_first_name', 'billing_last_name', 'billing_company', 
            'billing_address_1', 'billing_address_2', 'billing_city',
            'billing_postcode', 'billing_country', 'billing_state',
            'billing_phone', 'billing_email',
            'shipping_first_name', 'shipping_last_name', 'shipping_company',
            'shipping_address_1', 'shipping_address_2', 'shipping_city',
            'shipping_postcode', 'shipping_country', 'shipping_state'
        );
        
        // Vérifie si une des propriétés importantes a été mise à jour
        $should_sync = false;
        foreach ($sync_props as $prop) {
            if (in_array($prop, $updated_props)) {
                $should_sync = true;
                break;
            }
        }
        
        if (!$should_sync) {
            return;
        }
        
        try {
            // Vérifie si le client a les données minimales requises
            if (!$customer->get_billing_first_name() || !$customer->get_billing_last_name() || !$customer->get_billing_address_1()) {
                return;
            }
            
            $this->customer_sync->sync_customer($customer_id);
            
            if (get_option('woo_pennylane_debug_mode') === 'yes') {
                error_log('Pennylane: Client #' . $customer_id . ' synchronisé suite à la mise à jour des propriétés: ' . implode(', ', $updated_props));
            }
        } catch (Exception $e) {
            if (get_option('woo_pennylane_debug_mode') === 'yes') {
                error_log('Pennylane: Erreur lors de la synchronisation du client #' . $customer_id . ' après mise à jour: ' . $e->getMessage());
            }
        }
    }
    
    /**
     * Synchronise un client à partir d'une commande (utile pour les clients invités)
     */
    public function maybe_sync_customer_from_order($order_id) {
        $order = wc_get_order($order_id);
        
        if (!$order) {
            return;
        }
        
        // Si la commande est associée à un utilisateur enregistré
        $customer_id = $order->get_customer_id();
        
        if ($customer_id) {
            // Vérifie si le client n'est pas exclu
            if (get_user_meta($customer_id, '_pennylane_exclude', true) === 'yes') {
                return;
            }
            
            try {
                $customer = new WC_Customer($customer_id);
                
                // Vérifie si le client a les données minimales requises
                if (!$customer->get_billing_first_name() || !$customer->get_billing_last_name() || !$customer->get_billing_address_1()) {
                    return;
                }
                
                $this->customer_sync->sync_customer($customer_id);
                
                if (get_option('woo_pennylane_debug_mode') === 'yes') {
                    error_log('Pennylane: Client #' . $customer_id . ' synchronisé lors de la création de la commande #' . $order_id);
                }
            } catch (Exception $e) {
                if (get_option('woo_pennylane_debug_mode') === 'yes') {
                    error_log('Pennylane: Erreur lors de la synchronisation du client #' . $customer_id . ' depuis la commande: ' . $e->getMessage());
                }
            }
        }
        // Pour les clients invités, on pourrait implémenter une logique différente
        // par exemple créer un "client" dans Pennylane sans l'associer à un utilisateur WooCommerce
    }
}

// Initialisation de la classe
function init_woo_pennylane_customer_hooks() {
    new WooPennylane_Customer_Hooks();
}
add_action('init', 'init_woo_pennylane_customer_hooks');