# Ephemeral Capabilities Manager

[![License: GPL v2 or later](https://img.shields.io/badge/License-GPL%20v2%20or%20later-blue.svg)](https://www.gnu.org/licenses/gpl-2.0.html)
[![Stable tag](https://img.shields.io/badge/Stable%20tag-1.0.0-brightgreen.svg)](https://github.com/soyunomas/ephemeral-capabilities-manager/releases/tag/v1.0.0)
[![Requires WordPress](https://img.shields.io/badge/Requires%20WordPress-5.8+-blue.svg)](https://wordpress.org/download/)
[![Tested up to WordPress](https://img.shields.io/badge/Tested%20up%20to%20WordPress-6.8-blue.svg)](https://wordpress.org/download/)
[![Requires PHP](https://img.shields.io/badge/Requires%20PHP-7.4+-blue.svg)](https://www.php.net/releases/)

## Descripción

**Ephemeral Capabilities Manager (ECM)** mejora la seguridad de tu sitio WordPress permitiendo a los administradores conceder capacidades específicas o conjuntos predefinidos de capacidades (llamados "Tareas") a usuarios no administradores durante una **duración estrictamente limitada**.

¡Deja de dar acceso temporal de administrador o de elevar roles permanentemente para tareas de corta duración! ECM proporciona una forma segura y auditable de implementar el **Principio de Privilegio Mínimo** de forma dinámica.

### ¿Por qué ECM?

*   **Evita Riesgos:** Conceder temporalmente el rol de Administrador es peligroso.
*   **Evita Privilegios Permanentes:** Elevar el rol de un usuario de forma permanente para una tarea puntual deja una puerta abierta innecesaria.
*   **Solución Dinámica:** ECM aplica el privilegio mínimo *justo a tiempo* y lo revoca automáticamente.

### Características Principales

*   **Permisos Temporales:** Concede capacidades solo por el tiempo necesario (15 min, 1 hora, 4h, 8h, 1 día, 1 semana).
*   **Paquetes de Tareas:** Tareas comunes predefinidas (conjuntos de capacidades) para facilitar el uso (ej. Gestionar Contenido, Actualizar Plugins, Gestionar Apariencia).
*   **Indicadores de Riesgo:** Las tareas se marcan visualmente con niveles de riesgo (Bajo, Medio, Alto, Crítico).
*   **Aplicación del Privilegio Mínimo:** Solo concede capacidades que el usuario no posee ya para la tarea seleccionada.
*   **Implementación Segura:** Usa Nonces de WordPress, comprobaciones de capacidad (`promote_users`), sanitización de entradas, escapado de salidas y consultas seguras a la base de datos.
*   **Interfaz de Administración:** Interfaz clara bajo el menú "Usuarios" -> "Capacidades Efímeras" para conceder permisos y ver concesiones activas.
*   **Revocación:** Los administradores pueden revocar manualmente concesiones activas antes de que expiren (individualmente o en lote).
*   **Filtrado:** Filtra fácilmente las concesiones activas por usuario.
*   **Limpieza Automática:** Tarea WP-Cron (por defecto: cada hora) mantiene ordenada la tabla de concesiones marcando las expiradas.
*   **Internacionalización:** Listo para traducir.

---

## Instalación

### Opción 1: Subir ZIP desde WordPress

1.  Descarga la última versión como archivo `.zip` desde la sección [Releases](https://github.com/soyunomas/ephemeral-capabilities-manager/releases) de este repositorio.
2.  En tu panel de WordPress, ve a `Plugins` -> `Añadir nuevo`.
3.  Haz clic en `Subir plugin`.
4.  Selecciona el archivo `.zip` descargado y haz clic en `Instalar ahora`.
5.  Activa el plugin.

### Opción 2: Manualmente (vía FTP/SFTP/SSH)

1.  Descarga y descomprime la última versión desde [Releases](https://github.com/soyunomas/ephemeral-capabilities-manager/releases) o clona el repositorio:
    ```bash
    git clone https://github.com/soyunomas/ephemeral-capabilities-manager.git
    ```
2.  Sube la carpeta `ephemeral-capabilities-manager` completa al directorio `/wp-content/plugins/` de tu instalación de WordPress.
3.  Ve a tu panel de WordPress -> `Plugins` y activa "Ephemeral Capabilities Manager".

---

## Uso

1.  Como **Administrador** (o un usuario con la capacidad `promote_users`), navega a `Usuarios` -> `Capacidades Efímeras`.
2.  En el formulario **"Conceder Tarea Temporal"**:
    *   **Selecciona el Usuario:** Elige un usuario no administrador del desplegable.
    *   **Selecciona la Tarea a Permitir:** Elige una tarea. Las opciones se filtran automáticamente para mostrar solo tareas que otorgan capacidades que el usuario no posee. Fíjate en los indicadores de riesgo (Medio, Alto, Crítico). La descripción de la tarea aparecerá debajo.
    *   **Selecciona la Duración:** Elige por cuánto tiempo estará activo el permiso (ej: 15 Minutos, 1 Hora, etc.).
    *   Haz clic en **"Conceder Tarea Temporal"**.
3.  La tabla **"Concesiones Activas Actualmente"** mostrará todas las concesiones que aún no han expirado.
    *   Puedes **filtrar** la tabla por usuario.
    *   Puedes **revocar** una concesión individual haciendo clic en el enlace "Revocar".
    *   Puedes seleccionar varias concesiones y usar la acción en lote **"Revocar Seleccionadas"**.

---

## Capturas de Pantalla (Placeholders)

1.  **Desplegable de Tareas con Indicadores de Riesgo:**
    `![Desplegable de Tareas](images/screenshot_1.png)`
    
2.  **Tabla de Concesiones Activas:**
    `![Tabla de Concesiones](images/screenshot_2.png)`

---

## Licencia

Este plugin está licenciado bajo la **GPLv2 o posterior**.
Consulta la [Licencia GPLv2](https://www.gnu.org/licenses/gpl-2.0.html) para más detalles.

---

## Registro de Cambios

### 1.0.0 (29 de Abril de 2025)

*   Lanzamiento público inicial.
*   Funcionalidad para conceder Paquetes de Tareas (conjuntos de capacidades) a usuarios no administradores por una duración limitada.
*   Interfaz de administración para gestionar concesiones (conceder, ver, filtrar, revocar individual/lote).
*   Comprobación de expiración en tiempo real y limpieza automática vía WP-Cron.
*   Filtrado dinámico de tareas, indicadores de riesgo y validaciones.
*   Implementación segura y `uninstall.php` para eliminación limpia.
