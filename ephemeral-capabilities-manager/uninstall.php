<?php
/**
 * Ephemeral Capabilities Manager Uninstall Script
 *
 * Se ejecuta SOLO cuando el usuario hace clic en "Eliminar" para el plugin
 * desde la pantalla de administración de plugins de WordPress.
 * NO se ejecuta en la desactivación.
 *
 * @package     EphemeralCapabilitiesManager
 * @version     0.1.0
 */

// Exit if accessed directly.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit; // Salida de seguridad importante
}

// --- Tareas de limpieza ---

global $wpdb;

// 1. Eliminar la tabla personalizada de concesiones
$grants_table_name = $wpdb->prefix . 'ephemeral_grants'; // Asegúrate que coincida con la constante ECM_GRANTS_TABLE si la cambias
$wpdb->query( "DROP TABLE IF EXISTS {$grants_table_name}" );

// 2. Eliminar la opción de versión de la base de datos
delete_option( 'ecm_db_version' ); // Asegúrate que coincida con ECM_DB_VERSION_OPTION

// 3. Desprogramar el evento Cron (como medida de seguridad adicional)
$cron_hook_name = 'ecm_cleanup_expired_grants_cron'; // Asegúrate que coincida con ECM_CRON_HOOK
$timestamp = wp_next_scheduled( $cron_hook_name );
if ( $timestamp ) {
    wp_unschedule_event( $timestamp, $cron_hook_name );
}

// 4. (Opcional) Eliminar la tabla de logs si la creas en el futuro
// $logs_table_name = $wpdb->prefix . 'ephemeral_grants_log';
// $wpdb->query( "DROP TABLE IF EXISTS {$logs_table_name}" );

// 5. (Opcional) Eliminar otras opciones o metadatos si los añades en el futuro
// delete_option('otra_opcion_ecm');
// delete_metadata('user', 0, 'meta_key_ecm', '', true); // Eliminaría meta para todos los usuarios
