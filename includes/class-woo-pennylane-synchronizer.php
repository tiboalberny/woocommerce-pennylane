<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Classe responsable de la synchronisation des commandes WooCommerce vers Pennylane
 */
class WooPennylane_Synchronizer {
    /**
     * Clé API Pennylane
     *
     * @var string
     */
    private $api_key;
    
    /**
     * URL de base de l'API Pennylane
     *
     * @var string
     */
    private $api_url = 'https://app.pennylane.com/api/external/v2';
    
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
        $this->api_key = get_option('woo_pennylane_api_key');
        
        // Initialisation du logger si disponible
        global $woo_pennylane_logger;
        if ($woo_pennylane_logger) {
            $this->logger = $woo_pennylane_logger;
        }
    }

    /**
     * Synchronise une commande vers Pennylane
     *
     * @param int $order_id ID de la commande
     * @param string $sync_mode Mode de synchronisation (manual, automatic, webhook)
     * @return bool Succès ou échec
     * @throws Exception En cas d'erreur
     */
    public function sync_order($order_id, $sync_mode = 'manual') {
        $order = wc_get_order($order_id);
        
        if (!$order) {
            throw new Exception(__('Commande introuvable', 'woo-pennylane'));
        }

        // Pour l'historique
        global $woo_pennylane_sync_history;
        $start_time = microtime(true);
        
        try {
            // Prépare les données de la facture
            $invoice_data = $this->prepare_invoice_data($order);

            // Vérifie si la commande a déjà été synchronisée
            $pennylane_id = get_post_meta($order_id, '_pennylane_invoice_id', true);
            
            if ($pennylane_id) {
                // Mise à jour de la facture existante
                $response = $this->send_to_api("invoices/{$pennylane_id}", $invoice_data, 'PUT');
                $action_message = __('Facture mise à jour', 'woo-pennylane');
            } else {
                // Création d'une nouvelle facture
                $response = $this->send_to_api('invoices', $invoice_data);
                $action_message = __('Facture créée', 'woo-pennylane');
                
                // Sauvegarde l'ID Pennylane
                if (isset($response['id'])) {
                    update_post_meta($order_id, '_pennylane_invoice_id', $response['id']);
                }
            }

            // Met à jour le statut de synchronisation
            update_post_meta($order_id, '_pennylane_synced', 'yes');
            update_post_meta($order_id, '_pennylane_last_sync', current_time('mysql'));
            delete_post_meta($order_id, '_pennylane_sync_error');

            // Enregistrer l'événement dans l'historique
            if ($woo_pennylane_sync_history) {
                $execution_time = microtime(true) - $start_time;
                $order_number = $order->get_order_number();
                
                $woo_pennylane_sync_history->add_entry(
                    'order',
                    $sync_mode,
                    $order_id,
                    sprintf(__('Commande #%s', 'woo-pennylane'), $order_number),
                    'success',
                    sprintf(__('%s avec Pennylane ID: %s', 'woo-pennylane'), 
                        $action_message, 
                        isset($response['id']) ? $response['id'] : $pennylane_id),
                    $execution_time
                );
            }
            
            // Log de succès
            if ($this->logger) {
                $this->logger->info(sprintf(
                    'Commande #%d synchronisée avec succès vers Pennylane',
                    $order_id
                ));
            }

            return true;

        } catch (Exception $e) {
            // Log l'erreur
            if ($this->logger) {
                $this->logger->error(sprintf(
                    'Erreur lors de la synchronisation de la commande #%d: %s',
                    $order_id,
                    $e->getMessage()
                ));
            }
            
            // Enregistre l'erreur dans les métadonnées de la commande
            update_post_meta($order_id, '_pennylane_sync_error', $e->getMessage());
            update_post_meta($order_id, '_pennylane_last_sync', current_time('mysql'));
            
            // Enregistrer l'événement dans l'historique
            if ($woo_pennylane_sync_history) {
                $execution_time = microtime(true) - $start_time;
                $order_number = $order ? $order->get_order_number() : $order_id;
                
                $woo_pennylane_sync_history->add_entry(
                    'order',
                    $sync_mode,
                    $order_id,
                    sprintf(__('Commande #%s', 'woo-pennylane'), $order_number),
                    'error',
                    $e->getMessage(),
                    $execution_time
                );
            }
            
            throw $e;
        }
    }

    /**
     * Prépare les données de la facture au format Pennylane
     *
     * @param WC_Order $order Commande WooCommerce
     * @return array Données de la facture formatées
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
        
        // Remises
        if ($order->get_discount_total() > 0) {
            $line_items[] = array(
                'description' => __('Remise', 'woo-pennylane'),
                'quantity' => 1,
                'unit_price_without_vat' => -1 * $order->get_discount_total(),
                'vat_rate' => 0,
                'category' => 'DISCOUNT'
            );
        }

        // Données de la facture
        $invoice_data = array(
            'customer' => $customer,
            'date' => $order->get_date_created()->format('Y-m-d'),
            'currency' => $order->get_currency(),
            'ref' => $order->get_order_number(),
            'items' => $line_items,
            'payment_method' => $order->get_payment_method_title(),
            'notes' => $order->get_customer_note()
        );
        
        // Journal code et compte comptable
        $journal_code = get_option('woo_pennylane_journal_code', '');
        if (!empty($journal_code)) {
            $invoice_data['journal_code'] = $journal_code;
        }
        
        $account_number = get_option('woo_pennylane_account_number', '');
        if (!empty($account_number)) {
            $invoice_data['account_number'] = $account_number;
        }

        return apply_filters('woo_pennylane_invoice_data', $invoice_data, $order);
    }

    /**
     * Calcule le taux de TVA d'un article
     *
     * @param WC_Order_Item $item Ligne de commande
     * @return float Taux de TVA
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
     *
     * @param WC_Order $order Commande
     * @return float Taux de TVA
     */
    private function get_shipping_tax_rate($order) {
        $shipping_tax = $order->get_shipping_tax();
        $shipping_total = $order->get_shipping_total();

        return $shipping_total > 0 ? round(($shipping_tax / $shipping_total) * 100, 2) : 0;
    }

    /**
     * Envoie une requête à l'API Pennylane
     *
     * @param string $endpoint Point de terminaison
     * @param array $data Données à envoyer
     * @param string $method Méthode HTTP (POST par défaut)
     * @return array Réponse de l'API
     * @throws Exception En cas d'erreur
     */
    private function send_to_api($endpoint, $data = null, $method = 'POST') {
        if (empty($this->api_key)) {
            throw new Exception(__('Clé API non configurée', 'woo-pennylane'));
        }

        $url = $this->api_url . '/' . trim($endpoint, '/');
        
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
            $curl_options[CURLOPT_CUSTOMREQUEST] = $method;
            $curl_options[CURLOPT_POSTFIELDS] = json_encode($data);
        } else {
            $curl_options[CURLOPT_CUSTOMREQUEST] = $method;
        }

        curl_setopt_array($curl, $curl_options);

        $response = curl_exec($curl);
        $err = curl_error($curl);
        $http_code = curl_getinfo($curl, CURLINFO_HTTP_CODE);

        curl_close($curl);

        // Log en mode debug
        if (get_option('woo_pennylane_debug_mode') === 'yes') {
            error_log('Pennylane API Request: ' . $url);
            error_log('Pennylane API Request Method: ' . $method);
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

    /**
     * Ajoute une méta box sur la page de détail d'une commande
     */
    public function add_order_meta_box() {
        add_meta_box(
            'pennylane_order_sync',
            __('Synchronisation Pennylane', 'woo-pennylane'),
            array($this, 'render_order_meta_box'),
            'shop_order',
            'side',
            'default'
        );
    }

    /**
     * Affiche le contenu de la méta box
     *
     * @param WP_Post $post Post WordPress
     */
    public function render_order_meta_box($post) {
        $order_id = $post->ID;
        $synced = get_post_meta($order_id, '_pennylane_synced', true) === 'yes';
        $pennylane_id = get_post_meta($order_id, '_pennylane_invoice_id', true);
        $error = get_post_meta($order_id, '_pennylane_sync_error', true);
        $last_sync = get_post_meta($order_id, '_pennylane_last_sync', true);
        
        wp_nonce_field('pennylane_order_sync_nonce', 'pennylane_order_sync_nonce');
        
        ?>
        <div class="pennylane-order-sync">
            <?php if ($synced && $pennylane_id) : ?>
                <p>
                    <span class="pennylane-synced"><?php _e('Synchronisé', 'woo-pennylane'); ?></span>
                    <br>
                    <?php _e('ID Pennylane:', 'woo-pennylane'); ?> <strong><?php echo esc_html($pennylane_id); ?></strong>
                </p>
                
                <?php if ($last_sync) : ?>
                    <p>
                        <?php _e('Dernière synchronisation:', 'woo-pennylane'); ?> 
                        <span title="<?php echo esc_attr($last_sync); ?>">
                            <?php echo esc_html(human_time_diff(strtotime($last_sync), current_time('timestamp'))); ?> <?php _e('ago', 'woo-pennylane'); ?>
                        </span>
                    </p>
                <?php endif; ?>
            <?php else : ?>
                <p><span class="pennylane-not-synced"><?php _e('Non synchronisé', 'woo-pennylane'); ?></span></p>
                
                <?php if ($error) : ?>
                    <div class="pennylane-sync-error">
                        <p><strong><?php _e('Erreur de synchronisation:', 'woo-pennylane'); ?></strong></p>
                        <p><?php echo esc_html($error); ?></p>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
            
            <p>
                <button type="button" class="button" id="pennylane-sync-order" data-order-id="<?php echo esc_attr($order_id); ?>" data-nonce="<?php echo wp_create_nonce('pennylane_sync_order'); ?>">
                    <?php _e('Synchroniser maintenant', 'woo-pennylane'); ?>
                </button>
                <span class="spinner" style="float: none; margin-top: 0;"></span>
                <span class="sync-result"></span>
            </p>
        </div>
        
        <script type="text/javascript">
        jQuery(document).ready(function($) {
            $('#pennylane-sync-order').on('click', function() {
                var button = $(this);
                var orderId = button.data('order-id');
                var nonce = button.data('nonce');
                var spinner = button.next('.spinner');
                var result = spinner.next('.sync-result');
                
                button.prop('disabled', true);
                spinner.addClass('is-active');
                result.html('');
                
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'woo_pennylane_sync_order',
                        order_id: orderId,
                        nonce: nonce
                    },
                    success: function(response) {
                        if (response.success) {
                            result.html('<span class="pennylane-success">' + response.data.message + '</span>');
                            // Recharger la page pour mettre à jour les informations
                            location.reload();
                        } else {
                            result.html('<span class="pennylane-error">' + response.data + '</span>');
                        }
                    },
                    error: function() {
                        result.html('<span class="pennylane-error"><?php _e('Erreur de communication avec le serveur', 'woo-pennylane'); ?></span>');
                    },
                    complete: function() {
                        button.prop('disabled', false);
                        spinner.removeClass('is-active');
                    }
                });
            });
        });
        </script>
        
        <style>
        .pennylane-synced {
            color: #46b450;
            font-weight: bold;
        }
        .pennylane-not-synced {
            color: #dc3232;
            font-weight: bold;
        }
        .pennylane-sync-error {
            background: #fbeaea;
            border-left: 4px solid #dc3232;
            padding: 5px 10px;
            margin: 5px 0;
        }
        .pennylane-success {
            color: #46b450;
        }
        .pennylane-error {
            color: #dc3232;
        }
        </style>
        <?php
    }

    /**
     * Synchronise une commande via AJAX
     */
    public function ajax_sync_order() {
        // Vérification du nonce
        check_ajax_referer('pennylane_sync_order', 'nonce');
        
        // Vérification des permissions
        if (!current_user_can('edit_shop_orders')) {
            wp_send_json_error(__('Permission refusée.', 'woo-pennylane'));
            return;
        }
        
        // Récupération de l'ID de la commande
        $order_id = isset($_POST['order_id']) ? intval($_POST['order_id']) : 0;
        
        if (!$order_id) {
            wp_send_json_error(__('ID de commande invalide.', 'woo-pennylane'));
            return;
        }
        
        try {
            // Synchronisation de la commande
            $this->sync_order($order_id, 'manual');
            
            // Succès
            wp_send_json_success(array(
                'message' => sprintf(__('Commande #%d synchronisée avec succès.', 'woo-pennylane'), $order_id)
            ));
            
        } catch (Exception $e) {
            wp_send_json_error($e->getMessage());
        }
    }

    /**
     * Gère la synchronisation automatique lors du changement de statut d'une commande
     *
     * @param int $order_id ID de la commande
     * @param string $old_status Ancien statut
     * @param string $new_status Nouveau statut
     */
    public function sync_on_status_change($order_id, $old_status, $new_status) {
        // Vérifier si la synchronisation automatique est activée
        if (get_option('woo_pennylane_auto_sync') !== 'yes') {
            return;
        }
        
        // Récupérer les statuts qui déclenchent la synchronisation
        $sync_statuses = get_option('woo_pennylane_sync_status', array('completed'));
        
        // Si le nouveau statut fait partie des statuts de synchronisation
        if (in_array($new_status, $sync_statuses)) {
            try {
                $this->sync_order($order_id, 'automatic');
                
                if ($this->logger) {
                    $this->logger->info(sprintf(
                        'Commande #%d synchronisée automatiquement suite au changement de statut vers %s',
                        $order_id,
                        $new_status
                    ));
                }
            } catch (Exception $e) {
                if ($this->logger) {
                    $this->logger->error(sprintf(
                        'Erreur lors de la synchronisation automatique de la commande #%d: %s',
                        $order_id,
                        $e->getMessage()
                    ));
                }
            }
        }
    }

    /**
     * Ajoute un bouton de synchronisation sur la liste des commandes
     *
     * @param array $actions Actions existantes
     * @param WC_Order $order Commande
     * @return array Actions mises à jour
     */
    public function add_order_list_actions($actions, $order) {
        $synced = get_post_meta($order->get_id(), '_pennylane_synced', true) === 'yes';
        
        $sync_url = wp_nonce_url(
            admin_url('admin-ajax.php?action=woo_pennylane_sync_order_list&order_id=' . $order->get_id()),
            'woo_pennylane_sync_order_list_' . $order->get_id(),
            'nonce'
        );
        
        $action_text = $synced 
            ? __('Resynchroniser vers Pennylane', 'woo-pennylane')
            : __('Synchroniser vers Pennylane', 'woo-pennylane');
        
        $actions['pennylane_sync'] = array(
            'url'    => $sync_url,
            'name'   => $action_text,
            'action' => 'pennylane-sync',
        );
        
        return $actions;
    }

    /**
     * Synchronise une commande depuis la liste des commandes
     */
    public function sync_from_order_list() {
        // Vérification du nonce
        $order_id = isset($_GET['order_id']) ? intval($_GET['order_id']) : 0;
        if (!wp_verify_nonce($_GET['nonce'], 'woo_pennylane_sync_order_list_' . $order_id)) {
            wp_die(__('Action non autorisée.', 'woo-pennylane'));
        }
        
        // Vérification des permissions
        if (!current_user_can('edit_shop_orders')) {
            wp_die(__('Vous n\'avez pas les permissions nécessaires.', 'woo-pennylane'));
        }
        
        // Synchronisation
        try {
            $this->sync_order($order_id, 'manual');
            
            // Message de succès
            WC_Admin_Notices::add_custom_notice(
                'pennylane_sync_success',
                sprintf(__('Commande #%d synchronisée avec succès vers Pennylane.', 'woo-pennylane'), $order_id)
            );
        } catch (Exception $e) {
            // Message d'erreur
            WC_Admin_Notices::add_custom_notice(
                'pennylane_sync_error',
                sprintf(__('Erreur lors de la synchronisation de la commande #%d: %s', 'woo-pennylane'), $order_id, $e->getMessage())
            );
        }
        
        // Redirection vers la liste des commandes
        wp_redirect(admin_url('edit.php?post_type=shop_order'));
        exit;
    }

    /**
     * Ajoute une colonne dans la liste des commandes
     *
     * @param array $columns Colonnes existantes
     * @return array Colonnes mises à jour
     */
    public function add_order_list_column($columns) {
        $new_columns = array();
        
        foreach ($columns as $column_name => $column_info) {
            $new_columns[$column_name] = $column_info;
            
            // Ajouter après la colonne de statut
            if ($column_name === 'order_status') {
                $new_columns['pennylane_status'] = __('Pennylane', 'woo-pennylane');
            }
        }
        
        return $new_columns;
    }

    /**
     * Affiche le contenu de la colonne personnalisée
     *
     * @param string $column Nom de la colonne
     */
    public function render_order_list_column($column) {
        global $post;
        
        if ($column === 'pennylane_status') {
            $order_id = $post->ID;
            $synced = get_post_meta($order_id, '_pennylane_synced', true) === 'yes';
            $pennylane_id = get_post_meta($order_id, '_pennylane_invoice_id', true);
            $error = get_post_meta($order_id, '_pennylane_sync_error', true);
            
            if ($synced && $pennylane_id) {
                echo '<span class="dashicons dashicons-yes" style="color: #46b450;" title="' . esc_attr(__('Synchronisé', 'woo-pennylane')) . '"></span> ';
                echo esc_html($pennylane_id);
            } elseif ($error) {
                echo '<span class="dashicons dashicons-warning" style="color: #dc3232;" title="' . esc_attr($error) . '"></span>';
            } else {
                echo '<span class="dashicons dashicons-minus" style="color: #999;" title="' . esc_attr(__('Non synchronisé', 'woo-pennylane')) . '"></span>';
            }
        }
    }

    /**
     * Ajoute des actions en masse pour les commandes
     *
     * @param array $actions Actions existantes
     * @return array Actions mises à jour
     */
    public function add_bulk_actions($actions) {
        $actions['pennylane_sync'] = __('Synchroniser vers Pennylane', 'woo-pennylane');
        return $actions;
    }

    /**
     * Traite les actions en masse
     *
     * @param string $redirect_to URL de redirection
     * @param string $action Action sélectionnée
     * @param array $post_ids IDs des commandes sélectionnées
     * @return string URL de redirection mise à jour
     */
    public function handle_bulk_actions($redirect_to, $action, $post_ids) {
        if ($action !== 'pennylane_sync') {
            return $redirect_to;
        }
        
        $processed = 0;
        $success = 0;
        $error = 0;
        
        foreach ($post_ids as $post_id) {
            try {
                $this->sync_order($post_id, 'manual');
                $success++;
            } catch (Exception $e) {
                $error++;
            }
            $processed++;
        }
        
        return add_query_arg(
            array(
                'pennylane_bulk_sync' => '1',
                'processed' => $processed,
                'success' => $success,
                'error' => $error
            ),
            $redirect_to
        );
    }

    /**
     * Affiche un message après les actions en masse
     */
    public function bulk_action_admin_notice() {
        if (empty($_REQUEST['pennylane_bulk_sync'])) {
            return;
        }
        
        $success = isset($_REQUEST['success']) ? intval($_REQUEST['success']) : 0;
        $error = isset($_REQUEST['error']) ? intval($_REQUEST['error']) : 0;
        
        if ($success > 0) {
            $message = sprintf(
                /* translators: %d: number of orders */
                _n(
                    '%d commande synchronisée avec succès vers Pennylane.',
                    '%d commandes synchronisées avec succès vers Pennylane.',
                    $success,
                    'woo-pennylane'
                ),
                $success
            );
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html($message) . '</p></div>';
        }
        
        if ($error > 0) {
            $message = sprintf(
                /* translators: %d: number of orders */
                _n(
                    'Échec de la synchronisation de %d commande vers Pennylane.',
                    'Échec de la synchronisation de %d commandes vers Pennylane.',
                    $error,
                    'woo-pennylane'
                ),
                $error
            );
            echo '<div class="notice notice-error is-dismissible"><p>' . esc_html($message) . '</p></div>';
        }
    }
}