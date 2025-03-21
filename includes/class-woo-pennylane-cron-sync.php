<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Classe responsable de la synchronisation périodique vers Pennylane via CRON
 */
class WooPennylane_Cron_Sync {
    /**
     * API Client
     *
     * @var WooPennylane\Api\Client
     */
    private $api_client;

    /**
     * Logger
     *
     * @var WooPennylane_Logger
     */
    private $logger;

    /**
     * Constructeur
     */
    public function __construct() {
        // Initialisation de l'API client (en lazy loading)
        $this->api_client = null;
        
        // Hooks pour enregistrer les tâches CRON
        add_action('init', array($this, 'schedule_events'));
        add_action('woo_pennylane_sync_changes', array($this, 'sync_changes_task'));
        
        // Hook de désactivation pour nettoyer les tâches CRON
        register_deactivation_hook(WOO_PENNYLANE_PLUGIN_BASENAME, array($this, 'clear_scheduled_events'));

        // Hooks pour détecter les changements
        add_action('profile_update', array($this, 'flag_customer_for_sync'), 10, 2);
        add_action('woocommerce_customer_save_address', array($this, 'flag_customer_for_sync'), 10, 2);
        add_action('woocommerce_update_product', array($this, 'flag_product_for_sync'));
        add_action('woocommerce_save_product_variation', array($this, 'flag_product_for_sync'));
        
        // Initialisation du logger si disponible
        global $woo_pennylane_logger;
        if ($woo_pennylane_logger) {
            $this->logger = $woo_pennylane_logger;
        }
    }

    /**
     * Initialise l'API client si nécessaire
     */
    private function init_api_client() {
        if ($this->api_client === null) {
            require_once WOO_PENNYLANE_PLUGIN_DIR . 'includes/class-woo-pennylane-api-client.php';
            $this->api_client = new WooPennylane\Api\Client();
        }
        
        return $this->api_client;
    }

    /**
     * Planifie les événements CRON
     */
    public function schedule_events() {
        if (!wp_next_scheduled('woo_pennylane_sync_changes')) {
            // Utilisation de l'intervalle 'daily' prédéfini par WordPress
            wp_schedule_event(time(), 'daily', 'woo_pennylane_sync_changes');
        }
    }

    /**
     * Supprime les événements CRON lors de la désactivation du plugin
     */
    public function clear_scheduled_events() {
        wp_clear_scheduled_hook('woo_pennylane_sync_changes');
    }

    /**
     * Marque un client comme devant être synchronisé
     *
     * @param int $user_id ID du client
     * @param mixed $old_data Anciennes données (non utilisé)
     */
    public function flag_customer_for_sync($user_id, $old_data = null) {
        // Vérifier que c'est bien un client
        if (!wc_customer_bought_product('', $user_id, '') && !user_can($user_id, 'customer')) {
            return;
        }
        
        update_user_meta($user_id, '_pennylane_needs_sync', 'yes');
        update_user_meta($user_id, '_pennylane_last_modified', current_time('mysql'));
        
        if ($this->logger) {
            $customer = new WC_Customer($user_id);
            $this->logger->info(sprintf(
                'Client #%d (%s) marqué pour synchronisation suite à modification',
                $user_id,
                $customer->get_username()
            ));
        }
    }

    /**
     * Marque un produit comme devant être synchronisé
     *
     * @param int $product_id ID du produit
     */
    public function flag_product_for_sync($product_id) {
        // Si c'est une variation, récupérer le produit parent
        $product = wc_get_product($product_id);
        if ($product && $product->is_type('variation')) {
            $product_id = $product->get_parent_id();
        }
        
        update_post_meta($product_id, '_pennylane_product_needs_sync', 'yes');
        update_post_meta($product_id, '_pennylane_product_last_modified', current_time('mysql'));
        
        if ($this->logger) {
            $product = wc_get_product($product_id);
            $this->logger->info(sprintf(
                'Produit #%d (%s) marqué pour synchronisation suite à modification',
                $product_id,
                $product ? $product->get_name() : 'Inconnu'
            ));
        }
    }

    /**
     * Tâche CRON de synchronisation des changements
     * 
     * Identifie les produits et clients qui ont été modifiés
     * et les synchronise avec Pennylane
     */
    public function sync_changes_task() {
        // Pour l'historique
        global $woo_pennylane_sync_history;
        $start_time = microtime(true);
        $batch_start_time = $start_time;
        
        if ($this->logger) {
            $this->logger->info('Démarrage de la tâche CRON de synchronisation quotidienne avec Pennylane');
        }
        
        try {
            // Vérifier si l'API est configurée
            if (empty(get_option('woo_pennylane_api_key'))) {
                if ($this->logger) {
                    $this->logger->error('Clé API Pennylane non configurée, synchronisation CRON annulée');
                }
                
                if ($woo_pennylane_sync_history) {
                    $woo_pennylane_sync_history->add_entry(
                        'batch',
                        'cron',
                        null,
                        __('Tâche CRON quotidienne', 'woo-pennylane'),
                        'error',
                        __('Clé API Pennylane non configurée, synchronisation CRON annulée', 'woo-pennylane'),
                        microtime(true) - $batch_start_time
                    );
                }
                
                return;
            }
            
            // Vérifier si la synchronisation automatique est activée
            if (get_option('woo_pennylane_auto_sync') !== 'yes') {
                if ($this->logger) {
                    $this->logger->info('Synchronisation automatique désactivée, CRON terminé');
                }
                
                if ($woo_pennylane_sync_history) {
                    $woo_pennylane_sync_history->add_entry(
                        'batch',
                        'cron',
                        null,
                        __('Tâche CRON quotidienne', 'woo-pennylane'),
                        'skipped',
                        __('Synchronisation automatique désactivée, CRON terminé', 'woo-pennylane'),
                        microtime(true) - $batch_start_time
                    );
                }
                
                return;
            }
            
            if ($woo_pennylane_sync_history) {
                $woo_pennylane_sync_history->add_entry(
                    'batch',
                    'cron',
                    null,
                    __('Tâche CRON quotidienne - Début', 'woo-pennylane'),
                    'success',
                    __('Démarrage de la tâche CRON de synchronisation quotidienne', 'woo-pennylane'),
                    microtime(true) - $batch_start_time
                );
            }
            
            // Synchroniser les produits modifiés
            $products_result = $this->sync_modified_products();
            
            // Synchroniser les clients modifiés
            $customers_result = $this->sync_modified_customers();
            
            $execution_time = microtime(true) - $start_time;
            
            if ($this->logger) {
                $this->logger->info(sprintf(
                    'Fin de la tâche CRON de synchronisation quotidienne avec Pennylane (durée: %.2f secondes)', 
                    $execution_time
                ));
            }
            
            if ($woo_pennylane_sync_history) {
                $woo_pennylane_sync_history->add_entry(
                    'batch',
                    'cron',
                    null,
                    __('Tâche CRON quotidienne - Fin', 'woo-pennylane'),
                    'success',
                    sprintf(
                        __('Synchronisation terminée: %d produits et %d clients traités', 'woo-pennylane'),
                        $products_result['total'],
                        $customers_result['total']
                    ),
                    $execution_time
                );
            }
            
        } catch (Exception $e) {
            if ($this->logger) {
                $this->logger->error('Erreur lors de la synchronisation CRON: ' . $e->getMessage());
            }
            
            if ($woo_pennylane_sync_history) {
                $woo_pennylane_sync_history->add_entry(
                    'batch',
                    'cron',
                    null,
                    __('Tâche CRON quotidienne', 'woo-pennylane'),
                    'error',
                    sprintf(__('Erreur lors de la synchronisation CRON: %s', 'woo-pennylane'), $e->getMessage()),
                    microtime(true) - $start_time
                );
            }
        }
    }

    /**
     * Synchronise les produits modifiés
     */
    private function sync_modified_products() {
        $start_time = microtime(true);
        $batch_size = apply_filters('woo_pennylane_cron_batch_size', 50); // Augmenté car exécution quotidienne
        $updated_count = 0;
        $error_count = 0;
        $skipped_count = 0;
        
        try {
            // Récupérer les produits marqués pour synchronisation
            $args = array(
                'status' => 'publish',
                'limit' => $batch_size,
                'meta_key' => '_pennylane_product_needs_sync',
                'meta_value' => 'yes',
            );
            
            $products = wc_get_products($args);
            $total = count($products);
            
            if (empty($products)) {
                if ($this->logger) {
                    $this->logger->info('Aucun produit à synchroniser');
                }
                
                return array(
                    'total' => 0,
                    'updated' => 0,
                    'errors' => 0,
                    'skipped' => 0,
                    'execution_time' => microtime(true) - $start_time
                );
            }
            
            if ($this->logger) {
                $this->logger->info(sprintf('Synchronisation de %d produits modifiés', $total));
            }
            
            // Charger le synchroniseur de produits
            require_once WOO_PENNYLANE_PLUGIN_DIR . 'includes/class-woo-pennylane-product-sync.php';
            $synchronizer = new WooPennylane_Product_Sync();
            
            foreach ($products as $product) {
                $product_id = $product->get_id();
                
                try {
                    // Vérifier si le produit est exclu de la synchronisation
                    if (get_post_meta($product_id, '_pennylane_product_exclude', true) === 'yes') {
                        delete_post_meta($product_id, '_pennylane_product_needs_sync');
                        $skipped_count++;
                        continue;
                    }
                    
                    // Synchroniser le produit
                    $synchronizer->sync_product($product_id, 'cron');
                    
                    // Marquer comme synchronisé
                    delete_post_meta($product_id, '_pennylane_product_needs_sync');
                    
                    $updated_count++;
                    
                    if ($this->logger) {
                        $this->logger->info(sprintf('Produit #%d (%s) synchronisé avec succès', 
                            $product_id, 
                            $product->get_name()
                        ));
                    }
                    
                } catch (Exception $e) {
                    $error_count++;
                    
                    if ($this->logger) {
                        $this->logger->error(sprintf('Erreur lors de la synchronisation du produit #%d: %s', 
                            $product_id, 
                            $e->getMessage()
                        ));
                    }
                }
            }
            
            $execution_time = microtime(true) - $start_time;
            
            if ($this->logger) {
                $this->logger->info(sprintf('Synchronisation des produits terminée: %d produits mis à jour en %.2f secondes', 
                    $updated_count, 
                    $execution_time
                ));
            }
            
            return array(
                'total' => $total,
                'updated' => $updated_count,
                'errors' => $error_count,
                'skipped' => $skipped_count,
                'execution_time' => $execution_time
            );
            
        } catch (Exception $e) {
            if ($this->logger) {
                $this->logger->error('Erreur lors de la synchronisation des produits: ' . $e->getMessage());
            }
            
            return array(
                'total' => 0,
                'updated' => $updated_count,
                'errors' => $error_count + 1,
                'skipped' => $skipped_count,
                'execution_time' => microtime(true) - $start_time
            );
        }
    }

    /**
     * Synchronise les clients modifiés
     */
    private function sync_modified_customers() {
        $start_time = microtime(true);
        $batch_size = apply_filters('woo_pennylane_cron_batch_size', 50); // Augmenté car exécution quotidienne
        $updated_count = 0;
        $error_count = 0;
        $skipped_count = 0;
        
        try {
            // Récupérer les clients marqués pour synchronisation
            $args = array(
                'role' => 'customer',
                'number' => $batch_size,
                'meta_key' => '_pennylane_needs_sync',
                'meta_value' => 'yes',
            );
            
            $customers = get_users($args);
            $total = count($customers);
            
            if (empty($customers)) {
                if ($this->logger) {
                    $this->logger->info('Aucun client à synchroniser');
                }
                
                return array(
                    'total' => 0,
                    'updated' => 0,
                    'errors' => 0,
                    'skipped' => 0,
                    'execution_time' => microtime(true) - $start_time
                );
            }
            
            if ($this->logger) {
                $this->logger->info(sprintf('Synchronisation de %d clients modifiés', $total));
            }
            
            // Charger le synchroniseur de clients
            require_once WOO_PENNYLANE_PLUGIN_DIR . 'includes/class-woo-pennylane-customer-sync.php';
            $synchronizer = new WooPennylane_Customer_Sync();
            
            foreach ($customers as $customer) {
                $customer_id = $customer->ID;
                
                try {
                    // Synchroniser le client
                    $synchronizer->sync_customer($customer_id, 'cron');
                    
                    // Marquer comme synchronisé
                    delete_user_meta($customer_id, '_pennylane_needs_sync');
                    
                    $updated_count++;
                    
                    if ($this->logger) {
                        $this->logger->info(sprintf('Client #%d (%s) synchronisé avec succès', 
                            $customer_id, 
                            $customer->display_name
                        ));
                    }
                    
                } catch (Exception $e) {
                    $error_count++;
                    
                    if ($this->logger) {
                        $this->logger->error(sprintf('Erreur lors de la synchronisation du client #%d: %s', 
                            $customer_id, 
                            $e->getMessage()
                        ));
                    }
                }
            }
            
            $execution_time = microtime(true) - $start_time;
            
            if ($this->logger) {
                $this->logger->info(sprintf('Synchronisation des clients terminée: %d clients mis à jour en %.2f secondes', 
                    $updated_count, 
                    $execution_time
                ));
            }
            
            return array(
                'total' => $total,
                'updated' => $updated_count,
                'errors' => $error_count,
                'skipped' => $skipped_count,
                'execution_time' => $execution_time
            );
            
        } catch (Exception $e) {
            if ($this->logger) {
                $this->logger->error('Erreur lors de la synchronisation des clients: ' . $e->getMessage());
            }
            
            return array(
                'total' => 0,
                'updated' => $updated_count,
                'errors' => $error_count + 1,
                'skipped' => $skipped_count,
                'execution_time' => microtime(true) - $start_time
            );
        }
    }

    /**
     * Force la synchronisation de tous les produits non-exclus qui ne sont pas à jour
     *
     * @return array Résultats de la synchronisation
     */
    public function force_sync_all_products() {
        // Marquer tous les produits non-exclus pour synchronisation
        global $wpdb;
        
        // Récupérer tous les IDs de produits publiés
        $product_ids = $wpdb->get_col("
            SELECT ID FROM {$wpdb->posts} 
            WHERE post_type IN ('product', 'product_variation') 
            AND post_status = 'publish'
        ");
        
        $marked_count = 0;
        
        foreach ($product_ids as $product_id) {
            // Ignorer les produits exclus
            if (get_post_meta($product_id, '_pennylane_product_exclude', true) === 'yes') {
                continue;
            }
            
            update_post_meta($product_id, '_pennylane_product_needs_sync', 'yes');
            $marked_count++;
        }
        
        if ($this->logger) {
            $this->logger->info(sprintf('%d produits marqués pour synchronisation forcée', $marked_count));
        }
        
        // Lancer la synchronisation immédiatement
        return $this->sync_modified_products();
    }

    /**
     * Force la synchronisation de tous les clients qui ne sont pas à jour
     *
     * @return array Résultats de la synchronisation
     */
    public function force_sync_all_customers() {
        // Marquer tous les clients pour synchronisation
        $customers = get_users(array(
            'role' => 'customer',
            'fields' => 'ID'
        ));
        
        $marked_count = 0;
        
        foreach ($customers as $customer_id) {
            update_user_meta($customer_id, '_pennylane_needs_sync', 'yes');
            $marked_count++;
        }
        
        if ($this->logger) {
            $this->logger->info(sprintf('%d clients marqués pour synchronisation forcée', $marked_count));
        }
        
        // Lancer la synchronisation immédiatement
        return $this->sync_modified_customers();
    }
}