<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Classe pour gérer les champs personnalisés dans le profil utilisateur
 */
class WooPennylane_User_Profile {
    
    public function __construct() {
        // Afficher les champs Pennylane dans la page de profil utilisateur
        add_action('show_user_profile', array($this, 'add_pennylane_fields'));
        add_action('edit_user_profile', array($this, 'add_pennylane_fields'));
        
        // Sauvegarder les champs personnalisés lors de la mise à jour du profil
        add_action('personal_options_update', array($this, 'save_pennylane_fields'));
        add_action('edit_user_profile_update', array($this, 'save_pennylane_fields'));
        
        // Ajouter un hook pour la synchronisation AJAX d'un client
        add_action('wp_ajax_woo_pennylane_sync_single_customer', array($this, 'sync_single_customer'));
    }
    
    /**
     * Ajoute les champs liés à Pennylane dans le profil utilisateur
     */
    public function add_pennylane_fields($user) {
        // Vérifie les permissions
        if (!current_user_can('manage_woocommerce')) {
            return;
        }
        
        // Récupère les métadonnées Pennylane
        $synced = get_user_meta($user->ID, '_pennylane_synced', true);
        $pennylane_id = get_user_meta($user->ID, '_pennylane_customer_id', true);
        $last_sync = get_user_meta($user->ID, '_pennylane_last_sync', true);
        $exclude = get_user_meta($user->ID, '_pennylane_exclude', true);
        $sync_error = get_user_meta($user->ID, '_pennylane_sync_error', true);
        
        ?>
        <h2><?php _e('Informations Pennylane', 'woo-pennylane'); ?></h2>
        <table class="form-table">
            <tr>
                <th><label for="pennylane_exclude"><?php _e('Exclure de la synchronisation', 'woo-pennylane'); ?></label></th>
                <td>
                    <input type="checkbox" name="pennylane_exclude" id="pennylane_exclude" value="yes" <?php checked($exclude, 'yes'); ?> />
                    <span class="description"><?php _e('Ne pas synchroniser ce client avec Pennylane', 'woo-pennylane'); ?></span>
                </td>
            </tr>
            
            <?php if ($synced === 'yes' && $pennylane_id) : ?>
                <tr>
                    <th><?php _e('Statut Pennylane', 'woo-pennylane'); ?></th>
                    <td>
                        <p><strong><?php _e('Synchronisé', 'woo-pennylane'); ?></strong></p>
                        <p><?php _e('ID Pennylane:', 'woo-pennylane'); ?> <code><?php echo esc_html($pennylane_id); ?></code></p>
                        <?php if ($last_sync) : ?>
                            <p><?php _e('Dernière synchronisation:', 'woo-pennylane'); ?> 
                               <span title="<?php echo esc_attr($last_sync); ?>"><?php echo esc_html(human_time_diff(strtotime($last_sync), current_time('timestamp'))); ?> <?php _e('ago', 'woo-pennylane'); ?></span>
                            </p>
                        <?php endif; ?>
                    </td>
                </tr>
                <tr>
                    <th><?php _e('Actions', 'woo-pennylane'); ?></th>
                    <td>
                        <a href="#" class="button sync-pennylane-customer" data-customer-id="<?php echo esc_attr($user->ID); ?>">
                            <?php _e('Synchroniser maintenant', 'woo-pennylane'); ?>
                        </a>
                        <span class="spinner"></span>
                        <div class="sync-result"></div>
                    </td>
                </tr>
            <?php elseif ($sync_error) : ?>
                <tr>
                    <th><?php _e('Statut Pennylane', 'woo-pennylane'); ?></th>
                    <td>
                        <p><strong class="pennylane-error"><?php _e('Erreur de synchronisation', 'woo-pennylane'); ?></strong></p>
                        <p class="pennylane-error-message"><?php echo esc_html($sync_error); ?></p>
                        <?php if ($last_sync) : ?>
                            <p><?php _e('Tentative de synchronisation:', 'woo-pennylane'); ?> 
                               <span title="<?php echo esc_attr($last_sync); ?>"><?php echo esc_html(human_time_diff(strtotime($last_sync), current_time('timestamp'))); ?> <?php _e('ago', 'woo-pennylane'); ?></span>
                            </p>
                        <?php endif; ?>
                    </td>
                </tr>
                <tr>
                    <th><?php _e('Actions', 'woo-pennylane'); ?></th>
                    <td>
                        <a href="#" class="button sync-pennylane-customer" data-customer-id="<?php echo esc_attr($user->ID); ?>">
                            <?php _e('Réessayer la synchronisation', 'woo-pennylane'); ?>
                        </a>
                        <span class="spinner"></span>
                        <div class="sync-result"></div>
                    </td>
                </tr>
            <?php else : ?>
                <tr>
                    <th><?php _e('Statut Pennylane', 'woo-pennylane'); ?></th>
                    <td>
                        <p><strong><?php _e('Non synchronisé', 'woo-pennylane'); ?></strong></p>
                    </td>
                </tr>
                <tr>
                    <th><?php _e('Actions', 'woo-pennylane'); ?></th>
                    <td>
                        <a href="#" class="button sync-pennylane-customer" data-customer-id="<?php echo esc_attr($user->ID); ?>">
                            <?php _e('Synchroniser maintenant', 'woo-pennylane'); ?>
                        </a>
                        <span class="spinner"></span>
                        <div class="sync-result"></div>
                    </td>
                </tr>
            <?php endif; ?>
        </table>
        
        <script>
            jQuery(document).ready(function($) {
                $('.sync-pennylane-customer').on('click', function(e) {
                    e.preventDefault();
                    
                    const button = $(this);
                    const customerId = button.data('customer-id');
                    const spinner = button.next('.spinner');
                    const resultContainer = button.siblings('.sync-result');
                    
                    button.prop('disabled', true);
                    spinner.addClass('is-active');
                    resultContainer.empty();
                    
                    $.ajax({
                        url: ajaxurl,
                        type: 'POST',
                        data: {
                            action: 'woo_pennylane_sync_single_customer',
                            nonce: '<?php echo wp_create_nonce('woo_pennylane_nonce'); ?>',
                            customer_id: customerId
                        },
                        success: function(response) {
                            if (response.success) {
                                resultContainer.html('<div class="notice notice-success inline"><p>' + response.data.message + '</p></div>');
                                
                                // Mise à jour de l'interface sans rechargement
                                setTimeout(function() {
                                    window.location.reload();
                                }, 2000);
                            } else {
                                resultContainer.html('<div class="notice notice-error inline"><p>' + response.data.message + '</p></div>');
                            }
                        },
                        error: function() {
                            resultContainer.html('<div class="notice notice-error inline"><p><?php _e('Erreur de communication avec le serveur', 'woo-pennylane'); ?></p></div>');
                        },
                        complete: function() {
                            button.prop('disabled', false);
                            spinner.removeClass('is-active');
                        }
                    });
                });
            });
        </script>
        <?php
    }
    
    /**
     * Sauvegarde les champs Pennylane lors de la mise à jour du profil
     */
    public function save_pennylane_fields($user_id) {
        // Vérifie les permissions
        if (!current_user_can('manage_woocommerce')) {
            return;
        }
        
        // Sauvegarde l'exclusion de synchronisation
        $exclude = isset($_POST['pennylane_exclude']) ? 'yes' : 'no';
        update_user_meta($user_id, '_pennylane_exclude', $exclude);
    }
    
    /**
     * Synchronise un client individuel via AJAX
    */
    public function sync_single_customer() {
        check_ajax_referer('woo_pennylane_nonce', 'nonce');

        if (!current_user_can('edit_users')) {
            wp_send_json_error(array(
                'message' => __('Permission refusée', 'woo-pennylane')
            ));
        }

        $customer_id = isset($_POST['customer_id']) ? intval($_POST['customer_id']) : 0;
        $force_resync = isset($_POST['force_resync']) && $_POST['force_resync'] === 'yes';
        
        // Vérification du nonce spécifique au client pour plus de sécurité (optionnel)
        if (isset($_POST['user_nonce'])) {
            $user_nonce = sanitize_text_field($_POST['user_nonce']);
            if (!wp_verify_nonce($user_nonce, 'sync_customer_' . $customer_id)) {
                wp_send_json_error(array(
                    'message' => __('Vérification de sécurité échouée', 'woo-pennylane')
                ));
            }
        }

        if (!$customer_id) {
            wp_send_json_error(array(
                'message' => __('ID client invalide', 'woo-pennylane')
            ));
        }

        try {
            // Vérifie si le client est exclu (sauf en cas de resynchronisation forcée)
            if (!$force_resync && get_user_meta($customer_id, '_pennylane_exclude', true) === 'yes') {
                wp_send_json_error(array(
                    'message' => __('Ce client est exclu de la synchronisation', 'woo-pennylane')
                ));
            }

            // Vérification rapide si le client existe et a les données requises
            $customer = new WC_Customer($customer_id);
            if (!$customer->get_id() || !$customer->get_billing_first_name() || !$customer->get_billing_last_name()) {
                wp_send_json_error(array(
                    'message' => __('Données client incomplètes', 'woo-pennylane')
                ));
            }

            // Initialise le synchroniseur
            require_once WOO_PENNYLANE_PLUGIN_DIR . 'includes/class-woo-pennylane-customer-sync.php';
            $synchronizer = new WooPennylane_Customer_Sync();

            // Si c'est une resynchronisation forcée, supprime les métadonnées de synchronisation
            if ($force_resync) {
                // On garde l'ID Pennylane mais on supprime le flag _pennylane_synced pour forcer la resynchronisation
                delete_user_meta($customer_id, '_pennylane_synced');
                delete_user_meta($customer_id, '_pennylane_sync_error');
            }

            // Synchronise le client
            $synchronizer->sync_customer($customer_id);

            // Récupère les informations mises à jour
            $pennylane_id = get_user_meta($customer_id, '_pennylane_customer_id', true);
            $last_sync = get_user_meta($customer_id, '_pennylane_last_sync', true);

            // Message de succès adapté selon le contexte
            if ($pennylane_id) {
                $message = $force_resync 
                    ? sprintf(__('Client "%s" resynchronisé avec succès (ID: %s)', 'woo-pennylane'), $customer->get_billing_first_name() . ' ' . $customer->get_billing_last_name(), $pennylane_id)
                    : sprintf(__('Client "%s" synchronisé avec succès (ID: %s)', 'woo-pennylane'), $customer->get_billing_first_name() . ' ' . $customer->get_billing_last_name(), $pennylane_id);
            } else {
                $message = sprintf(__('Client "%s" synchronisé avec succès', 'woo-pennylane'), $customer->get_billing_first_name() . ' ' . $customer->get_billing_last_name());
            }

            wp_send_json_success(array(
                'message' => $message,
                'pennylane_id' => $pennylane_id,
                'last_sync' => $last_sync,
                'human_time' => human_time_diff(strtotime($last_sync), current_time('timestamp')),
                'customer_name' => $customer->get_billing_first_name() . ' ' . $customer->get_billing_last_name()
            ));

        } catch (Exception $e) {
            // Enregistrer l'erreur dans le log
            if (class_exists('WooPennylane_Logger')) {
                WooPennylane_Logger::add_log($customer_id, 'customer', 'error', $e->getMessage());
            }
            
            // Retourner l'erreur
            wp_send_json_error(array(
                'message' => $e->getMessage()
            ));
        }
    }
}