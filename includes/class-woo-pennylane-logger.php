<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Classe de journalisation pour Pennylane
 */
class WooPennylane_Logger {
    /**
     * Ajoute une entrée dans le journal de logs Pennylane
     * 
     * @param int $entity_id ID de l'entité concernée
     * @param string $entity_type Type d'entité
     * @param string $status Statut du log
     * @param string $message Message à enregistrer
     * @return int|false ID du log créé ou false en cas d'erreur
     */
    public static function add_log($entity_id, $entity_type, $status, $message) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'woo_pennylane_logs';
        
        // Vérifier si la table existe
        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$table_name'") == $table_name;
        
        if (!$table_exists) {
            return false;
        }
        
        $result = $wpdb->insert(
            $table_name,
            array(
                'entity_id' => $entity_id,
                'entity_type' => $entity_type,
                'status' => $status,
                'message' => $message,
                'created_at' => current_time('mysql')
            ),
            array('%d', '%s', '%s', '%s', '%s')
        );
        
        if ($result) {
            return $wpdb->insert_id;
        }
        
        return false;
    }
    
    /**
     * Crée la table de logs
     */
    public static function create_logs_table() {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'woo_pennylane_logs';
        $charset_collate = $wpdb->get_charset_collate();
        
        $sql = "CREATE TABLE IF NOT EXISTS $table_name (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            entity_id bigint(20) NOT NULL,
            entity_type varchar(50) NOT NULL,
            status varchar(50) NOT NULL,
            message text NOT NULL,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY entity_id (entity_id),
            KEY entity_type (entity_type),
            KEY status (status)
        ) $charset_collate;";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
        
        return true;
    }
    
    /**
     * Vérifie si le module d'historique est correctement initialisé
     * 
     * @return bool True si le module est prêt, false sinon
     */
    public static function is_history_module_ready() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'woo_pennylane_logs';
        
        return ($wpdb->get_var("SHOW TABLES LIKE '$table_name'") == $table_name);
    }
    
    /**
     * Récupère les entrées de log
     * 
     * @param array $args Arguments de filtrage
     * @return array Résultats de la requête
     */
    public static function get_logs($args = array()) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'woo_pennylane_logs';
        
        // Vérifier si la table existe
        if ($wpdb->get_var("SHOW TABLES LIKE '$table_name'") != $table_name) {
            return array();
        }
        
        // Paramètres par défaut
        $defaults = array(
            'per_page' => 20,
            'page' => 1,
            'entity_type' => '',
            'status' => '',
            'orderby' => 'created_at',
            'order' => 'DESC'
        );
        
        $args = wp_parse_args($args, $defaults);
        
        // Construction de la requête
        $sql = "SELECT * FROM $table_name";
        $where = array();
        
        if (!empty($args['entity_type'])) {
            $where[] = $wpdb->prepare("entity_type = %s", $args['entity_type']);
        }
        
        if (!empty($args['status'])) {
            $where[] = $wpdb->prepare("status = %s", $args['status']);
        }
        
        if (!empty($where)) {
            $sql .= " WHERE " . implode(' AND ', $where);
        }
        
        $orderby = 'created_at';
        if (!empty($args['orderby'])) {
            $orderby = $args['orderby'];
        }
        
        $order = 'DESC';
        if (!empty($args['order']) && in_array(strtoupper($args['order']), array('ASC', 'DESC'))) {
            $order = strtoupper($args['order']);
        }
        
        $sql .= " ORDER BY $orderby $order";
        
        // Pagination
        $offset = ($args['page'] - 1) * $args['per_page'];
        $sql .= $wpdb->prepare(" LIMIT %d OFFSET %d", $args['per_page'], $offset);
        
        return $wpdb->get_results($sql);
    }
    
    /**
     * Compte le nombre total de logs
     * 
     * @param array $args Arguments de filtrage
     * @return int Nombre total de logs
     */
    public static function count_logs($args = array()) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'woo_pennylane_logs';
        
        // Vérifier si la table existe
        if ($wpdb->get_var("SHOW TABLES LIKE '$table_name'") != $table_name) {
            return 0;
        }
        
        $sql = "SELECT COUNT(*) FROM $table_name";
        $where = array();
        
        if (!empty($args['entity_type'])) {
            $where[] = $wpdb->prepare("entity_type = %s", $args['entity_type']);
        }
        
        if (!empty($args['status'])) {
            $where[] = $wpdb->prepare("status = %s", $args['status']);
        }
        
        if (!empty($where)) {
            $sql .= " WHERE " . implode(' AND ', $where);
        }
        
        return (int) $wpdb->get_var($sql);
    }
}