<?php
// Protection contre l'accès direct
if (!defined('ABSPATH')) {
    exit;
}

// Vérifie les permissions utilisateur
if (!current_user_can('manage_woocommerce')) {
    wp_die(__('Vous n\'avez pas les permissions suffisantes pour accéder à cette page.', 'woo-pennylane'));
}
?>

<div class="wrap woo-pennylane-settings">
    <h1 class="wp-heading-inline">
        <?php _e('Configuration Pennylane', 'woo-pennylane'); ?>
    </h1>
    
    <hr class="wp-header-end">

    <?php settings_errors('woo_pennylane_messages'); ?>

    <div class="card">
        <form method="post" action="options.php" id="woo-pennylane-settings-form">
            <?php
            settings_fields('woo_pennylane_settings');
            do_settings_sections('woo_pennylane_settings');
            ?>

            <table class="form-table">
                <!-- Section API -->
                <tr>
                    <th scope="row" colspan="2">
                        <h2 class="title"><?php _e('Configuration API', 'woo-pennylane'); ?></h2>
                    </th>
                </tr>

                <tr>
                    <th scope="row">
                        <label for="woo_pennylane_api_key">
                            <?php _e('Clé API', 'woo-pennylane'); ?>
                            <span class="required">*</span>
                        </label>
                    </th>
                    <td>
                        <div class="api-key-wrapper">
                            <input type="password" 
                                   id="woo_pennylane_api_key" 
                                   name="woo_pennylane_api_key"
                                   value="<?php echo esc_attr(get_option('woo_pennylane_api_key')); ?>" 
                                   class="regular-text">
                            <button type="button" class="button woo-pennylane-toggle-visibility">
                                <?php _e('Afficher', 'woo-pennylane'); ?>
                            </button>
                            <button type="button" class="button button-secondary" id="woo-pennylane-test-connection">
                                <?php _e('Tester la connexion', 'woo-pennylane'); ?>
                            </button>
                        </div>
                        <p class="description">
                            <?php _e('Votre clé API Pennylane. Vous pouvez la trouver dans les paramètres de votre compte Pennylane.', 'woo-pennylane'); ?>
                        </p>
                    </td>
                </tr>

                <!-- Section Comptabilité -->
                <tr>
                    <th scope="row" colspan="2">
                        <h2 class="title"><?php _e('Configuration Comptable', 'woo-pennylane'); ?></h2>
                    </th>
                </tr>

                <tr>
                    <th scope="row">
                        <label for="woo_pennylane_journal_code">
                            <?php _e('Code Journal', 'woo-pennylane'); ?>
                            <span class="required">*</span>
                        </label>
                    </th>
                    <td>
                        <input type="text" 
                               id="woo_pennylane_journal_code" 
                               name="woo_pennylane_journal_code"
                               value="<?php echo esc_attr(get_option('woo_pennylane_journal_code')); ?>" 
                               class="regular-text">
                        <p class="description">
                            <?php _e('Code du journal des ventes dans Pennylane', 'woo-pennylane'); ?>
                        </p>
                    </td>
                </tr>

                <tr>
                    <th scope="row">
                        <label for="woo_pennylane_account_number">
                            <?php _e('Numéro de Compte', 'woo-pennylane'); ?>
                            <span class="required">*</span>
                        </label>
                    </th>
                    <td>
                        <input type="text" 
                               id="woo_pennylane_account_number" 
                               name="woo_pennylane_account_number"
                               value="<?php echo esc_attr(get_option('woo_pennylane_account_number')); ?>" 
                               class="regular-text">
                        <p class="description">
                            <?php _e('Numéro de compte pour les ventes', 'woo-pennylane'); ?>
                        </p>
                    </td>
                </tr>

                <tr>
                    <th scope="row">
                        <label for="woo_pennylane_product_ledger_account">
                            <?php _e('Compte comptable des produits', 'woo-pennylane'); ?>
                            <span class="required">*</span>
                        </label>
                    </th>
                    <td>
                        <input type="text" 
                               id="woo_pennylane_product_ledger_account" 
                               name="woo_pennylane_product_ledger_account"
                               value="<?php echo esc_attr(get_option('woo_pennylane_product_ledger_account', '707')); ?>" 
                               class="regular-text">
                        <p class="description">
                            <?php _e('Numéro de compte comptable pour les produits dans Pennylane', 'woo-pennylane'); ?>
                        </p>
                    </td>
                </tr>

                <!-- Section Synchronisation -->
                <tr>
                    <th scope="row" colspan="2">
                        <h2 class="title"><?php _e('Options de Synchronisation', 'woo-pennylane'); ?></h2>
                    </th>
                </tr>

                <tr>
                    <th scope="row">
                        <?php _e('Synchronisation Automatique', 'woo-pennylane'); ?>
                    </th>
                    <td>
                        <label>
                            <input type="checkbox" 
                                   name="woo_pennylane_auto_sync" 
                                   value="yes" 
                                   <?php checked(get_option('woo_pennylane_auto_sync'), 'yes'); ?>>
                            <?php _e('Activer la synchronisation automatique des commandes', 'woo-pennylane'); ?>
                        </label>
                        <p class="description">
                            <?php _e('Les commandes seront automatiquement synchronisées avec Pennylane lors du changement de statut.', 'woo-pennylane'); ?>
                        </p>
                    </td>
                </tr>

                <tr>
                    <th scope="row">
                        <?php _e('Synchronisation Produits', 'woo-pennylane'); ?>
                    </th>
                    <td>
                        <label>
                            <input type="checkbox" 
                                   name="woo_pennylane_auto_sync_products" 
                                   value="yes" 
                                   <?php checked(get_option('woo_pennylane_auto_sync_products'), 'yes'); ?>>
                            <?php _e('Activer la synchronisation automatique des produits', 'woo-pennylane'); ?>
                        </label>
                        <p class="description">
                            <?php _e('Les produits seront automatiquement synchronisés avec Pennylane lors de leur création ou mise à jour.', 'woo-pennylane'); ?>
                        </p>
                    </td>
                </tr>

                <tr>
                    <th scope="row">
                        <?php _e('Statuts à Synchroniser', 'woo-pennylane'); ?>
                    </th>
                    <td>
                        <?php
                        $selected_statuses = get_option('woo_pennylane_sync_status', array('completed'));
                        // S'assurer que selected_statuses est un tableau
                        if (!is_array($selected_statuses)) {
                            $selected_statuses = array('completed'); // Valeur par défaut si ce n'est pas un tableau
                        }
                        $order_statuses = wc_get_order_statuses();
                        foreach ($order_statuses as $status => $label) :
                            $status_key = str_replace('wc-', '', $status);
                        ?>
                            <label style="display: block; margin-bottom: 5px;">
                                <input type="checkbox" 
                                       name="woo_pennylane_sync_status[]" 
                                       value="<?php echo esc_attr($status_key); ?>"
                                       <?php checked(in_array($status_key, $selected_statuses)); ?>>
                                <?php echo esc_html($label); ?>
                            </label>
                        <?php endforeach; ?>
                        <p class="description">
                            <?php _e('Sélectionnez les statuts de commande qui déclencheront la synchronisation.', 'woo-pennylane'); ?>
                        </p>
                    </td>
                </tr>

                <!-- Section Debug -->
                <tr>
                    <th scope="row" colspan="2">
                        <h2 class="title"><?php _e('Débogage', 'woo-pennylane'); ?></h2>
                    </th>
                </tr>

                <tr>
                    <th scope="row">
                        <?php _e('Mode Debug', 'woo-pennylane'); ?>
                    </th>
                    <td>
                        <label>
                            <input type="checkbox" 
                                   name="woo_pennylane_debug_mode" 
                                   value="yes" 
                                   <?php checked(get_option('woo_pennylane_debug_mode'), 'yes'); ?>>
                            <?php _e('Activer le mode debug', 'woo-pennylane'); ?>
                        </label>
                        <p class="description">
                            <?php _e('Active la journalisation détaillée pour le débogage.', 'woo-pennylane'); ?>
                        </p>
                    </td>
                </tr>
            </table>

            <?php submit_button(); ?>
        </form>
    </div>

    <?php if (get_option('woo_pennylane_debug_mode') === 'yes') : ?>
        <div class="card woo-pennylane-debug">
            <h3><?php _e('Informations de Débogage', 'woo-pennylane'); ?></h3>
            <table class="widefat">
                <tr>
                    <td><strong><?php _e('Version du Plugin', 'woo-pennylane'); ?></strong></td>
                    <td><?php echo WOO_PENNYLANE_VERSION; ?></td>
                </tr>
                <tr>
                    <td><strong><?php _e('Version de WordPress', 'woo-pennylane'); ?></strong></td>
                    <td><?php echo get_bloginfo('version'); ?></td>
                </tr>
                <tr>
                    <td><strong><?php _e('Version de WooCommerce', 'woo-pennylane'); ?></strong></td>
                    <td><?php echo WC()->version; ?></td>
                </tr>
                <tr>
                    <td><strong><?php _e('Version de PHP', 'woo-pennylane'); ?></strong></td>
                    <td><?php echo phpversion(); ?></td>
                </tr>
            </table>
        </div>
    <?php endif; ?>
</div>