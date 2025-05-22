<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Classe de synchronisation des clients invités (sans compte WooCommerce)
 */
class WooPennylane_Guest_Customer_Sync {
    /**
     * @var \WooPennylane\Api\Client
     */
    private $api_client;

    public function __construct() {
        $this->api_client = new \WooPennylane\Api\Client();
    }

    /**
     * Récupère tous les emails des clients invités (sans compte) ayant passé commande
     * 
     * @return array Liste des emails uniques des clients invités
     */
    public function get_guest_customers() {
        global $wpdb;
        
        // Récupère tous les emails des commandes
        $order_emails = $wpdb->get_col("
            SELECT DISTINCT pm.meta_value 
            FROM {$wpdb->postmeta} pm
            JOIN {$wpdb->posts} p ON p.ID = pm.post_id
            WHERE p.post_type = 'shop_order'
            AND pm.meta_key = '_billing_email'
        ");
        
        // Récupère tous les emails des utilisateurs enregistrés
        $user_emails = $wpdb->get_col("
            SELECT DISTINCT user_email 
            FROM {$wpdb->users}
        ");
        
        // Filtre pour ne garder que les emails qui n'appartiennent pas à des utilisateurs enregistrés
        $guest_emails = array_diff($order_emails, $user_emails);
        
        return $guest_emails;
    }
    
    /**
     * Compte le nombre de clients invités déjà synchronisés
     * 
     * @return array Tableau contenant le nombre total et le nombre de synchronisés
     */
    public function count_guest_customers() {
        global $wpdb;
        
        $guest_emails = $this->get_guest_customers();
        $total = count($guest_emails);
        $synced = 0;
        
        // Pour chaque email, vérifie s'il existe une entrée dans la table de synchronisation
        foreach ($guest_emails as $email) {
            $exists = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->prefix}woo_pennylane_guest_sync WHERE email = %s AND synced = 1",
                $email
            ));
            
            if ($exists) {
                $synced++;
            }
        }
        
        return array(
            'total' => $total,
            'synced' => $synced
        );
    }
    
    /**
     * Crée la table pour stocker les informations de synchronisation des clients invités
     */
    public static function create_guest_sync_table() {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'woo_pennylane_guest_sync';
        $charset_collate = $wpdb->get_charset_collate();
        
        $sql = "CREATE TABLE IF NOT EXISTS $table_name (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            email varchar(100) NOT NULL,
            pennylane_id varchar(50) DEFAULT NULL,
            synced tinyint(1) DEFAULT 0,
            last_sync datetime DEFAULT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY email (email)
        ) $charset_collate;";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }
    /**
     * Synchronise un client invité vers Pennylane en utilisant sa dernière commande
     * 
     * @param string $email Email du client invité
     * @return bool Succès ou échec
     * @throws Exception En cas d'erreur
     */
    public function sync_guest_customer($email) {
        global $wpdb;
        
        // Vérifie si l'email existe déjà dans la table de synchronisation
        $table_name = $wpdb->prefix . 'woo_pennylane_guest_sync';
        $synced = $wpdb->get_var($wpdb->prepare(
            "SELECT synced FROM $table_name WHERE email = %s",
            $email
        ));
        
        if ($synced) {
            // Déjà synchronisé, pas besoin de le refaire
            return true;
        }
        
        try {
            // Récupère la dernière commande de ce client
            $order_id = $wpdb->get_var($wpdb->prepare(
                "SELECT post_id FROM {$wpdb->postmeta} 
                WHERE meta_key = '_billing_email' AND meta_value = %s 
                ORDER BY post_id DESC LIMIT 1",
                $email
            ));
            
            if (!$order_id) {
                throw new Exception(__('Aucune commande trouvée pour cet email', 'woo-pennylane'));
            }
            
            $order = wc_get_order($order_id);
            if (!$order) {
                throw new Exception(__('Commande introuvable', 'woo-pennylane'));
            }
            
            // Prépare les données du client à partir de la commande
            $customer_data = $this->prepare_customer_data_from_order($order);
            
            // Valider les données du client invité
            $this->validate_guest_customer_data($customer_data, $email);

            // Vérifie d'abord si le client existe déjà dans Pennylane
            $existing_customer = $this->check_existing_customer($email);
            
            if ($existing_customer) {
                // Le client existe déjà, on utilise son ID
                $pennylane_id = $existing_customer;
            } else {
                // Crée un nouveau client dans Pennylane
                $response = $this->api_client->create_individual_customer($customer_data);
                $pennylane_id = $response['id'];
            }
            
            // Met à jour ou insère dans la table de synchronisation
            $wpdb->replace(
                $table_name,
                array(
                    'email' => $email,
                    'pennylane_id' => $pennylane_id,
                    'synced' => 1,
                    'last_sync' => current_time('mysql')
                ),
                array('%s', '%s', '%d', '%s')
            );
            
            \WooPennylane\Logger::info(
                sprintf('Client invité (Email: %s) synchronisé avec succès. ID Pennylane: %s', esc_html($email), $pennylane_id),
                null, // Pas d'ID utilisateur direct pour l'invité, l'email est la clé
                'guest_customer'
            );

            return true;
            
        } catch (Exception $e) {
            $error_message_for_meta = $e->getMessage();
            $log_message_error = sprintf(
                'Erreur API Pennylane lors de la synchronisation du client invité (Email: %s): %s',
                esc_html($email),
                $e->getMessage()
            );

            \WooPennylane\Logger::error($log_message_error, null, 'guest_customer');

            if (isset($customer_data)) {
                \WooPennylane\Logger::debug("Données préparées pour client invité (Email: " . esc_html($email) . "): " . wp_json_encode($customer_data), null, 'guest_customer');
            }
            
            // Enregistre l'erreur dans la table
            $wpdb->replace(
                $table_name,
                array(
                    'email' => $email,
                    'synced' => 0,
                    'last_sync' => current_time('mysql')
                ),
                array('%s', '%d', '%s')
            );
            
            throw $e;
        }
    }
    
    /**
     * Valide les données du client invité avant l'envoi à Pennylane
     *
     * @param array $data Données du client
     * @param string $email Email utilisé pour la validation (si $data['emails'] est vide)
     * @throws Exception Si des données obligatoires sont manquantes ou incorrectes
     */
    private function validate_guest_customer_data($data, $email_context) {
        if (empty($data['first_name'])) {
            throw new Exception(sprintf(__('Prénom manquant pour le client invité (Email: %s)', 'woo-pennylane'), esc_html($email_context)));
        }
        
        if (empty($data['last_name'])) {
            throw new Exception(sprintf(__('Nom manquant pour le client invité (Email: %s)', 'woo-pennylane'), esc_html($email_context)));
        }
        
        if (empty($data['billing_address']) || 
            empty($data['billing_address']['address']) || 
            empty($data['billing_address']['postal_code']) || 
            empty($data['billing_address']['city']) || 
            empty($data['billing_address']['country_alpha2'])) {
            throw new Exception(sprintf(__('Adresse de facturation incomplète pour le client invité (Email: %s)', 'woo-pennylane'), esc_html($email_context)));
        }

        if (!preg_match('/^[A-Z]{2}$/', $data['billing_address']['country_alpha2'])) {
            throw new Exception(sprintf(__('Format de code pays invalide pour l\'adresse de facturation du client invité (Email: %s, Pays: %s). Doit être 2 lettres majuscules (ex: FR).', 'woo-pennylane'), esc_html($email_context), esc_html($data['billing_address']['country_alpha2'])));
        }
        
        if (empty($data['emails']) || !is_array($data['emails']) || empty($data['emails'][0])) {
             throw new Exception(sprintf(__('Email manquant ou invalide dans les données préparées pour le client invité (Email contexte: %s)', 'woo-pennylane'), esc_html($email_context)));
        }

        foreach ($data['emails'] as $email_item) {
            if (!filter_var($email_item, FILTER_VALIDATE_EMAIL)) {
                throw new Exception(sprintf(__('Format d\'email invalide pour le client invité (Email: %s, Contexte: %s)', 'woo-pennylane'), esc_html($email_item), esc_html($email_context)));
            }
        }

        // Validation de l'adresse de livraison si présente
        if (!empty($data['delivery_address'])) {
            if (empty($data['delivery_address']['address']) || 
                empty($data['delivery_address']['postal_code']) || 
                empty($data['delivery_address']['city']) || 
                empty($data['delivery_address']['country_alpha2'])) {
                throw new Exception(sprintf(__('Adresse de livraison incomplète pour le client invité (Email: %s)', 'woo-pennylane'), esc_html($email_context)));
            }
            if (!preg_match('/^[A-Z]{2}$/', $data['delivery_address']['country_alpha2'])) {
                throw new Exception(sprintf(__('Format de code pays invalide pour l\'adresse de livraison du client invité (Email: %s, Pays: %s). Doit être 2 lettres majuscules (ex: FR).', 'woo-pennylane'), esc_html($email_context), esc_html($data['delivery_address']['country_alpha2'])));
            }
        }
    }

    /**
     * Vérifie si un client existe déjà dans Pennylane en utilisant son email
     * 
     * @param string $email Email du client
     * @return string|false ID Pennylane ou false si non trouvé
     */
    private function check_existing_customer($email) {
        try {
            // Appel à l'API pour rechercher un client par email
            $response = $this->api_client->find_individual_customer_by_email($email);
            
            if (!empty($response) && is_array($response)) {
                // Retourne l'ID du premier client trouvé
                foreach ($response as $customer) {
                    if (isset($customer['id']) && isset($customer['emails']) && in_array($email, $customer['emails'])) {
                        return $customer['id'];
                    }
                }
            }
            
            return false;
        } catch (Exception $e) {
            \WooPennylane\Logger::error('Erreur API Pennylane (Find Guest Customer by email ' . esc_html($email) . '): ' . $e->getMessage(), null, 'guest_customer');
            return false;
        }
    }
    /**
     * Prépare les données du client à partir d'une commande
     * 
     * @param WC_Order $order Commande WooCommerce
     * @return array Données du client formatées pour Pennylane
     */
    private function prepare_customer_data_from_order($order) {
        // Email dans un tableau
        $email = $order->get_billing_email();
        $emails = !empty($email) ? array($email) : array();

        // Adresse de facturation
        $billing_address = array(
            'address' => $order->get_billing_address_1() . 
                        (!empty($order->get_billing_address_2()) ? "\n" . $order->get_billing_address_2() : ''),
            'postal_code' => $order->get_billing_postcode(),
            'city' => $order->get_billing_city(),
            'country_alpha2' => $order->get_billing_country()
        );

        // Construction des données client
        $customer_data = array(
            'first_name' => $order->get_billing_first_name(),
            'last_name' => $order->get_billing_last_name(),
            'reference' => 'WC-Guest-' . $order->get_id(),
            'emails' => $emails,
            'billing_address' => $billing_address
        );

        // Ajout du téléphone s'il existe
        $phone = $order->get_billing_phone();
        if (!empty($phone)) {
            $customer_data['phone'] = $phone;
        }

        // Adresse de livraison si différente
        if ($order->get_shipping_address_1() && 
            ($order->get_shipping_address_1() !== $order->get_billing_address_1() ||
             $order->get_shipping_city() !== $order->get_billing_city())) {
            
            $customer_data['delivery_address'] = array(
                'address' => $order->get_shipping_address_1() . 
                            (!empty($order->get_shipping_address_2()) ? "\n" . $order->get_shipping_address_2() : ''),
                'postal_code' => $order->get_shipping_postcode(),
                'city' => $order->get_shipping_city(),
                'country_alpha2' => $order->get_shipping_country()
            );
        }

        return $customer_data;
    }
}