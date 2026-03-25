<?php
/**
 * The base configuration for WordPress
 *
 * The wp-config.php creation script uses this file during the installation.
 * You don't have to use the website, you can copy this file to "wp-config.php"
 * and fill in the values.
 *
 * This file contains the following configurations:
 *
 * * Database settings
 * * Secret keys
 * * Database table prefix
 * * ABSPATH
 *
 * @link https://developer.wordpress.org/advanced-administration/wordpress/wp-config/
 *
 * @package WordPress
 */

// ** Database settings - You can get this info from your web host ** //
/** The name of the database for WordPress */
define( 'DB_NAME', 'allergens_wp' );

/** Database username */
define( 'DB_USER', 'root' );

/** Database password */
define( 'DB_PASSWORD', '' );

/** Database hostname */
define( 'DB_HOST', 'localhost:3306' );

/** Database charset to use in creating database tables. */
define( 'DB_CHARSET', 'utf8mb4' );

/** The database collate type. Don't change this if in doubt. */
define( 'DB_COLLATE', '' );

/**#@+
 * Authentication unique keys and salts.
 *
 * Change these to different unique phrases! You can generate these using
 * the {@link https://api.wordpress.org/secret-key/1.1/salt/ WordPress.org secret-key service}.
 *
 * You can change these at any point in time to invalidate all existing cookies.
 * This will force all users to have to log in again.
 *
 * @since 2.6.0
 */
define( 'AUTH_KEY',         '~Ut?EwlkIv9h9Y&k.3O{kFEedZ8:V8+x}aQXJy|P@YK;|BqmHx]8BV4pJe3-WX=n' );
define( 'SECURE_AUTH_KEY',  'R/XC^R5)>gqfHI)CdR0wZKp3?{+tfwAs_VTLzuE8ln?JQV4!anz.1q2zs-68!lGa' );
define( 'LOGGED_IN_KEY',    '+ye)-E:ocf9BOjoWi*g=iKd/cl)-=mQI,O(ut/S]bO2/NJBC7_;Brj]1{.MyR3 V' );
define( 'NONCE_KEY',        '}/3)4wi+k7pFAG_,7FdUJ4MU[s8$CA.9r959fItFz^ddOgAuXv#N5K{FQ+mTsy_9' );
define( 'AUTH_SALT',        '].6a0?,-OZHWR{A|acH`&e#Eo>Nwvnb0F$UH5m{}_f7<H}cg3wH5AA8?7|sv2k>9' );
define( 'SECURE_AUTH_SALT', '*E{]L^,wp]|GzDDM8?sG1r0wnZNk pUwrZs$dyHj FNkLSB <|N}9W_N5qmDYNfj' );
define( 'LOGGED_IN_SALT',   '{|qUZgvFdFrt c+`(a[v%-4Jc?xk:u V]52@ZcaIOuX/A>K/fYaMt}JNA#!%<]Vt' );
define( 'NONCE_SALT',       'Us4ufc(gFGW]r?N6$FRZ+f$Wnb:Mu?Dz6=(FvavSz$b!$}rWJkw4`dSIb`xGOND`' );

/**#@-*/

/**
 * WordPress database table prefix.
 *
 * You can have multiple installations in one database if you give each
 * a unique prefix. Only numbers, letters, and underscores please!
 *
 * At the installation time, database tables are created with the specified prefix.
 * Changing this value after WordPress is installed will make your site think
 * it has not been installed.
 *
 * @link https://developer.wordpress.org/advanced-administration/wordpress/wp-config/#table-prefix
 */
$table_prefix = 'wp_';

/**
 * For developers: WordPress debugging mode.
 *
 * Change this to true to enable the display of notices during development.
 * It is strongly recommended that plugin and theme developers use WP_DEBUG
 * in their development environments.
 *
 * For information on other constants that can be used for debugging,
 * visit the documentation.
 *
 * @link https://developer.wordpress.org/advanced-administration/debug/debug-wordpress/
 */
define( 'WP_DEBUG', false );

/* Add any custom values between this line and the "stop editing" line. */



/* That's all, stop editing! Happy publishing. */

/** Absolute path to the WordPress directory. */
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

/** Sets up WordPress vars and included files. */
require_once ABSPATH . 'wp-settings.php';
