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

            // Envoie à l'API Pennylane
            $response = $this->send_to_api('/individual_customers', $customer_data);

            // Met à jour le statut de synchronisation
            update_user_meta($customer_id, '_pennylane_synced', 'yes');
            update_user_meta($customer_id, '_pennylane_customer_id', $response['id']);

            return true;

        } catch (Exception $e) {
            // Log l'erreur
            if (get_option('woo_pennylane_debug_mode') === 'yes') {
                error_log('Pennylane Customer Sync Error (ID #' . $customer_id . '): ' . $e->getMessage());
            }
            throw $e;
        }
    }

    /**
     * Valide que tous les champs obligatoires sont présents
     */
    private function validate_customer_data($data) {
        if (empty($data['first_name'])) {
            throw new Exception(__('Prénom manquant', 'woo-pennylane'));
        }
        
        if (empty($data['last_name'])) {
            throw new Exception(__('Nom manquant', 'woo-pennylane'));
        }
        
        if (empty($data['billing_address'])) {
            throw new Exception(__('Adresse de facturation manquante', 'woo-pennylane'));
        }
    }

    /**
     * Prépare les données du client au format Pennylane
     */
    private function prepare_customer_data($customer) {
        // Email dans un tableau
        $email = $customer->get_billing_email();
        $emails = !empty($email) ? array($email) : array();

        // Adresse de facturation
        $billing_address = array(
            'address' => $customer->get_billing_address_1() . 
                        (!empty($customer->get_billing_address_2()) ? "\n" . $customer->get_billing_address_2() : ''),
            'postal_code' => $customer->get_billing_postcode(),
            'city' => $customer->get_billing_city(),
            'country_alpha2' => $customer->get_billing_country()
        );

        // Construction des données client
        $customer_data = array(
            'first_name' => $customer->get_billing_first_name(),
            'last_name' => $customer->get_billing_last_name(),
            'reference' => 'WC-' . $customer->get_id(),
            'emails' => $emails,
            'billing_address' => $billing_address
        );

        // Ajout du téléphone s'il existe
        $phone = $customer->get_billing_phone();
        if (!empty($phone)) {
            $customer_data['phone'] = $phone;
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

        return $customer_data;
    }

    /**
     * Envoie une requête à l'API Pennylane
     */
    private function send_to_api($endpoint, $data = null) {
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
            CURLOPT_HTTPHEADER => $headers
        );

        if ($data !== null) {
            $curl_options[CURLOPT_CUSTOMREQUEST] = 'POST';
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
            error_log('Pennylane API Request Data: ' . json_encode($data));
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