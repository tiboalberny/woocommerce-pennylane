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
                      //  Sauvegarder le statut envoyé
                    $sent_status = $invoice_data['status'] ?? 'unknown'; // Récupérer le statut depuis $invoice_data
                    update_post_meta($order_id, '_pennylane_invoice_status_sent', $sent_status);
                
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
     * Prépare les données de la facture au format Pennylane V2
     *
     * @param WC_Order $order Commande WooCommerce
     * @return array Données de la facture formatées
     * @throws Exception Si le client ne peut pas être déterminé ou synchronisé
     */
    private function prepare_invoice_data($order) {
        $order_id = $order->get_id();

        // --- AJOUT : Lire le statut de création souhaité ---
        $invoice_status_pref = get_option('woo_pennylane_invoice_creation_status', 'draft'); // Lire l'option, défaut 'draft'

        // --- 1. Gestion du Client ---
        $pennylane_customer_id = null;
        $customer_id_wc = $order->get_customer_id(); // ID utilisateur WC (0 si invité)

        if ($customer_id_wc > 0) {
            // Client enregistré
            $pennylane_customer_id = get_user_meta($customer_id_wc, '_pennylane_customer_id', true);
            // Si non trouvé, essayer de le synchroniser maintenant
            if (!$pennylane_customer_id) {
                 try {
                     // Assurer que la classe est chargée
                     if (!class_exists('WooPennylane_Customer_Sync')) {
                         require_once WOO_PENNYLANE_PLUGIN_DIR . 'includes/class-woo-pennylane-customer-sync.php';
                     }
                     $customer_sync = new WooPennylane_Customer_Sync();
                     $customer_sync->sync_customer($customer_id_wc); // Tente la synchronisation
                     $pennylane_customer_id = get_user_meta($customer_id_wc, '_pennylane_customer_id', true); // Re-vérifie l'ID
                     if (!$pennylane_customer_id) {
                         // Si toujours pas d'ID après la tentative, lancer une erreur claire
                         throw new Exception(sprintf(__('Impossible de synchroniser ou récupérer l\'ID Pennylane pour le client enregistré #%d.', 'woo-pennylane'), $customer_id_wc));
                     }
                 } catch (Exception $e) {
                     // Relancer l'exception avec plus de contexte
                     throw new Exception(sprintf(__('Erreur lors de la synchronisation du client enregistré #%d requis pour la facture: %s', 'woo-pennylane'), $customer_id_wc, $e->getMessage()));
                 }
            }
        } else {
            // Client invité
            $guest_email = $order->get_billing_email();
            if (empty($guest_email)) {
                throw new Exception(__('L\'email de facturation est manquant pour ce client invité.', 'woo-pennylane'));
            }
            // Rechercher dans la table des invités
            global $wpdb;
            $guest_table = $wpdb->prefix . 'woo_pennylane_guest_sync';
            $guest_info = $wpdb->get_row($wpdb->prepare("SELECT pennylane_id FROM $guest_table WHERE email = %s AND synced = 1", $guest_email));
            $pennylane_customer_id = $guest_info ? $guest_info->pennylane_id : null;

            // Si non trouvé, essayer de le synchroniser maintenant
            if (!$pennylane_customer_id) {
                 try {
                     // Assurer que la classe est chargée
                     if (!class_exists('WooPennylane_Guest_Customer_Sync')) {
                         require_once WOO_PENNYLANE_PLUGIN_DIR . 'includes/class-woo-pennylane-guest-customer-sync.php';
                     }
                     $guest_sync = new WooPennylane_Guest_Customer_Sync();
                     $guest_sync->sync_guest_customer($guest_email); // Tente la synchro
                     // Re-vérifier après synchro
                     $guest_info = $wpdb->get_row($wpdb->prepare("SELECT pennylane_id FROM $guest_table WHERE email = %s AND synced = 1", $guest_email));
                     $pennylane_customer_id = $guest_info ? $guest_info->pennylane_id : null;
                     if (!$pennylane_customer_id) {
                         // Si toujours pas d'ID après la tentative, lancer une erreur claire
                         throw new Exception(sprintf(__('Impossible de synchroniser ou récupérer l\'ID Pennylane pour le client invité (%s).', 'woo-pennylane'), $guest_email));
                     }
                 } catch (Exception $e) {
                      // Relancer l'exception avec plus de contexte
                      throw new Exception(sprintf(__('Erreur lors de la synchronisation du client invité (%s) requis pour la facture: %s', 'woo-pennylane'), $guest_email, $e->getMessage()));
                 }
            }
        }

        // Vérification finale de l'ID client Pennylane
        if (empty($pennylane_customer_id)) {
             throw new Exception(__('Impossible de déterminer l\'ID client Pennylane pour cette commande après toutes les tentatives.', 'woo-pennylane'));
        }


        // --- 2. Lignes de la facture ---
        $invoice_lines = array();

        // Produits
        foreach ($order->get_items() as $item_id => $item) {
            $product = $item->get_product();
            $quantity = $item->get_quantity();
            if ($quantity <= 0) continue; // Ignorer les lignes avec quantité nulle ou négative?

            // Prix unitaire HT = Total HT de la ligne / Quantité
            $line_subtotal_excl_tax = $item->get_subtotal(); // Total HT de la ligne
            $unit_price_excl_tax = wc_format_decimal($line_subtotal_excl_tax / $quantity, wc_get_price_decimals()); // Prix unitaire HT

            // Taux de TVA
            $vat_rate = $this->get_item_tax_rate($item); // Récupère le taux en pourcentage (ex: 20.0)
            $vat_rate_formatted = 'FR_' . (int)round($vat_rate * 10); // Format Pennylane (Ex: FR_200). Arrondir pour être sûr.

            // ID Produit Pennylane (si synchro produit active)
            $pennylane_product_id = $product ? get_post_meta($product->get_id(), '_pennylane_product_id', true) : null;

            $line_data = array(
                'label'                  => $item->get_name(), // Nom de l'item
                'quantity'               => $quantity,
                'price_before_tax'       => (float) $unit_price_excl_tax,
                'vat_rate'               => $vat_rate_formatted, // Ex: FR_200
            );

            // Ajouter l'ID produit Pennylane seulement s'il existe
            if (!empty($pennylane_product_id)) {
                 $line_data['product_id'] = $pennylane_product_id;
            } else {
                 // Si pas d'ID Pennylane, on peut envoyer la référence SKU ou ID WC comme référence produit externe?
                 // A vérifier si l'API le permet/requiert dans ce cas.
                 $sku = $product ? $product->get_sku() : null;
                 if (!empty($sku)) {
                     $line_data['reference'] = $sku; // Envoyer SKU si pas d'ID Pennylane
                 }
                 // $line_data['external_product_reference'] = $product ? (string) $product->get_id() : null; // Optionnel
            }

            $invoice_lines[] = $line_data;
        }

        // Frais de livraison
        if ((float)$order->get_shipping_total() > 0) {
            $shipping_total_excl_tax = (float)$order->get_shipping_total();
            $shipping_vat_rate = $this->get_shipping_tax_rate($order); // Taux en pourcentage
            $shipping_vat_rate_formatted = 'FR_' . (int)round($shipping_vat_rate * 10); // Format Pennylane

            $invoice_lines[] = array(
                'label'                 => __('Frais de livraison', 'woo-pennylane'),
                'quantity'              => 1,
                'price_before_tax'      => $shipping_total_excl_tax,
                'vat_rate'              => $shipping_vat_rate_formatted,
            );
        }

        // Frais Additionnels
        foreach ($order->get_fees() as $fee_id => $fee) {
             if ((float)$fee->get_total() == 0) continue; // Ignorer frais nuls

            $fee_total_excl_tax = (float)$fee->get_total();
            $fee_tax_total = (float)$fee->get_total_tax();
            // Calculer le taux de TVA pour les frais
            $fee_vat_rate = ($fee_total_excl_tax != 0) ? round(($fee_tax_total / abs($fee_total_excl_tax)) * 100, wc_get_rounding_precision() - 2) : 0;
            $fee_vat_rate_formatted = 'FR_' . (int)round($fee_vat_rate * 10); // Format Pennylane

             $invoice_lines[] = array(
                'label'                 => $fee->get_name(),
                'quantity'              => 1,
                'price_before_tax'      => $fee_total_excl_tax, // Peut être négatif
                'vat_rate'              => $fee_vat_rate_formatted,
            );
        }

        // Remises (représentées comme des lignes négatives)
        // Attention: S'assurer que c'est bien la méthode attendue par Pennylane.
        if ($order->get_total_discount(false) > 0) { // Remise HT
            $discount_total_excl_tax = -abs((float)$order->get_total_discount(false));

            $invoice_lines[] = array(
                'label'                 => __('Remise', 'woo-pennylane'),
                'quantity'              => 1,
                'price_before_tax'      => $discount_total_excl_tax,
                // Hypothèse: la remise n'a pas de TVA propre mais réduit la base taxable globale.
                // Mettre 0% ou le taux moyen? Mettre 0% est plus simple/prudent. A VERIFIER avec Pennylane.
                'vat_rate'              => 'FR_0',
            );
        }


        // --- 4. Construction des données finales pour l'API ---
        // Structure basée sur la documentation API V2 de Pennylane (à vérifier attentivement)
        $invoice_data = array(
            'customer_id'           => $pennylane_customer_id, // ID du client Pennylane (OBLIGATOIRE)
            'currency'              => $order->get_currency(), // Devise (OBLIGATOIRE)
            'issued_on'             => $order->get_date_paid() ? $order->get_date_paid()->format('Y-m-d') : $order->get_date_created()->format('Y-m-d'), // Date d'émission (OBLIGATOIRE)
            'label'                 => sprintf(__('Commande WooCommerce #%s', 'woo-pennylane'), $order->get_order_number()), // Libellé interne (OBLIGATOIRE)
            'external_reference'    => (string)$order_id, // Référence externe (ID commande WC) (Recommandé)
            'invoice_lines_attributes' => $invoice_lines, // Lignes de la facture (OBLIGATOIRE, au moins une ligne)

            // --- AJOUT du statut basé sur le réglage ---
            // VÉRIFIER LE NOM EXACT DU CHAMP ('status', 'state', 'is_draft' ?) ET LES VALEURS ('draft', 'final', true/false) DANS LA DOC API V2
            'status'                => $invoice_status_pref, // 'draft' ou 'final' lu depuis les options

            // --- Champs Optionnels (à vérifier si utiles/corrects) ---
             'notes'                 => $order->get_customer_note(), // Notes client
             'payment_method'        => $order->get_payment_method_title(), // Méthode de paiement
             'metadata'              => [ // Métadonnées personnalisées
                 'woocommerce_order_id' => $order_id,
                 'woocommerce_order_number' => $order->get_order_number(),
                 'payment_via' => $order->get_payment_method() // ID technique du moyen de paiement
             ]
             // 'due_on'                => ??? // Date d'échéance - A calculer si nécessaire (ex: date_emission + 30 jours)
             // 'invoice_number'       => ??? // Si Pennylane ne le génère pas automatiquement pour les factures finales ?
        );

        // Ajouter le code journal et numéro de compte si configurés dans les options
        // Vérifier la structure exacte attendue par l'API V2 (ID? Code? Objet?)
        $journal_code = get_option('woo_pennylane_journal_code', '');
        if (!empty($journal_code)) {
            // Exemple hypothétique : $invoice_data['journal_entry_attributes']['journal_code'] = $journal_code;
        }

        $account_number = get_option('woo_pennylane_account_number', ''); // Compte client par défaut ?
        if (!empty($account_number)) {
             // Exemple hypothétique : $invoice_data['customer_ledger_account_id'] = $account_number;
        }


        // Appliquer un filtre pour permettre des modifications finales juste avant l'envoi
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