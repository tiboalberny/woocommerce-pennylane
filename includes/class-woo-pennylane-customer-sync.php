<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Classe responsable de la synchronisation des clients WooCommerce vers Pennylane
 */
class WooPennylane_Customer_Sync {
    /**
     * API Client
     *
     * @var WooPennylane\Api\Client
     */
    private $api_client;

    /**
     * API URL
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
        // Récupération de la clé API
        $this->api_key = get_option('woo_pennylane_api_key');
        
        // Initialisation du logger si disponible
        global $woo_pennylane_logger;
        if ($woo_pennylane_logger) {
            $this->logger = $woo_pennylane_logger;
        }
    }

    /**
     * Synchronise un client vers Pennylane
     * 
     * @param int $customer_id ID du client
     * @param string $sync_mode Mode de synchronisation (manual, automatic, cron)
     * @return bool Succès ou échec
     * @throws Exception En cas d'erreur
     */
    public function sync_customer($customer_id, $sync_mode = 'manual') {
        $customer = new WC_Customer($customer_id);
        
        if (!$customer || !$customer->get_id()) {
            throw new Exception(__('Client introuvable', 'woo-pennylane'));
        }

        // Pour l'historique
        global $woo_pennylane_sync_history;
        $start_time = microtime(true);
        
        try {
            // Prépare les données du client
            $customer_data = $this->prepare_customer_data($customer);

            // Vérifie les champs obligatoires
            $this->validate_customer_data($customer_data);

            // Vérifie si le client existe déjà dans Pennylane
            $pennylane_id = get_user_meta($customer_id, '_pennylane_customer_id', true);
            
            if ($pennylane_id) {
                // Mise à jour du client existant (à adapter selon l'API Pennylane)
                $response = $this->send_to_api('/individual_customers/' . $pennylane_id, $customer_data, 'PUT');
                $action_message = __('Client mis à jour', 'woo-pennylane');
            } else {
                // Création d'un nouveau client
                $response = $this->send_to_api('/individual_customers', $customer_data);
                $action_message = __('Client créé', 'woo-pennylane');
                
                // Sauvegarde l'ID Pennylane
                if (isset($response['id'])) {
                    update_user_meta($customer_id, '_pennylane_customer_id', $response['id']);
                }
            }

            // Met à jour le statut de synchronisation
            update_user_meta($customer_id, '_pennylane_synced', 'yes');
            update_user_meta($customer_id, '_pennylane_last_sync', current_time('mysql'));
            delete_user_meta($customer_id, '_pennylane_sync_error');

            // Enregistrer l'événement dans l'historique
            if ($woo_pennylane_sync_history) {
                $execution_time = microtime(true) - $start_time;
                $customer_name = $customer->get_first_name() . ' ' . $customer->get_last_name();
                if (empty(trim($customer_name))) {
                    $customer_name = $customer->get_username();
                }
                
                $woo_pennylane_sync_history->add_entry(
                    'customer',
                    $sync_mode,
                    $customer_id,
                    $customer_name,
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
                    'Client #%d (%s) synchronisé avec succès vers Pennylane',
                    $customer_id,
                    $customer->get_username()
                ));
            }

            return true;

        } catch (Exception $e) {
            // Log l'erreur
            if ($this->logger) {
                $this->logger->error(sprintf(
                    'Erreur lors de la synchronisation du client #%d: %s',
                    $customer_id,
                    $e->getMessage()
                ));
            }
            
            // Enregistre l'erreur dans les métadonnées du client
            update_user_meta($customer_id, '_pennylane_sync_error', $e->getMessage());
            update_user_meta($customer_id, '_pennylane_last_sync', current_time('mysql'));
            
            // Enregistrer l'événement dans l'historique
            if ($woo_pennylane_sync_history) {
                $execution_time = microtime(true) - $start_time;
                $customer_name = $customer->get_first_name() . ' ' . $customer->get_last_name();
                if (empty(trim($customer_name))) {
                    $customer_name = $customer->get_username();
                }
                
                $woo_pennylane_sync_history->add_entry(
                    'customer',
                    $sync_mode,
                    $customer_id,
                    $customer_name,
                    'error',
                    $e->getMessage(),
                    $execution_time
                );
            }
            
            throw $e;
        }
    }

    /**
     * Valide que tous les champs obligatoires sont présents
     *
     * @param array $data Données du client
     * @throws Exception Si des champs obligatoires sont manquants
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
     *
     * @param WC_Customer $customer Client WooCommerce
     * @return array Données formatées pour l'API Pennylane
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
        
        // Numéro de TVA si disponible (champ personnalisé)
        $vat_number = get_user_meta($customer->get_id(), 'vat_number', true);
        if (!empty($vat_number)) {
            $customer_data['vat_number'] = $vat_number;
        }

        return apply_filters('woo_pennylane_customer_data', $customer_data, $customer);
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
     * Ajoute un bouton de synchronisation sur la page d'édition d'un utilisateur
     *
     * @param WP_User $user Utilisateur WordPress
     */
    public function add_sync_button($user) {
        // Vérifier si c'est un client
        if (!user_can($user->ID, 'customer') && !wc_customer_bought_product('', $user->ID, '')) {
            return;
        }
        
        $synced = get_user_meta($user->ID, '_pennylane_synced', true) === 'yes';
        $pennylane_id = get_user_meta($user->ID, '_pennylane_customer_id', true);
        $error = get_user_meta($user->ID, '_pennylane_sync_error', true);
        $last_sync = get_user_meta($user->ID, '_pennylane_last_sync', true);
        
        ?>
        <h2><?php _e('Pennylane', 'woo-pennylane'); ?></h2>
        <table class="form-table">
            <tr>
                <th scope="row"><?php _e('Statut de synchronisation', 'woo-pennylane'); ?></th>
                <td>
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
                        <button type="button" class="button" id="pennylane-sync-customer" data-customer-id="<?php echo esc_attr($user->ID); ?>" data-nonce="<?php echo wp_create_nonce('pennylane_sync_customer'); ?>">
                            <?php _e('Synchroniser maintenant', 'woo-pennylane'); ?>
                        </button>
                        <span class="spinner" style="float: none; margin-top: 0;"></span>
                        <span class="sync-result"></span>
                    </p>
                </td>
            </tr>
        </table>
        
        <script type="text/javascript">
        jQuery(document).ready(function($) {
            $('#pennylane-sync-customer').on('click', function() {
                var button = $(this);
                var customerId = button.data('customer-id');
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
                        action: 'woo_pennylane_sync_customer',
                        customer_id: customerId,
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
     * Synchronise un client via AJAX
     */
    public function ajax_sync_customer() {
        // Vérification du nonce
        check_ajax_referer('pennylane_sync_customer', 'nonce');
        
        // Vérification des permissions
        if (!current_user_can('edit_users')) {
            wp_send_json_error(__('Permission refusée.', 'woo-pennylane'));
            return;
        }
        
        // Récupération de l'ID du client
        $customer_id = isset($_POST['customer_id']) ? intval($_POST['customer_id']) : 0;
        
        if (!$customer_id) {
            wp_send_json_error(__('ID de client invalide.', 'woo-pennylane'));
            return;
        }
        
        try {
            // Synchronisation du client
            $this->sync_customer($customer_id, 'manual');
            
            // Récupération du client
            $customer = new WC_Customer($customer_id);
            $customer_name = $customer->get_first_name() . ' ' . $customer->get_last_name();
            if (empty(trim($customer_name))) {
                $customer_name = $customer->get_username();
            }
            
            wp_send_json_success(array(
                'message' => sprintf(__('Client "%s" synchronisé avec succès.', 'woo-pennylane'), $customer_name)
            ));
            
        } catch (Exception $e) {
            wp_send_json_error($e->getMessage());
        }
    }
}