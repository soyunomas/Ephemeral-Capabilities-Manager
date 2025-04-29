=== Ephemeral Capabilities Manager ===
Contributors: soyunomas
Tags: security, capabilities, permissions, roles, temporary access, user management, admin, iam, principle of least privilege, zero trust, seguridad, permisos, roles, acceso temporal, gestion usuarios
Requires at least: 5.8
Tested up to: 6.8
Stable tag: 1.0.0
Requires PHP: 7.4
License: GPL v2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html
Donate link: (Opcional: Si tienes un enlace de donación)

Concede capacidades de WordPress a usuarios por un tiempo limitado. Mejora la seguridad aplicando el Principio de Privilegio Mínimo dinámicamente.

== Description ==

**Ephemeral Capabilities Manager (ECM)** mejora la seguridad de tu sitio WordPress permitiendo a los administradores conceder capacidades específicas o conjuntos predefinidos de capacidades (llamados "Tareas") a usuarios no administradores durante una **duración estrictamente limitada**.

¡Deja de dar acceso temporal de administrador o de elevar roles permanentemente para tareas de corta duración! ECM proporciona una forma segura y auditable de implementar el **Principio de Privilegio Mínimo** de forma dinámica.

**¿Cómo Funciona?**

1.  Un administrador navega a "Usuarios" -> "Capacidades Efímeras".
2.  Selecciona un usuario no administrador.
3.  Elige una "Tarea" predefinida (ej. "Publicar Contenido Propio", "Moderar Comentarios", "Actualizar Plugins"). Las tareas disponibles se filtran según las capacidades que el usuario *no* posee actualmente. Las tareas se clasifican por nivel de riesgo.
4.  Selecciona una duración (ej. 15 minutos, 1 hora, 1 día).
5.  ECM registra de forma segura esta concesión temporal en una tabla de base de datos personalizada.
6.  Mientras la concesión está activa, las comprobaciones de permisos de WordPress (`current_user_can()`) reconocerán las capacidades temporales para ese usuario.
7.  **Fundamental: La comprobación de permisos verifica el tiempo de expiración en tiempo real.** Tan pronto como expira la duración, el usuario pierde automáticamente las capacidades concedidas, sin necesidad de intervención del administrador.
8.  Una tarea WP-Cron en segundo plano se ejecuta periódicamente (por defecto: cada hora) para limpiar la base de datos marcando las concesiones expiradas como 'expired'. **Esta tarea de limpieza NO afecta a la expiración en tiempo real aplicada por la comprobación de permisos.**

**Características Principales:**

*   **Permisos Temporales:** Concede capacidades solo por el tiempo necesario (minutos, horas, días).
*   **Paquetes de Tareas:** Tareas comunes predefinidas (conjuntos de capacidades) para facilitar el uso (ej. Gestionar Contenido, Actualizar Plugins, Gestionar Apariencia).
*   **Indicadores de Riesgo:** Las tareas se marcan visualmente con niveles de riesgo (Bajo, Medio, Alto, Crítico).
*   **Aplicación del Privilegio Mínimo:** Evita la sobreasignación permanente de roles. Solo concede capacidades que el usuario no posee ya para la tarea seleccionada.
*   **Implementación Segura:** Usa Nonces de WordPress, comprobaciones de capacidad, sanitización de entradas, escapado de salidas y consultas seguras a la base de datos.
*   **Interfaz de Administración:** Interfaz clara bajo el menú "Usuarios" para conceder permisos y ver concesiones activas.
*   **Revocación:** Los administradores pueden revocar manualmente concesiones activas antes de que expiren. También se soporta la revocación en lote.
*   **Filtrado:** Filtra fácilmente las concesiones activas por usuario.
*   **Limpieza Automática:** Tarea WP-Cron mantiene ordenada la tabla de concesiones.
*   **Extensible:** Incluye un filtro (`ecm_task_bundles`) para que los desarrolladores añadan o modifiquen paquetes de tareas.

ECM es ideal para escenarios como:
*   Permitir a un colaborador actualizar un plugin específico durante 15 minutos.
*   Dar a un editor derechos temporales para gestionar todo el contenido durante un día en un evento.
*   Conceder acceso temporal a las opciones del tema a un técnico de soporte.

== Installation ==

1.  **Subir ZIP:**
    *   Descarga el archivo `ephemeral-capabilities-manager.zip` (ej. desde el [repositorio GitHub](https://github.com/soyunomas/ephemeral-capabilities-manager)).
    *   En tu panel de WordPress, ve a `Plugins` -> `Añadir nuevo`.
    *   Haz clic en `Subir plugin`.
    *   Selecciona el archivo `ephemeral-capabilities-manager.zip` y haz clic en `Instalar ahora`.
    *   Activa el plugin a través del menú 'Plugins' en WordPress.
2.  **Instalación Manual:**
    *   Descarga y descomprime el paquete del plugin.
    *   Sube la carpeta `ephemeral-capabilities-manager` completa al directorio `/wp-content/plugins/` de tu servidor vía FTP o el gestor de archivos de tu hosting.
    *   Activa el plugin a través del menú 'Plugins' en WordPress.

**Uso:**

1.  Como Administrador (o un usuario con la capacidad `promote_users`), navega a `Usuarios` -> `Capacidades Efímeras`.
2.  Usa el formulario "Conceder Tarea Temporal":
    *   Selecciona el usuario destinatario (solo no administradores).
    *   Selecciona la tarea deseada del desplegable (las opciones se filtran según las capacidades actuales del usuario). Observa los indicadores de riesgo.
    *   Elige la duración durante la cual los permisos estarán activos.
    *   Haz clic en "Conceder Tarea Temporal".
3.  Las concesiones activas se listan en la tabla debajo del formulario. Puedes filtrar esta tabla por usuario y revocar concesiones individuales o múltiples.

== Frequently Asked Questions ==

= ¿Qué pasa cuando expira el tiempo? =

El usuario pierde inmediatamente las capacidades concedidas. La comprobación central de permisos (filtro `user_has_cap`) compara constantemente el tiempo de expiración de la concesión con la hora actual. Incluso si la tarea Cron de limpieza en segundo plano aún no se ha ejecutado, el permiso desaparece efectivamente en el momento en que pasa el tiempo de expiración.

= ¿Puedo conceder permisos a Administradores? =

No. El plugin está diseñado para conceder *elevaciones temporales* a usuarios con *menos* privilegios. Los administradores ya tienen todas las capacidades, por lo que concederles capacidades temporales es innecesario y potencialmente confuso. El desplegable de selección de usuario excluye a los administradores.

= ¿Qué 'Tareas' están disponibles por defecto? =

El plugin incluye paquetes para tareas comunes como:
*   Publicar Contenido Propio (Riesgo Bajo)
*   Gestionar Todo el Contenido (Riesgo Medio)
*   Moderar Comentarios (Riesgo Bajo)
*   Gestionar Apariencia (Riesgo Alto)
*   Instalar Plugins (Riesgo Crítico)
*   Activar/Desactivar Plugins (Riesgo Alto)
*   Actualizar Plugins (Riesgo Medio)
*   Instalar Temas (Riesgo Crítico)
*   Cambiar Tema Activo (Riesgo Alto)
*   Actualizar Temas (Riesgo Medio)
*   Gestionar Usuarios (Básico) (Riesgo Alto)
*   Promover Usuarios (Limitado) (Riesgo Crítico)
*   Importar/Exportar Contenido (Riesgo Medio)
*(Nota: Las capacidades exactas dentro de cada paquete están definidas en el código y son visibles a través del filtro `ecm_task_bundles`).*

= ¿Qué pasa si concedo una tarea pero el usuario ya tiene esas capacidades? =

El plugin lo comprueba antes de concederla. Si la tarea seleccionada no proporciona ninguna capacidad *nueva* que el usuario no posea ya (directamente o a través de su rol), la concesión será denegada y se mostrará un mensaje de error.

= ¿Puede un usuario ver qué permisos temporales tiene? =

Actualmente (v1.0.0), no hay una interfaz específica para que el *usuario final* vea sus concesiones efímeras activas. Solo los administradores (con la capacidad `promote_users`) pueden ver la lista en la página de administración del plugin. Esto podría ser una característica para una versión futura.

= ¿Qué pasa si desactivo el plugin? =

Al desactivarlo, se elimina la tarea WP-Cron programada para la limpieza. La tabla de base de datos personalizada (`wp_ephemeral_grants`) y las opciones del plugin permanecen en la base de datos, pero el filtro central (`user_has_cap`) ya no está activo, por lo que **todos los permisos temporales concedidos por este plugin dejan de funcionar inmediatamente**, incluso si su tiempo de expiración no ha llegado según la base de datos.

= ¿Qué pasa si elimino el plugin? =

Al eliminarlo a través de la pantalla de Plugins de WordPress, se ejecuta el script `uninstall.php` y **elimina completamente** la tabla de base de datos personalizada (`wp_ephemeral_grants`), la opción de versión del plugin de `wp_options`, y asegura que la tarea WP-Cron esté desprogramada. Es una eliminación limpia.

= ¿Es compatible con plugins de Autenticación de Dos Factores (2FA)? =

Sí, debería ser totalmente compatible. ECM opera sobre las capacidades de WordPress *después* de que un usuario haya iniciado sesión correctamente. Los plugins de 2FA suelen actuar *durante* el propio proceso de inicio de sesión. Funcionan en capas diferentes.

= ¿Pueden los desarrolladores añadir sus propias Tareas? =

¡Sí! El plugin proporciona el filtro `ecm_task_bundles`, permitiendo a otros plugins o temas añadir, modificar o eliminar paquetes de tareas programáticamente.

== Screenshots ==

1.  **Página de Administración:** Muestra el formulario para Conceder Tarea y la lista de Concesiones Activas.
2.  **Desplegable de Tareas:** Ejemplo del desplegable de tareas mostrando las tareas disponibles filtradas para un usuario específico, incluyendo indicadores de riesgo.
3.  **Tabla de Concesiones Activas:** Detalle de la tabla que lista las concesiones activas con usuario, capacidad, expiración y acciones de revocación.

== Changelog ==

= 1.0.0 =
*   **Fecha de Lanzamiento:** 29 de Abril de 2025
*   Lanzamiento público inicial.
*   Funcionalidad: Conceder Paquetes de Tareas predefinidos (conjuntos de capacidades) a usuarios no administradores por una duración limitada.
*   Funcionalidad: Interfaz de administración bajo "Usuarios" para gestionar concesiones.
*   Funcionalidad: Ver, filtrar (por usuario) y revocar concesiones activas.
*   Funcionalidad: Revocación en lote de concesiones.
*   Funcionalidad: Comprobación automática de expiración vía filtro `user_has_cap` (tiempo real).
*   Funcionalidad: Limpieza automática de base de datos vía WP-Cron (por defecto: cada hora).
*   Funcionalidad: Filtrado dinámico del desplegable de tareas basado en las capacidades actuales del usuario.
*   Funcionalidad: Indicadores visuales de riesgo para las tareas.
*   Funcionalidad: Implementación segura usando Nonces, sanitización, escapado, comprobaciones de capacidad.
*   Funcionalidad: `uninstall.php` para eliminación limpia.
*   Funcionalidad: Listo para internacionalización con archivo `.pot` y traducciones al Español, Alemán y Francés incluidas.
*   Funcionalidad: Filtro `ecm_task_bundles` para extensibilidad.
*   Funcionalidad: Intervalos WP-Cron personalizados (5min, 15min) definibles vía filtro.

== Upgrade Notice ==

= 1.0.0 =
Lanzamiento inicial estable.
