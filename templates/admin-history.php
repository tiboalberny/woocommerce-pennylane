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

?>
<div class="wrap woo-pennylane-history">
    <h2><?php _e('Historique des synchronisations Pennylane', 'woo-pennylane'); ?></h2>

    <div class="card">
        <p class="description">
            <?php _e('Cet historique vous permet de suivre toutes les synchronisations effectuées avec Pennylane, qu\'elles soient manuelles ou automatiques.', 'woo-pennylane'); ?>
        </p>
        
        <form method="get" action="<?php echo admin_url('admin.php'); ?>" id="sync-history-filter">
            <input type="hidden" name="page" value="woo-pennylane-settings">
            <input type="hidden" name="tab" value="history">
            
            <div class="tablenav top">
                <div class="alignleft actions">
                    <select name="sync_type">
                        <option value=""><?php _e('Tous les types', 'woo-pennylane'); ?></option>
                        <option value="product" <?php selected($sync_type, 'product'); ?>><?php _e('Produits', 'woo-pennylane'); ?></option>
                        <option value="customer" <?php selected($sync_type, 'customer'); ?>><?php _e('Clients', 'woo-pennylane'); ?></option>
                        <option value="order" <?php selected($sync_type, 'order'); ?>><?php _e('Commandes', 'woo-pennylane'); ?></option>
                        <option value="batch" <?php selected($sync_type, 'batch'); ?>><?php _e('Lots', 'woo-pennylane'); ?></option>
                    </select>
                    
                    <select name="sync_mode">
                        <option value=""><?php _e('Tous les modes', 'woo-pennylane'); ?></option>
                        <option value="manual" <?php selected($sync_mode, 'manual'); ?>><?php _e('Manuel', 'woo-pennylane'); ?></option>
                        <option value="automatic" <?php selected($sync_mode, 'automatic'); ?>><?php _e('Automatique', 'woo-pennylane'); ?></option>
                        <option value="cron" <?php selected($sync_mode, 'cron'); ?>><?php _e('Programmé', 'woo-pennylane'); ?></option>
                    </select>
                    
                    <select name="status">
                        <option value=""><?php _e('Tous les statuts', 'woo-pennylane'); ?></option>
                        <option value="success" <?php selected($status, 'success'); ?>><?php _e('Succès', 'woo-pennylane'); ?></option>
                        <option value="error" <?php selected($status, 'error'); ?>><?php _e('Erreur', 'woo-pennylane'); ?></option>
                        <option value="warning" <?php selected($status, 'warning'); ?>><?php _e('Avertissement', 'woo-pennylane'); ?></option>
                        <option value="skipped" <?php selected($status, 'skipped'); ?>><?php _e('Ignoré', 'woo-pennylane'); ?></option>
                    </select>
                    
                    <div class="date-inputs">
                        <label>
                            <?php _e('Du', 'woo-pennylane'); ?>
                            <input type="date" name="date_from" value="<?php echo esc_attr($date_from); ?>">
                        </label>
                        
                        <label>
                            <?php _e('Au', 'woo-pennylane'); ?>
                            <input type="date" name="date_to" value="<?php echo esc_attr($date_to); ?>">
                        </label>
                    </div>
                    
                    <input type="submit" class="button" value="<?php _e('Filtrer', 'woo-pennylane'); ?>">
                    
                    <?php if ($sync_type || $sync_mode || $status || $date_from !== $month_ago || $date_to !== $today || $search) : ?>
                        <a href="<?php echo admin_url('admin.php?page=woo-pennylane-settings&tab=history'); ?>" class="button"><?php _e('Réinitialiser', 'woo-pennylane'); ?></a>
                    <?php endif; ?>
                </div>
                
                <div class="alignright">
                    <p class="search-box">
                        <label class="screen-reader-text" for="sync-search-input"><?php _e('Rechercher', 'woo-pennylane'); ?></label>
                        <input type="search" id="sync-search-input" name="s" value="<?php echo esc_attr($search); ?>" placeholder="<?php _e('Rechercher dans l\'historique...', 'woo-pennylane'); ?>">
                        <input type="submit" id="search-submit" class="button" value="<?php _e('Rechercher', 'woo-pennylane'); ?>">
                    </p>
                </div>
                
                <div class="tablenav-pages">
                    <span class="displaying-num">
                        <?php
                        printf(
                            _n('%s élément', '%s éléments', $total_items, 'woo-pennylane'),
                            number_format_i18n($total_items)
                        );
                        ?>
                    </span>
                    
                    <?php if ($total_pages > 1) : ?>
                        <span class="pagination-links">
                            <?php
                            // Premier page
                            if ($current_page > 1) {
                                echo '<a href="' . esc_url(add_query_arg('paged', 1, $base_url)) . '" class="first-page" title="' . esc_attr__('Aller à la première page', 'woo-pennylane') . '">&laquo;</a>';
                                echo '<a href="' . esc_url(add_query_arg('paged', max(1, $current_page - 1), $base_url)) . '" class="prev-page" title="' . esc_attr__('Aller à la page précédente', 'woo-pennylane') . '">&lsaquo;</a>';
                            } else {
                                echo '<span class="tablenav-pages-navspan" aria-hidden="true">&laquo;</span>';
                                echo '<span class="tablenav-pages-navspan" aria-hidden="true">&lsaquo;</span>';
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
                                echo '<a href="' . esc_url(add_query_arg('paged', min($total_pages, $current_page + 1), $base_url)) . '" class="next-page" title="' . esc_attr__('Aller à la page suivante', 'woo-pennylane') . '">&rsaquo;</a>';
                                echo '<a href="' . esc_url(add_query_arg('paged', $total_pages, $base_url)) . '" class="last-page" title="' . esc_attr__('Aller à la dernière page', 'woo-pennylane') . '">&raquo;</a>';
                            } else {
                                echo '<span class="tablenav-pages-navspan" aria-hidden="true">&rsaquo;</span>';
                                echo '<span class="tablenav-pages-navspan" aria-hidden="true">&raquo;</span>';
                            }
                            ?>
                        </span>
                    <?php endif; ?>
                </div>
                
                <div class="clear"></div>
            </div>
        </form>

        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th scope="col" class="manage-column column-date"><?php echo get_sortable_link('created_at', __('Date', 'woo-pennylane'), 'created_at', 'DESC', $base_url); ?></th>
                    <th scope="col" class="manage-column column-type"><?php echo get_sortable_link('sync_type', __('Type', 'woo-pennylane'), 'sync_type', 'ASC', $base_url); ?></th>
                    <th scope="col" class="manage-column column-mode"><?php echo get_sortable_link('sync_mode', __('Mode', 'woo-pennylane'), 'sync_mode', 'ASC', $base_url); ?></th>
                    <th scope="col" class="manage-column column-object"><?php _e('Objet', 'woo-pennylane'); ?></th>
                    <th scope="col" class="manage-column column-status"><?php echo get_sortable_link('status', __('Statut', 'woo-pennylane'), 'status', 'ASC', $base_url); ?></th>
                    <th scope="col" class="manage-column column-message"><?php _e('Message', 'woo-pennylane'); ?></th>
                    <th scope="col" class="manage-column column-execution"><?php echo get_sortable_link('execution_time', __('Durée', 'woo-pennylane'), 'execution_time', 'ASC', $base_url); ?></th>
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
        
        <div class="tablenav bottom">
            <div class="alignleft actions">
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
            
            <div class="tablenav-pages">
                <?php if ($total_pages > 1) : ?>
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
                <?php endif; ?>
            </div>
            
            <div class="clear"></div>
        </div>
    </div>

    <style>
        .column-date { width: 15%; }
        .column-type { width: 8%; }
        .column-mode { width: 8%; }
        .column-object { width: 15%; }
        .column-status { width: 8%; }
        .column-message { width: 25%; }
        .column-execution { width: 8%; }
        .column-user { width: 13%; }
        
        .sync-status {
            display: inline-block;
            padding: 3px 8px;
            border-radius: 3px;
            font-size: 12px;
            font-weight: 500;
        }
        
        .sync-status-success {
            background-color: #edfaef;
            color: #0a3622;
        }
        
        .sync-status-error {
            background-color: #fbeaea;
            color: #8b343c;
        }
        
        .sync-status-warning {
            background-color: #fcf9e8;
            color: #8a6d3b;
        }
        
        .sync-status-skipped {
            background-color: #f0f0f1;
            color: #666;
        }
        
        #sync-history-filter .date-inputs {
            display: inline-block;
            margin: 0 10px;
        }
        
        #sync-history-filter .date-inputs label {
            margin-right: 10px;
        }
    </style>
</div>