<?php
if (!defined('ABSPATH')) {
    exit;
}

// Attempt to load the Logger class early
$logger_file_path_early_check = WOO_PENNYLANE_PLUGIN_DIR . 'includes/class-woo-pennylane-logger.php';
if (file_exists($logger_file_path_early_check)) {
    // Temporarily set max error reporting for this include
    $old_error_reporting = error_reporting(E_ALL);
    $old_display_errors = ini_set('display_errors', '1'); // Attempt to force display if possible
    try {
        require_once $logger_file_path_early_check;
    } catch (Throwable $t) {
        error_log('WOO_PENNYLANE_THROWABLE during logger require: ' . $t->getMessage());
    }
    // Restore previous error reporting
    error_reporting($old_error_reporting);
    ini_set('display_errors', $old_display_errors);
} else {
    // This will be a critical failure, log it if possible, though Logger might not be available
    error_log('WOO_PENNYLANE_CRITICAL: Logger file does not exist at path (early check): ' . $logger_file_path_early_check);
}

/**
 * Classe de synchronisation des produits WooCommerce avec Pennylane
 */
class WooPennylane_Product_Sync {
    /**
     * @var \WooPennylane\Api\Client
     */
    private $api_client;

    /**
     * Constructeur
     */
    public function __construct() {
        // The Logger class should have been loaded by the require_once statement at the top of this file.
        // We can add a check here for debugging if it's still not found.
        if (!class_exists('WooPennylane\\Logger')) {
            error_log('WOO_PENNYLANE_DEBUG: Logger class STILL not found in __construct, despite early require_once.');
        }
        $this->api_client = new \WooPennylane\Api\Client();
    }

    /**
     * Synchronise un produit vers Pennylane
     * 
     * @param int $product_id ID du produit
     * @return bool Succès ou échec
     * @throws Exception En cas d'erreur
     */
    
    public function sync_product($product_id, $sync_mode = 'manual') {
    $product = wc_get_product($product_id);
    global $woo_pennylane_sync_history;
    $start_time = microtime(true);
        
        if (!$product) {
            throw new Exception(__('Produit introuvable', 'woo-pennylane'));
        }

        try {
            // Prépare les données du produit
            $product_data = self::prepare_product_data($product);

            // Vérifie les champs obligatoires
            $this->validate_product_data($product_data);

            // Vérifie si le produit existe déjà dans Pennylane
            $pennylane_id = get_post_meta($product_id, '_pennylane_product_id', true);
            
            if ($pennylane_id) {
                // Mise à jour du produit existant
                $response = $this->api_client->update_product($pennylane_id, $product_data);
            } else {
                // Création d'un nouveau produit
                $response = $this->api_client->create_product($product_data);
                
                // Sauvegarde l'ID Pennylane
                if (isset($response['id'])) {
                    update_post_meta($product_id, '_pennylane_product_id', $response['id']);
                }
            }

            // Met à jour le statut de synchronisation
            update_post_meta($product_id, '_pennylane_product_synced', 'yes');
            update_post_meta($product_id, '_pennylane_product_last_sync', current_time('mysql'));
            delete_post_meta($product_id, '_pennylane_product_sync_error');

            if ($woo_pennylane_sync_history) {
            $execution_time = microtime(true) - $start_time;
            $woo_pennylane_sync_history->add_entry(
                'product',
                $sync_mode,
                $product_id,
                $product->get_name(),
                'success',
                'Message de succès',
                $execution_time
            );
        }

            \WooPennylane\Logger::info(
                sprintf('Produit ID #%d synchronisé avec succès. ID Pennylane: %s', $product_id, isset($response['id']) ? $response['id'] : $pennylane_id),
                $product_id,
                'product'
            );

            return true;

        } catch (Exception $e) {
            $error_message_for_meta = $e->getMessage();
            $log_message_error = sprintf(
                'Erreur API Pennylane lors de la synchronisation du produit ID #%d (Mode: %s): %s',
                $product_id,
                $sync_mode,
                $e->getMessage()
            );
            
            \WooPennylane\Logger::error($log_message_error, $product_id, 'product');

            if (isset($product_data)) {
                 \WooPennylane\Logger::debug("Données préparées pour le produit #{$product_id}: " . wp_json_encode($product_data), $product_id, 'product');
            }
            
            // Enregistre l'erreur dans les métadonnées du produit
            update_post_meta($product_id, '_pennylane_product_sync_error', $error_message_for_meta);
            update_post_meta($product_id, '_pennylane_product_last_sync', current_time('mysql'));
            
            if ($woo_pennylane_sync_history) {
            $execution_time = microtime(true) - $start_time;
            $woo_pennylane_sync_history->add_entry(
                'product',
                $sync_mode,
                $product_id,
                $product->get_name(),
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
     * @param array $data Données du produit
     * @throws Exception Si des champs obligatoires sont manquants
     */
    private function validate_product_data($data) {
        if (empty($data['label'])) {
            throw new Exception(__('Nom du produit manquant', 'woo-pennylane'));
        }
        
        if (!isset($data['price_before_tax']) || !is_numeric($data['price_before_tax'])) {
            throw new Exception(__('Prix HT manquant ou invalide', 'woo-pennylane'));
        }
        
        if (empty($data['vat_rate']) || !preg_match('/^[A-Z]{2}_\d+$/', $data['vat_rate'])) {
            throw new Exception(__('Taux de TVA manquant ou invalide (format attendu: FR_XXX)', 'woo-pennylane'));
        }
        
        if (empty($data['product_type']) || !in_array($data['product_type'], ['GOODS', 'SERVICE'])) {
            throw new Exception(sprintf(__('Type de produit invalide: %s. Doit être GOODS ou SERVICE.', 'woo-pennylane'), $data['product_type']));
        }
    }

    /**
     * Prépare les données du produit au format Pennylane
     * 
     * @param WC_Product $product Produit WooCommerce
     * @return array Données formatées pour l'API Pennylane
     */
    public static function prepare_product_data($product) {
        // Récupération du prix HT
        $price_before_tax = (float) $product->get_regular_price();
        
        // Si le prix régulier est vide, utiliser le prix normal
        if (empty($price_before_tax) || $price_before_tax === '') {
            $price_before_tax = (float) $product->get_price();
        }
        
        // Récupération du taux de TVA
        $vat_rate = self::get_product_tax_rate($product);
        $vat_rate_formatted = self::format_vat_rate($vat_rate); // "FR_200" pour 20%
        
        // Préparation des données selon le format requis
        $product_data = array(
            'label' => $product->get_name(),
            'description' => $product->get_description(),
            'external_reference' => (string) $product->get_id(), // ID WooCommerce comme référence externe
            'price_before_tax' => $price_before_tax,
            'vat_rate' => $vat_rate_formatted,
            'unit' => 'piece', // Par défaut, à adapter selon vos besoins
            'currency' => get_woocommerce_currency(),
            'reference' => $product->get_sku() ? $product->get_sku() : 'WC-' . $product->get_id(),
            'product_type' => self::get_product_type($product) // Ajout du type de produit (GOODS ou SERVICE)
        );
        
        // Ajout du compte comptable si configuré
        $ledger_account_id = get_option('woo_pennylane_product_ledger_account', '');
        if (!empty($ledger_account_id)) {
            $product_data['ledger_account_id'] = $ledger_account_id; // Corrigé pour API V2
        }
        
        return apply_filters('woo_pennylane_product_data', $product_data, $product);
    }

    /**
     * Formate le taux de TVA au format attendu par Pennylane
     * 
     * @param float $rate Taux de TVA (ex: 20.0)
     * @return string Format Pennylane (ex: "FR_200")
     */
    public static function format_vat_rate($rate) {
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
    public static function get_product_type($product) {
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
    public static function get_product_tax_rate($product) {
        $tax_class = $product->get_tax_class();
        $tax_rates = WC_Tax::get_rates($tax_class);
        
        if (!empty($tax_rates)) {
            $tax_rate = reset($tax_rates);
            return isset($tax_rate['rate']) ? (float) $tax_rate['rate'] : 0;
        }
        
        return 0;
    }

    /**
     * Compare les données d'un produit WooCommerce préparé avec les données Pennylane.
     * Utilisé par WooPennylane_Settings pour déterminer si une MàJ est nécessaire.
     *
     * @param array $wc_data Données WooCommerce préparées pour Pennylane.
     * @param array $pennylane_data Données du produit récupérées de Pennylane.
     * @return bool True si les données sont considérées comme identiques, False sinon.
     */
    public static function compare_product_data($wc_data, $pennylane_data) {
        // Normaliser les prix en float pour la comparaison
        $wc_price = isset($wc_data['price_before_tax']) ? (float)$wc_data['price_before_tax'] : null;
        $pl_price = isset($pennylane_data['price_before_tax']) ? (float)$pennylane_data['price_before_tax'] : null;

        // Comparaison du prix
        if (abs($wc_price - $pl_price) > 0.001) return false; // Tolérance pour les flottants
        
        if (isset($wc_data['label']) && $wc_data['label'] !== $pennylane_data['label']) return false;
        
        // La référence (SKU) est envoyée dans $wc_data['reference'], Pennylane la stocke dans $pennylane_data['reference'].
        if (isset($wc_data['reference']) && $wc_data['reference'] !== $pennylane_data['reference']) return false;
        
        // Comparer le type de produit
        if (isset($wc_data['product_type']) && $wc_data['product_type'] !== $pennylane_data['product_type']) return false;
        
        // Comparer le taux de TVA formaté
        if (isset($wc_data['vat_rate']) && $wc_data['vat_rate'] !== $pennylane_data['vat_rate']) return false;
        
        // Comparer la devise
        if (isset($wc_data['currency']) && $wc_data['currency'] !== $pennylane_data['currency']) return false;

        // Comparer le external_reference (WC Product ID)
        // Pennylane stocke cela dans 'external_reference'
        if (isset($wc_data['external_reference']) && $wc_data['external_reference'] !== $pennylane_data['external_reference']) {
            // Exception: si le external_reference de WC est 'WC-ID' et celui de PL est juste 'ID' (ancien format)
            // on peut considérer ça comme potentiellement ok si les ID matchent.
            // Mais pour une comparaison stricte, on les garde différents si le format n'est pas identique.
            // La synchro devrait mettre à jour le format de `external_reference` sur PL si besoin.
        }


        // Optionnel: Comparer la description si c'est un champ important
        // if (isset($wc_data['description']) && $wc_data['description'] !== $pennylane_data['description']) return false;

        // Optionnel: Comparer l'ID du compte comptable
        // if (isset($wc_data['ledger_account_id']) && $wc_data['ledger_account_id'] !== $pennylane_data['ledger_account_id']) return false;
        // Attention: $pennylane_data['ledger_account_id'] peut être null si non défini, $wc_data['ledger_account_id'] peut ne pas exister si non configuré.
        $wc_ledger_id = isset($wc_data['ledger_account_id']) ? $wc_data['ledger_account_id'] : null;
        $pl_ledger_id = isset($pennylane_data['ledger_account_id']) ? $pennylane_data['ledger_account_id'] : null;
        if ($wc_ledger_id !== $pl_ledger_id) return false;


        return true;
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
         * Affiche le contenu de la colonne personnalisée de manière améliorée
         */
        public function render_product_list_column($column, $post_id) {
            if ($column !== 'pennylane_sync') {
                return;
            }
            
            $pennylane_id = get_post_meta($post_id, '_pennylane_product_id', true);
            $synced = get_post_meta($post_id, '_pennylane_product_synced', true);
            $excluded = get_post_meta($post_id, '_pennylane_product_exclude', true);
            $last_sync = get_post_meta($post_id, '_pennylane_product_last_sync', true);
            $error = get_post_meta($post_id, '_pennylane_product_sync_error', true);
            
            echo '<div class="pennylane-column-content">';
            
            // Affichage du statut
            if ($excluded === 'yes') {
                echo '<span class="pennylane-status excluded">';
                echo '<span class="dashicons dashicons-no-alt"></span> ';
                echo esc_html__('Exclu', 'woo-pennylane');
                echo '</span>';
            } elseif ($synced === 'yes' && $pennylane_id) {
                echo '<span class="pennylane-status synced">';
                echo '<span class="dashicons dashicons-yes"></span> ';
                echo esc_html__('Synchronisé', 'woo-pennylane');
                
                if ($last_sync) {
                    $time_diff = human_time_diff(strtotime($last_sync), current_time('timestamp'));
                    echo ' <span class="sync-time" title="' . esc_attr($last_sync) . '">(' . esc_html($time_diff) . ')</span>';
                }
                echo '</span>';
            } else {
                if ($error) {
                    echo '<span class="pennylane-status error">';
                    echo '<span class="dashicons dashicons-warning"></span> ';
                    echo esc_html__('Erreur', 'woo-pennylane');
                    echo '<span class="error-details" title="' . esc_attr($error) . '">';
                    echo '<span class="dashicons dashicons-info"></span>';
                    echo '</span>';
                    echo '</span>';
                } else {
                    echo '<span class="pennylane-status not-synced">';
                    echo '<span class="dashicons dashicons-minus"></span> ';
                    echo esc_html__('Non synchronisé', 'woo-pennylane');
                    echo '</span>';
                }
            }
            
            // Bouton de synchronisation
            if ($excluded !== 'yes') {
                echo '<div class="pennylane-actions">';
                echo '<button type="button" class="button button-small pennylane-sync-product" data-product-id="' . esc_attr($post_id) . '">';
                echo '<span class="dashicons dashicons-update"></span> ';
                echo esc_html__('Synchroniser', 'woo-pennylane');
                echo '</button>';
                echo '<span class="spinner"></span>';
                echo '</div>';
            }
            
            echo '</div>';
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