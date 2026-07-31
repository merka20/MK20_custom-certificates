<?php
/**
 * Plugin Name: MK20 Custom Certificates
 * Plugin URI:  https://github.com/merka20/MK20_custom-certificates
 * Description: Genera certificados PDF de dos caras (anverso y reverso) personalizados mediante FPDF al completar cursos de LearnDash.
 * Version:     1.3.0
 * Author:      Merka2.0
 * Author URI:  https://merka20.com
 * License:     GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Requires at least: 5.0
 * Requires PHP: 7.4
 * Text Domain: mk20-custom-certificates
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'MK20_CERT_PATH', plugin_dir_path( __FILE__ ) );
define( 'MK20_CERT_URL', plugin_dir_url( __FILE__ ) );
define( 'MK20_CERT_DB_VERSION', '1.0.0' );

if ( ! defined( 'MK20_EXT_API_URL' ) ) {
    define( 'MK20_EXT_API_URL', '' );
}
if ( ! defined( 'MK20_EXT_API_TOKEN' ) ) {
    define( 'MK20_EXT_API_TOKEN', '' );
}
if ( ! defined( 'MK20_EXT_API_LOG' ) ) {
    define( 'MK20_EXT_API_LOG', '' );
}
if ( ! defined( 'MK20_EXT_UPLOAD_URL' ) ) {
    define( 'MK20_EXT_UPLOAD_URL', '' );
}

/**
 * Obtiene la version instalada de FPDF desde el archivo.
 */
function mk20_get_fpdf_version() {
    $file = MK20_CERT_PATH . 'lib/fpdf/fpdf.php';
    if ( ! file_exists( $file ) ) {
        return '0';
    }
    $content = file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
    if ( preg_match( "/const\s+VERSION\s*=\s*'([^']+)'/", $content, $m ) ) {
        return $m[1];
    }
    return '0';
}

/**
 * Consulta Packagist para obtener la ultima version estable de FPDF.
 * Cachea el resultado en un transient (7 dias).
 */
function mk20_check_fpdf_update() {
    $current = mk20_get_fpdf_version();
    $cache   = get_transient( 'mk20_fpdf_update_check' );

    if ( $cache !== false ) {
        return $cache;
    }

    $response = wp_remote_get( 'https://packagist.org/packages/fpdf-mirror/fpdf.json', array(
        'timeout' => 10,
    ) );

    if ( is_wp_error( $response ) || wp_remote_retrieve_response_code( $response ) !== 200 ) {
        return null;
    }

    $body = json_decode( wp_remote_retrieve_body( $response ), true );
    if ( ! is_array( $body ) || ! isset( $body['package']['versions'] ) ) {
        return null;
    }

    $versions = array_keys( $body['package']['versions'] );
    $stable   = array();
    foreach ( $versions as $v ) {
        if ( preg_match( '/^\d+\.\d+(\.\d+)?$/', $v ) ) {
            $stable[] = $v;
        }
    }

    if ( empty( $stable ) ) {
        return null;
    }

    usort( $stable, 'version_compare' );
    $latest = end( $stable );

    $current_normalized = $current;
    if ( substr_count( $current_normalized, '.' ) === 1 ) {
        $current_normalized .= '.0';
    }

    $result = array(
        'current' => $current,
        'latest'  => $latest,
        'update'  => version_compare( $latest, $current_normalized, '>' ),
    );

    set_transient( 'mk20_fpdf_update_check', $result, WEEK_IN_SECONDS );

    return $result;
}

/**
 * Aviso en admin si hay una nueva version de FPDF disponible.
 */
function mk20_fpdf_update_admin_notice() {
    if ( ! current_user_can( 'manage_options' ) ) {
        return;
    }

    $screen = get_current_screen();
    if ( $screen && ( $screen->id === 'toplevel_page_mk20-certificates' || $screen->id === 'certificates_page_mk20-certificates-settings' ) ) {
        $info = mk20_check_fpdf_update();
        if ( $info && $info['update'] ) {
            echo '<div class="notice notice-warning is-dismissible"><p>';
            printf(
                /* translators: 1: current FPDF version, 2: latest FPDF version, 3: changelog link. */
                esc_html__( 'MK20 Custom Certificates: La librería FPDF está desactualizada (v%1$s). Hay disponible v%2$s. Revisa los cambios en %3$s.', 'mk20-custom-certificates' ),
                esc_html( $info['current'] ),
                esc_html( $info['latest'] ),
                '<a href="http://www.fpdf.org/en/changelog.php" target="_blank" rel="noopener">changelog</a>'
            );
            echo '</p></div>';
        }
    }
}

require_once MK20_CERT_PATH . 'includes/helpers.php';
require_once MK20_CERT_PATH . 'includes/class-mk20-admin.php';
require_once MK20_CERT_PATH . 'includes/class-mk20-pdf-engine.php';
require_once MK20_CERT_PATH . 'includes/class-mk20-rest.php';

add_action( 'plugins_loaded', 'mk20_custom_certificates_init' );

register_activation_hook( __FILE__, 'mk20_activate_flush_rewrites' );
function mk20_activate_flush_rewrites() {
    mk20_create_cert_index_table();
    mk20_backfill_cert_index();

    add_rewrite_rule( '^verificar/([a-f0-9]{12,})/?$', 'index.php?mk20_verificar=$matches[1]', 'top' );
    add_rewrite_tag( '%mk20_verificar%', '([a-f0-9]{12,})' );
    flush_rewrite_rules();
}

function mk20_protect_cert_directory() {
    global $wp_filesystem;

    if ( ! function_exists( 'WP_Filesystem' ) ) {
        require_once ABSPATH . 'wp-admin/includes/file.php';
    }
    WP_Filesystem();

    if ( ! $wp_filesystem ) {
        return;
    }

    $upload_dir = wp_upload_dir();
    $cert_dir   = $upload_dir['basedir'] . '/mk20-certificates';
    $htaccess   = $cert_dir . '/.htaccess';

    if ( $wp_filesystem->exists( $htaccess ) ) {
        return;
    }

    if ( ! $wp_filesystem->exists( $cert_dir ) ) {
        wp_mkdir_p( $cert_dir );
    }

    if ( $wp_filesystem->exists( $cert_dir ) && $wp_filesystem->is_writable( $cert_dir ) ) {
        $wp_filesystem->put_contents( $htaccess, "Deny from all\n", FS_CHMOD_FILE );
    }
}

function mk20_custom_certificates_init() {
    mk20_ensure_cert_index_table();
    mk20_protect_cert_directory();

    if ( is_admin() ) {
        new MK20_Admin();
    }

    new MK20_REST();

    add_action( 'learndash_course_completed', 'mk20_handle_course_completion', 10, 1 );

    add_action( 'admin_post_mk20_download_cert', 'mk20_download_certificate' );
    add_action( 'admin_post_nopriv_mk20_download_cert', 'mk20_download_certificate' );

    add_action( 'admin_post_mk20_download_ext_cert', 'mk20_download_external_certificate' );
    add_action( 'admin_post_nopriv_mk20_download_ext_cert', 'mk20_download_external_certificate' );
    add_action( 'admin_post_mk20_reset_settings', 'mk20_reset_to_defaults' );
    add_action( 'admin_post_mk20_dismiss_rejected', 'mk20_dismiss_rejected_certificates' );

    add_action( 'admin_notices', 'mk20_fpdf_update_admin_notice' );

    add_action( 'bp_setup_nav', 'mk20_certificates_profile_tab', 100 );
}

/**
 * Limpia el buffer de salida antes de enviar archivos PDF.
 */
function mk20_clean_output_before_pdf() {
    while ( ob_get_level() ) {
        ob_end_clean();
    }
}

/**
 * Helper de debug que cumple con WPCS.
 */
function mk20_debug_log( $message ) {
    if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
        // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
        error_log( $message );
    }
}

function mk20_api_audit_log( $message ) {
    if ( empty( MK20_EXT_API_LOG ) ) {
        return;
    }
    $timestamp = current_time( 'mysql' );
    $line      = "[{$timestamp}] {$message}" . PHP_EOL;
    // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_file_put_contents
    file_put_contents( MK20_EXT_API_LOG, $line, FILE_APPEND | LOCK_EX );
}

function mk20_dismiss_rejected_certificates() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( esc_html__( 'No tienes permiso para realizar esta acción.', 'mk20-custom-certificates' ) );
    }
    if ( ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( sanitize_key( $_GET['_wpnonce'] ), 'mk20_dismiss_rejected' ) ) {
        wp_die( esc_html__( 'Enlace inválido o expirado.', 'mk20-custom-certificates' ) );
    }
    delete_option( 'mk20_rejected_certificates' );
    wp_safe_redirect( admin_url( 'options-general.php?page=mk20-certificates' ) );
    exit;
}

function mk20_handle_course_completion( $data ) {
    if ( ! isset( $data['user'] ) || ! isset( $data['course'] ) ) {
        return;
    }

    $user   = $data['user'];
    $course = $data['course'];

    $user_id   = $user->ID;
    $course_id = $course->ID;

    $student_name    = mk20_get_student_name( $user_id );
    $course_title    = mk20_clean_course_title( get_the_title( $course_id ) );
    $completion_date = date_i18n( 'd/m/Y' );

    $pdf_engine = new MK20_PDF_Engine();
    $pdf_path   = $pdf_engine->generate( $student_name, $course_title, $completion_date, $user_id, $course_id );

    if ( $pdf_path ) {
        update_user_meta( $user_id, '_mk20_cert_path_' . $course_id, $pdf_path );
        update_user_meta( $user_id, '_mk20_cert_date_' . $course_id, current_time( 'mysql' ) );

        $integrity_hash = $pdf_engine->get_last_integrity_hash();
        $timestamp      = $pdf_engine->get_last_timestamp();
        $verify_hash    = $pdf_engine->get_last_verify_hash();
        if ( $integrity_hash ) {
            update_user_meta( $user_id, '_mk20_cert_hash_' . $course_id, $integrity_hash );
            update_user_meta( $user_id, '_mk20_cert_ts_' . $course_id, $timestamp );
            update_user_meta( $user_id, '_mk20_cert_verify_' . $course_id, $verify_hash );
            mk20_index_certificate( $user_id, $course_id, $verify_hash );
        }

        $upload_dir = wp_upload_dir();
        $filename   = basename( $pdf_path );
        $cert_url   = $upload_dir['baseurl'] . '/mk20-certificates/' . $filename;
        update_user_meta( $user_id, '_mk20_cert_url_' . $course_id, $cert_url );

        mk20_register_certificate_attachment( $pdf_path, $user_id, $course_id, $course_title, $student_name );

        mk20_upload_certificate_to_external_api( $pdf_path, $user_id, $course_id, $course_title, $student_name, $completion_date );

        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
            error_log( "MK20 Custom Certificates: Certificado generado con éxito para el usuario $user_id y curso $course_id. Ubicación: $pdf_path" );
        }
    } else {
        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
            error_log( "MK20 Custom Certificates: Error al generar el certificado para el usuario $user_id y curso $course_id." );
        }
    }
}

/**
 * Registra el PDF como attachment en la librería multimedia de WordPress.
 */
function mk20_register_certificate_attachment( $pdf_path, $user_id, $course_id, $course_title, $student_name ) {
    if ( ! function_exists( 'wp_generate_attachment_metadata' ) ) {
        require_once ABSPATH . 'wp-admin/includes/image.php';
    }
    if ( ! function_exists( 'wp_read_video_metadata' ) ) {
        require_once ABSPATH . 'wp-admin/includes/media.php';
    }

    $existing_attachment_id = get_user_meta( $user_id, '_mk20_cert_attachment_id_' . $course_id, true );
    if ( $existing_attachment_id && get_post( $existing_attachment_id ) ) {
        return; // Ya está registrado
    }

    $filetype = wp_check_filetype( basename( $pdf_path ), null );

    $attachment = array(
        'guid'           => $pdf_path,
        'post_mime_type' => $filetype['type'] ?: 'application/pdf',
        'post_title'     => sprintf( 'Certificado: %s — %s', $student_name, mk20_clean_course_title( $course_title ) ),
        'post_content'   => '',
        'post_status'    => 'inherit',
        'post_author'    => $user_id,
    );

    $attach_id = wp_insert_attachment( $attachment, $pdf_path );
    if ( is_wp_error( $attach_id ) ) {
        return;
    }

    wp_generate_attachment_metadata( $attach_id, $pdf_path );
    update_post_meta( $attach_id, '_mk20_cert_user_id', $user_id );
    update_post_meta( $attach_id, '_mk20_cert_course_id', $course_id );

    update_user_meta( $user_id, '_mk20_cert_attachment_id_' . $course_id, $attach_id );
}

/**
 * Endpoint para restaurar los valores por defecto del plugin.
 */
function mk20_reset_to_defaults() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( esc_html__( 'No tienes permiso para realizar esta acción.', 'mk20-custom-certificates' ) );
    }

    if ( ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( sanitize_key( $_GET['_wpnonce'] ), 'mk20_reset_settings' ) ) {
        wp_die( esc_html__( 'Enlace inválido o expirado.', 'mk20-custom-certificates' ) );
    }

    delete_option( 'mk20_cert_settings' );
    set_transient( 'mk20_cert_reset_notice', __( 'Ajustes restaurados a los valores por defecto.', 'mk20-custom-certificates' ), 30 );
    wp_safe_redirect( admin_url( 'options-general.php?page=mk20-certificates' ) );
    exit;
}

/**
 * Endpoint de descarga segura del certificado.
 * URL: /wp-admin/admin-post.php?action=mk20_download_cert&course_id=XX
 */
function mk20_download_certificate() {
    $course_id = isset( $_GET['course_id'] ) ? intval( $_GET['course_id'] ) : 0;
    if ( ! $course_id ) {
        wp_die( esc_html__( 'Curso no especificado.', 'mk20-custom-certificates' ) );
    }

    if ( ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( sanitize_key( $_GET['_wpnonce'] ), 'mk20_download_cert_' . $course_id ) ) {
        wp_die( esc_html__( 'Enlace inválido o expirado.', 'mk20-custom-certificates' ) );
    }

    $user_id = get_current_user_id();
    if ( ! $user_id ) {
        wp_die( esc_html__( 'Debes iniciar sesión para descargar tu certificado.', 'mk20-custom-certificates' ) );
    }

    if ( current_user_can( 'manage_options' ) && isset( $_GET['user_id'] ) ) {
        $user_id = absint( $_GET['user_id'] );
    }

    $cert_path = get_user_meta( $user_id, '_mk20_cert_path_' . $course_id, true );
    if ( ! $cert_path || ! file_exists( $cert_path ) ) {
        wp_die( esc_html__( 'El certificado no está disponible. Completa el curso primero.', 'mk20-custom-certificates' ) );
    }

    $filename = basename( $cert_path );

    mk20_clean_output_before_pdf();

    global $wp_filesystem;
    if ( ! function_exists( 'WP_Filesystem' ) ) {
        require_once ABSPATH . 'wp-admin/includes/file.php';
    }
    WP_Filesystem();

    $contents = $wp_filesystem->get_contents( $cert_path );
    if ( false === $contents ) {
        wp_die( esc_html__( 'Error al leer el certificado.', 'mk20-custom-certificates' ) );
    }

    header( 'Content-Type: application/pdf' );
    header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
    header( 'Content-Length: ' . strlen( $contents ) );
    header( 'Pragma: no-cache' );

    // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
    echo $contents;
    exit;
}

/**
 * Endpoint de descarga segura del certificado externo.
 * URL: /wp-admin/admin-post.php?action=mk20_download_ext_cert&ext_cert=HASH
 */
function mk20_download_external_certificate() {
    $hash = isset( $_GET['ext_cert'] ) ? sanitize_key( $_GET['ext_cert'] ) : '';
    if ( empty( $hash ) ) {
        wp_die( esc_html__( 'Certificado no especificado.', 'mk20-custom-certificates' ) );
    }

    if ( ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( sanitize_key( $_GET['_wpnonce'] ), 'mk20_download_ext_cert_' . $hash ) ) {
        wp_die( esc_html__( 'Enlace inválido o expirado.', 'mk20-custom-certificates' ) );
    }

    $user_id = get_current_user_id();
    if ( ! $user_id ) {
        wp_die( esc_html__( 'Debes iniciar sesión para descargar tu certificado.', 'mk20-custom-certificates' ) );
    }

    if ( current_user_can( 'manage_options' ) && isset( $_GET['user_id'] ) ) {
        $user_id = absint( $_GET['user_id'] );
    }

    $cert_path = get_user_meta( $user_id, '_mk20_ext_cert_path_' . $hash, true );
    if ( ! $cert_path || ! file_exists( $cert_path ) ) {
        wp_die( esc_html__( 'El certificado no está disponible.', 'mk20-custom-certificates' ) );
    }

    $filename = basename( $cert_path );

    mk20_clean_output_before_pdf();

    global $wp_filesystem;
    if ( ! function_exists( 'WP_Filesystem' ) ) {
        require_once ABSPATH . 'wp-admin/includes/file.php';
    }
    WP_Filesystem();

    $contents = $wp_filesystem->get_contents( $cert_path );
    if ( false === $contents ) {
        wp_die( esc_html__( 'Error al leer el certificado.', 'mk20-custom-certificates' ) );
    }

    header( 'Content-Type: application/pdf' );
    header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
    header( 'Content-Length: ' . strlen( $contents ) );
    header( 'Pragma: no-cache' );

    // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
    echo $contents;
    exit;
}

/**
 * Agrega "Certificados" como sub-pestaña dentro de "Courses" en el perfil de BuddyBoss.
 */
function mk20_certificates_profile_tab() {
    if ( ! function_exists( 'bp_core_new_subnav_item' ) ) {
        return;
    }

    // Eliminar la pestaña nativa de BuddyBoss para reemplazarla con la nuestra
    if ( function_exists( 'bp_core_remove_subnav_item' ) ) {
        bp_core_remove_subnav_item( 'courses', 'certificates' );
    }

    $parent_url = trailingslashit( bp_displayed_user_domain() . 'courses' );

    bp_core_new_subnav_item( array(
        'name'            => __( 'Mis Certificados', 'mk20-custom-certificates' ),
        'slug'            => 'certificates',
        'parent_slug'     => 'courses',
        'parent_url'      => $parent_url,
        'screen_function' => 'mk20_certificates_screen',
        'position'        => 76,
        'user_has_access' => bp_is_my_profile() || current_user_can( 'manage_options' ),
    ) );
}

function mk20_certificates_screen() {
    add_action( 'bp_template_content', 'mk20_certificates_screen_content' );
    // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
    bp_core_load_template( apply_filters( 'bp_core_template_plugin', 'members/single/plugins' ) );
}

function mk20_certificates_screen_content() {
    $displayed_user_id = bp_displayed_user_id();
    $is_own_profile    = bp_is_my_profile();

    // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Flag de solo lectura tras redireccion post-eliminacion; el nonce se verifico en la accion de borrado.
    if ( isset( $_GET['cert_deleted'] ) && absint( $_GET['cert_deleted'] ) === 1 ) {
        echo '<div class="bp-feedback success"><span class="bp-icon" aria-hidden="true"></span><p>';
        esc_html_e( 'Certificado eliminado correctamente.', 'mk20-custom-certificates' );
        echo '</p></div>';
    }

    if ( ! $is_own_profile && ! current_user_can( 'manage_options' ) ) {
        echo '<p>' . esc_html__( 'Este usuario no tiene certificados visibles.', 'mk20-custom-certificates' ) . '</p>';
        return;
    }

    if ( $is_own_profile && ! empty( MK20_EXT_API_URL ) ) {
        mk20_sync_external_certificates( $displayed_user_id );
    }

    $cache_key = 'mk20_all_cert_paths_' . $displayed_user_id;
    $results   = wp_cache_get( $cache_key, 'mk20_certificates' );

    if ( false === $results ) {
        $results   = array();
        $user_meta = get_user_meta( $displayed_user_id );

        foreach ( $user_meta as $meta_key => $meta_values ) {
            if ( 0 === strpos( $meta_key, '_mk20_cert_path_' ) || 0 === strpos( $meta_key, '_mk20_ext_cert_path_' ) ) {
                foreach ( (array) $meta_values as $meta_value ) {
                    $results[] = (object) array(
                        'user_id'   => $displayed_user_id,
                        'mk20_key'  => $meta_key,
                        'mk20_val'  => $meta_value,
                    );
                }
            }
        }

        usort( $results, static function ( $a, $b ) {
            return strcmp( $a->mk20_key, $b->mk20_key );
        } );

        wp_cache_set( $cache_key, $results, 'mk20_certificates', 3600 );
    }

    if ( empty( $results ) ) {
        echo '<div class="bp-feedback info"><span class="bp-icon" aria-hidden="true"></span><p>';
        esc_html_e( 'Aún no tienes certificados. Completa un curso para obtener tu primer certificado.', 'mk20-custom-certificates' );
        echo '</p></div>';
        return;
    }

    $native_download_url = admin_url( 'admin-post.php?action=mk20_download_cert' );
    $ext_download_url    = admin_url( 'admin-post.php?action=mk20_download_ext_cert' );

    echo '<table class="mk20-certificates-table" style="width:100%; border-collapse: collapse;">';
    echo '<thead><tr style="text-align:left; border-bottom:2px solid #e2e8f0;">
            <th style="padding:12px 8px;">' . esc_html__( 'Curso', 'mk20-custom-certificates' ) . '</th>
            <th style="padding:12px 8px;">' . esc_html__( 'Fecha de emisión', 'mk20-custom-certificates' ) . '</th>
            <th style="padding:12px 8px;">' . esc_html__( 'Descargar', 'mk20-custom-certificates' ) . '</th>
          </tr></thead><tbody>';

    foreach ( $results as $row ) {
        $is_external = strpos( $row->mk20_key, '_mk20_ext_cert_path_' ) === 0;

        if ( $is_external ) {
            $hash          = str_replace( '_mk20_ext_cert_path_', '', $row->mk20_key );
            $course_title  = get_user_meta( $displayed_user_id, '_mk20_ext_course_title_' . $hash, true );
            $cert_date     = get_user_meta( $displayed_user_id, '_mk20_ext_cert_date_' . $hash, true );
            $cert_date_fmt = $cert_date ? date_i18n( 'd/m/Y', strtotime( $cert_date ) ) : '—';
            $download_url  = wp_nonce_url( add_query_arg( 'ext_cert', $hash, $ext_download_url ), 'mk20_download_ext_cert_' . $hash );
        } else {
            $course_id     = intval( str_replace( '_mk20_cert_path_', '', $row->mk20_key ) );
            if ( ! $course_id ) {
                continue;
            }
            $course_title  = mk20_clean_course_title( get_the_title( $course_id ) );
            $cert_date     = get_user_meta( $displayed_user_id, '_mk20_cert_date_' . $course_id, true );
            $cert_date_fmt = $cert_date ? date_i18n( 'd/m/Y', strtotime( $cert_date ) ) : '—';
            $download_url  = wp_nonce_url( add_query_arg( 'course_id', $course_id, $native_download_url ), 'mk20_download_cert_' . $course_id );
        }

        echo '<tr style="border-bottom:1px solid #edf2f0;">';
        echo '<td style="padding:10px 8px;">' . esc_html( $course_title ) . '</td>';
        echo '<td style="padding:10px 8px;">' . esc_html( $cert_date_fmt ) . '</td>';
        echo '<td style="padding:10px 8px;">
                <a href="' . esc_url( $download_url ) . '"
                   class="button bp-secondary-button"
                   style="text-decoration:none; padding:6px 14px; border-radius:4px; background:#10b981; color:#fff; display:inline-block;">
                   ' . esc_html__( 'Descargar PDF', 'mk20-custom-certificates' ) . '
                </a>
              </td>';
        echo '</tr>';
    }

    echo '</tbody></table>';

    echo '<style>
        .mk20-certificates-table td, .mk20-certificates-table th {
            font-size: 14px;
        }
        .mk20-certificates-table .button:hover {
            background: #059669 !important;
            color: #fff !important;
        }
    </style>';
}

add_action( 'admin_post_mk20_delete_cert', 'mk20_handle_delete_cert' );
add_action( 'admin_post_mk20_delete_ext_cert', 'mk20_handle_delete_ext_cert' );

function mk20_handle_delete_cert() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( esc_html__( 'No tienes permisos para realizar esta acción.', 'mk20-custom-certificates' ) );
    }

    $course_id = isset( $_GET['course_id'] ) ? absint( $_GET['course_id'] ) : 0;
    if ( ! $course_id ) {
        wp_die( esc_html__( 'ID de curso inválido.', 'mk20-custom-certificates' ) );
    }

    $nonce = isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : '';
    if ( ! wp_verify_nonce( $nonce, 'mk20_delete_cert_' . $course_id ) ) {
        wp_die( esc_html__( 'Nonce inválido.', 'mk20-custom-certificates' ) );
    }

    $user_id = isset( $_GET['user_id'] ) ? absint( $_GET['user_id'] ) : 0;
    if ( ! $user_id ) {
        wp_die( esc_html__( 'Usuario no especificado.', 'mk20-custom-certificates' ) );
    }

    $attachment_id = get_user_meta( $user_id, '_mk20_cert_attachment_id_' . $course_id, true );
    $file_path     = get_user_meta( $user_id, '_mk20_cert_path_' . $course_id, true );

    if ( $file_path && file_exists( $file_path ) ) {
        wp_delete_file( $file_path );
    }

    if ( $attachment_id && get_post( $attachment_id ) ) {
        wp_delete_attachment( $attachment_id, true );
    }

    $meta_keys = [ '_mk20_cert_path_', '_mk20_cert_date_', '_mk20_cert_url_', '_mk20_cert_hash_', '_mk20_cert_ts_', '_mk20_cert_verify_', '_mk20_cert_attachment_id_', '_mk20_cert_uploaded_id_' ];
    foreach ( $meta_keys as $prefix ) {
        delete_user_meta( $user_id, $prefix . $course_id );
    }

    mk20_remove_certificate_index( $user_id, $course_id );

    set_transient( 'mk20_cert_deleted_notice', __( 'Certificado eliminado correctamente.', 'mk20-custom-certificates' ), 30 );

    $redirect = remove_query_arg( 'cert_deleted', wp_get_referer() );
    wp_safe_redirect( $redirect );
    exit;
}

function mk20_handle_delete_ext_cert() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( esc_html__( 'No tienes permisos para realizar esta acción.', 'mk20-custom-certificates' ) );
    }

    $hash  = isset( $_GET['ext_cert'] ) ? sanitize_text_field( wp_unslash( $_GET['ext_cert'] ) ) : '';
    if ( empty( $hash ) || strlen( $hash ) !== 32 ) {
        wp_die( esc_html__( 'Hash de certificado inválido.', 'mk20-custom-certificates' ) );
    }

    $nonce = isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : '';
    if ( ! wp_verify_nonce( $nonce, 'mk20_delete_ext_cert_' . $hash ) ) {
        wp_die( esc_html__( 'Nonce inválido.', 'mk20-custom-certificates' ) );
    }

    $user_id = isset( $_GET['user_id'] ) ? absint( $_GET['user_id'] ) : 0;
    if ( ! $user_id ) {
        $user_id = bp_displayed_user_id();
    }
    if ( ! $user_id ) {
        wp_die( esc_html__( 'Usuario no especificado.', 'mk20-custom-certificates' ) );
    }

    $file_path = get_user_meta( $user_id, '_mk20_ext_cert_path_' . $hash, true );
    if ( $file_path && file_exists( $file_path ) ) {
        wp_delete_file( $file_path );
    }

    $attachment_id = get_user_meta( $user_id, '_mk20_ext_cert_attachment_id_' . $hash, true );
    if ( $attachment_id ) {
        wp_delete_attachment( $attachment_id, true );
    }

    $meta_keys = [ '_mk20_ext_cert_path_', '_mk20_ext_cert_date_', '_mk20_ext_cert_url_', '_mk20_ext_course_title_', '_mk20_ext_cert_hash_', '_mk20_ext_cert_ts_', '_mk20_ext_cert_attachment_id_' ];
    foreach ( $meta_keys as $prefix ) {
        delete_user_meta( $user_id, $prefix . $hash );
    }

    mk20_api_audit_log( "DELETE_CERT user_id={$user_id} hash={$hash} tipo=externa" );

    $redirect = add_query_arg( 'cert_deleted', '1', wp_get_referer() );
    wp_safe_redirect( $redirect );
    exit;
}
