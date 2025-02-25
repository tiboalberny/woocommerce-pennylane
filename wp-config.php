<?php
//Begin Really Simple SSL session cookie settings
@ini_set('session.cookie_httponly', true);
@ini_set('session.cookie_secure', true);
@ini_set('session.use_only_cookies', true);
//END Really Simple SSL
/**
 * La configuration de base de votre installation WordPress.
 *
 * Ce fichier est utilisé par le script de création de wp-config.php pendant
 * le processus d’installation. Vous n’avez pas à utiliser le site web, vous
 * pouvez simplement renommer ce fichier en « wp-config.php » et remplir les
 * valeurs.
 *
 * Ce fichier contient les réglages de configuration suivants :
 *
 * Réglages MySQL
 * Préfixe de table
 * Clés secrètes
 * Langue utilisée
 * ABSPATH
 *
 * @link https://fr.wordpress.org/support/article/editing-wp-config-php/.
 *
 * @package WordPress
 */
// ** Réglages MySQL - Votre hébergeur doit vous fournir ces informations. ** //
/** Nom de la base de données de WordPress. */
define( 'DB_NAME', "lespetxskedhze48" );
/** Utilisateur de la base de données MySQL. */
define( 'DB_USER', "lespetxskedhze48" );
/** Mot de passe de la base de données MySQL. */
define( 'DB_PASSWORD', "kaf6v5WcK5thzRPD" );
/** Adresse de l’hébergement MySQL. */
define( 'DB_HOST', "lespetxskedhze48.mysql.db" );
/** Jeu de caractères à utiliser par la base de données lors de la création des tables. */
define( 'DB_CHARSET', 'utf8mb4' );
/**
 * Type de collation de la base de données.
 * N’y touchez que si vous savez ce que vous faites.
 */
define( 'DB_COLLATE', '' );
/**#@+
 * Clés uniques d’authentification et salage.
 *
 * Remplacez les valeurs par défaut par des phrases uniques !
 * Vous pouvez générer des phrases aléatoires en utilisant
 * {@link https://api.wordpress.org/secret-key/1.1/salt/ le service de clés secrètes de WordPress.org}.
 * Vous pouvez modifier ces phrases à n’importe quel moment, afin d’invalider tous les cookies existants.
 * Cela forcera également tous les utilisateurs à se reconnecter.
 *
 * @since 2.6.0
 */
define( 'AUTH_KEY',         '3{k_(Mh<EZtN:}nYur-u5lvVU9xu|/WO~yPU9!/smB4X/l./V=qMqu2gcB59v-70' );
define( 'SECURE_AUTH_KEY',  'z$E?[YQl~x6J =s8ev^-~$TJDz;)Z^(xDSAv(41txUo?FeQ)2aM>nu${gC&T7Tpt' );
define( 'LOGGED_IN_KEY',    '}Oam1R-)`>|0JJxY5+H=1OBIvAQR%ZV32,gCALz4+ Q4 /w2nR}6(i GNM|Gp;/P' );
define( 'NONCE_KEY',        'kH:`DiPf;^6E,VZ|!B{5Jxf?HG`BhL^7@R{&HtetqFn<s|4OtK2c7%uJY]dtj{QQ' );
define( 'AUTH_SALT',        '63kv2.6J75ix-9T2q,SiXlawe I9?x~lk7{&z@b YtXCPKH@z9C!dSc+qTR#m2~j' );
define( 'SECURE_AUTH_SALT', ']JErtd4$7=4oHF~{s3Rf_Tq`?7:4Dz;6e2G<*e1v!HGvPoOqjs=Z3J Gi6d$RJs-' );
define( 'LOGGED_IN_SALT',   'uzP?L?(9$:#F(HbDKDv,>UYimE=8w<`W38)(<(>sq)Zo8oxjs+6YFQw5y8*3t.ib' );
define( 'NONCE_SALT',       'gnAj$6Ddc3]ya-;{Y+<Fu3LYnzrELyC(4F#^hUZIrrl`sMwV)u?e|P8G;86P:Y(7' );
/**#@-*/
/**
 * Préfixe de base de données pour les tables de WordPress.
 *
 * Vous pouvez installer plusieurs WordPress sur une seule base de données
 * si vous leur donnez chacune un préfixe unique.
 * N’utilisez que des chiffres, des lettres non-accentuées, et des caractères soulignés !
 */
$table_prefix = 'wp_chaudrons_';
/**
 * Pour les développeurs : le mode déboguage de WordPress.
 *
 * En passant la valeur suivante à "true", vous activez l’affichage des
 * notifications d’erreurs pendant vos essais.
 * Il est fortemment recommandé que les développeurs d’extensions et
 * de thèmes se servent de WP_DEBUG dans leur environnement de
 * développement.
 *
 * Pour plus d’information sur les autres constantes qui peuvent être utilisées
 * pour le déboguage, rendez-vous sur le Codex.
 *
 * @link https://fr.wordpress.org/support/article/debugging-in-wordpress/
 */
define('WP_DEBUG', false);
define('WP_DEBUG_LOG', true);
define('WP_MEMORY_LIMIT', '256M');
/* C’est tout, ne touchez pas à ce qui suit ! Bonne publication. */
/** Chemin absolu vers le dossier de WordPress. */
if ( ! defined( 'ABSPATH' ) )
  define( 'ABSPATH', dirname( __FILE__ ) . '/' );
/** Réglage des variables de WordPress et de ses fichiers inclus. */
require_once( ABSPATH . 'wp-settings.php' );
