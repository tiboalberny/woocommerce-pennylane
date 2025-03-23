<?php
if (!defined('ABSPATH')) {
    exit;
}
?>
<div class="wrap woo-pennylane-guest-customers">
    <h2><?php _e('Synchronisation des clients invités', 'woo-pennylane'); ?></h2>

    <div class="card">
        <div class="customers-intro">
            <p><?php _e('Cette page vous permet de synchroniser les clients invités (sans compte WooCommerce) vers Pennylane.', 'woo-pennylane'); ?></p>
            <p><?php _e('Les clients invités sont des personnes qui ont passé commande sans créer de compte sur votre site.', 'woo-pennylane'); ?></p>
            <p><?php _e('Les informations suivantes seront synchronisées :', 'woo-pennylane'); ?></p>
            <ul>
                <li><?php _e('Nom et prénom (tirés de la commande)', 'woo-pennylane'); ?></li>
                <li><?php _e('Email', 'woo-pennylane'); ?></li>
                <li><?php _e('Téléphone', 'woo-pennylane'); ?></li>
                <li><?php _e('Adresse de facturation', 'woo-pennylane'); ?></li>
                <li><?php _e('Adresse de livraison (si différente)', 'woo-pennylane'); ?></li>
            </ul>
        </div>

        <div class="guest-customer-controls">
            <button type="button" class="button button-primary" id="analyze-guest-customers">
                <?php _e('Analyser les clients invités', 'woo-pennylane'); ?>
            </button>
        </div>
    </div>

    <div id="guest-customers-results" class="card" style="display: none;">
        <h3><?php _e('Résultats de l\'analyse', 'woo-pennylane'); ?></h3>
        
        <div class="customers-stats">
            <div class="stat-item">
                <span class="label"><?php _e('Clients invités trouvés :', 'woo-pennylane'); ?></span>
                <span class="value" id="guest-customers-found">0</span>
            </div>
            
            <div class="stat-item">
                <span class="label"><?php _e('Déjà synchronisés :', 'woo-pennylane'); ?></span>
                <span class="value" id="guest-customers-synced">0</span>
            </div>
            
            <div class="stat-item">
                <span class="label"><?php _e('À synchroniser :', 'woo-pennylane'); ?></span>
                <span class="value" id="guest-customers-to-sync">0</span>
            </div>
        </div>

        <div class="sync-actions">
            <button type="button" class="button button-primary" id="start-guest-customer-sync" disabled>
                <?php _e('Démarrer la synchronisation', 'woo-pennylane'); ?>
            </button>
        </div>
    </div>

    <div id="guest-customer-sync-progress" class="card" style="display: none;">
        <h3><?php _e('Progression', 'woo-pennylane'); ?></h3>
        
        <div class="progress-bar">
            <div class="progress-bar-inner" style="width: 0%;">
                <span class="progress-text">0%</span>
            </div>
        </div>

        <div class="sync-log">
            <h4><?php _e('Journal de synchronisation', 'woo-pennylane'); ?></h4>
            <div id="guest-customer-sync-log-content"></div>
        </div>
    </div>
</div>