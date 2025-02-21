<?php
if (!defined('ABSPATH')) {
    exit;
}

class WooPennylane_Synchronizer {
    private $api_key;
    private $api_url = 'https://app.pennylane.com/api/external/v2';

    public function __construct() {
        $this->api_key = get_option('woo_pennylane_api_key');
    }

    /**
     * Synchronise une commande vers Pennylane
     */
    public function sync_order($order_id) {
        $order = wc_get_order($order_id);
        
        if (!$order) {
            throw new Exception(__('Commande introuvable', 'woo-pennylane'));
        }

        try {
            // Prépare les données de la facture
            $invoice_data = $this->prepare_invoice_data($order);

            // Envoie à l'API Pennylane
            $response = $this->send_to_api('/invoices', $invoice_data);

            // Met à jour le statut de synchronisation
            update_post_meta($order_id, '_pennylane_synced', 'yes');
            update_post_meta($order_id, '_pennylane_invoice_id', $response['id']);

            return true;

        } catch (Exception $e) {
            // Log l'erreur
            if (get_option('woo_pennylane_debug_mode') === 'yes') {
                error_log('Pennylane Sync Error (Order #' . $order_id . '): ' . $e->getMessage());
            }
            throw $e;
        }
    }

    /**
     * Prépare les données de la facture au format Pennylane
     */
    private function prepare_invoice_data($order) {
        // Information client
        $customer = array(
            'name' => $order->get_billing_first_name() . ' ' . $order->get_billing_last_name(),
            'email' => $order->get_billing_email(),
            'address' => array(
                'street' => $order->get_billing_address_1(),
                'street_line_2' => $order->get_billing_address_2(),
                'postal_code' => $order->get_billing_postcode(),
                'city' => $order->get_billing_city(),
                'country' => $order->get_billing_country()
            )
        );

        // Lignes de la facture
        $line_items = array();
        
        // Produits
        foreach ($order->get_items() as $item) {
            $product = $item->get_product();
            $unit_price = $order->get_item_total($item, false, false);
            $tax_rate = $this->get_item_tax_rate($item);

            $line_items[] = array(
                'description' => $item->get_name(),
                'quantity' => $item->get_quantity(),
                'unit_price_without_vat' => $unit_price,
                'vat_rate' => $tax_rate,
                'product_id' => $product ? $product->get_id() : null,
                'sku' => $product ? $product->get_sku() : '',
                'category' => 'GOODS'
            );
        }

        // Frais de livraison
        if ($order->get_shipping_total() > 0) {
            $shipping_tax_rate = $this->get_shipping_tax_rate($order);
            $line_items[] = array(
                'description' => __('Frais de livraison', 'woo-pennylane'),
                'quantity' => 1,
                'unit_price_without_vat' => $order->get_shipping_total(),
                'vat_rate' => $shipping_tax_rate,
                'category' => 'SHIPPING'
            );
        }

        return array(
            'customer' => $customer,
            'date' => $order->get_date_created()->format('Y-m-d'),
            'currency' => $order->get_currency(),
            'ref' => $order->get_order_number(),
            'items' => $line_items,
            'payment_method' => $order->get_payment_method_title(),
            'notes' => $order->get_customer_note()
        );
    }

    /**
     * Calcule le taux de TVA d'un article
     */
    private function get_item_tax_rate($item) {
        $tax_items = $item->get_taxes();
        if (empty($tax_items['total'])) {
            return 0;
        }

        $total_tax = 0;
        $total_net = $item->get_total();

        foreach ($tax_items['total'] as $tax_id => $tax_total) {
            $total_tax += (float) $tax_total;
        }

        return $total_net > 0 ? round(($total_tax / $total_net) * 100, 2) : 0;
    }

    /**
     * Calcule le taux de TVA des frais de livraison
     */
    private function get_shipping_tax_rate($order) {
        $shipping_tax = $order->get_shipping_tax();
        $shipping_total = $order->get_shipping_total();

        return $shipping_total > 0 ? round(($shipping_tax / $shipping_total) * 100, 2) : 0;
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

        // Log en mode debug
        if (get_option('woo_pennylane_debug_mode') === 'yes') {
            error_log('Pennylane API Request: ' . $url);
            error_log('Pennylane API Request Data: ' . json_encode($data));
            error_log('Pennylane API Response Code: ' . $http_code);
            error_log('Pennylane API Response: ' . $response);
            if ($err) {
                error_log('Pennylane API Error: ' . $err);
            }
        }

        if ($err) {
            throw new Exception('Erreur cURL: ' . $err);
        }

        $response_data = json_decode($response, true);

        if ($http_code >= 400) {
            throw new Exception(
                isset($response_data['message']) 
                    ? $response_data['message'] 
                    : 'Erreur API (HTTP ' . $http_code . ')'
            );
        }

        return $response_data;
    }
}