<?php
namespace WooPennylane\Api;

class Client {
    private $api_key;
    private $base_url = 'https://app.pennylane.com/api/external/';
    private $api_version = 'v2';

    public function __construct() {
        $this->api_key = get_option('woo_pennylane_api_key');
    }

    public function send_invoice($data) {
        return $this->request('POST', 'customer_invoices', $data);
    }

    public function get_invoice($invoice_id) {
        return $this->request('GET', "customer_invoices/{$invoice_id}");
    }

    public function update_invoice($invoice_id, $data) {
        return $this->request('PUT', "customer_invoices/{$invoice_id}", $data);
    }

    // TODO: Investigate the correct V2 API method for deleting/archiving invoices.
    // The V2 API documentation (https://pennylane.readme.io/reference/delete_draft_invoices-draft_invoice_id)
    // shows deletion for draft invoices, but not directly for finalized customer invoices.
    // Consider using an archive function or updating status if available.
    /*
    public function delete_invoice($invoice_id) {
        return $this->request('DELETE', "customer_invoices/{$invoice_id}");
    }
    */

    private function request($method, $endpoint, $data = null) {
        if (empty($this->api_key)) {
            throw new \Exception('API key not configured');
        }

        $url = $this->base_url . $this->api_version . '/' . trim($endpoint, '/');

        $args = array(
            'method' => $method,
            'headers' => array(
                'Authorization' => 'Bearer ' . $this->api_key,
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
                'User-Agent' => 'WooCommerce Pennylane Integration/' . WOO_PENNYLANE_VERSION
            ),
            'timeout' => 30
        );

        if ($data !== null) {
            $args['body'] = json_encode($data);
        }

        $response = wp_remote_request($url, $args);

        if (is_wp_error($response)) {
            throw new \Exception($response->get_error_message());
        }

        $status_code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);

        if ($status_code >= 400) {
            $error_detail = 'Unknown API error';
            if (isset($body['message'])) {
                $error_detail = $body['message'];
            } elseif (isset($body['error'])) {
                $error_detail = $body['error'];
            } elseif (isset($body['detail'])) {
                $error_detail = $body['detail'];
            } elseif (is_string($body)) { // Si le corps n'est pas un JSON mais une chaîne (erreur brute)
                $error_detail = $body;
            }
            throw new \Exception("API Error ({$status_code}): {$error_detail}");
        }

        return $body;
    }

    public function validate_api_key() {
        try {
            // Appel simple à l'API pour vérifier la validité de la clé (ex: récupérer le profil utilisateur)
            $this->request('GET', 'user_profile');
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    // --- Customer Methods ---
    public function create_individual_customer($data) {
        return $this->request('POST', 'individual_customers', $data);
    }

    public function update_individual_customer($customer_id, $data) {
        return $this->request('PUT', "individual_customers/{$customer_id}", $data);
    }

    public function find_individual_customer_by_external_reference($external_reference) {
        return $this->request('GET', 'individual_customers?external_reference=' . urlencode($external_reference));
    }

    public function get_individual_customer($customer_id) {
        return $this->request('GET', "individual_customers/{$customer_id}");
    }

    public function find_individual_customer_by_email($email) {
        return $this->request('GET', 'individual_customers?email=' . urlencode($email));
    }
    // --- End Customer Methods ---

    // --- Product Methods ---
    public function create_product($data) {
        return $this->request('POST', 'products', $data);
    }

    public function update_product($product_id, $data) {
        return $this->request('PUT', "products/{$product_id}", $data);
    }
    // --- End Product Methods ---

    public function get_debug_info() {
        return array(
            'api_key_configured' => !empty($this->api_key),
            'api_version' => $this->api_version,
            'wordpress_version' => get_bloginfo('version'),
            'woocommerce_version' => WC()->version,
            'plugin_version' => WOO_PENNYLANE_VERSION
        );
    }
    
}