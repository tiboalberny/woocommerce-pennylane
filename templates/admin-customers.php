<?php
if (!defined('ABSPATH')) {
    exit;
}
?>
<div class="wrap woo-pennylane-customers">
    <h2><?php _e('Synchronisation des clients', 'woo-pennylane'); ?></h2>

    <div class="card">
        <div class="customers-intro">
            <p><?php _e('Cette page vous permet de synchroniser vos clients WooCommerce vers Pennylane.', 'woo-pennylane'); ?></p>
            <p><?php _e('Les informations suivantes seront synchronisées :', 'woo-pennylane'); ?></p>
            <ul>
                <li><?php _e('Nom et prénom', 'woo-pennylane'); ?></li>
                <li><?php _e('Email', 'woo-pennylane'); ?></li>
                <li><?php _e('Téléphone', 'woo-pennylane'); ?></li>
                <li><?php _e('Adresse de facturation', 'woo-pennylane'); ?></li>
                <li><?php _e('Adresse de livraison (si différente)', 'woo-pennylane'); ?></li>
                <li><?php _e('Numéro de TVA (si disponible)', 'woo-pennylane'); ?></li>
            </ul>
        </div>

        <div class="customer-controls">
            <button type="button" class="button button-primary" id="analyze-customers">
                <?php _e('Analyser les clients', 'woo-pennylane'); ?>
            </button>
        </div>
    </div>

    <div id="customers-results" class="card" style="display: none;">
        <h3><?php _e('Résultats de l\'analyse', 'woo-pennylane'); ?></h3>
        
        <div class="customers-stats">
            <div class="stat-item">
                <span class="label"><?php _e('Clients trouvés :', 'woo-pennylane'); ?></span>
                <span class="value" id="customers-found">0</span>
            </div>
            
            <div class="stat-item">
                <span class="label"><?php _e('Déjà synchronisés :', 'woo-pennylane'); ?></span>
                <span class="value" id="customers-synced">0</span>
            </div>
            
            <div class="stat-item">
                <span class="label"><?php _e('À synchroniser :', 'woo-pennylane'); ?></span>
                <span class="value" id="customers-to-sync">0</span>
            </div>
        </div>

        <div class="sync-actions">
            <button type="button" class="button button-primary" id="start-customer-sync" disabled>
                <?php _e('Démarrer la synchronisation', 'woo-pennylane'); ?>
            </button>
        </div>
    </div>

    <div id="customer-sync-progress" class="card" style="display: none;">
        <h3><?php _e('Progression', 'woo-pennylane'); ?></h3>
        
        <div class="progress-bar">
            <div class="progress-bar-inner" style="width: 0%;">
                <span class="progress-text">0%</span>
            </div>
        </div>

        <div class="sync-log">
            <h4><?php _e('Journal de synchronisation', 'woo-pennylane'); ?></h4>
            <div id="customer-sync-log-content"></div>
        </div>
    </div>
</div>