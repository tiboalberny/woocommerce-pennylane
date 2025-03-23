<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Classe de synchronisation des clients invités (sans compte WooCommerce)
 */
class WooPennylane_Guest_Customer_Sync {
    private $api_key;
    private $api_url = 'https://app.pennylane.com/api/external/v2';

    public function __construct() {
        $this->api_key = get_option('woo_pennylane_api_key');
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
            
            // Vérifie d'abord si le client existe déjà dans Pennylane
            $existing_customer = $this->check_existing_customer($email);
            
            if ($existing_customer) {
                // Le client existe déjà, on utilise son ID
                $pennylane_id = $existing_customer;
            } else {
                // Crée un nouveau client dans Pennylane
                $response = $this->send_to_api('/individual_customers', $customer_data);
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
            
            return true;
            
        } catch (Exception $e) {
            // Log l'erreur
            if (get_option('woo_pennylane_debug_mode') === 'yes') {
                error_log('Pennylane Guest Customer Sync Error (Email: ' . $email . '): ' . $e->getMessage());
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
     * Vérifie si un client existe déjà dans Pennylane en utilisant son email
     * 
     * @param string $email Email du client
     * @return string|false ID Pennylane ou false si non trouvé
     */
    private function check_existing_customer($email) {
        try {
            // Appel à l'API pour rechercher un client par email
            $response = $this->send_to_api('/individual_customers?email=' . urlencode($email), null, 'GET');
            
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
    
    /**
     * Envoie une requête à l'API Pennylane
     * 
     * @param string $endpoint Point de terminaison de l'API
     * @param array $data Données à envoyer (null pour GET)
     * @param string $method Méthode HTTP (POST par défaut)
     * @return array Réponse de l'API
     * @throws Exception En cas d'erreur
     */
    private function send_to_api($endpoint, $data = null, $method = 'POST') {
        if (empty($this->api_key)) {
            throw new Exception(__('Clé API non configurée', 'woo-pennylane'));
        }

        $url = $this->api_url . $endpoint;
        
        $headers = array(
            'accept: application/json',
            'authorization: Bearer ' . $this->api_key,
            'content-type: application/json'
        );

        $curl = curl_init();

        $curl_options = array(
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => '',
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_CUSTOMREQUEST => $method
        );

        if ($data !== null && $method !== 'GET') {
            $curl_options[CURLOPT_POSTFIELDS] = json_encode($data);
        }

        curl_setopt_array($curl, $curl_options);

        $response = curl_exec($curl);
        $err = curl_error($curl);
        $http_code = curl_getinfo($curl, CURLINFO_HTTP_CODE);

        curl_close($curl);

        // Log détaillé
        if (get_option('woo_pennylane_debug_mode') === 'yes') {
            error_log('Pennylane API URL: ' . $url);
            if ($data !== null) {
                error_log('Pennylane API Request Data: ' . json_encode($data));
            }
            error_log('Pennylane API Response Code: ' . $http_code);
            error_log('Pennylane API Response: ' . $response);
            error_log('Pennylane API Error: ' . ($err ? $err : 'None'));
        }

        if ($err) {
            throw new Exception('Erreur cURL: ' . $err);
        }

        $response_data = json_decode($response, true);

        if ($http_code >= 400) {
            $error_message = 'Erreur API (HTTP ' . $http_code . ')';
            
            if (isset($response_data['message'])) {
                $error_message .= ': ' . $response_data['message'];
            } elseif (isset($response_data['error'])) {
                $error_message .= ': ' . $response_data['error'];
            } elseif (isset($response_data['detail'])) {
                $error_message .= ': ' . $response_data['detail'];
            } else {
                $error_message .= ': ' . $response;
            }
            
            throw new Exception($error_message);
        }

        return $response_data;
    }
}