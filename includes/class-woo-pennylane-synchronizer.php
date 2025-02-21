<?php
namespace WooPennylane\Integration;

use WooPennylane\Api\Client;

class Synchronizer {
    private $api_client;
    private $logger;

    public function __construct() {
        $this->api_client = new Client();
        $this->logger = wc_get_logger();

        // Hook pour les nouvelles commandes
        add_action('woocommerce_order_status_completed', array($this, 'sync_order'));
        
        // Hook pour la resynchronisation manuelle
        add_action('wp_ajax_woo_pennylane_resync_order', array($this, 'handle_manual_sync'));
        
        // Ajout du bouton de resync dans l'admin
        add_action('woocommerce_admin_order_actions_end', array($this, 'add_resync_button'));
    }

    public function sync_order($order_id) {
        $order = wc_get_order($order_id);
        
        if (!$order) {
            $this->log("Erreur: Commande #$order_id introuvable", 'error');
            return false;
        }

        // Vérifie si la commande a déjà été synchronisée
        if ($order->get_meta('_pennylane_synced') === 'yes') {
            $this->log("Commande #$order_id déjà synchronisée", 'notice');
            return true;
        }

        try {
            $invoice_data = $this->prepare_invoice_data($order);
            $response = $this->api_client->send_invoice($invoice_data);

            if (isset($response['id'])) {
                $order->add_meta_data('_pennylane_synced', 'yes', true);
                $order->add_meta_data('_pennylane_invoice_id', $response['id'], true);
                $order->save();

                $this->log("Commande #$order_id synchronisée avec succès", 'info');
                return true;
            } else {
                throw new \Exception('Réponse API invalide');
            }
        } catch (\Exception $e) {
            $this->log("Erreur lors de la synchronisation de la commande #$order_id: " . $e->getMessage(), 'error');
            return false;
        }
    }

    private function prepare_invoice_data($order) {
        // Informations client
        $customer = array(
            'name' => $order->get_billing_first_name() . ' ' . $order->get_billing_last_name(),
            'email' => $order->get_billing_email(),
            'address' => array(
                'street' => $order->get_billing_address_1(),
                'street2' => $order->get_billing_address_2(),
                'city' => $order->get_billing_city(),
                'state' => $order->get_billing_state(),
                'postal_code' => $order->get_billing_postcode(),
                'country' => $order->get_billing_country()
            )
        );

        // Lignes de facture
        $line_items = array();
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
                'account_number' => get_option('woo_pennylane_account_number')
            );
        }

        // Frais de livraison
        if ($order->get_shipping_total() > 0) {
            $shipping_tax_rate = $this->get_shipping_tax_rate($order);
            $line_items[] = array(
                'description' => __('Shipping', 'woo-pennylane'),
                'quantity' => 1,
                'unit_price_without_vat' => $order->get_shipping_total(),
                'vat_rate' => $shipping_tax_rate,
                'account_number' => get_option('woo_pennylane_shipping_account_number', get_option('woo_pennylane_account_number'))
            );
        }

        return array(
            'customer' => $customer,
            'journal_code' => get_option('woo_pennylane_journal_code'),
            'date' => $order->get_date_created()->format('Y-m-d'),
            'due_date' => $order->get_date_created()->modify('+30 days')->format('Y-m-d'),
            'invoice_number' => $order->get_order_number(),
            'currency' => $order->get_currency(),
            'line_items' => $line_items,
            'payment_method' => $order->get_payment_method_title(),
            'notes' => $order->get_customer_note(),
            'reference' => 'WC-' . $order->get_id(),
            'total_without_vat' => $order->get_total() - $order->get_total_tax(),
            'total_vat' => $order->get_total_tax(),
            'total_with_vat' => $order->get_total()
        );
    }

    private function get_item_tax_rate($item) {
        $tax_items = $item->get_taxes();
        if (empty($tax_items['total'])) {
            return 0;
        }

        $tax_rate = 0;
        foreach ($tax_items['total'] as $tax_id => $tax_amount) {
            if ($tax_amount > 0) {
                $rate = \WC_Tax::_get_tax_rate($tax_id);
                $tax_rate += floatval($rate['tax_rate']);
            }
        }
        
        return $tax_rate;
    }

    private function get_shipping_tax_rate($order) {
        $shipping_tax_total = $order->get_shipping_tax();
        $shipping_total = $order->get_shipping_total();

        if ($shipping_total > 0 && $shipping_tax_total > 0) {
            return ($shipping_tax_total / $shipping_total) * 100;
        }

        return 0;
    }

    public function handle_manual_sync() {
        if (!current_user_can('manage_woocommerce')) {
            wp_die(-1);
        }

        $order_id = isset($_POST['order_id']) ? absint($_POST['order_id']) : 0;
        $nonce = isset($_POST['nonce']) ? sanitize_text_field($_POST['nonce']) : '';

        if (!wp_verify_nonce($nonce, 'woo_pennylane_resync_' . $order_id)) {
            wp_die(-1);
        }

        $result = $this->sync_order($order_id);

        wp_send_json(array(
            'success' => $result,
            'message' => $result 
                ? __('Order successfully synchronized', 'woo-pennylane')
                : __('Synchronization failed', 'woo-pennylane')
        ));
    }

    public function add_resync_button($order) {
        ?>
        <button type="button" 
                class="button woo-pennylane-resync" 
                data-order-id="<?php echo esc_attr($order->get_id()); ?>"
                data-nonce="<?php echo wp_create_nonce('woo_pennylane_resync_' . $order->get_id()); ?>">
            <?php _e('Sync to Pennylane', 'woo-pennylane'); ?>
        </button>
        <?php
    }

    private function log($message, $level = 'info') {
        if (get_option('woo_pennylane_debug_mode') === 'yes') {
            $this->logger->log($level, $message, array('source' => 'woo-pennylane'));
        }
    }
}