<?php
if (!defined('ABSPATH')) {
    exit;
}

class WooPennylane_Customer_Sync {
    /**
     * @var \WooPennylane\Api\Client
     */
    private $api_client;

    public function __construct() {
        $this->api_client = new \WooPennylane\Api\Client();
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
                $response = $this->api_client->update_individual_customer($existing_customer['id'], $customer_data);
                $pennylane_id = $existing_customer['id'];
            } else {
                // Création d'un nouveau client
                $response = $this->api_client->create_individual_customer($customer_data);
                $pennylane_id = isset($response['id']) ? $response['id'] : null;
            }

            // Met à jour le statut de synchronisation
            update_user_meta($customer_id, '_pennylane_synced', 'yes');
            update_user_meta($customer_id, '_pennylane_customer_id', $pennylane_id);
            update_user_meta($customer_id, '_pennylane_last_sync', current_time('mysql'));
            delete_user_meta($customer_id, '_pennylane_sync_error');

            \WooPennylane\Logger::info(
                sprintf('Client WooCommerce ID #%d synchronisé avec succès. ID Pennylane: %s', $customer_id, $pennylane_id),
                $customer_id,
                'customer'
            );

            return true;

        } catch (Exception $e) {
            $error_message_for_meta = $e->getMessage();
            $log_message_error = sprintf(
                'Erreur API Pennylane lors de la synchronisation du client WooCommerce ID #%d: %s',
                $customer_id,
                $e->getMessage()
            );
            
            \WooPennylane\Logger::error($log_message_error, $customer_id, 'customer');

            if (isset($customer_data)) {
                \WooPennylane\Logger::debug("Données préparées pour le client #{$customer_id}: " . wp_json_encode($customer_data), $customer_id, 'customer');
            }
            
            // Enregistre l'erreur dans les métadonnées
            update_user_meta($customer_id, '_pennylane_sync_error', $error_message_for_meta);
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

        if (!preg_match('/^[A-Z]{2}$/', $data['billing_address']['country_alpha2'])) {
            throw new Exception(sprintf(__('Format de code pays invalide pour l\'adresse de facturation: %s. Doit être 2 lettres majuscules (ex: FR).', 'woo-pennylane'), $data['billing_address']['country_alpha2']));
        }
        
        // Validation de l'adresse de livraison si présente
        if (!empty($data['delivery_address'])) {
            if (empty($data['delivery_address']['address']) || 
                empty($data['delivery_address']['postal_code']) || 
                empty($data['delivery_address']['city']) || 
                empty($data['delivery_address']['country_alpha2'])) {
                throw new Exception(__('Adresse de livraison incomplète', 'woo-pennylane'));
            }
            if (!preg_match('/^[A-Z]{2}$/', $data['delivery_address']['country_alpha2'])) {
                throw new Exception(sprintf(__('Format de code pays invalide pour l\'adresse de livraison: %s. Doit être 2 lettres majuscules (ex: FR).', 'woo-pennylane'), $data['delivery_address']['country_alpha2']));
            }
        }

        if (!empty($data['emails'])) {
            if (!is_array($data['emails'])) {
                throw new Exception(__('Le champ emails doit être un tableau.', 'woo-pennylane'));
            }
            foreach ($data['emails'] as $email) {
                if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    throw new Exception(sprintf(__('Format d\'email invalide: %s', 'woo-pennylane'), $email));
                }
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
            $response = $this->api_client->find_individual_customer_by_external_reference($external_reference);
            
            if (!empty($response) && isset($response[0]['id'])) {
                return $response[0];
            }
            
            return null;
        } catch (Exception $e) {
            \WooPennylane\Logger::error('Erreur API Pennylane (Find Customer by external_reference ' . $external_reference . '): ' . $e->getMessage(), null, 'customer');
            return null;
        }
    }

    /**
     * Récupère un client Pennylane par son ID
     */
    public function get_customer($pennylane_id) {
        return $this->api_client->get_individual_customer($pennylane_id);
    }
}