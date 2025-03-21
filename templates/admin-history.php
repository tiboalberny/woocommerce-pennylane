<?php
if (!defined('ABSPATH')) {
    exit;
}

// Vérifie les permissions utilisateur
if (!current_user_can('manage_woocommerce')) {
    wp_die(__('Vous n\'avez pas les permissions suffisantes pour accéder à cette page.', 'woo-pennylane'));
}

// Récupérer la date actuelle
$today = date('Y-m-d');
$month_ago = date('Y-m-d', strtotime('-30 days'));

// Récupérer les paramètres de filtrage
$sync_type = isset($_GET['sync_type']) ? sanitize_text_field($_GET['sync_type']) : '';
$sync_mode = isset($_GET['sync_mode']) ? sanitize_text_field($_GET['sync_mode']) : '';
$status = isset($_GET['status']) ? sanitize_text_field($_GET['status']) : '';
$date_from = isset($_GET['date_from']) ? sanitize_text_field($_GET['date_from']) : $month_ago;
$date_to = isset($_GET['date_to']) ? sanitize_text_field($_GET['date_to']) : $today;
$search = isset($_GET['s']) ? sanitize_text_field($_GET['s']) : '';

// Pagination
$per_page = 20;
$current_page = isset($_GET['paged']) ? absint($_GET['paged']) : 1;
$offset = ($current_page - 1) * $per_page;

// Récupérer l'historique
global $woo_pennylane_sync_history;

// Vérifier que l'historique est disponible
if (!$woo_pennylane_sync_history) {
    echo '<div class="notice notice-error"><p>' . __('Erreur: Le module d\'historique n\'est pas correctement initialisé.', 'woo-pennylane') . '</p></div>';
    return;
}

// Arguments de filtrage
$args = array(
    'number' => $per_page,
    'offset' => $offset,
    'sync_type' => $sync_type,
    'sync_mode' => $sync_mode,
    'status' => $status,
    'date_from' => $date_from,
    'date_to' => $date_to,
    'search' => $search
);

// Compter le nombre total de résultats
$total_items = $woo_pennylane_sync_history->count_entries($args);

// Récupérer les entrées
$entries = $woo_pennylane_sync_history->get_entries($args);

// Calculer la pagination
$total_pages = ceil($total_items / $per_page);

// Lien de base pour la pagination et le tri
$base_url = add_query_arg(array(
    'page' => 'woo-pennylane-settings',
    'tab' => 'history',
    'sync_type' => $sync_type,
    'sync_mode' => $sync_mode,
    'status' => $status,
    'date_from' => $date_from,
    'date_to' => $date_to,
    's' => $search
), admin_url('admin.php'));

// Fonction pour générer les liens de tri
function get_sortable_link($column, $label, $current_orderby, $current_order, $base_url) {
    $current_sort = $current_orderby === $column;
    $new_order = $current_sort && $current_order === 'ASC' ? 'DESC' : 'ASC';
    
    $class = $current_sort ? 'sorted ' . strtolower($current_order) : 'sortable';
    $arrow = $current_sort ? ($current_order === 'ASC' ? '&uarr;' : '&darr;') : '';
    
    return '<a href="' . esc_url(add_query_arg(array('orderby' => $column, 'order' => $new_order), $base_url)) . '" class="' . $class . '"><span>' . $label . '</span> ' . $arrow . '</a>';
}

// Current orderby and order params
$current_orderby = isset($_GET['orderby']) ? sanitize_text_field($_GET['orderby']) : 'created_at';
$current_order = isset($_GET['order']) && strtoupper($_GET['order']) === 'ASC' ? 'ASC' : 'DESC';
?>
<div class="wrap woo-pennylane-history">
    <h2><?php _e('Historique des synchronisations Pennylane', 'woo-pennylane'); ?></h2>

    <div class="card">
        <p class="description">
            <?php _e('Cet historique vous permet de suivre toutes les synchronisations effectuées avec Pennylane, qu\'elles soient manuelles ou automatiques.', 'woo-pennylane'); ?>
        </p>
        
        <!-- NOUVELLE STRUCTURE POUR LE FORMULAIRE DE FILTRAGE -->
        <div style="margin-bottom: 40px; border-bottom: 1px solid #ccc; padding-bottom: 20px;">
            <form method="get" action="<?php echo admin_url('admin.php'); ?>" id="sync-history-filter">
                <!-- Champs cachés nécessaires -->
                <input type="hidden" name="page" value="woo-pennylane-settings">
                <input type="hidden" name="tab" value="history">
                <input type="hidden" name="orderby" value="<?php echo esc_attr($current_orderby); ?>">
                <input type="hidden" name="order" value="<?php echo esc_attr($current_order); ?>">
                
                <!-- Début du conteneur en grille pour tous les filtres -->
                <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); gap: 15px; margin-bottom: 20px;">
                    <!-- Type de synchronisation -->
                    <div>
                        <label for="sync_type" style="display: block; margin-bottom: 5px; font-weight: 500;"><?php _e('Type', 'woo-pennylane'); ?></label>
                        <select id="sync_type" name="sync_type" style="width: 100%; max-width: 100%;">
                            <option value=""><?php _e('Tous les types', 'woo-pennylane'); ?></option>
                            <option value="product" <?php selected($sync_type, 'product'); ?>><?php _e('Produits', 'woo-pennylane'); ?></option>
                            <option value="customer" <?php selected($sync_type, 'customer'); ?>><?php _e('Clients', 'woo-pennylane'); ?></option>
                            <option value="order" <?php selected($sync_type, 'order'); ?>><?php _e('Commandes', 'woo-pennylane'); ?></option>
                            <option value="batch" <?php selected($sync_type, 'batch'); ?>><?php _e('Lots', 'woo-pennylane'); ?></option>
                        </select>
                    </div>
                    
                    <!-- Mode de synchronisation -->
                    <div>
                        <label for="sync_mode" style="display: block; margin-bottom: 5px; font-weight: 500;"><?php _e('Mode', 'woo-pennylane'); ?></label>
                        <select id="sync_mode" name="sync_mode" style="width: 100%; max-width: 100%;">
                            <option value=""><?php _e('Tous les modes', 'woo-pennylane'); ?></option>
                            <option value="manual" <?php selected($sync_mode, 'manual'); ?>><?php _e('Manuel', 'woo-pennylane'); ?></option>
                            <option value="automatic" <?php selected($sync_mode, 'automatic'); ?>><?php _e('Automatique', 'woo-pennylane'); ?></option>
                            <option value="cron" <?php selected($sync_mode, 'cron'); ?>><?php _e('Programmé', 'woo-pennylane'); ?></option>
                        </select>
                    </div>
                    
                    <!-- Statut -->
                    <div>
                        <label for="status" style="display: block; margin-bottom: 5px; font-weight: 500;"><?php _e('Statut', 'woo-pennylane'); ?></label>
                        <select id="status" name="status" style="width: 100%; max-width: 100%;">
                            <option value=""><?php _e('Tous les statuts', 'woo-pennylane'); ?></option>
                            <option value="success" <?php selected($status, 'success'); ?>><?php _e('Succès', 'woo-pennylane'); ?></option>
                            <option value="error" <?php selected($status, 'error'); ?>><?php _e('Erreur', 'woo-pennylane'); ?></option>
                            <option value="warning" <?php selected($status, 'warning'); ?>><?php _e('Avertissement', 'woo-pennylane'); ?></option>
                            <option value="skipped" <?php selected($status, 'skipped'); ?>><?php _e('Ignoré', 'woo-pennylane'); ?></option>
                        </select>
                    </div>
                    
                    <!-- Date de début -->
                    <div>
                        <label for="date_from" style="display: block; margin-bottom: 5px; font-weight: 500;"><?php _e('Du', 'woo-pennylane'); ?></label>
                        <input type="date" id="date_from" name="date_from" value="<?php echo esc_attr($date_from); ?>" style="width: 100%; max-width: 100%;">
                    </div>
                    
                    <!-- Date de fin -->
                    <div>
                        <label for="date_to" style="display: block; margin-bottom: 5px; font-weight: 500;"><?php _e('Au', 'woo-pennylane'); ?></label>
                        <input type="date" id="date_to" name="date_to" value="<?php echo esc_attr($date_to); ?>" style="width: 100%; max-width: 100%;">
                    </div>
                    
                    <!-- Recherche -->
                    <div>
                        <label for="sync-search-input" style="display: block; margin-bottom: 5px; font-weight: 500;"><?php _e('Rechercher', 'woo-pennylane'); ?></label>
                        <input type="search" id="sync-search-input" name="s" value="<?php echo esc_attr($search); ?>" placeholder="<?php _e('Rechercher...', 'woo-pennylane'); ?>" style="width: 100%; max-width: 100%;">
                    </div>
                </div>
                
                <!-- Actions de filtrage -->
                <div style="margin-top: 20px; display: flex; gap: 10px;">
                    <input type="submit" class="button button-primary" value="<?php _e('Filtrer', 'woo-pennylane'); ?>">
                    
                    <?php if ($sync_type || $sync_mode || $status || $date_from !== $month_ago || $date_to !== $today || $search) : ?>
                        <a href="<?php echo admin_url('admin.php?page=woo-pennylane-settings&tab=history'); ?>" class="button"><?php _e('Réinitialiser', 'woo-pennylane'); ?></a>
                    <?php endif; ?>
                </div>
            </form>
            
            <!-- Info sur le nombre total d'éléments -->
            <div style="margin-top: 20px; text-align: right;">
                <span>
                    <?php
                    printf(
                        _n('%s élément trouvé', '%s éléments trouvés', $total_items, 'woo-pennylane'),
                        '<strong>' . number_format_i18n($total_items) . '</strong>'
                    );
                    ?>
                </span>
            </div>
        </div>

        <!-- TABLEAU DES RÉSULTATS -->
        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th scope="col" class="manage-column column-date"><?php echo get_sortable_link('created_at', __('Date', 'woo-pennylane'), $current_orderby, $current_order, $base_url); ?></th>
                    <th scope="col" class="manage-column column-type"><?php echo get_sortable_link('sync_type', __('Type', 'woo-pennylane'), $current_orderby, $current_order, $base_url); ?></th>
                    <th scope="col" class="manage-column column-mode"><?php echo get_sortable_link('sync_mode', __('Mode', 'woo-pennylane'), $current_orderby, $current_order, $base_url); ?></th>
                    <th scope="col" class="manage-column column-object"><?php _e('Objet', 'woo-pennylane'); ?></th>
                    <th scope="col" class="manage-column column-status"><?php echo get_sortable_link('status', __('Statut', 'woo-pennylane'), $current_orderby, $current_order, $base_url); ?></th>
                    <th scope="col" class="manage-column column-message"><?php _e('Message', 'woo-pennylane'); ?></th>
                    <th scope="col" class="manage-column column-execution"><?php echo get_sortable_link('execution_time', __('Durée', 'woo-pennylane'), $current_orderby, $current_order, $base_url); ?></th>
                    <th scope="col" class="manage-column column-user"><?php _e('Utilisateur', 'woo-pennylane'); ?></th>
                </tr>
            </thead>
            
            <tbody>
                <?php if (empty($entries)) : ?>
                    <tr>
                        <td colspan="8" class="colspanchange">
                            <?php _e('Aucune entrée trouvée dans l\'historique.', 'woo-pennylane'); ?>
                        </td>
                    </tr>
                <?php else : ?>
                    <?php foreach ($entries as $entry) : ?>
                        <tr>
                            <td class="column-date">
                                <?php 
                                $date = new DateTime($entry->created_at);
                                echo esc_html($date->format('d/m/Y H:i:s')); 
                                ?>
                            </td>
                            <td class="column-type">
                                <?php echo esc_html($woo_pennylane_sync_history->get_type_label($entry->sync_type)); ?>
                            </td>
                            <td class="column-mode">
                                <?php echo esc_html($woo_pennylane_sync_history->get_mode_label($entry->sync_mode)); ?>
                            </td>
                            <td class="column-object">
                                <?php 
                                if ($entry->object_id && $entry->object_name) {
                                    // Lien vers l'objet selon son type
                                    $url = '';
                                    switch ($entry->sync_type) {
                                        case 'order':
                                            $url = admin_url('post.php?post=' . $entry->object_id . '&action=edit');
                                            break;
                                        case 'product':
                                            $url = admin_url('post.php?post=' . $entry->object_id . '&action=edit');
                                            break;
                                        case 'customer':
                                            $url = admin_url('user-edit.php?user_id=' . $entry->object_id);
                                            break;
                                    }
                                    
                                    if ($url) {
                                        echo '<a href="' . esc_url($url) . '" title="' . esc_attr($entry->object_name) . '">' . esc_html($entry->object_name) . '</a>';
                                    } else {
                                        echo esc_html($entry->object_name);
                                    }
                                } else {
                                    echo '<em>' . __('N/A', 'woo-pennylane') . '</em>';
                                }
                                ?>
                            </td>
                            <td class="column-status">
                                <?php 
                                switch ($entry->status) {
                                    case 'success':
                                        echo '<span class="sync-status sync-status-success">' . __('Succès', 'woo-pennylane') . '</span>';
                                        break;
                                    case 'error':
                                        echo '<span class="sync-status sync-status-error">' . __('Erreur', 'woo-pennylane') . '</span>';
                                        break;
                                    case 'warning':
                                        echo '<span class="sync-status sync-status-warning">' . __('Avertissement', 'woo-pennylane') . '</span>';
                                        break;
                                    case 'skipped':
                                        echo '<span class="sync-status sync-status-skipped">' . __('Ignoré', 'woo-pennylane') . '</span>';
                                        break;
                                    default:
                                        echo '<span class="sync-status">' . esc_html($entry->status) . '</span>';
                                        break;
                                }
                                ?>
                            </td>
                            <td class="column-message">
                                <?php 
                                if (!empty($entry->message)) {
                                    $short_message = wp_trim_words($entry->message, 10, '...');
                                    echo '<span title="' . esc_attr($entry->message) . '">' . esc_html($short_message) . '</span>';
                                } else {
                                    echo '<em>' . __('N/A', 'woo-pennylane') . '</em>';
                                }
                                ?>
                            </td>
                            <td class="column-execution">
                                <?php 
                                if ($entry->execution_time !== null) {
                                    if ($entry->execution_time < 0.01) {
                                        echo '<1ms';
                                    } elseif ($entry->execution_time < 1) {
                                        printf('%.0fms', $entry->execution_time * 1000);
                                    } else {
                                        printf('%.2fs', $entry->execution_time);
                                    }
                                } else {
                                    echo '<em>' . __('N/A', 'woo-pennylane') . '</em>';
                                }
                                ?>
                            </td>
                            <td class="column-user">
                                <?php echo esc_html($woo_pennylane_sync_history->get_user_name($entry->user_id)); ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
            
            <tfoot>
                <tr>
                    <th scope="col" class="manage-column column-date"><?php _e('Date', 'woo-pennylane'); ?></th>
                    <th scope="col" class="manage-column column-type"><?php _e('Type', 'woo-pennylane'); ?></th>
                    <th scope="col" class="manage-column column-mode"><?php _e('Mode', 'woo-pennylane'); ?></th>
                    <th scope="col" class="manage-column column-object"><?php _e('Objet', 'woo-pennylane'); ?></th>
                    <th scope="col" class="manage-column column-status"><?php _e('Statut', 'woo-pennylane'); ?></th>
                    <th scope="col" class="manage-column column-message"><?php _e('Message', 'woo-pennylane'); ?></th>
                    <th scope="col" class="manage-column column-execution"><?php _e('Durée', 'woo-pennylane'); ?></th>
                    <th scope="col" class="manage-column column-user"><?php _e('Utilisateur', 'woo-pennylane'); ?></th>
                </tr>
            </tfoot>
        </table>
        
        <!-- PAGINATION ET PURGE -->
        <div style="display: flex; justify-content: space-between; align-items: center; margin-top: 20px;">
            <!-- Formulaire de purge -->
            <div>
                <form method="post" action="">
                    <?php wp_nonce_field('woo_pennylane_purge_history', 'woo_pennylane_nonce'); ?>
                    <input type="hidden" name="action" value="woo_pennylane_purge_history">
                    <label>
                        <select name="days">
                            <option value="30"><?php _e('30 jours', 'woo-pennylane'); ?></option>
                            <option value="60"><?php _e('60 jours', 'woo-pennylane'); ?></option>
                            <option value="90" selected><?php _e('90 jours', 'woo-pennylane'); ?></option>
                            <option value="180"><?php _e('6 mois', 'woo-pennylane'); ?></option>
                            <option value="365"><?php _e('1 an', 'woo-pennylane'); ?></option>
                        </select>
                    </label>
                    <input type="submit" class="button" value="<?php _e('Purger l\'ancien historique', 'woo-pennylane'); ?>" onclick="return confirm('<?php _e('Êtes-vous sûr de vouloir purger l\'ancien historique ? Cette action est irréversible.', 'woo-pennylane'); ?>');">
                </form>
            </div>
            
            <!-- Pagination -->
            <?php if ($total_pages > 1) : ?>
                <div style="margin-left: auto;">
                    <div class="tablenav-pages">
                        <span class="pagination-links">
                            <?php
                            // Premier page
                            if ($current_page > 1) {
                                echo '<a href="' . esc_url(add_query_arg('paged', 1, $base_url)) . '" class="first-page">&laquo;</a>';
                                echo '<a href="' . esc_url(add_query_arg('paged', max(1, $current_page - 1), $base_url)) . '" class="prev-page">&lsaquo;</a>';
                            } else {
                                echo '<span class="tablenav-pages-navspan">&laquo;</span>';
                                echo '<span class="tablenav-pages-navspan">&lsaquo;</span>';
                            }
                            
                            // Numéro de page
                            echo '<span class="paging-input">';
                            echo sprintf(
                                '<input class="current-page" type="text" name="paged" value="%s" size="1"> ' . __('sur', 'woo-pennylane') . ' <span class="total-pages">%s</span>',
                                $current_page,
                                $total_pages
                            );
                            echo '</span>';
                            
                            // Page suivante et dernière page
                            if ($current_page < $total_pages) {
                                echo '<a href="' . esc_url(add_query_arg('paged', min($total_pages, $current_page + 1), $base_url)) . '" class="next-page">&rsaquo;</a>';
                                echo '<a href="' . esc_url(add_query_arg('paged', $total_pages, $base_url)) . '" class="last-page">&raquo;</a>';
                            } else {
                                echo '<span class="tablenav-pages-navspan">&rsaquo;</span>';
                                echo '<span class="tablenav-pages-navspan">&raquo;</span>';
                            }
                            ?>
                        </span>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Modal de détails du log (inchangé) -->
    <div id="log-details-modal" class="woo-pennylane-modal" style="display:none;">
        <div class="woo-pennylane-modal-content">
            <span class="woo-pennylane-modal-close">&times;</span>
            <h2><?php _e('Détails du log', 'woo-pennylane'); ?></h2>
            <div class="woo-pennylane-modal-body">
                <div class="log-details-content"></div>
            </div>
        </div>
    </div>

    <script>
    jQuery(document).ready(function($) {
        // Afficher/masquer les détails des données
        $('.toggle-data').on('click', function(e) {
            e.preventDefault();
            $(this).next('.data-details').toggle();
        });
        
        // Modal des détails du log
        $('.view-log-details').on('click', function(e) {
            e.preventDefault();
            var logId = $(this).data('id');
            
            // Spinner de chargement
            var modal = $('#log-details-modal');
            var content = modal.find('.log-details-content');
            content.html('<div class="spinner is-active"></div>');
            modal.show();
            
            // Charger les détails du log via AJAX
            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'woo_pennylane_get_log_details',
                    nonce: '<?php echo wp_create_nonce('woo_pennylane_get_log_details'); ?>',
                    log_id: logId
                },
                success: function(response) {
                    if (response.success) {
                        content.html(response.data);
                    } else {
                        content.html('<div class="notice notice-error"><p>' + response.data + '</p></div>');
                    }
                },
                error: function() {
                    content.html('<div class="notice notice-error"><p><?php _e('Erreur lors du chargement des détails', 'woo-pennylane'); ?></p></div>');
                }
            });
        });
        
        // Fermer la modal
        $('.woo-pennylane-modal-close').on('click', function() {
            $('#log-details-modal').hide();
        });
        
        // Fermer la modal en cliquant en dehors
        $(window).on('click', function(e) {
            if ($(e.target).is('#log-details-modal')) {
                $('#log-details-modal').hide();
            }
        });
    });
    </script>
</div>
