<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Classe responsable de la gestion de l'historique des synchronisations
 */
class WooPennylane_Sync_History {
    /**
     * Constructeur
     */
    public function __construct() {
        // Création de la table lors de l'activation du plugin
        register_activation_hook(WOO_PENNYLANE_PLUGIN_BASENAME, array($this, 'create_tables'));
        
        // Action pour purger les anciennes entrées
        add_action('woo_pennylane_purge_old_history', array($this, 'purge_old_entries'));
        
        // Planification de la tâche de nettoyage
        add_action('init', array($this, 'schedule_events'));
    }

    /**
     * Planifie les événements récurrents
     */
    public function schedule_events() {
        if (!wp_next_scheduled('woo_pennylane_purge_old_history')) {
            wp_schedule_event(time(), 'weekly', 'woo_pennylane_purge_old_history');
        }
    }

    /**
     * Crée la table de l'historique
     */
    public function create_tables() {
        global $wpdb;
        
        $charset_collate = $wpdb->get_charset_collate();
        
        $sql = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}woo_pennylane_sync_history (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            sync_type varchar(20) NOT NULL COMMENT 'Type de synchronisation (product, customer, order)',
            sync_mode varchar(20) NOT NULL COMMENT 'Mode de synchronisation (manual, automatic, cron)',
            object_id bigint(20) DEFAULT NULL COMMENT 'ID de l'objet synchronisé',
            object_name varchar(255) DEFAULT NULL COMMENT 'Nom de l'objet synchronisé',
            status varchar(20) NOT NULL COMMENT 'Statut de la synchronisation (success, error, warning)',
            message text DEFAULT NULL COMMENT 'Message détaillé',
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'Date de la synchronisation',
            execution_time float DEFAULT NULL COMMENT 'Temps d'exécution en secondes',
            user_id bigint(20) DEFAULT NULL COMMENT 'ID de l'utilisateur ayant déclenché la synchronisation',
            PRIMARY KEY  (id),
            KEY sync_type (sync_type),
            KEY sync_mode (sync_mode),
            KEY status (status),
            KEY created_at (created_at)
        ) $charset_collate;";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }

    /**
     * Enregistre un événement de synchronisation
     *
     * @param string $sync_type Type de synchronisation (product, customer, order)
     * @param string $sync_mode Mode de synchronisation (manual, automatic, cron)
     * @param int|null $object_id ID de l'objet synchronisé (facultatif)
     * @param string|null $object_name Nom de l'objet synchronisé (facultatif)
     * @param string $status Statut de la synchronisation (success, error, warning)
     * @param string|null $message Message détaillé (facultatif)
     * @param float|null $execution_time Temps d'exécution en secondes (facultatif)
     * @return int|false ID de l'entrée créée ou false en cas d'échec
     */
    public function add_entry($sync_type, $sync_mode, $object_id = null, $object_name = null, $status = 'success', $message = null, $execution_time = null) {
        global $wpdb;
        
        $data = array(
            'sync_type' => $sync_type,
            'sync_mode' => $sync_mode,
            'status' => $status,
            'created_at' => current_time('mysql')
        );
        
        // Données facultatives
        if ($object_id !== null) {
            $data['object_id'] = $object_id;
        }
        
        if ($object_name !== null) {
            $data['object_name'] = $object_name;
        }
        
        if ($message !== null) {
            $data['message'] = $message;
        }
        
        if ($execution_time !== null) {
            $data['execution_time'] = $execution_time;
        }
        
        // ID de l'utilisateur connecté si disponible
        if (is_user_logged_in()) {
            $data['user_id'] = get_current_user_id();
        }
        
        $result = $wpdb->insert(
            $wpdb->prefix . 'woo_pennylane_sync_history',
            $data
        );
        
        if ($result) {
            return $wpdb->insert_id;
        }
        
        return false;
    }

    /**
     * Récupère les entrées de l'historique
     *
     * @param array $args Arguments de filtrage
     * @return array Liste des entrées
     */
    public function get_entries($args = array()) {
        global $wpdb;
        
        $defaults = array(
            'number' => 50,
            'offset' => 0,
            'orderby' => 'created_at',
            'order' => 'DESC',
            'sync_type' => null,
            'sync_mode' => null,
            'status' => null,
            'date_from' => null,
            'date_to' => null,
            'search' => null
        );
        
        $args = wp_parse_args($args, $defaults);
        
        $where = array();
        $values = array();
        
        // Filtres
        if ($args['sync_type']) {
            $where[] = 'sync_type = %s';
            $values[] = $args['sync_type'];
        }
        
        if ($args['sync_mode']) {
            $where[] = 'sync_mode = %s';
            $values[] = $args['sync_mode'];
        }
        
        if ($args['status']) {
            $where[] = 'status = %s';
            $values[] = $args['status'];
        }
        
        if ($args['date_from']) {
            $where[] = 'created_at >= %s';
            $values[] = $args['date_from'] . ' 00:00:00';
        }
        
        if ($args['date_to']) {
            $where[] = 'created_at <= %s';
            $values[] = $args['date_to'] . ' 23:59:59';
        }
        
        if ($args['search']) {
            $where[] = '(object_name LIKE %s OR message LIKE %s)';
            $values[] = '%' . $wpdb->esc_like($args['search']) . '%';
            $values[] = '%' . $wpdb->esc_like($args['search']) . '%';
        }
        
        // Construction de la requête
        $where_clause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';
        
        // Tri
        $order_clause = "ORDER BY {$args['orderby']} {$args['order']}";
        
        // Pagination
        $limit_clause = '';
        if ($args['number'] > 0) {
            $limit_clause = $wpdb->prepare("LIMIT %d OFFSET %d", $args['number'], $args['offset']);
        }
        
        // Requête finale
        $query = "SELECT * FROM {$wpdb->prefix}woo_pennylane_sync_history $where_clause $order_clause $limit_clause";
        
        if (!empty($values)) {
            $query = $wpdb->prepare($query, $values);
        }
        
        return $wpdb->get_results($query);
    }

    /**
     * Compte le nombre total d'entrées selon les filtres
     *
     * @param array $args Arguments de filtrage
     * @return int Nombre d'entrées
     */
    public function count_entries($args = array()) {
        global $wpdb;
        
        $defaults = array(
            'sync_type' => null,
            'sync_mode' => null,
            'status' => null,
            'date_from' => null,
            'date_to' => null,
            'search' => null
        );
        
        $args = wp_parse_args($args, $defaults);
        
        $where = array();
        $values = array();
        
        // Filtres
        if ($args['sync_type']) {
            $where[] = 'sync_type = %s';
            $values[] = $args['sync_type'];
        }
        
        if ($args['sync_mode']) {
            $where[] = 'sync_mode = %s';
            $values[] = $args['sync_mode'];
        }
        
        if ($args['status']) {
            $where[] = 'status = %s';
            $values[] = $args['status'];
        }
        
        if ($args['date_from']) {
            $where[] = 'created_at >= %s';
            $values[] = $args['date_from'] . ' 00:00:00';
        }
        
        if ($args['date_to']) {
            $where[] = 'created_at <= %s';
            $values[] = $args['date_to'] . ' 23:59:59';
        }
        
        if ($args['search']) {
            $where[] = '(object_name LIKE %s OR message LIKE %s)';
            $values[] = '%' . $wpdb->esc_like($args['search']) . '%';
            $values[] = '%' . $wpdb->esc_like($args['search']) . '%';
        }
        
        // Construction de la requête
        $where_clause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';
        
        // Requête finale
        $query = "SELECT COUNT(*) FROM {$wpdb->prefix}woo_pennylane_sync_history $where_clause";
        
        if (!empty($values)) {
            $query = $wpdb->prepare($query, $values);
        }
        
        return (int) $wpdb->get_var($query);
    }

    /**
     * Supprime une entrée de l'historique
     *
     * @param int $entry_id ID de l'entrée à supprimer
     * @return bool Succès ou échec
     */
    public function delete_entry($entry_id) {
        global $wpdb;
        
        return $wpdb->delete(
            $wpdb->prefix . 'woo_pennylane_sync_history',
            array('id' => $entry_id),
            array('%d')
        );
    }

    /**
     * Purge les anciennes entrées
     *
     * @param int $days Nombre de jours à conserver
     * @return int Nombre d'entrées supprimées
     */
    public function purge_old_entries($days = 90) {
        global $wpdb;
        
        $date_limit = date('Y-m-d H:i:s', strtotime("-{$days} days"));
        
        return $wpdb->query($wpdb->prepare(
            "DELETE FROM {$wpdb->prefix}woo_pennylane_sync_history WHERE created_at < %s",
            $date_limit
        ));
    }

    /**
     * Récupère le nom de l'utilisateur associé à une entrée
     *
     * @param int $user_id ID de l'utilisateur
     * @return string Nom de l'utilisateur ou 'Système' si non disponible
     */
    public function get_user_name($user_id) {
        if ($user_id) {
            $user = get_userdata($user_id);
            if ($user) {
                return $user->display_name;
            }
        }
        
        return __('Système', 'woo-pennylane');
    }

    /**
     * Récupère le libellé lisible du type de synchronisation
     *
     * @param string $sync_type Type de synchronisation
     * @return string Libellé
     */
    public function get_type_label($sync_type) {
        $types = array(
            'product' => __('Produit', 'woo-pennylane'),
            'customer' => __('Client', 'woo-pennylane'),
            'order' => __('Commande', 'woo-pennylane'),
            'batch' => __('Lot', 'woo-pennylane'),
        );
        
        return isset($types[$sync_type]) ? $types[$sync_type] : $sync_type;
    }

    /**
     * Récupère le libellé lisible du mode de synchronisation
     *
     * @param string $sync_mode Mode de synchronisation
     * @return string Libellé
     */
    public function get_mode_label($sync_mode) {
        $modes = array(
            'manual' => __('Manuel', 'woo-pennylane'),
            'automatic' => __('Automatique', 'woo-pennylane'),
            'cron' => __('Programmé', 'woo-pennylane'),
            'webhook' => __('Webhook', 'woo-pennylane'),
        );
        
        return isset($modes[$sync_mode]) ? $modes[$sync_mode] : $sync_mode;
    }

    /**
     * Récupère le libellé lisible du statut
     *
     * @param string $status Statut
     * @return string Libellé
     */
    public function get_status_label($status) {
        $statuses = array(
            'success' => __('Succès', 'woo-pennylane'),
            'error' => __('Erreur', 'woo-pennylane'),
            'warning' => __('Avertissement', 'woo-pennylane'),
            'skipped' => __('Ignoré', 'woo-pennylane'),
        );
        
        return isset($statuses[$status]) ? $statuses[$status] : $status;
    }
}

// Initialisation globale pour accès facile
global $woo_pennylane_sync_history;
$woo_pennylane_sync_history = new WooPennylane_Sync_History();