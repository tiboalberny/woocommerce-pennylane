<?php
namespace WooPennylane;

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
     * @param int|null $entity_id ID de l'entité concernée
     * @param string|null $entity_type Type d'entité
     * @param string $status Statut du log
     * @param string $message Message à enregistrer
     * @return int|false ID du log créé ou false en cas d'erreur
     */
    public static function add_log($entity_id, $entity_type, $status, $message) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'woo_pennylane_logs';
        
        // Vérifier si la table existe
        // Si get_var retourne NULL (la table n'existe pas) ou une chaîne non égale au nom de la table,
        // la condition est vraie et nous ne devrions pas essayer d'insérer.
        if ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table_name)) != $table_name) {
            // Optionnel: logguer une erreur via error_log() si la table manque, car notre propre logger ne peut pas écrire.
            error_log('WooPennylane_Logger: La table des logs ' . $table_name . ' est manquante.');
            return false;
        }
        
        $result = $wpdb->insert(
            $table_name,
            array(
                'entity_id'   => $entity_id,
                'entity_type' => $entity_type,
                'status'      => $status,
                'message'     => $message,
                'created_at'  => current_time('mysql', 1) // GMT timestamp
            ),
            array(
                $entity_id ? '%d' : '%s', // Permettre null pour entity_id
                $entity_type ? '%s' : '%s', // Permettre null pour entity_type
                '%s', 
                '%s', 
                '%s'
            )
        );
        
        if ($result) {
            return $wpdb->insert_id;
        }
        
        return false;
    }
    
    /**
     * Crée la table de logs si elle n'existe pas.
     * Normalement appelée à l'activation du plugin.
     */
    public static function create_logs_table() {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'woo_pennylane_logs';
        $charset_collate = $wpdb->get_charset_collate();
        
        $sql = "CREATE TABLE $table_name (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            entity_id bigint(20) NULL,
            entity_type varchar(50) NULL,
            status varchar(50) NOT NULL,
            message longtext NOT NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY entity_id (entity_id),
            KEY entity_type (entity_type),
            KEY status (status)
        ) $charset_collate;";
        
        if (!function_exists('dbDelta')) {
            require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        }
        dbDelta($sql);
        
        // Vérifier si la table a bien été créée
        if ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table_name)) != $table_name) {
            error_log('WooPennylane_Logger: Échec de la création de la table des logs: ' . $table_name);
            return false;
        }
        return true;
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
        
        if ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table_name)) != $table_name) {
            return array(); 
        }
        
        $defaults = array(
            'per_page'    => 20,
            'page'        => 1,
            'entity_type' => '',
            'status'      => '',
            'search'      => '',
            'orderby'     => 'created_at',
            'order'       => 'DESC'
        );
        $args = wp_parse_args($args, $defaults);
        
        $sql = "SELECT * FROM {$table_name}";
        $where_clauses = array();

        if (!empty($args['entity_type'])) {
            $where_clauses[] = $wpdb->prepare("entity_type = %s", $args['entity_type']);
        }
        if (!empty($args['status'])) {
            $where_clauses[] = $wpdb->prepare("status = %s", $args['status']);
        }
        if (!empty($args['search'])) {
            $where_clauses[] = $wpdb->prepare("message LIKE %s", '%' . $wpdb->esc_like($args['search']) . '%');
        }

        if (!empty($where_clauses)) {
            $sql .= " WHERE " . implode(' AND ', $where_clauses);
        }
        
        $sql .= $wpdb->prepare(" ORDER BY " . sanitize_sql_orderby($args['orderby'].' '.$args['order']) . " LIMIT %d OFFSET %d", $args['per_page'], ($args['page'] - 1) * $args['per_page']);
        
        return $wpdb->get_results($sql, ARRAY_A);
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

        if ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table_name)) != $table_name) {
            return 0;
        }
        
        $sql = "SELECT COUNT(*) FROM {$table_name}";
        $where_clauses = array();

        if (!empty($args['entity_type'])) {
            $where_clauses[] = $wpdb->prepare("entity_type = %s", $args['entity_type']);
        }
        if (!empty($args['status'])) {
            $where_clauses[] = $wpdb->prepare("status = %s", $args['status']);
        }
        if (!empty($args['search'])) {
            $where_clauses[] = $wpdb->prepare("message LIKE %s", '%' . $wpdb->esc_like($args['search']) . '%');
        }

        if (!empty($where_clauses)) {
            $sql .= " WHERE " . implode(' AND ', $where_clauses);
        }
        
        return (int) $wpdb->get_var($sql);
    }

    /**
     * Raccourci pour loguer une information.
     * @param string $message Message.
     * @param int|null $entity_id ID de l'entité.
     * @param string|null $entity_type Type d'entité.
     */
    public static function info($message, $entity_id = null, $entity_type = null) {
        return self::add_log($entity_id, $entity_type, 'INFO', $message);
    }

    /**
     * Raccourci pour loguer une erreur.
     * @param string $message Message.
     * @param int|null $entity_id ID de l'entité.
     * @param string|null $entity_type Type d'entité.
     */
    public static function error($message, $entity_id = null, $entity_type = null) {
        return self::add_log($entity_id, $entity_type, 'ERROR', $message);
    }

    /**
     * Raccourci pour loguer un avertissement.
     * @param string $message Message.
     * @param int|null $entity_id ID de l'entité.
     * @param string|null $entity_type Type d'entité.
     */
    public static function warning($message, $entity_id = null, $entity_type = null) {
        return self::add_log($entity_id, $entity_type, 'WARNING', $message);
    }

    /**
     * Raccourci pour loguer un message de debug.
     * Logue seulement si le mode debug du plugin est activé.
     * @param string $message Message.
     * @param int|null $entity_id ID de l'entité.
     * @param string|null $entity_type Type d'entité.
     */
    public static function debug($message, $entity_id = null, $entity_type = null) {
        if (get_option('woo_pennylane_debug_mode') === 'yes') {
            return self::add_log($entity_id, $entity_type, 'DEBUG', $message);
        }
        return false;
    }
}

?>