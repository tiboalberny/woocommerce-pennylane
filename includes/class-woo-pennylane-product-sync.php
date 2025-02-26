<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Classe de synchronisation des produits WooCommerce avec Pennylane
 */
class WooPennylane_Product_Sync {
    /**
     * Clé API Pennylane
     */
    private $api_key;

    /**
     * URL de base de l'API Pennylane
     */
    private $api_url = 'https://app.pennylane.com/api/external/v2';

    /**
     * Constructeur
     */
    public function __construct() {
        $this->api_key = get_option('woo_pennylane_api_key');
    }

    /**
     * Synchronise un produit vers Pennylane
     * 
     * @param int $product_id ID du produit
     * @return bool Succès ou échec
     * @throws Exception En cas d'erreur
     */
    public function sync_product($product_id) {
        $product = wc_get_product($product_id);
        
        if (!$product) {
            throw new Exception(__('Produit introuvable', 'woo-pennylane'));
        }

        try {
            // Prépare les données du produit
            $product_data = $this->prepare_product_data($product);

            // Vérifie les champs obligatoires
            $this->validate_product_data($product_data);

            // Vérifie si le produit existe déjà dans Pennylane
            $pennylane_id = get_post_meta($product_id, '_pennylane_product_id', true);
            
            if ($pennylane_id) {
                // Mise à jour du produit existant
                $response = $this->send_to_api('/products/' . $pennylane_id, $product_data, 'PUT');
            } else {
                // Création d'un nouveau produit
                $response = $this->send_to_api('/products', $product_data);
                
                // Sauvegarde l'ID Pennylane
                if (isset($response['id'])) {
                    update_post_meta($product_id, '_pennylane_product_id', $response['id']);
                }
            }

            // Met à jour le statut de synchronisation
            update_post_meta($product_id, '_pennylane_product_synced', 'yes');
            update_post_meta($product_id, '_pennylane_product_last_sync', current_time('mysql'));
            delete_post_meta($product_id, '_pennylane_product_sync_error');

            return true;

        } catch (Exception $e) {
            // Log l'erreur
            if (get_option('woo_pennylane_debug_mode') === 'yes') {
                error_log('Pennylane Product Sync Error (ID #' . $product_id . '): ' . $e->getMessage());
            }
            
            // Enregistre l'erreur dans les métadonnées du produit
            update_post_meta($product_id, '_pennylane_product_sync_error', $e->getMessage());
            update_post_meta($product_id, '_pennylane_product_last_sync', current_time('mysql'));
            
            throw $e;
        }
    }

    /**
     * Valide que tous les champs obligatoires sont présents
     * 
     * @param array $data Données du produit
     * @throws Exception Si des champs obligatoires sont manquants
     */
    private function validate_product_data($data) {
        if (empty($data['label'])) {
            throw new Exception(__('Nom du produit manquant', 'woo-pennylane'));
        }
        
        if (empty($data['external_reference'])) {
            throw new Exception(__('Référence externe manquante', 'woo-pennylane'));
        }
        
        if (!isset($data['price_before_tax'])) {
            throw new Exception(__('Prix HT manquant', 'woo-pennylane'));
        }
        
        if (empty($data['vat_rate'])) {
            throw new Exception(__('Taux de TVA manquant', 'woo-pennylane'));
        }
    }

    /**
     * Prépare les données du produit au format Pennylane
     * 
     * @param WC_Product $product Produit WooCommerce
     * @return array Données formatées pour l'API Pennylane
     */
    private function prepare_product_data($product) {
        // Récupération du prix HT
        $price_before_tax = (float) $product->get_regular_price();
        
        // Récupération du taux de TVA
        $vat_rate = $this->get_product_tax_rate($product);
        $vat_rate_formatted = $this->format_vat_rate($vat_rate); // "FR_200" pour 20%
        
        // Calcul du prix TTC si disponible, sinon calculé
        $price = wc_get_price_including_tax($product);
        
        // Préparation des données selon le format requis
        $product_data = array(
            'label' => $product->get_name(),
            'description' => $product->get_description(),
            'external_reference' => (string) $product->get_id(), // ID WooCommerce comme référence externe
            'price_before_tax' => $price_before_tax,
            'vat_rate' => $vat_rate_formatted,
            'price' => $price,
            'unit' => 'piece', // Par défaut, à adapter selon vos besoins
            'currency' => get_woocommerce_currency(),
            'reference' => $product->get_sku() ? $product->get_sku() : 'WC-' . $product->get_id()
        );
        
        // Ajout du compte comptable si configuré
        $ledger_account_id = get_option('woo_pennylane_product_ledger_account', 0);
        if ($ledger_account_id) {
            $product_data['ledger_account'] = array(
                'id' => (int) $ledger_account_id
            );
        }
        
        return apply_filters('woo_pennylane_product_data', $product_data, $product);
    }

    /**
     * Formate le taux de TVA au format attendu par Pennylane
     * 
     * @param float $rate Taux de TVA (ex: 20.0)
     * @return string Format Pennylane (ex: "FR_200")
     */
    private function format_vat_rate($rate) {
        // Par défaut, utiliser la France comme pays de TVA
        $country = 'FR';
        
        // Conversion du taux (ex: 20.0 -> 200)
        $rate_formatted = (int) ($rate * 10);
        
        return $country . '_' . $rate_formatted;
    }

    /**
     * Détermine le type de produit pour Pennylane
     * 
     * @param WC_Product $product Produit WooCommerce
     * @return string Type de produit
     */
    private function get_product_type($product) {
        if ($product->is_virtual() || $product->is_downloadable()) {
            return 'SERVICE';
        }
        return 'GOODS';
    }

    /**
     * Récupère les attributs d'une variation
     * 
     * @param WC_Product_Variation $variation Variation de produit
     * @return array Attributs de la variation
     */
    private function get_variation_attributes($variation) {
        $attributes = array();
        $variation_attributes = $variation->get_attributes();
        
        if (!empty($variation_attributes)) {
            foreach ($variation_attributes as $attribute_name => $attribute_value) {
                $taxonomy = str_replace('pa_', '', $attribute_name);
                
                if ($attribute_value) {
                    $term = get_term_by('slug', $attribute_value, 'pa_' . $taxonomy);
                    $attributes[$taxonomy] = $term ? $term->name : $attribute_value;
                }
            }
        }
        
        return $attributes;
    }

    /**
     * Calcule le taux de TVA d'un produit
     * 
     * @param WC_Product $product Produit WooCommerce
     * @return float Taux de TVA
     */
    private function get_product_tax_rate($product) {
        $tax_class = $product->get_tax_class();
        $tax_rates = WC_Tax::get_rates($tax_class);
        
        if (!empty($tax_rates)) {
            $tax_rate = reset($tax_rates);
            return isset($tax_rate['rate']) ? (float) $tax_rate['rate'] : 0;
        }
        
        return 0;
    }

    /**
     * Envoie une requête à l'API Pennylane
     * 
     * @param string $endpoint Point de terminaison de l'API
     * @param array $data Données à envoyer
     * @param string $method Méthode HTTP (POST par défaut)
     * @return array Réponse de l'API
     * @throws Exception En cas d'erreur
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
     * Ajoute une métabox sur la page d'édition de produit
     */
    public function add_product_metabox() {
        add_meta_box(
            'pennylane_product_sync',
            __('Synchronisation Pennylane', 'woo-pennylane'),
            array($this, 'render_product_metabox'),
            'product',
            'side',
            'default'
        );
    }

    /**
     * Affiche le contenu de la métabox
     */
    public function render_product_metabox($post) {
        // Récupérer les données du produit
        $product_id = $post->ID;
        $pennylane_id = get_post_meta($product_id, '_pennylane_product_id', true);
        $synced = get_post_meta($product_id, '_pennylane_product_synced', true);
        $last_sync = get_post_meta($product_id, '_pennylane_product_last_sync', true);
        $error = get_post_meta($product_id, '_pennylane_product_sync_error', true);
        
        wp_nonce_field('pennylane_product_sync_nonce', 'pennylane_product_sync_nonce');
        
        echo '<div class="pennylane-product-sync-box">';
        
        // Si le produit est déjà synchronisé
        if ($synced === 'yes' && $pennylane_id) {
            echo '<p>' . __('Statut:', 'woo-pennylane') . ' <span class="pennylane-synced">' . __('Synchronisé', 'woo-pennylane') . '</span></p>';
            echo '<p>' . __('ID Pennylane:', 'woo-pennylane') . ' <strong>' . esc_html($pennylane_id) . '</strong></p>';
            
            if ($last_sync) {
                echo '<p>' . __('Dernière synchronisation:', 'woo-pennylane') . ' <br>';
                echo '<span title="' . esc_attr($last_sync) . '">' . esc_html(human_time_diff(strtotime($last_sync), current_time('timestamp'))) . ' ' . __('ago', 'woo-pennylane') . '</span></p>';
            }
        } else {
            echo '<p>' . __('Statut:', 'woo-pennylane') . ' <span class="pennylane-not-synced">' . __('Non synchronisé', 'woo-pennylane') . '</span></p>';
            
            if ($error) {
                echo '<div class="pennylane-sync-error">';
                echo '<p><strong>' . __('Erreur de synchronisation:', 'woo-pennylane') . '</strong></p>';
                echo '<p>' . esc_html($error) . '</p>';
                echo '</div>';
            }
        }
        
        // Ajouter checkbox pour exclure de la synchronisation auto
        $excluded = get_post_meta($product_id, '_pennylane_product_exclude', true);
        echo '<p>';
        echo '<label>';
        echo '<input type="checkbox" name="_pennylane_product_exclude" value="yes" ' . checked($excluded, 'yes', false) . '> ';
        echo __('Exclure de la synchronisation', 'woo-pennylane');
        echo '</label>';
        echo '</p>';
        
        // Bouton de synchronisation manuelle
        echo '<p>';
        echo '<button type="button" class="button" id="pennylane-sync-product" data-product-id="' . esc_attr($product_id) . '">';
        echo __('Synchroniser maintenant', 'woo-pennylane');
        echo '</button>';
        echo '</p>';
        
        echo '</div>';
    }

    /**
     * Sauvegarde les données de la métabox
     */
    public function save_product_metabox($post_id) {
        // Vérifier le nonce
        if (!isset($_POST['pennylane_product_sync_nonce']) || !wp_verify_nonce($_POST['pennylane_product_sync_nonce'], 'pennylane_product_sync_nonce')) {
            return;
        }
        
        // Vérifier les autorisations
        if (!current_user_can('edit_post', $post_id)) {
            return;
        }
        
        // Mettre à jour l'exclusion
        $exclude = isset($_POST['_pennylane_product_exclude']) ? 'yes' : 'no';
        update_post_meta($post_id, '_pennylane_product_exclude', $exclude);
    }

    /**
     * Synchronisation automatique lors de la mise à jour d'un produit
     */
    public function maybe_sync_on_update($product_id) {
        // Vérifier si la synchronisation auto est activée
        if (get_option('woo_pennylane_auto_sync_products', 'no') !== 'yes') {
            return;
        }
        
        // Vérifier si le produit est exclu
        if (get_post_meta($product_id, '_pennylane_product_exclude', true) === 'yes') {
            return;
        }
        
        try {
            $this->sync_product($product_id);
        } catch (Exception $e) {
            // L'erreur est déjà loguée dans sync_product
        }
    }

    /**
     * Ajoute une colonne dans la liste des produits
     */
    public function add_product_list_column($columns) {
        $new_columns = array();
        
        foreach ($columns as $key => $value) {
            $new_columns[$key] = $value;
            
            // Ajouter après la colonne de prix
            if ($key === 'price') {
                $new_columns['pennylane_sync'] = __('Pennylane', 'woo-pennylane');
            }
        }
        
        return $new_columns;
    }

    /**
     * Affiche le contenu de la colonne personnalisée
     */
    public function render_product_list_column($column, $post_id) {
        if ($column !== 'pennylane_sync') {
            return;
        }
        
        $pennylane_id = get_post_meta($post_id, '_pennylane_product_id', true);
        $synced = get_post_meta($post_id, '_pennylane_product_synced', true);
        $excluded = get_post_meta($post_id, '_pennylane_product_exclude', true);
        
        if ($excluded === 'yes') {
            echo '<span class="dashicons dashicons-no-alt" title="' . esc_attr__('Exclu de la synchronisation', 'woo-pennylane') . '"></span>';
        } elseif ($synced === 'yes' && $pennylane_id) {
            echo '<span class="dashicons dashicons-yes" title="' . esc_attr__('Synchronisé', 'woo-pennylane') . '"></span>';
            
            $last_sync = get_post_meta($post_id, '_pennylane_product_last_sync', true);
            if ($last_sync) {
                echo ' <span title="' . esc_attr($last_sync) . '">' . esc_html(human_time_diff(strtotime($last_sync), current_time('timestamp'))) . '</span>';
            }
        } else {
            $error = get_post_meta($post_id, '_pennylane_product_sync_error', true);
            if ($error) {
                echo '<span class="dashicons dashicons-warning" title="' . esc_attr($error) . '"></span>';
            } else {
                echo '<span class="dashicons dashicons-minus" title="' . esc_attr__('Non synchronisé', 'woo-pennylane') . '"></span>';
            }
        }
        
        echo ' <a href="#" class="pennylane-sync-product" data-product-id="' . esc_attr($post_id) . '">' . esc_html__('Sync', 'woo-pennylane') . '</a>';
    }

    /**
     * Ajoute des actions en masse pour les produits
     */
    public function add_bulk_actions($actions) {
        $actions['pennylane_sync'] = __('Synchroniser avec Pennylane', 'woo-pennylane');
        return $actions;
    }

    /**
     * Traite les actions en masse
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
                $this->sync_product($post_id);
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
                /* translators: %d: number of products */
                _n(
                    '%d produit synchronisé avec succès vers Pennylane.',
                    '%d produits synchronisés avec succès vers Pennylane.',
                    $success,
                    'woo-pennylane'
                ),
                $success
            );
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html($message) . '</p></div>';
        }
        
        if ($error > 0) {
            $message = sprintf(
                /* translators: %d: number of products */
                _n(
                    'Échec de la synchronisation de %d produit vers Pennylane.',
                    'Échec de la synchronisation de %d produits vers Pennylane.',
                    $error,
                    'woo-pennylane'
                ),
                $error
            );
            echo '<div class="notice notice-error is-dismissible"><p>' . esc_html($message) . '</p></div>';
        }
    }
}