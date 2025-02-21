<?php
if (!defined('ABSPATH')) {
    exit;
}
?>
<div class="wrap woo-pennylane-sync">
    <h2><?php _e('Synchronisation des commandes', 'woo-pennylane'); ?></h2>

    <div class="card">
        <h3><?php _e('Sélection de la période', 'woo-pennylane'); ?></h3>
        
        <div class="sync-controls">
            <div class="date-inputs">
                <label>
                    <?php _e('Date de début :', 'woo-pennylane'); ?>
                    <input type="date" id="sync-date-start" name="sync-date-start">
                </label>
                
                <label>
                    <?php _e('Date de fin :', 'woo-pennylane'); ?>
                    <input type="date" id="sync-date-end" name="sync-date-end">
                </label>
            </div>

            <button type="button" class="button button-primary" id="analyze-orders">
                <?php _e('Analyser les commandes', 'woo-pennylane'); ?>
            </button>
        </div>
    </div>

    <div id="sync-results" class="card" style="display: none;">
        <h3><?php _e('Résultats de l\'analyse', 'woo-pennylane'); ?></h3>
        
        <div class="sync-stats">
            <div class="stat-item">
                <span class="label"><?php _e('Commandes trouvées :', 'woo-pennylane'); ?></span>
                <span class="value" id="orders-found">0</span>
            </div>
            
            <div class="stat-item">
                <span class="label"><?php _e('Déjà synchronisées :', 'woo-pennylane'); ?></span>
                <span class="value" id="orders-synced">0</span>
            </div>
            
            <div class="stat-item">
                <span class="label"><?php _e('À synchroniser :', 'woo-pennylane'); ?></span>
                <span class="value" id="orders-to-sync">0</span>
            </div>
        </div>

        <div class="sync-actions">
            <button type="button" class="button button-primary" id="start-sync" disabled>
                <?php _e('Démarrer la synchronisation', 'woo-pennylane'); ?>
            </button>
        </div>
    </div>

    <div id="sync-progress" class="card" style="display: none;">
        <h3><?php _e('Progression', 'woo-pennylane'); ?></h3>
        
        <div class="progress-bar">
            <div class="progress-bar-inner" style="width: 0%;">
                <span class="progress-text">0%</span>
            </div>
        </div>

        <div class="sync-log">
            <h4><?php _e('Journal de synchronisation', 'woo-pennylane'); ?></h4>
            <div id="sync-log-content"></div>
        </div>
    </div>
</div>