<?php
if (!defined('ABSPATH')) {
    exit;
}

class WooPennylane_Customer_Sync {
    private $api_key;
    private $api_url = 'https://app.pennylane.com/api/external/v2';

    public function __construct() {
        $this->api_key = get_option('woo_pennylane_api_key');
    }

    /**
     * Synchronise un client vers Pennylane
     */
    public function sync_customer($customer_id) {
        $customer = new WC_Customer($customer_id);
        
        if (!$customer || !$customer->get_id()) {
            throw new Exception(__('Client introuvable', 'woo-pennylane'));
        }

        try {
            // Prépare les données du client
            $customer_data = $this->prepare_customer_data($customer);

            // Vérifie les champs obligatoires
            $this->validate_customer_data($customer_data);

            // Vérifie si le client existe déjà dans Pennylane
            $external_ref = 'WC-' . $customer_id;
            $existing_customer = $this->find_customer_by_external_reference($external_ref);
            
            if ($existing_customer) {
                // Mise à jour du client existant
                $response = $this->send_to_api('/individual_customers/' . $existing_customer['id'], $customer_data, 'PUT');
                $pennylane_id = $existing_customer['id'];
            } else {
                // Création d'un nouveau client
                $response = $this->send_to_api('/individual_customers', $customer_data);
                $pennylane_id = isset($response['id']) ? $response['id'] : null;
            }

            // Met à jour le statut de synchronisation
            update_user_meta($customer_id, '_pennylane_synced', 'yes');
            update_user_meta($customer_id, '_pennylane_customer_id', $pennylane_id);
            update_user_meta($customer_id, '_pennylane_last_sync', current_time('mysql'));
            delete_user_meta($customer_id, '_pennylane_sync_error');

            return true;

        } catch (Exception $e) {
            // Log l'erreur
            if (get_option('woo_pennylane_debug_mode') === 'yes') {
                error_log('Pennylane Customer Sync Error (ID #' . $customer_id . '): ' . $e->getMessage());
            }
            
            // Enregistre l'erreur dans les métadonnées
            update_user_meta($customer_id, '_pennylane_sync_error', $e->getMessage());
            update_user_meta($customer_id, '_pennylane_last_sync', current_time('mysql'));
            
            throw $e;
        }
    }

    /**
     * Prépare les données du client au format attendu par l'API Pennylane
     * en utilisant uniquement les champs standard de WooCommerce
     * 
     * @param WC_Customer $customer Objet client WooCommerce
     * @return array Données formatées pour l'API Pennylane
     */
    private function prepare_customer_data($customer) {
        // Données obligatoires
        $customer_data = array(
            // Paramètres obligatoires
            'first_name' => $customer->get_billing_first_name(),
            'last_name' => $customer->get_billing_last_name(),
            'billing_address' => array(
                'address' => $customer->get_billing_address_1() . 
                            (!empty($customer->get_billing_address_2()) ? "\n" . $customer->get_billing_address_2() : ''),
                'postal_code' => $customer->get_billing_postcode(),
                'city' => $customer->get_billing_city(),
                'country_alpha2' => $customer->get_billing_country()
            ),
            
            // External reference - ID client WooCommerce
            'external_reference' => 'WC-' . $customer->get_id()
        );
        
        // Ajout du téléphone s'il existe
        $phone = $customer->get_billing_phone();
        if (!empty($phone)) {
            $customer_data['phone'] = $phone;
        }
        
        // Ajout de l'email dans le tableau d'emails
        $email = $customer->get_billing_email();
        if (!empty($email)) {
            $customer_data['emails'] = array($email);
        }
        
        // Adresse de livraison si différente
        if ($customer->get_shipping_address_1() && 
            ($customer->get_shipping_address_1() !== $customer->get_billing_address_1() ||
             $customer->get_shipping_city() !== $customer->get_billing_city())) {
            
            $customer_data['delivery_address'] = array(
                'address' => $customer->get_shipping_address_1() . 
                            (!empty($customer->get_shipping_address_2()) ? "\n" . $customer->get_shipping_address_2() : ''),
                'postal_code' => $customer->get_shipping_postcode(),
                'city' => $customer->get_shipping_city(),
                'country_alpha2' => $customer->get_shipping_country()
            );
        }
        
        // Filtre pour permettre l'ajout ou la modification des données client
        return apply_filters('woo_pennylane_customer_data', $customer_data, $customer);
    }

    /**
     * Valide que toutes les données obligatoires sont présentes avant l'envoi à l'API
     * 
     * @param array $data Données du client formatées
     * @throws Exception Si des données obligatoires sont manquantes
     */
    private function validate_customer_data($data) {
        if (empty($data['first_name'])) {
            throw new Exception(__('Prénom manquant', 'woo-pennylane'));
        }
        
        if (empty($data['last_name'])) {
            throw new Exception(__('Nom manquant', 'woo-pennylane'));
        }
        
        if (empty($data['billing_address']) || 
            empty($data['billing_address']['address']) || 
            empty($data['billing_address']['postal_code']) || 
            empty($data['billing_address']['city']) || 
            empty($data['billing_address']['country_alpha2'])) {
            throw new Exception(__('Adresse de facturation incomplète', 'woo-pennylane'));
        }
        
        // Validation de l'adresse de livraison si présente
        if (!empty($data['delivery_address'])) {
            if (empty($data['delivery_address']['address']) || 
                empty($data['delivery_address']['postal_code']) || 
                empty($data['delivery_address']['city']) || 
                empty($data['delivery_address']['country_alpha2'])) {
                throw new Exception(__('Adresse de livraison incomplète', 'woo-pennylane'));
            }
        }
    }

    /**
     * Vérifie si un client existe déjà dans Pennylane avec la référence externe fournie
     * 
     * @param string $external_reference Référence externe à rechercher
     * @return array|null Données du client si trouvé, null sinon
     */
    public function find_customer_by_external_reference($external_reference) {
        try {
            $response = $this->send_to_api('/individual_customers?external_reference=' . urlencode($external_reference), null, 'GET');
            
            if (!empty($response) && isset($response[0]['id'])) {
                return $response[0];
            }
            
            return null;
        } catch (Exception $e) {
            if (get_option('woo_pennylane_debug_mode') === 'yes') {
                error_log('Pennylane API Error (Find Customer): ' . $e->getMessage());
            }
            return null;
        }
    }

    /**
     * Récupère un client Pennylane par son ID
     */
    public function get_customer($pennylane_id) {
        return $this->send_to_api('/individual_customers/' . $pennylane_id, null, 'GET');
    }

    /**
     * Envoie une requête à l'API Pennylane
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

        if ($data !== null && ($method === 'POST' || $method === 'PUT')) {
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
            error_log('Pennylane API Method: ' . $method);
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