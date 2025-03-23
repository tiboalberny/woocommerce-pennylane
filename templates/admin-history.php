<?php
if (!defined('ABSPATH')) {
    exit;
}

// Vérifier si la classe logger existe
if (!class_exists('WooPennylane_Logger')) {
    ?>
    <div class="notice notice-error">
        <p><?php _e('Erreur: La classe de journalisation n\'est pas disponible.', 'woo-pennylane'); ?></p>
    </div>
    <?php
    return;
}

// Vérifier si la table de logs existe
if (!WooPennylane_Logger::is_history_module_ready()) {
    ?>
    <div class="notice notice-error">
        <p><?php _e('Erreur: Le module d\'historique n\'est pas correctement initialisé.', 'woo-pennylane'); ?></p>
        <p><?php _e('La table de logs n\'existe pas. Essayez de désactiver puis réactiver le plugin.', 'woo-pennylane'); ?></p>
        <p>
            <button type="button" class="button" id="create-logs-table">
                <?php _e('Créer la table de logs maintenant', 'woo-pennylane'); ?>
            </button>
            <span class="spinner" style="float:none;"></span>
            <span id="create-table-result"></span>
        </p>
    </div>
    
    <script>
    jQuery(document).ready(function($) {
        $('#create-logs-table').on('click', function() {
            var button = $(this);
            var spinner = button.next('.spinner');
            var result = $('#create-table-result');
            
            button.prop('disabled', true);
            spinner.addClass('is-active');
            
            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'woo_pennylane_create_logs_table',
                    nonce: '<?php echo wp_create_nonce('woo_pennylane_admin'); ?>'
                },
                success: function(response) {
                    if (response.success) {
                        result.html('<span style="color:green;">' + response.data + '</span>');
                        setTimeout(function() {
                            window.location.reload();
                        }, 1500);
                    } else {
                        result.html('<span style="color:red;">' + response.data + '</span>');
                    }
                },
                error: function() {
                    result.html('<span style="color:red;">Une erreur s\'est produite</span>');
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
    return;
}

// Récupération des logs
$per_page = 20;
$current_page = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
$entity_type = isset($_GET['entity_type']) ? sanitize_text_field($_GET['entity_type']) : '';
$status = isset($_GET['status']) ? sanitize_text_field($_GET['status']) : '';

$args = array(
    'per_page' => $per_page,
    'page' => $current_page,
    'entity_type' => $entity_type,
    'status' => $status
);

$logs = WooPennylane_Logger::get_logs($args);
$total_logs = WooPennylane_Logger::count_logs(array(
    'entity_type' => $entity_type,
    'status' => $status
));

$total_pages = ceil($total_logs / $per_page);

// Récupération des types d'entités et statuts - même s'il n'y a pas encore de logs
global $wpdb;
$table_name = $wpdb->prefix . 'woo_pennylane_logs';

// Définition des types d'entités par défaut
$default_entity_types = array('order', 'customer', 'product');
$entity_types = $wpdb->get_col("SELECT DISTINCT entity_type FROM $table_name");
// Si aucun type n'est encore enregistré, utiliser les types par défaut
if (empty($entity_types)) {
    $entity_types = $default_entity_types;
}

// Définition des statuts par défaut
$default_statuses = array('success', 'error', 'warning', 'info');
$statuses = $wpdb->get_col("SELECT DISTINCT status FROM $table_name");
// Si aucun statut n'est encore enregistré, utiliser les statuts par défaut
if (empty($statuses)) {
    $statuses = $default_statuses;
}
?>

<div class="wrap woo-pennylane-history">
    <h2><?php _e('Historique de synchronisation Pennylane', 'woo-pennylane'); ?></h2>
    
    <?php if (empty($logs) && empty($entity_type) && empty($status)): ?>
        <div class="notice notice-warning">
            <p><?php _e('Aucun historique disponible. Les activités de synchronisation seront enregistrées ici.', 'woo-pennylane'); ?></p>
        </div>
    <?php endif; ?>
    
    <div class="card">
        <!-- Filtres - affichés même s'il n'y a pas encore de logs -->
        <form method="get">
            <input type="hidden" name="page" value="woo-pennylane-settings">
            <input type="hidden" name="tab" value="history">
            
            <div class="tablenav top">
                <div class="alignleft actions">
                    <!-- Menu déroulant pour les types d'entités -->
                    <select name="entity_type" id="entity-type-filter">
                        <option value=""><?php _e('Tous les types', 'woo-pennylane'); ?></option>
                        <?php foreach ($entity_types as $type): ?>
                            <option value="<?php echo esc_attr($type); ?>" <?php selected($entity_type, $type); ?>>
                                <?php 
                                switch($type) {
                                    case 'order':
                                        echo __('Commande', 'woo-pennylane');
                                        break;
                                    case 'customer':
                                        echo __('Client', 'woo-pennylane');
                                        break;
                                    case 'product':
                                        echo __('Produit', 'woo-pennylane');
                                        break;
                                    default:
                                        echo esc_html($type);
                                }
                                ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    
                    <!-- Menu déroulant pour les statuts -->
                    <select name="status" id="status-filter">
                        <option value=""><?php _e('Tous les statuts', 'woo-pennylane'); ?></option>
                        <?php foreach ($statuses as $stat): ?>
                            <option value="<?php echo esc_attr($stat); ?>" <?php selected($status, $stat); ?>>
                                <?php 
                                switch($stat) {
                                    case 'success':
                                        echo __('Succès', 'woo-pennylane');
                                        break;
                                    case 'error':
                                        echo __('Erreur', 'woo-pennylane');
                                        break;
                                    case 'warning':
                                        echo __('Avertissement', 'woo-pennylane');
                                        break;
                                    case 'info':
                                        echo __('Information', 'woo-pennylane');
                                        break;
                                    default:
                                        echo esc_html($stat);
                                }
                                ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    
                    <!-- Filtres de date -->
                    <label for="date-from">
                        <?php _e('De:', 'woo-pennylane'); ?>
                        <input type="date" id="date-from" name="date_from" value="<?php echo isset($_GET['date_from']) ? esc_attr($_GET['date_from']) : ''; ?>">
                    </label>
                    
                    <label for="date-to">
                        <?php _e('À:', 'woo-pennylane'); ?>
                        <input type="date" id="date-to" name="date_to" value="<?php echo isset($_GET['date_to']) ? esc_attr($_GET['date_to']) : ''; ?>">
                    </label>
                    
                    <input type="submit" class="button" value="<?php _e('Filtrer', 'woo-pennylane'); ?>">
                </div>
                
                <?php if ($total_pages > 1): ?>
                    <div class="tablenav-pages">
                        <span class="displaying-num">
                            <?php printf(_n('%s élément', '%s éléments', $total_logs, 'woo-pennylane'), number_format_i18n($total_logs)); ?>
                        </span>
                        <span class="pagination-links">
                            <?php
                            echo paginate_links(array(
                                'base' => add_query_arg('paged', '%#%'),
                                'format' => '',
                                'prev_text' => '&laquo;',
                                'next_text' => '&raquo;',
                                'total' => $total_pages,
                                'current' => $current_page
                            ));
                            ?>
                        </span>
                    </div>
                <?php endif; ?>
                
                <br class="clear">
            </div>
        </form>
        
        <!-- Table des logs -->
        <table class="widefat striped">
            <thead>
                <tr>
                    <th><?php _e('Date', 'woo-pennylane'); ?></th>
                    <th><?php _e('Type', 'woo-pennylane'); ?></th>
                    <th><?php _e('ID', 'woo-pennylane'); ?></th>
                    <th><?php _e('Statut', 'woo-pennylane'); ?></th>
                    <th><?php _e('Message', 'woo-pennylane'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php if (!empty($logs)): ?>
                    <?php foreach ($logs as $log): ?>
                        <tr>
                            <td>
                                <?php echo esc_html(date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($log->created_at))); ?>
                            </td>
                            <td>
                                <?php 
                                switch($log->entity_type) {
                                    case 'order':
                                        echo __('Commande', 'woo-pennylane');
                                        break;
                                    case 'customer':
                                        echo __('Client', 'woo-pennylane');
                                        break;
                                    case 'product':
                                        echo __('Produit', 'woo-pennylane');
                                        break;
                                    default:
                                        echo esc_html($log->entity_type);
                                }
                                ?>
                            </td>
                            <td>
                                <?php 
                                $entity_link = '#';
                                $entity_name = $log->entity_id;
                                
                                switch($log->entity_type) {
                                    case 'order':
                                        $entity_link = admin_url('post.php?post=' . $log->entity_id . '&action=edit');
                                        $entity_name = '#' . $log->entity_id;
                                        break;
                                    case 'customer':
                                        $entity_link = admin_url('user-edit.php?user_id=' . $log->entity_id);
                                        $user = get_userdata($log->entity_id);
                                        if ($user) {
                                            $entity_name = $user->user_login . ' (#' . $log->entity_id . ')';
                                        }
                                        break;
                                    case 'product':
                                        $entity_link = admin_url('post.php?post=' . $log->entity_id . '&action=edit');
                                        $product_title = get_the_title($log->entity_id);
                                        if ($product_title) {
                                            $entity_name = $product_title . ' (#' . $log->entity_id . ')';
                                        }
                                        break;
                                }
                                ?>
                                <a href="<?php echo esc_url($entity_link); ?>"><?php echo esc_html($entity_name); ?></a>
                            </td>
                            <td>
                                <span class="status-<?php echo esc_attr($log->status); ?>">
                                    <?php 
                                    switch($log->status) {
                                        case 'success':
                                            echo __('Succès', 'woo-pennylane');
                                            break;
                                        case 'error':
                                            echo __('Erreur', 'woo-pennylane');
                                            break;
                                        case 'warning':
                                            echo __('Avertissement', 'woo-pennylane');
                                            break;
                                        case 'info':
                                            echo __('Information', 'woo-pennylane');
                                            break;
                                        default:
                                            echo esc_html($log->status);
                                    }
                                    ?>
                                </span>
                            </td>
                            <td><?php echo esc_html($log->message); ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="5"><?php _e('Aucun enregistrement trouvé.', 'woo-pennylane'); ?></td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>