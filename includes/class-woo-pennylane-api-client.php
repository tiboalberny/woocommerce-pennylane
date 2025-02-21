<?php
namespace WooPennylane\Api;

class Client {
    private $api_key;
    private $base_url = 'https://api.pennylane.tech/';
    private $api_version = 'v1';

    public function __construct() {
        $this->api_key = get_option('woo_pennylane_api_key');
    }

    public function send_invoice($data) {
        return $this->request('POST', 'invoices', $data);
    }

    public function get_invoice($invoice_id) {
        return $this->request('GET', "invoices/{$invoice_id}");
    }

    public function update_invoice($invoice_id, $data) {
        return $this->request('PUT', "invoices/{$invoice_id}", $data);
    }

    public function delete_invoice($invoice_id) {
        return $this->request('DELETE', "invoices/{$invoice_id}");
    }

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
            $error_message = isset($body['message']) ? $body['message'] : 'Unknown API error';
            throw new \Exception("API Error ({$status_code}): {$error_message}");
        }

        return $body;
    }

    public function validate_api_key() {
        try {
            // Appel simple à l'API pour vérifier la validité de la clé
            $this->request('GET', 'ping');
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

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