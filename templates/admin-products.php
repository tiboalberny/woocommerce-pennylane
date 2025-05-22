<?php
if (!defined('ABSPATH')) {
    exit;
}
?>
<div class="wrap woo-pennylane-products">
    <h2><?php _e('Synchronisation des produits', 'woo-pennylane'); ?></h2>

    <div class="card">
        <div class="products-intro">
            <p><?php _e('Cette page vous permet de synchroniser vos produits WooCommerce vers Pennylane.', 'woo-pennylane'); ?></p>
            <p><?php _e('Les informations suivantes seront synchronisées :', 'woo-pennylane'); ?></p>
            <ul>
                <li><?php _e('Nom et description', 'woo-pennylane'); ?></li>
                <li><?php _e('Prix et taux de TVA', 'woo-pennylane'); ?></li>
                <li><?php _e('Référence (SKU)', 'woo-pennylane'); ?></li>
                <li><?php _e('Stock', 'woo-pennylane'); ?></li>
                <li><?php _e('Catégories', 'woo-pennylane'); ?></li>
                <li><?php _e('Variations (pour les produits variables)', 'woo-pennylane'); ?></li>
            </ul>
        </div>

        <div class="product-controls">
            <button type="button" class="button button-primary" id="analyze-products">
                <?php _e('Analyser les produits', 'woo-pennylane'); ?>
            </button>
        </div>
    </div>

    <div id="products-results" class="card" style="display: none;">
        <h3><?php _e('Résultats de l\'analyse', 'woo-pennylane'); ?></h3>
        
        <div class="products-stats">
            <div class="stat-item">
                <span class="label"><?php _e('Produits WooCommerce trouvés :', 'woo-pennylane'); ?></span>
                <span class="value" id="products-total-wc">0</span>
            </div>
            
            <div class="stat-item">
                <span class="label"><?php _e('Produits à créer sur Pennylane :', 'woo-pennylane'); ?></span>
                <span class="value" id="products-to-create">0</span>
            </div>

            <div class="stat-item">
                <span class="label"><?php _e('Produits à mettre à jour sur Pennylane :', 'woo-pennylane'); ?></span>
                <span class="value" id="products-to-update">0</span>
            </div>

            <div class="stat-item">
                <span class="label"><?php _e('Produits déjà à jour sur Pennylane :', 'woo-pennylane'); ?></span>
                <span class="value" id="products-up-to-date">0</span>
            </div>
        </div>

        <div class="sync-actions">
            <button type="button" class="button button-primary" id="start-product-sync" disabled>
                <?php _e('Démarrer la synchronisation', 'woo-pennylane'); ?>
            </button>
        </div>
    </div>

    <div id="product-sync-progress" class="card" style="display: none;">
        <h3><?php _e('Progression', 'woo-pennylane'); ?></h3>
        
        <div class="progress-bar">
            <div class="progress-bar-inner" style="width: 0%;">
                <span class="progress-text">0%</span>
            </div>
        </div>

        <div class="sync-log">
            <h4><?php _e('Journal de synchronisation', 'woo-pennylane'); ?></h4>
            <div id="product-sync-log-content"></div>
        </div>
    </div>
</div>