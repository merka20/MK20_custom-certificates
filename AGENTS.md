# AGENTS.md — MK20 Custom Certificates

Plugin WordPress clásico (sin build, sin composer/npm, sin CI). Genera certificados PDF de dos caras con FPDF al completar cursos de LearnDash, integra BuddyBoss/BuddyPress (perfil + verificación pública) y subida automática a API externa.

## Estructura

- `mk20-custom-certificates.php` — bootstrap: define constantes `MK20_CERT_PATH/URL`, `MK20_CERT_DB_VERSION`, opciones de API externa; requiere los 4 archivos de `includes/` (líneas 133-136); hook `mk20_custom_certificates_init` instancia `MK20_Admin` y `MK20_REST`.
- `includes/helpers.php` — funciones `mk20_*` (nombre de estudiante, índice de certificados, API externa, audit log, protección de directorio).
- `includes/class-mk20-admin.php` — panel de ajustes, meta box de cursos, visor admin global.
- `includes/class-mk20-pdf-engine.php` — render PDF FPDF; plantillas en `templates/diploma-anverso.jpg` y `diploma-reverso.jpg` (NO borrarlas, se referencian por ruta).
- `includes/class-mk20-rest.php` — endpoints REST + rewrite público `/verificar/{hex12+}` (página pública, fuerza bypass de restricciones BuddyBoss).
- `lib/fpdf/` — **tercero, nunca modificar, siempre excluir de lint/phpcs/empaquetado**.
- `uninstall.php` — borra opciones, user-meta `_mk20_cert_*`/`_mk20_ext_cert_*`, directorio `mk20-certificates/` y la tabla `{prefix}mk20_certs`.
- `languages/mk20-custom-certificates.pot` — regenerar con `wp i18n make-pot`.

## Convenciones del repo (no obvias)

- Commits con formato `[AGENTE] mensaje`; commit inicial `[INICIAL] ...`.
- Branch `master`, remote `https://github.com/merka20/MK20_custom-certificates.git`.
- Todos los `wp_die()` y strings de UI traducidos con `esc_html__()`/`esc_html_e()`, text domain `mk20-custom-certificates`.
- Variables/globals prefijadas `mk20_`. Al construir objetos a partir de user-meta, usar claves `mk20_key`/`mk20_val` (NO `meta_key`/`meta_value`): el sniff `WordPress.DB.SlowDBQuery` las marca como falso positivo).
- No añadir header `Update URI` (plugin-check lo marca como ERROR `plugin_updater_detected`). No llamar `load_plugin_textdomain()` manualmente (wp.org carga traducciones solo).
- La tabla `{prefix}mk20_certs` (índice de verificación) se gestiona SOLO vía helpers de `includes/helpers.php`: `mk20_get_cert_index_table()`, `mk20_create_cert_index_table()`, `mk20_ensure_cert_index_table()`, `mk20_index_certificate()`, `mk20_remove_certificate_index()`, `mk20_backfill_cert_index()`.

## Verificación

- Lint: `php -l` sobre cada PHP del plugin, **excluyendo `lib/fpdf/`**.
- phpcs (WPCS 3.13.1): `php "C:\Users\PC_mini\AppData\Local\Temp\opencode\phpcs\phpcs2.phar" --standard=WordPress --sniffs=WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL,WordPress.DB.SlowDBQuery,WordPress.Security.ValidatedSanitizedInput,WordPress.Security.NonceVerification,WordPress.Security.EscapeOutput,WordPress.WP.CronInterval,WordPress.NamingConventions.PrefixAllGlobals,WordPress.WP.GlobalVariablesOverride <archivos>`. WPCS config global ya tiene `installed_paths` correctos.
- `phpcs:ignore` solo con justificación (ej. escrituras `$wpdb->replace/delete` sobre tabla propia: NoCaching no aplica; DROP en uninstall: SchemaChange intencional). Para el DROP usar bloque `phpcs:disable/...enable` (el reporte de plugin-check cae en otra línea que el `phpcs:ignore` inline).
- Norma del usuario: fixes con APIs reales de WP, no silenciar con `phpcs:ignore` salvo casos aislados justificados.

## Empaquetado (crítico)

- Subir a WordPress: generar zip en el **Desktop** (`C:\Users\PC_mini\Desktop\mk20-custom-certificates-1.3.0-fixes.zip`), NO LocalWP.
- Excluir: `.git/`, `.gitignore`, `lib/fpdf/makefont/`, `lib/fpdf/fpdf.css`. Incluir `templates/diploma-*.jpg` y `languages/`.
- Validar contra https://wordpress.org/plugins/plugin-check/ re-subiendo el zip.
