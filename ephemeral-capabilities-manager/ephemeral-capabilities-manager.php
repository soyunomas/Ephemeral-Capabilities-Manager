<?php
/**
 * Plugin Name:       Ephemeral Capabilities Manager
 * Plugin URI:        https://github.com/soyunomas/ephemeral-capabilities-manager
 * Description:       Permite conceder conjuntos de capacidades de WordPress (tareas comunes filtradas) a usuarios por un tiempo limitado.
 * Version:           1.0.0
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Author:            Soyunomas
 * Author URI:        https://github.com/soyunomas/ephemeral-capabilities-manager
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       ephemeral-capabilities-manager
 * Domain Path:       /languages
 *
 * @package         EphemeralCapabilitiesManager
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// --- Define plugin constants ---
define( 'ECM_VERSION', '0.9.0' ); // Actualizada versión
define( 'ECM_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'ECM_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'ECM_PLUGIN_FILE', __FILE__ );
define( 'ECM_DB_VERSION_OPTION', 'ecm_db_version' );
define( 'ECM_CURRENT_DB_VERSION', '1.0' );
define( 'ECM_GRANTS_TABLE', 'ephemeral_grants' );
// --- Constantes para el CRON ---
define( 'ECM_CRON_HOOK', 'ecm_cleanup_expired_grants_cron' ); // Nombre único para nuestro hook de cron
define( 'ECM_CRON_INTERVAL', 'hourly' ); // Frecuencia de ejecución (puede ser 'twicedaily', 'daily', etc.)


// --- Cargar WP_List_Table si es necesario ---
if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * Class ECM_Grants_List_Table
 *
 * Maneja la visualización de la tabla de concesiones efímeras activas
 * con acciones en lote y filtrado en el área de administración.
 *
 * @since 0.5.0 Extends WP_List_Table.
 */
class ECM_Grants_List_Table extends WP_List_Table {

	/**
	 * Constructor. Configura los nombres singular y plural.
	 *
	 * @since 0.5.0
	 */
	public function __construct() {
		parent::__construct(
			[
				'singular' => __( 'Concesión', 'ephemeral-capabilities-manager' ), // label for singular form.
				'plural'   => __( 'Concesiones', 'ephemeral-capabilities-manager' ), // label for plural form.
				'ajax'     => false, // No usamos AJAX por ahora.
			]
		);
	}

	/**
	 * Añade controles de filtrado encima de la tabla.
	 *
	 * @since 0.7.0
	 * @param string $which 'top' o 'bottom' para indicar la posición.
	 */
	protected function extra_tablenav( $which ) {
		// Solo mostrar en la parte superior.
		if ( 'top' === $which ) {
			echo '<div class="alignleft actions">';

			// --- Dropdown de Filtro de Usuario ---
			$users = get_users(
				[
					'fields'       => [ 'ID', 'display_name' ],
					'orderby'      => 'display_name',
					'role__not_in' => [ 'administrator' ], // Excluir administradores.
				]
			);
			$current_user_filter = isset( $_GET['user_filter'] ) ? absint( $_GET['user_filter'] ) : 0;

			echo '<label for="filter-by-user" class="screen-reader-text">' . esc_html__( 'Filtrar por usuario', 'ephemeral-capabilities-manager' ) . '</label>';
			echo '<select name="user_filter" id="filter-by-user">';
			echo '<option value="0" ' . selected( $current_user_filter, 0, false ) . '>' . esc_html__( 'Todos los usuarios', 'ephemeral-capabilities-manager' ) . '</option>';
			if ( ! empty( $users ) ) {
				foreach ( $users as $user ) {
					printf(
						'<option value="%d" %s>%s (ID: %d)</option>',
						esc_attr( $user->ID ),
						selected( $current_user_filter, $user->ID, false ),
						esc_html( $user->display_name ),
						esc_html( $user->ID )
					);
				}
			}
			echo '</select>';
			// Botón secundario para aplicar el filtro.
			submit_button( __( 'Filtrar', 'ephemeral-capabilities-manager' ), 'secondary', 'filter_action', false );
			echo '</div>';
		}
	}

	/**
	 * Obtiene los datos de la tabla de concesiones desde la base de datos.
	 *
	 * @since 0.5.0
	 * @since 0.7.0 Añadido filtro $filter_user_id y ordenación.
	 *
	 * @param int $per_page       Número de items por página.
	 * @param int $page_number    Número de página actual.
	 * @param int $filter_user_id ID del usuario por el que filtrar (0 para todos).
	 * @return array              Array de concesiones (arrays asociativos).
	 */
	public static function get_grants( $per_page = 20, $page_number = 1, $filter_user_id = 0 ) {
		global $wpdb;
		$table_name       = $wpdb->prefix . ECM_GRANTS_TABLE;
		$current_time_utc = current_time( 'timestamp', true );

		// Solo concesiones activas y no expiradas.
		$sql    = "SELECT * FROM {$table_name} WHERE status = %s AND expiry_timestamp > %d";
		$params = [ 'active', $current_time_utc ];

		// Añadir filtro de usuario si se especifica.
		if ( 0 < $filter_user_id ) {
			$sql     .= ' AND user_id = %d';
			$params[] = $filter_user_id;
		}

		// Ordenar por fecha de expiración (más cercana primero).
		$sql .= ' ORDER BY expiry_timestamp ASC';

		// Añadir paginación.
		if ( ! empty( $per_page ) && 0 < $per_page ) {
			$sql     .= ' LIMIT %d OFFSET %d';
			$params[] = $per_page;
			$params[] = ( $page_number - 1 ) * $per_page;
		}

		// Ejecutar consulta preparada.
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $sql is prepared with placeholders and $params.
		return $wpdb->get_results( $wpdb->prepare( $sql, $params ), 'ARRAY_A' );
	}

	/**
	 * Obtiene el número total de concesiones activas que coinciden con el filtro.
	 *
	 * @since 0.5.0
	 * @since 0.7.0 Añadido filtro $filter_user_id.
	 *
	 * @param int $filter_user_id ID del usuario por el que filtrar (0 para todos).
	 * @return int                Número total de registros.
	 */
	public static function record_count( $filter_user_id = 0 ) {
		global $wpdb;
		$table_name       = $wpdb->prefix . ECM_GRANTS_TABLE;
		$current_time_utc = current_time( 'timestamp', true );

		$sql    = "SELECT COUNT(*) FROM {$table_name} WHERE status = %s AND expiry_timestamp > %d";
		$params = [ 'active', $current_time_utc ];

		if ( 0 < $filter_user_id ) {
			$sql     .= ' AND user_id = %d';
			$params[] = $filter_user_id;
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $sql is prepared with placeholders and $params.
		return (int) $wpdb->get_var( $wpdb->prepare( $sql, $params ) );
	}

	/**
	 * Texto a mostrar cuando no hay datos en la tabla.
	 *
	 * @since 0.5.0
	 */
	public function no_items() {
		esc_html_e( 'No hay concesiones efímeras activas que coincidan con el filtro.', 'ephemeral-capabilities-manager' );
	}

	/**
	 * Define las columnas de la tabla.
	 *
	 * @since 0.5.0
	 * @return array Array asociativo de `[column_id => Column Label]`.
	 */
	public function get_columns() {
		return [
			'cb'         => '<input type="checkbox" />', // Checkbox para acciones en lote.
			'user_id'    => __( 'Usuario Concedido', 'ephemeral-capabilities-manager' ),
			'capability' => __( 'Capacidad Concedida', 'ephemeral-capabilities-manager' ),
			'granted_by' => __( 'Otorgado Por', 'ephemeral-capabilities-manager' ),
			'expires_in' => __( 'Expira En / Fecha', 'ephemeral-capabilities-manager' ),
			'actions'    => __( 'Acciones', 'ephemeral-capabilities-manager' ), // Columna para acciones individuales.
		];
	}

	/**
	 * Define qué columnas son ordenables.
	 *
	 * @since 0.5.0
	 * @return array Array vacío por ahora (no se implementa ordenación por cabecera).
	 */
	public function get_sortable_columns() {
		return [];
	}

	/**
	 * Define las acciones en lote disponibles.
	 *
	 * @since 0.5.0
	 * @return array Array asociativo de `[action_id => Action Label]`.
	 */
	protected function get_bulk_actions() {
		return [
			'bulk_revoke' => __( 'Revocar Seleccionadas', 'ephemeral-capabilities-manager' ),
		];
	}

	/**
	 * Renderiza la columna de checkbox para acciones en lote.
	 *
	 * @since 0.5.0
	 * @since 0.9.0 Se añade esc_attr() por seguridad.
	 *
	 * @param array $item Fila de datos actual.
	 * @return string HTML del checkbox.
	 */
	protected function column_cb( $item ) {
		// El valor es el ID de la concesión.
		return sprintf( '<input type="checkbox" name="bulk_grant_ids[]" value="%s" />', esc_attr( $item['grant_id'] ) );
	}

	/**
	 * Prepara los items para mostrar en la tabla (consulta datos, paginación, etc.).
	 *
	 * @since 0.5.0
	 * @since 0.7.0 Pasa el filtro a get_grants/record_count y procesa acciones en lote aquí.
	 */
	public function prepare_items() {
		// Definir cabeceras.
		$this->_column_headers = [ $this->get_columns(), [], $this->get_sortable_columns() ];

		// Obtener el filtro de usuario actual de la URL.
		$filter_user_id = isset( $_GET['user_filter'] ) ? absint( $_GET['user_filter'] ) : 0;

		// Procesar acción en lote ANTES de obtener los datos para reflejar cambios.
		$this->process_bulk_action();

		// Configurar paginación.
		$per_page     = $this->get_items_per_page( 'grants_per_page', 20 ); // items por página.
		$current_page = $this->get_pagenum(); // página actual.
		$total_items  = self::record_count( $filter_user_id ); // total de items CON filtro.

		$this->set_pagination_args(
			[
				'total_items' => $total_items,
				'per_page'    => $per_page,
			]
		);

		// Obtener los items para la página actual CON filtro.
		$this->items = self::get_grants( $per_page, $current_page, $filter_user_id );
	}

	/**
	 * Renderiza la columna 'user_id'. Muestra el nombre del usuario y enlace a su perfil.
	 *
	 * @since 0.5.0
	 * @param array $item Fila de datos actual.
	 * @return string HTML para la celda.
	 */
	protected function column_user_id( $item ) {
		$user_info = get_userdata( $item['user_id'] );
		// Manejar caso de usuario no encontrado.
		if ( ! $user_info ) {
			return sprintf( __( 'Usuario ID: %d (No encontrado)', 'ephemeral-capabilities-manager' ), $item['user_id'] );
		}
		$edit_link = get_edit_user_link( $item['user_id'] );
		// Enlace al perfil del usuario.
		return sprintf( '<a href="%s"><strong>%s</strong></a>', esc_url( $edit_link ), esc_html( $user_info->display_name ) );
	}

	/**
	 * Renderiza la columna 'capability'. Muestra la capacidad y la etiqueta de la tarea si existe.
	 *
	 * @since 0.6.0 Contexto de tarea añadido.
	 * @param array $item Fila de datos actual.
	 * @return string HTML para la celda.
	 */
	protected function column_capability( $item ) {
		$context = maybe_unserialize( $item['context_data'] );
		// Si hay una clave de tarea en el contexto.
		if ( isset( $context['task_key'] ) ) {
			$bundles = ecm_get_task_bundles(); // Obtener bundles definidos.
			if ( isset( $bundles[ $context['task_key'] ] ) ) {
				// Mostrar capacidad y etiqueta de la tarea.
				return '<code>' . esc_html( $item['capability'] ) . '</code> <small>(' . esc_html( $bundles[ $context['task_key'] ]['label'] ) . ')</small>';
			}
		}
		// Si no hay contexto, mostrar solo la capacidad.
		return '<code>' . esc_html( $item['capability'] ) . '</code>';
	}

	/**
	 * Renderiza la columna 'granted_by'. Muestra quién otorgó el permiso.
	 *
	 * @since 0.5.0
	 * @param array $item Fila de datos actual.
	 * @return string HTML para la celda.
	 */
	protected function column_granted_by( $item ) {
		$admin_info = get_userdata( $item['granted_by_user_id'] );
		// Muestra el nombre o 'Desconocido'.
		return $admin_info ? esc_html( $admin_info->display_name ) : __( 'Desconocido', 'ephemeral-capabilities-manager' );
	}

	/**
	 * Renderiza la columna 'expires_in'. Muestra cuánto falta o la fecha de expiración.
	 *
	 * @since 0.5.0
	 * @param array $item Fila de datos actual.
	 * @return string HTML para la celda.
	 */
	protected function column_expires_in( $item ) {
		$expiry_timestamp = (int) $item['expiry_timestamp'];
		$current_time_utc = current_time( 'timestamp', true );
		$time_diff        = $expiry_timestamp - $current_time_utc;

		// Si aún no ha expirado.
		if ( 0 < $time_diff ) {
			// Mostrar tiempo restante legible y fecha/hora exacta.
			return sprintf( __( 'en %s', 'ephemeral-capabilities-manager' ), human_time_diff( $current_time_utc, $expiry_timestamp ) ) . '<br><small>' . wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $expiry_timestamp ) . '</small>';
		} else {
			// Si está expirado pero aún visible (p.ej., justo antes de que corra el cron).
			return '<span style="color: #999;">' . __( 'Expirado', 'ephemeral-capabilities-manager' ) . '</span>';
		}
	}

	/**
	 * Renderiza la columna 'actions' con el enlace para revocar individualmente.
	 *
	 * @since 0.5.0
	 * @param array $item Fila de datos actual.
	 * @return string HTML con las acciones de fila (solo Revocar).
	 */
	protected function column_actions( $item ) {
		$revoke_nonce = wp_create_nonce( 'ecm_revoke_grant_' . $item['grant_id'] );
		// Construir la URL para la acción admin-post.php, manteniendo filtros.
		$revoke_url = add_query_arg(
			[
				'action'      => 'ecm_revoke_grant', // Nuestro hook para admin-post.php.
				'grant_id'    => $item['grant_id'],
				'_wpnonce'    => $revoke_nonce,
				'user_filter' => isset( $_GET['user_filter'] ) ? absint( $_GET['user_filter'] ) : 0, // Mantener filtro.
			],
			admin_url( 'admin-post.php' )
		);

		$actions            = [];
		$actions['revoke'] = sprintf(
			'<a href="%s" class="ecm-revoke-link" onclick="return confirm(\'%s\');" style="color:#a00;">%s</a>',
			esc_url( $revoke_url ),
			esc_js( sprintf( __( '¿Estás seguro de que quieres revocar la capacidad "%s" para este usuario?', 'ephemeral-capabilities-manager' ), $item['capability'] ) ),
			esc_html__( 'Revocar', 'ephemeral-capabilities-manager' )
		);

		// Usar row_actions para formatear las acciones correctamente.
		return $this->row_actions( $actions );
	}

	/**
	 * Procesa las acciones en lote (solo 'bulk_revoke').
	 * Verifica nonce, permisos, sanitiza IDs, actualiza la BD y redirige.
	 *
	 * @since 0.5.0
	 */
	public function process_bulk_action() {
		$action = $this->current_action();

		// Solo procesar nuestra acción 'bulk_revoke'.
		if ( 'bulk_revoke' === $action ) {

			// 1. Verificar Nonce generado por WP_List_Table.
			if ( ! isset( $_REQUEST['_wpnonce'] ) || ! wp_verify_nonce( sanitize_key( $_REQUEST['_wpnonce'] ), 'bulk-' . $this->_args['plural'] ) ) {
				wp_die( esc_html__( 'Error de seguridad (Nonce inválido). Inténtalo de nuevo.', 'ephemeral-capabilities-manager' ) );
			}

			// 2. Verificar Permisos del Usuario Actual.
			if ( ! current_user_can( 'promote_users' ) ) { // Capacidad requerida.
				wp_die( esc_html__( 'No tienes permisos suficientes para realizar esta acción.', 'ephemeral-capabilities-manager' ) );
			}

			// 3. Obtener y Sanitizar IDs de las filas seleccionadas.
			$grant_ids = isset( $_REQUEST['bulk_grant_ids'] ) ? array_map( 'absint', (array) $_REQUEST['bulk_grant_ids'] ) : [];

			// 4. Verificar si se seleccionó algo.
			if ( empty( $grant_ids ) ) {
				add_settings_error( 'ecm_grants_messages', 'bulk_no_selection', __( 'No has seleccionado ninguna concesión para revocar.', 'ephemeral-capabilities-manager' ), 'warning' );
				set_transient( 'settings_errors', get_settings_errors(), 30 );
				return; // Detener si no hay selección.
			}

			// 5. Realizar la Acción en la Base de Datos.
			global $wpdb;
			$table_name = $wpdb->prefix . ECM_GRANTS_TABLE;

			// Crear placeholders para la cláusula IN de forma segura (%d para cada ID).
			$ids_placeholder = implode( ', ', array_fill( 0, count( $grant_ids ), '%d' ) );

			// Construir y ejecutar la consulta UPDATE preparada.
			// Solo actualizamos las que están 'active'.
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $ids_placeholder is safe, constructed from %d.
			$sql = $wpdb->prepare(
				"UPDATE {$table_name} SET status = %s WHERE grant_id IN ( {$ids_placeholder} ) AND status = %s",
				array_merge(
					[ 'revoked' ], // Nuevo estado.
					$grant_ids,   // Array de IDs.
					[ 'active' ]   // Condición de estado actual.
				)
			);

			$updated_rows = $wpdb->query( $sql );

			// 6. Mostrar Mensaje de Resultado (se guardan en transient para ver tras redirigir).
			if ( false === $updated_rows ) {
				add_settings_error( 'ecm_grants_messages', 'bulk_revoke_db_error', __( 'Error de base de datos al intentar revocar las concesiones seleccionadas.', 'ephemeral-capabilities-manager' ), 'error' );
			} elseif ( 0 === $updated_rows ) {
				add_settings_error( 'ecm_grants_messages', 'bulk_revoke_not_found', __( 'Ninguna de las concesiones seleccionadas estaba activa o fueron encontradas.', 'ephemeral-capabilities-manager' ), 'warning' );
			} else {
				add_settings_error(
					'ecm_grants_messages',
					'bulk_revoke_success',
					sprintf(
						// Translators: %d is the number of grants revoked.
						_n( '%d concesión revocada correctamente.', '%d concesiones revocadas correctamente.', $updated_rows, 'ephemeral-capabilities-manager' ),
						$updated_rows
					),
					'success'
				);
			}
			set_transient( 'settings_errors', get_settings_errors(), 30 );

			// 7. Redirigir para evitar reenvío del formulario y limpiar URL.
			$redirect_url = add_query_arg(
				[
					'page'        => $_REQUEST['page'] ?? 'ecm_manage_grants', // Mantener página actual.
					'user_filter' => isset( $_REQUEST['user_filter'] ) ? absint( $_REQUEST['user_filter'] ) : 0, // Mantener filtro.
					// 'bulk_revoked' => $updated_rows // Podríamos añadir un parámetro si fuera necesario.
				],
				admin_url( 'users.php' )
			);
			// Eliminar parámetros de acción y nonce de la URL.
			$redirect_url = remove_query_arg( [ 'action', 'action2', '_wpnonce', 'bulk_grant_ids' ], $redirect_url );
			wp_safe_redirect( $redirect_url );
			exit; // Terminar ejecución del script aquí.
		}
	}

} // Fin de la clase ECM_Grants_List_Table


// --- Activation / Deactivation Hooks ---
register_activation_hook( ECM_PLUGIN_FILE, 'ecm_activate' );
register_deactivation_hook( ECM_PLUGIN_FILE, 'ecm_deactivate' );

/**
 * Función que se ejecuta al activar el plugin.
 * Crea/actualiza la tabla personalizada y programa el evento Cron si no existe.
 *
 * @since 0.1.0
 * @since 0.8.0 Añadida programación de Cron.
 */
function ecm_activate() {
	global $wpdb;
	// Necesario para dbDelta().
	require_once ABSPATH . 'wp-admin/includes/upgrade.php';

	// --- Creación/Actualización de la tabla ---
	$table_name      = $wpdb->prefix . ECM_GRANTS_TABLE;
	$charset_collate = $wpdb->get_charset_collate();
	$sql             = "CREATE TABLE $table_name (
        grant_id bigint(20) NOT NULL AUTO_INCREMENT,
        user_id bigint(20) UNSIGNED NOT NULL,
        capability varchar(255) NOT NULL,
        granted_by_user_id bigint(20) UNSIGNED NOT NULL,
        grant_timestamp bigint(20) NOT NULL,
        expiry_timestamp bigint(20) NOT NULL,
        status varchar(20) NOT NULL DEFAULT 'active',
        context_data longtext DEFAULT NULL,
        PRIMARY KEY  (grant_id),
        KEY idx_user_id (user_id),
        KEY idx_capability (capability(191)), # Index prefix for long varchars if needed
        KEY idx_expiry_timestamp (expiry_timestamp),
        KEY idx_status (status)
    ) $charset_collate;";
	dbDelta( $sql ); // Crea o actualiza la tabla de forma segura.
	// Guardar versión de BD (útil para futuras migraciones).
	update_option( ECM_DB_VERSION_OPTION, ECM_CURRENT_DB_VERSION ); // Use update_option for subsequent activations.

	// --- Programar el evento Cron si no está ya programado ---
	if ( ! wp_next_scheduled( ECM_CRON_HOOK ) ) {
		// Programa el evento para que se ejecute recurrentemente.
		// time() + 60: Pequeño retardo inicial opcional.
		wp_schedule_event( time() + 60, ECM_CRON_INTERVAL, ECM_CRON_HOOK );
	}
}

/**
 * Función que se ejecuta al desactivar el plugin.
 * Desprograma el evento Cron para evitar tareas huérfanas.
 *
 * @since 0.1.0
 * @since 0.8.0 Añadida desprogramación de Cron.
 */
function ecm_deactivate() {
	// --- Desprogramar el evento Cron ---
	$timestamp = wp_next_scheduled( ECM_CRON_HOOK );
	if ( $timestamp ) {
		wp_unschedule_event( $timestamp, ECM_CRON_HOOK );
	}
}

// --- Core Logic: Filter User Capabilities ---
add_filter( 'user_has_cap', 'ecm_filter_user_has_cap', 10, 3 );

/**
 * Filtra las capacidades del usuario para añadir dinámicamente las concedidas temporalmente.
 * Se ejecuta muy a menudo, por lo que debe ser eficiente. Usa cachés estáticos.
 *
 * @since 0.2.0
 *
 * @param array $allcaps    Array de todas las capacidades del usuario.
 * @param array $caps       Array de capacidades primitivas que se están comprobando.
 * @param array $args       Argumentos adicionales: $args[0] = capacidad original solicitada,
 *                          $args[1] = ID de usuario, $args[2] = ID de objeto (opcional).
 *                          ¡El índice del ID de usuario puede variar!
 * @return array Array $allcaps modificado con las capacidades efímeras añadidas.
 */
function ecm_filter_user_has_cap( $allcaps, $caps, $args ) {
	global $wpdb;
	// Cachés estáticos para optimizar rendimiento dentro de la misma petición.
	static $ephemeral_cache = []; // Almacena las caps efímeras activas por usuario.
	static $checked_users = []; // Almacena los IDs de usuario ya verificados en esta petición.

	// --- Determinar el ID de usuario ---
	// WordPress pasa el ID de usuario en diferentes índices de $args dependiendo del contexto.
	// Ver: https://developer.wordpress.org/reference/hooks/user_has_cap/
	$user_id = 0;
	if ( isset( $args[1] ) && is_numeric( $args[1] ) ) {
		$user_id = (int) $args[1];
	} elseif ( isset( $args[2] ) && is_numeric( $args[2] ) ) {
		$user_id = (int) $args[2]; // A menudo usado por map_meta_cap.
	}

	// Si no podemos determinar el ID de usuario o es 0 (anónimo), no hacemos nada.
	if ( 0 === $user_id ) {
		return $allcaps;
	}

	// Si ya hemos procesado este usuario en esta petición, usar caché.
	if ( isset( $checked_users[ $user_id ] ) ) {
		// Combinar las capacidades base ($allcaps) con las efímeras cacheadas.
		return array_merge( $allcaps, $ephemeral_cache[ $user_id ] ?? [] );
	}

	// Marcar este usuario como procesado para esta petición.
	$checked_users[ $user_id ] = true;

	// --- Consultar la BD para obtener capacidades efímeras ---
	$table_name            = $wpdb->prefix . ECM_GRANTS_TABLE;
	$current_timestamp_utc = current_time( 'timestamp', true ); // ¡Siempre UTC para comparar timestamps!

	$sql = $wpdb->prepare(
		"SELECT capability FROM $table_name WHERE user_id = %d AND status = %s AND expiry_timestamp > %d",
		$user_id,
		'active',          // Solo las activas.
		$current_timestamp_utc // Y que no hayan expirado.
	);

	// Usamos get_col para obtener solo la columna 'capability' en un array simple.
	$active_ephemeral_caps = $wpdb->get_col( $sql );

	// Inicializar caché para este usuario (incluso si no tiene caps efímeras).
	$ephemeral_cache[ $user_id ] = [];

	// Si se encontraron capacidades efímeras activas.
	if ( ! empty( $active_ephemeral_caps ) ) {
		foreach ( $active_ephemeral_caps as $ephemeral_cap ) {
			// Añadir la capacidad efímera al array $allcaps del usuario.
			// Se establece como true para indicar que SÍ tiene esa capacidad ahora.
			$allcaps[ $ephemeral_cap ] = true;
			// Guardar en el caché estático para futuras llamadas a user_has_cap en esta petición.
			$ephemeral_cache[ $user_id ][ $ephemeral_cap ] = true;
		}
	}

	// Devolver el array de capacidades modificado.
	return $allcaps;
}

// --- Función de Callback para el WP-Cron ---
add_action( ECM_CRON_HOOK, 'ecm_do_cleanup_cron' );

/**
 * Función ejecutada por WP-Cron para limpiar concesiones expiradas.
 * Busca concesiones 'active' cuya fecha de expiración ha pasado
 * y las marca como 'expired' en la base de datos.
 *
 * @since 0.8.0
 */
function ecm_do_cleanup_cron() {
	global $wpdb;
	$table_name       = $wpdb->prefix . ECM_GRANTS_TABLE;
	$current_time_utc = current_time( 'timestamp', true ); // Siempre usa UTC para comparar.

	// Prepara la consulta para actualizar el estado de las concesiones expiradas.
	$sql = $wpdb->prepare(
		"UPDATE {$table_name}
         SET status = %s
         WHERE status = %s AND expiry_timestamp <= %d",
		'expired', // Nuevo estado: 'expired'.
		'active',  // Estado actual que buscamos.
		$current_time_utc // Momento actual para comparar (<= incluye las que expiran justo ahora).
	);

	// Ejecuta la consulta UPDATE.
	// No necesitamos $rows_affected para la lógica principal, pero podría usarse para logging.
	$wpdb->query( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $sql is prepared above.

	// Nota: Logging eliminado para versión final. Implementar un logger adecuado si es necesario.
}


// --- Helper Function: Define Task Bundles ---

/**
 * Define los conjuntos de capacidades (tareas) que se pueden conceder.
 * Incluye etiqueta, descripción, capacidades asociadas y nivel de riesgo.
 * Usa un caché estático y un filtro para extensibilidad.
 *
 * @since 0.6.0
 * @return array Array de bundles de tareas.
 */
function ecm_get_task_bundles() {
	// Caché estático para evitar redefinir en cada llamada.
	static $bundles = null;

	if ( null === $bundles ) {
		$bundles = [
			// --- Tareas Relacionadas con Contenido ---
			'publish_own_content'  => [
				'label'        => __( 'Publicar Contenido Propio', 'ephemeral-capabilities-manager' ),
				'description'  => __( 'Permite crear, editar, publicar y eliminar sus propias entradas. Incluye subir archivos.', 'ephemeral-capabilities-manager' ),
				'capabilities' => [ 'edit_posts', 'publish_posts', 'delete_posts', 'upload_files' ],
				'risk'         => 'low', // Bajo riesgo relativo.
			],
			'manage_all_content'   => [
				'label'        => __( 'Gestionar Todo el Contenido (Editor Temporal)', 'ephemeral-capabilities-manager' ),
				'description'  => __( 'Permite editar, publicar y eliminar entradas y páginas de CUALQUIER usuario. Incluye gestión de categorías y comentarios.', 'ephemeral-capabilities-manager' ),
				'capabilities' => [ 'edit_posts', 'edit_others_posts', 'edit_published_posts', 'publish_posts', 'delete_posts', 'delete_others_posts', 'delete_published_posts', 'read_private_posts', 'edit_pages', 'edit_others_pages', 'edit_published_pages', 'publish_pages', 'delete_pages', 'delete_others_pages', 'delete_published_pages', 'read_private_pages', 'manage_categories', 'moderate_comments', 'upload_files' ],
				'risk'         => 'medium', // Riesgo medio, puede afectar contenido ajeno.
			],
			'moderate_comments'    => [
				'label'        => __( 'Moderar Comentarios', 'ephemeral-capabilities-manager' ),
				'description'  => __( 'Permite aprobar, desaprobar y editar comentarios.', 'ephemeral-capabilities-manager' ),
				'capabilities' => [ 'moderate_comments' ], // 'edit_posts' podría ser excesivo solo para moderar.
				'risk'         => 'low',
			],
			// --- Tareas Relacionadas con Apariencia ---
			'manage_appearance'    => [
				'label'        => __( 'Gestionar Apariencia (Personalizador/Widgets/Menús)', 'ephemeral-capabilities-manager' ),
				'description'  => __( '¡RIESGO ALTO! Permite cambiar opciones de tema, widgets y menús. No permite instalar/cambiar temas.', 'ephemeral-capabilities-manager' ),
				'capabilities' => [ 'edit_theme_options' ], // Capacidad muy potente.
				'risk'         => 'high',
			],
			// --- Tareas Relacionadas con Plugins ---
			'install_plugins'      => [
				'label'        => __( 'Instalar Plugins', 'ephemeral-capabilities-manager' ),
				'description'  => __( '¡RIESGO MUY ALTO! Permite buscar e instalar nuevos plugins desde el repositorio de WordPress o subiendo un ZIP.', 'ephemeral-capabilities-manager' ),
				'capabilities' => [ 'install_plugins' ],
				'risk'         => 'critical', // Riesgo máximo, puede comprometer el sitio.
			],
			'activate_plugins'     => [
				'label'        => __( 'Activar/Desactivar Plugins', 'ephemeral-capabilities-manager' ),
				'description'  => __( '¡RIESGO ALTO! Permite activar o desactivar plugins ya instalados.', 'ephemeral-capabilities-manager' ),
				'capabilities' => [ 'activate_plugins' ],
				'risk'         => 'high',
			],
			'update_plugins'       => [
				'label'        => __( 'Actualizar Plugins', 'ephemeral-capabilities-manager' ),
				'description'  => __( 'Riesgo Medio. Permite actualizar plugins a nuevas versiones disponibles.', 'ephemeral-capabilities-manager' ),
				'capabilities' => [ 'update_plugins' ],
				'risk'         => 'medium', // Puede causar incompatibilidades.
			],
			// --- Tareas Relacionadas con Temas ---
			'install_themes'       => [
				'label'        => __( 'Instalar Temas', 'ephemeral-capabilities-manager' ),
				'description'  => __( '¡RIESGO MUY ALTO! Permite buscar e instalar nuevos temas desde el repositorio o subiendo un ZIP.', 'ephemeral-capabilities-manager' ),
				'capabilities' => [ 'install_themes' ],
				'risk'         => 'critical',
			],
			'switch_themes'        => [
				'label'        => __( 'Cambiar Tema Activo', 'ephemeral-capabilities-manager' ),
				'description'  => __( '¡RIESGO ALTO! Permite activar un tema diferente de los ya instalados.', 'ephemeral-capabilities-manager' ),
				'capabilities' => [ 'switch_themes' ],
				'risk'         => 'high',
			],
			'update_themes'        => [
				'label'        => __( 'Actualizar Temas', 'ephemeral-capabilities-manager' ),
				'description'  => __( 'Riesgo Medio. Permite actualizar temas a nuevas versiones disponibles.', 'ephemeral-capabilities-manager' ),
				'capabilities' => [ 'update_themes' ],
				'risk'         => 'medium',
			],
			// --- Tareas Relacionadas con Usuarios ---
			'manage_users_basic'   => [
				'label'        => __( 'Gestionar Usuarios (Básico)', 'ephemeral-capabilities-manager' ),
				'description'  => __( '¡RIESGO ALTO! Permite ver, añadir, editar y eliminar usuarios (excepto administradores).', 'ephemeral-capabilities-manager' ),
				'capabilities' => [ 'list_users', 'edit_users', 'create_users', 'delete_users' ],
				'risk'         => 'high', // Puede eliminar usuarios.
			],
			'promote_users_limited' => [
				'label'        => __( 'Promover Usuarios (Limitado)', 'ephemeral-capabilities-manager' ),
				'description'  => __( '¡RIESGO MUY ALTO! Permite cambiar el rol de otros usuarios a roles inferiores o iguales al propio. NO permite promover a Administrador.', 'ephemeral-capabilities-manager' ),
				'capabilities' => [ 'promote_users' ], // Capacidad extremadamente sensible.
				'risk'         => 'critical',
			],
			// --- Otras Tareas ---
			'import_export'        => [
				'label'        => __( 'Importar/Exportar Contenido', 'ephemeral-capabilities-manager' ),
				'description'  => __( 'Permite usar las herramientas de Importar y Exportar de WordPress.', 'ephemeral-capabilities-manager' ),
				'capabilities' => [ 'import', 'export' ],
				'risk'         => 'medium', // Puede añadir/eliminar grandes cantidades de datos.
			],
		];
		// Filtro para permitir a otros desarrolladores añadir/modificar bundles.
		$bundles = apply_filters( 'ecm_task_bundles', $bundles );
	}
	return $bundles;
}

// --- Admin Interface ---
add_action( 'admin_menu', 'ecm_add_admin_menu' );

/**
 * Añade la página de administración del plugin bajo el menú 'Usuarios'.
 *
 * @since 0.3.0
 */
function ecm_add_admin_menu() {
	// Añade la página. 'promote_users' es la capacidad requerida.
	$hook_suffix = add_users_page(
		__( 'Gestionar Capacidades Efímeras', 'ephemeral-capabilities-manager' ), // Título de la página (<title>).
		__( 'Capacidades Efímeras', 'ephemeral-capabilities-manager' ),          // Texto en el menú.
		'promote_users',                                                        // Capacidad requerida.
		'ecm_manage_grants',                                                    // Slug de la página.
		'ecm_render_manage_grants_page'                                         // Función que renderiza el contenido.
	);

	// Encolar scripts y estilos solo en nuestra página de admin.
	add_action(
		'admin_enqueue_scripts',
		function ( $hook ) use ( $hook_suffix ) {
			if ( $hook === $hook_suffix ) {
				ecm_enqueue_admin_scripts();
			}
		}
	);
}

/**
 * Prepara los datos necesarios para el script de administración (JavaScript).
 * Incluye bundles, capacidades de usuarios y cadenas traducidas.
 *
 * @since 0.5.0
 * @return array Datos para localizar en el script.
 */
function ecm_get_admin_script_data() {
	$task_bundles = ecm_get_task_bundles();
	// Obtener solo IDs de usuarios no administradores para el select.
	$users_for_select = get_users(
		[
			'fields'       => [ 'ID' ],
			'role__not_in' => [ 'administrator' ],
		]
	);

	// Mapear ID de usuario a sus capacidades actuales.
	$user_capabilities_map = [];
	foreach ( $users_for_select as $user ) {
		$user_data = get_userdata( $user->ID );
		if ( $user_data ) {
			// Guardamos todas las capacidades del usuario (solo las que son true).
			$user_capabilities_map[ $user->ID ] = array_filter( $user_data->allcaps );
		}
	}

	return [
		'bundles'          => $task_bundles,
		'userCapabilities' => $user_capabilities_map,
		'i18n'             => [ // Textos traducibles para JavaScript.
			'selectUserFirst' => __( 'Primero selecciona un usuario', 'ephemeral-capabilities-manager' ),
			'selectTask'      => __( '-- Seleccionar Tarea --', 'ephemeral-capabilities-manager' ),
			'riskMedium'      => __( 'Riesgo Medio', 'ephemeral-capabilities-manager' ),
			'riskHigh'        => __( '¡Riesgo Alto!', 'ephemeral-capabilities-manager' ),
			'riskCritical'    => __( '¡RIESGO CRÍTICO!', 'ephemeral-capabilities-manager' ),
		],
	];
}

/**
 * Encola el script de administración y localiza los datos necesarios.
 *
 * @since 0.5.0
 */
function ecm_enqueue_admin_scripts() {
	wp_enqueue_script(
		'ecm-admin-script',
		ECM_PLUGIN_URL . 'js/ecm-admin.js',
		[ 'jquery' ], // Dependencia.
		ECM_VERSION, // Versión para cache busting.
		true // Cargar en el footer.
	);

	// Pasar datos de PHP a JavaScript de forma segura (ecm_admin_params será el objeto JS).
	wp_localize_script(
		'ecm-admin-script',
		'ecm_admin_params',
		ecm_get_admin_script_data()
	);

	// wp_enqueue_style('ecm-admin-style', ECM_PLUGIN_URL . 'css/ecm-admin.css', [], ECM_VERSION); // Si tuvieras CSS.
}

/**
 * Renderiza el contenido completo de la página de administración.
 * Incluye el formulario de concesión y la tabla de concesiones activas.
 *
 * @since 0.3.0
 */
function ecm_render_manage_grants_page() {
	// Doble verificación de permisos (aunque el menú ya lo hace).
	if ( ! current_user_can( 'promote_users' ) ) {
		wp_die( esc_html__( 'No tienes permisos suficientes para acceder a esta página.', 'ephemeral-capabilities-manager' ) );
	}
	?>
	<div class="wrap">
		<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>

		<?php
		// Mostrar errores/éxitos guardados en transients o registrados con add_settings_error.
		settings_errors( 'ecm_grants_messages' );
		?>

		<h2><?php esc_html_e( 'Conceder Tarea Temporal', 'ephemeral-capabilities-manager' ); ?></h2>
		<p><?php esc_html_e( 'Selecciona un usuario, una tarea predefinida y una duración para conceder capacidades temporalmente.', 'ephemeral-capabilities-manager' ); ?></p>

		<?php // --- Formulario de concesión --- ?>
		<form id="ecm-grant-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php // 'action' indica a admin-post.php qué función ejecutar. ?>
			<input type="hidden" name="action" value="ecm_grant_task">
			<?php // Nonce de seguridad para verificar la intención. ?>
			<?php wp_nonce_field( 'ecm_grant_task_action', 'ecm_grant_nonce' ); ?>

			<table class="form-table" role="presentation">
				<tbody>
					<tr>
						<th scope="row"><label for="ecm_user_id"><?php esc_html_e( 'Usuario', 'ephemeral-capabilities-manager' ); ?></label></th>
						<td>
							<?php
							// Obtener usuarios no administradores para el dropdown.
							$users = get_users( [ 'fields' => [ 'ID', 'display_name' ], 'orderby' => 'display_name', 'role__not_in' => [ 'administrator' ] ] );
							// Recuperar valor previo si hubo error en envío anterior.
							$previous_user_id = '';
							$form_data        = get_transient( 'ecm_form_data_' . get_current_user_id() );
							if ( $form_data && isset( $form_data['ecm_user_id'] ) ) {
								$previous_user_id = $form_data['ecm_user_id'];
							}
							?>
							<select name="ecm_user_id" id="ecm_user_id" required style="min-width: 250px;">
								<option value=""><?php esc_html_e( '-- Seleccionar Usuario --', 'ephemeral-capabilities-manager' ); ?></option>
								<?php foreach ( $users as $user ) : ?>
									<option value="<?php echo esc_attr( $user->ID ); ?>" <?php selected( $previous_user_id, $user->ID ); ?>>
										<?php echo esc_html( $user->display_name ); ?> (ID: <?php echo esc_html( $user->ID ); ?>)
									</option>
								<?php endforeach; ?>
							</select>
							<p class="description"><?php esc_html_e( 'Solo se muestran usuarios no administradores.', 'ephemeral-capabilities-manager' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="ecm_task"><?php esc_html_e( 'Tarea a Permitir', 'ephemeral-capabilities-manager' ); ?></label></th>
						<td>
							<select name="ecm_task" id="ecm_task" required disabled style="max-width: 400px;">
								<?php // Las opciones se llenan dinámicamente con JavaScript. ?>
								<option value=""><?php esc_html_e( '-- Primero Selecciona un Usuario --', 'ephemeral-capabilities-manager' ); ?></option>
							</select>
							<p class="description">
								<?php esc_html_e( 'Las tareas disponibles dependen de las capacidades actuales del usuario seleccionado.', 'ephemeral-capabilities-manager' ); ?>
								<span id="ecm_task_description" style="display: block; margin-top: 5px; color: #666;"></span> <?php // Para mostrar descripción de la tarea. ?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="ecm_duration"><?php esc_html_e( 'Duración', 'ephemeral-capabilities-manager' ); ?></label></th>
						<td>
							<?php
							$previous_duration = '';
							if ( $form_data && isset( $form_data['ecm_duration'] ) ) {
								$previous_duration = $form_data['ecm_duration'];
							}
							// Definir duraciones permitidas (en segundos). Clave => Etiqueta.
							$durations = [
								900    => __( '15 Minutos', 'ephemeral-capabilities-manager' ),
								3600   => __( '1 Hora', 'ephemeral-capabilities-manager' ),
								14400  => __( '4 Horas', 'ephemeral-capabilities-manager' ),
								28800  => __( '8 Horas', 'ephemeral-capabilities-manager' ),
								86400  => __( '1 Día', 'ephemeral-capabilities-manager' ),
								604800 => __( '1 Semana', 'ephemeral-capabilities-manager' ),
							];
							?>
							<select name="ecm_duration" id="ecm_duration" required>
								<option value=""><?php esc_html_e( '-- Seleccionar Duración --', 'ephemeral-capabilities-manager' ); ?></option>
								<?php foreach ( $durations as $value => $label ) : ?>
									<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $previous_duration, $value ); ?>>
										<?php echo esc_html( $label ); ?>
									</option>
								<?php endforeach; ?>
							</select>
							<p class="description"><?php esc_html_e( 'Selecciona por cuánto tiempo serán válidos los permisos.', 'ephemeral-capabilities-manager' ); ?></p>
						</td>
					</tr>
				</tbody>
			</table>
			<?php
			// Limpiar datos del formulario transitorio si existían.
			if ( $form_data ) {
				delete_transient( 'ecm_form_data_' . get_current_user_id() );
			}
			?>
			<?php submit_button( __( 'Conceder Tarea Temporal', 'ephemeral-capabilities-manager' ) ); ?>
		</form>

		<hr>

		<h2><?php esc_html_e( 'Concesiones Activas Actualmente', 'ephemeral-capabilities-manager' ); ?></h2>

		<?php // --- Formulario para la tabla (necesario para filtros y acciones en lote) --- ?>
		<form id="ecm-grants-list-form" method="get"> <?php // Usamos GET para que los filtros sean parte de la URL. ?>
			<?php // Campo oculto necesario para que WP sepa en qué página estamos. ?>
			<input type="hidden" name="page" value="<?php echo esc_attr( $_REQUEST['page'] ?? 'ecm_manage_grants' ); ?>" />
			<?php
				// Instanciar y preparar la tabla.
				$grants_list_table = new ECM_Grants_List_Table();
				$grants_list_table->prepare_items(); // Prepara datos, paginación y procesa acciones.
				// Renderizar la tabla completa.
				$grants_list_table->display();
			?>
		</form>

	</div> <?php // Fin .wrap ?>
	<?php
}


// --- Form Processing (Grant Task) ---
add_action( 'admin_post_ecm_grant_task', 'ecm_handle_grant_task_form' );

/**
 * Maneja el envío del formulario para conceder tareas temporales.
 * Enganchado a 'admin_post_{action}' donde action es 'ecm_grant_task'.
 *
 * @since 0.4.0
 */
function ecm_handle_grant_task_form() {
	global $wpdb;

	// 1. Verificar Nonce y Permisos del usuario actual.
	if ( ! isset( $_POST['ecm_grant_nonce'] ) || ! wp_verify_nonce( sanitize_key( $_POST['ecm_grant_nonce'] ), 'ecm_grant_task_action' ) ) {
		wp_die( esc_html__( 'Error de seguridad (Nonce inválido). Inténtalo de nuevo.', 'ephemeral-capabilities-manager' ) );
	}
	if ( ! current_user_can( 'promote_users' ) ) {
		wp_die( esc_html__( 'No tienes permisos para realizar esta acción.', 'ephemeral-capabilities-manager' ) );
	}

	// 2. Sanitizar y Validar Entradas del formulario.
	$user_id  = isset( $_POST['ecm_user_id'] ) ? absint( $_POST['ecm_user_id'] ) : 0;
	$task_key = isset( $_POST['ecm_task'] ) ? sanitize_key( $_POST['ecm_task'] ) : '';
	$duration = isset( $_POST['ecm_duration'] ) ? absint( $_POST['ecm_duration'] ) : 0;
	$errors   = [];

	$task_bundles = ecm_get_task_bundles();
	$user_data    = get_userdata( $user_id );

	// Validar Usuario.
	if ( 0 >= $user_id || ! $user_data || user_can( $user_id, 'administrator' ) ) {
		$errors[] = __( 'Usuario seleccionado inválido o es un administrador.', 'ephemeral-capabilities-manager' );
	}
	// Validar Tarea.
	if ( empty( $task_key ) || ! isset( $task_bundles[ $task_key ] ) ) {
		$errors[] = __( 'Tarea seleccionada inválida.', 'ephemeral-capabilities-manager' );
	}
	// Validar Duración.
	// Asegúrate que estas duraciones coinciden con las del formulario.
	$allowed_durations = [ 900, 3600, 14400, 28800, 86400, 604800 ];
	if ( 0 >= $duration || ! in_array( $duration, $allowed_durations, true ) ) {
		$errors[] = __( 'Duración seleccionada inválida.', 'ephemeral-capabilities-manager' );
	}

	// Validar que la tarea realmente otorga nuevas capacidades al usuario.
	if ( empty( $errors ) && isset( $task_bundles[ $task_key ] ) ) {
		$capabilities_to_grant = $task_bundles[ $task_key ]['capabilities'];
		$grants_new_capability = false;
		$user_caps             = $user_data ? array_filter( $user_data->allcaps ) : []; // Caps actuales del usuario.
		foreach ( $capabilities_to_grant as $cap ) {
			// Comprueba si el usuario NO tiene la capacidad o la tiene explícitamente a false.
			if ( ! array_key_exists( $cap, $user_caps ) || ! $user_caps[ $cap ] ) {
				$grants_new_capability = true;
				break; // Basta con que una sea nueva.
			}
		}
		if ( ! $grants_new_capability ) {
			$errors[] = __( 'La tarea seleccionada no otorga ninguna capacidad nueva a este usuario (ya las posee).', 'ephemeral-capabilities-manager' );
		}
	}

	// 3. Si hay errores, guardar datos en transient y redirigir mostrando mensajes.
	if ( ! empty( $errors ) ) {
		// Guardar errores y datos del formulario para mostrarlos tras redirigir.
		set_transient( 'ecm_admin_errors_' . get_current_user_id(), $errors, 60 ); // 60 segundos de vida.
		set_transient( 'ecm_form_data_' . get_current_user_id(), $_POST, 60 );
		wp_safe_redirect( admin_url( 'users.php?page=ecm_manage_grants' ) );
		exit;
	}

	// 4. Si no hay errores, procesar la concesión.
	$capabilities_to_grant = $task_bundles[ $task_key ]['capabilities'];
	$granted_by_user_id  = get_current_user_id();
	$grant_timestamp     = current_time( 'timestamp', true ); // UTC.
	$expiry_timestamp    = $grant_timestamp + $duration;
	$table_name          = $wpdb->prefix . ECM_GRANTS_TABLE;
	$inserted_count      = 0;
	$total_to_insert     = count( $capabilities_to_grant );
	$db_error_occurred   = false;

	// Insertar una fila en la BD por cada capacidad en el bundle.
	foreach ( $capabilities_to_grant as $capability ) {
		$inserted = $wpdb->insert(
			$table_name,
			[
				'user_id'            => $user_id,
				'capability'         => $capability,
				'granted_by_user_id' => $granted_by_user_id,
				'grant_timestamp'    => $grant_timestamp,
				'expiry_timestamp'   => $expiry_timestamp,
				'status'             => 'active',
				'context_data'       => maybe_serialize( [ 'task_key' => $task_key ] ), // Guardar clave de tarea en contexto.
			],
			[ '%d', '%s', '%d', '%d', '%d', '%s', '%s' ] // Formatos para prepare() interno de insert.
		);

		if ( false !== $inserted ) {
			$inserted_count++;
		} else {
			$db_error_occurred = true;
			// Podrías loguear $wpdb->last_error aquí si necesitas depurar errores de BD.
			break; // Salir del bucle si hay un error de BD.
		}
	}

	// 5. Establecer mensaje de éxito/error y redirigir.
	if ( $db_error_occurred || 0 === $inserted_count ) {
		$error_message = __( 'Error al guardar una o más capacidades en la base de datos.', 'ephemeral-capabilities-manager' );
		if ( 0 < $inserted_count ) { // Error parcial.
			// Translators: %1$d = number saved, %2$d = total attempted.
			$error_message .= ' ' . sprintf( __( 'Se guardaron %1$d de %2$d capacidades.', 'ephemeral-capabilities-manager' ), $inserted_count, $total_to_insert );
		}
		add_settings_error( 'ecm_grants_messages', 'db_error', $error_message, 'error' );
	} else {
		// Éxito (total o parcial si la validación previa fallara).
		$task_label = $task_bundles[ $task_key ]['label'];
		// Translators: %1$s = Task Label (bold), %2$s = User Name (bold), %3$s = Expiry Date/Time.
		$success_message = sprintf(
			__( 'Tarea %1$s concedida a %2$s hasta %3$s.', 'ephemeral-capabilities-manager' ),
			'<strong>' . esc_html( $task_label ) . '</strong>',
			'<strong>' . esc_html( $user_data->display_name ) . '</strong>',
			wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $expiry_timestamp ) // Mostrar fecha/hora local.
		);
		if ( $inserted_count < $total_to_insert ) {
			// Translators: %d = number of capabilities granted.
			$success_message .= ' ' . sprintf( __( 'Se otorgaron %d capacidades nuevas/renovadas.', 'ephemeral-capabilities-manager' ), $inserted_count );
			add_settings_error( 'ecm_grants_messages', 'grant_partial_success', $success_message, 'info' );
		} else {
			add_settings_error( 'ecm_grants_messages', 'grant_success', $success_message, 'success' );
		}
	}

	set_transient( 'settings_errors', get_settings_errors(), 30 ); // Guardar mensajes para mostrar tras redirigir.
	wp_safe_redirect( admin_url( 'users.php?page=ecm_manage_grants' ) );
	exit;
}

// --- Revoke Action Processing (Individual) ---
add_action( 'admin_post_ecm_revoke_grant', 'ecm_handle_revoke_grant' );

/**
 * Maneja la acción de revocar una concesión individual desde la tabla (enlace 'Revocar').
 * Enganchado a 'admin_post_{action}' donde action es 'ecm_revoke_grant'.
 *
 * @since 0.5.0
 */
function ecm_handle_revoke_grant() {
	global $wpdb;

	// 1. Obtener y validar Grant ID de la URL.
	$grant_id = isset( $_GET['grant_id'] ) ? absint( $_GET['grant_id'] ) : 0;
	if ( 0 >= $grant_id ) {
		wp_die( esc_html__( 'ID de concesión inválido.', 'ephemeral-capabilities-manager' ) );
	}

	// 2. Verificar Nonce.
	$nonce = isset( $_GET['_wpnonce'] ) ? sanitize_key( $_GET['_wpnonce'] ) : '';
	if ( ! wp_verify_nonce( $nonce, 'ecm_revoke_grant_' . $grant_id ) ) {
		wp_die( esc_html__( 'Error de seguridad (Nonce inválido). Inténtalo de nuevo.', 'ephemeral-capabilities-manager' ) );
	}

	// 3. Verificar Permisos del usuario actual.
	if ( ! current_user_can( 'promote_users' ) ) {
		wp_die( esc_html__( 'No tienes permisos para revocar esta concesión.', 'ephemeral-capabilities-manager' ) );
	}

	// 4. Actualizar Base de Datos usando $wpdb->update para seguridad.
	$table_name = $wpdb->prefix . ECM_GRANTS_TABLE;
	$updated    = $wpdb->update(
		$table_name,
		[ 'status' => 'revoked' ], // Datos a actualizar.
		[                        // Condición WHERE.
			'grant_id' => $grant_id,
			'status'   => 'active', // Solo revocar si está activa.
		],
		[ '%s' ], // Formato de los datos a actualizar.
		[ '%d', '%s' ] // Formato de la condición WHERE.
	);

	// 5. Establecer Mensaje y Redirigir de vuelta a la página de gestión.
	if ( false === $updated ) {
		add_settings_error( 'ecm_grants_messages', 'revoke_db_error', __( 'Error al intentar revocar la concesión en la base de datos.', 'ephemeral-capabilities-manager' ), 'error' );
	} elseif ( 0 === $updated ) {
		add_settings_error( 'ecm_grants_messages', 'revoke_not_found', __( 'La concesión no se encontró o ya no estaba activa.', 'ephemeral-capabilities-manager' ), 'warning' );
	} else {
		add_settings_error( 'ecm_grants_messages', 'revoke_success', __( 'Concesión revocada correctamente.', 'ephemeral-capabilities-manager' ), 'success' );
	}

	set_transient( 'settings_errors', get_settings_errors(), 30 ); // Guardar mensajes.

	// Redirigir manteniendo el filtro de usuario si estaba activo.
	$redirect_url = add_query_arg(
		[
			'page'        => 'ecm_manage_grants',
			'user_filter' => isset( $_GET['user_filter'] ) ? absint( $_GET['user_filter'] ) : 0,
			// 'revoked'     => $grant_id // Podríamos añadir un parámetro si fuera útil.
		],
		admin_url( 'users.php' )
	);
	// Limpiar parámetros de acción y nonce de la URL de redirección.
	$redirect_url = remove_query_arg( [ 'action', '_wpnonce', 'grant_id' ], $redirect_url );
	wp_safe_redirect( $redirect_url );
	exit;
}


// --- Admin Notices ---
add_action( 'admin_notices', 'ecm_show_admin_notices' );
add_action( 'network_admin_notices', 'ecm_show_admin_notices' ); // Para multisitio.

/**
 * Muestra los mensajes administrativos (errores/éxitos) guardados en transients
 * o registrados con add_settings_error en la página del plugin.
 *
 * @since 0.4.0
 */
function ecm_show_admin_notices() {
	// Obtener pantalla actual para mostrar avisos solo donde sea relevante.
	$screen = get_current_screen();
	// Comparar con el ID base de nuestra página de admin (users_page_{slug}).
	if ( ! $screen || 'users_page_ecm_manage_grants' !== $screen->base ) {
		return; // No mostrar en otras páginas.
	}

	// Mostrar errores de validación del formulario (guardados en transient).
	$validation_errors = get_transient( 'ecm_admin_errors_' . get_current_user_id() );
	if ( $validation_errors ) {
		echo '<div id="message" class="notice notice-error is-dismissible"><p>'
			. implode( '</p><p>', array_map( 'esc_html', $validation_errors ) ) // Escapar cada mensaje.
			. '</p></div>';
		delete_transient( 'ecm_admin_errors_' . get_current_user_id() ); // Borrar tras mostrar.
	}

	// Mostrar mensajes registrados con add_settings_error (usados por acciones de tabla y form).
	// WordPress busca automáticamente los errores del grupo 'ecm_grants_messages'.
	settings_errors( 'ecm_grants_messages' );

	// Nota: No es necesario borrar el transient 'settings_errors' manualmente aquí,
	// settings_errors() maneja su ciclo de vida correctamente en la mayoría de los casos.
}

// --- Internationalization ---
add_action( 'plugins_loaded', 'ecm_load_textdomain' );

/**
 * Carga el text domain del plugin para permitir traducciones.
 *
 * @since 0.1.0
 */
function ecm_load_textdomain() {
	load_plugin_textdomain(
		'ephemeral-capabilities-manager', // Text domain.
		false, // No usar .mo obsoleto (WordPress buscará .mo basado en el text domain).
		basename( ECM_PLUGIN_DIR ) . '/languages' // Ruta relativa: plugin-dir/languages/.
	);
}

// --- Custom Cron Intervals ---
add_filter( 'cron_schedules', 'ecm_add_custom_cron_intervals' );

/**
 * Añade intervalos de tiempo personalizados a WP-Cron.
 *
 * @since 0.8.0
 *
 * @param array $schedules Array existente de intervalos [key => [interval, display]].
 * @return array Array modificado con los nuevos intervalos.
 */
function ecm_add_custom_cron_intervals( $schedules ) {
	// Añadir intervalo de 15 minutos si no existe.
	if ( ! isset( $schedules['ecm_15_minutes'] ) ) {
		$schedules['ecm_15_minutes'] = [
			'interval' => 900, // 15 * 60 segundos.
			'display'  => __( 'Cada 15 Minutos (ECM)', 'ephemeral-capabilities-manager' ),
		];
	}
	// Añadir intervalo de 5 minutos si no existe.
	if ( ! isset( $schedules['ecm_5_minutes'] ) ) {
		$schedules['ecm_5_minutes'] = [
			'interval' => 300, // 5 * 60 segundos.
			'display'  => __( 'Cada 5 Minutos (ECM)', 'ephemeral-capabilities-manager' ),
		];
	}
	return $schedules;
}

// --- FIN DEL PLUGIN ---
