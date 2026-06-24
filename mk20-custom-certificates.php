<?php
/**
 * Plugin Name: MK20 Custom Certificates
 * Plugin URI:  https://github.com/google-deepmind
 * Description: Genera certificados PDF de dos caras (anverso y reverso) personalizados mediante FPDF al completar cursos de LearnDash.
 * Version:     1.1.0
 * Author:      Merka2.0
 * Author URI:  https://merka20.com
 * License:     GPL2+
 * Text Domain: mk20-custom-certificates
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'MK20_CERT_PATH', plugin_dir_path( __FILE__ ) );
define( 'MK20_CERT_URL', plugin_dir_url( __FILE__ ) );

if ( ! defined( 'MK20_EXT_API_URL' ) ) {
    define( 'MK20_EXT_API_URL', '' );
}
if ( ! defined( 'MK20_EXT_API_TOKEN' ) ) {
    define( 'MK20_EXT_API_TOKEN', '' );
}
if ( ! defined( 'MK20_EXT_API_LOG' ) ) {
    define( 'MK20_EXT_API_LOG', '' );
}

require_once MK20_CERT_PATH . 'includes/helpers.php';
require_once MK20_CERT_PATH . 'includes/class-mk20-admin.php';
require_once MK20_CERT_PATH . 'includes/class-mk20-pdf-engine.php';

add_action( 'plugins_loaded', 'mk20_custom_certificates_init' );

function mk20_protect_cert_directory() {
    $upload_dir = wp_upload_dir();
    $cert_dir   = $upload_dir['basedir'] . '/mk20-certificates';
    $htaccess   = $cert_dir . '/.htaccess';

    if ( file_exists( $htaccess ) ) {
        return;
    }

    if ( ! file_exists( $cert_dir ) ) {
        wp_mkdir_p( $cert_dir );
    }

    if ( file_exists( $cert_dir ) && is_writable( $cert_dir ) ) {
        // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_file_put_contents
        file_put_contents( $htaccess, "Deny from all\n" );
    }
}

function mk20_custom_certificates_init() {
    mk20_protect_cert_directory();

    if ( is_admin() ) {
        new MK20_Admin();
    }

    add_action( 'learndash_course_completed', 'mk20_handle_course_completion', 10, 1 );

    add_action( 'admin_post_mk20_download_cert', 'mk20_download_certificate' );
    add_action( 'admin_post_nopriv_mk20_download_cert', 'mk20_download_certificate' );

    add_action( 'admin_post_mk20_delete_cert', 'mk20_delete_certificate' );
    add_action( 'admin_post_mk20_download_ext_cert', 'mk20_download_external_certificate' );
    add_action( 'admin_post_nopriv_mk20_download_ext_cert', 'mk20_download_external_certificate' );
    add_action( 'admin_post_mk20_reset_settings', 'mk20_reset_to_defaults' );
    add_action( 'admin_post_mk20_dismiss_rejected', 'mk20_dismiss_rejected_certificates' );

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
        wp_die( 'No tienes permiso para realizar esta accion.' );
    }
    if ( ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( sanitize_key( $_GET['_wpnonce'] ), 'mk20_dismiss_rejected' ) ) {
        wp_die( 'Enlace invalido o expirado.' );
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

        $upload_dir = wp_upload_dir();
        $filename   = basename( $pdf_path );
        $cert_url   = $upload_dir['baseurl'] . '/mk20-certificates/' . $filename;
        update_user_meta( $user_id, '_mk20_cert_url_' . $course_id, $cert_url );

        mk20_register_certificate_attachment( $pdf_path, $user_id, $course_id, $course_title, $student_name );

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
        wp_die( 'No tienes permiso para realizar esta acción.' );
    }

    if ( ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( sanitize_key( $_GET['_wpnonce'] ), 'mk20_reset_settings' ) ) {
        wp_die( 'Enlace inválido o expirado.' );
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
        wp_die( 'Curso no especificado.' );
    }

    if ( ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( sanitize_key( $_GET['_wpnonce'] ), 'mk20_download_cert_' . $course_id ) ) {
        wp_die( 'Enlace inválido o expirado.' );
    }

    $user_id = get_current_user_id();
    if ( ! $user_id ) {
        wp_die( 'Debes iniciar sesión para descargar tu certificado.' );
    }

    $cert_path = get_user_meta( $user_id, '_mk20_cert_path_' . $course_id, true );
    if ( ! $cert_path || ! file_exists( $cert_path ) ) {
        wp_die( 'El certificado no está disponible. Completa el curso primero.' );
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
        wp_die( 'Error al leer el certificado.' );
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
        wp_die( 'Certificado no especificado.' );
    }

    if ( ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( sanitize_key( $_GET['_wpnonce'] ), 'mk20_download_ext_cert_' . $hash ) ) {
        wp_die( 'Enlace inválido o expirado.' );
    }

    $user_id = get_current_user_id();
    if ( ! $user_id ) {
        wp_die( 'Debes iniciar sesión para descargar tu certificado.' );
    }

    $cert_path = get_user_meta( $user_id, '_mk20_ext_cert_path_' . $hash, true );
    if ( ! $cert_path || ! file_exists( $cert_path ) ) {
        wp_die( 'El certificado no está disponible.' );
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
        wp_die( 'Error al leer el certificado.' );
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
 * Endpoint para que administradores eliminen el certificado de un usuario.
 * URL: /wp-admin/admin-post.php?action=mk20_delete_cert&user_id=XX&course_id=XX
 */
function mk20_delete_certificate() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( 'No tienes permiso para realizar esta acción.' );
    }

    $user_id   = isset( $_GET['user_id'] ) ? intval( $_GET['user_id'] ) : 0;
    $course_id = isset( $_GET['course_id'] ) ? intval( $_GET['course_id'] ) : 0;

    if ( ! $user_id || ! $course_id ) {
        wp_die( 'Parámetros inválidos.' );
    }

    if ( ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( sanitize_key( $_GET['_wpnonce'] ), 'mk20_delete_cert_' . $user_id . '_' . $course_id ) ) {
        wp_die( 'Enlace inválido o expirado.' );
    }

    $cert_path   = get_user_meta( $user_id, '_mk20_cert_path_' . $course_id, true );
    $attach_id   = get_user_meta( $user_id, '_mk20_cert_attachment_id_' . $course_id, true );

    // Eliminar el archivo PDF
    if ( $cert_path && file_exists( $cert_path ) ) {
        wp_delete_file( $cert_path );
    }

    // Eliminar el attachment de la biblioteca multimedia
    if ( $attach_id && get_post( $attach_id ) ) {
        wp_delete_attachment( $attach_id, true );
    }

    // Limpiar meta del usuario
    delete_user_meta( $user_id, '_mk20_cert_path_' . $course_id );
    delete_user_meta( $user_id, '_mk20_cert_url_' . $course_id );
    delete_user_meta( $user_id, '_mk20_cert_date_' . $course_id );
    delete_user_meta( $user_id, '_mk20_cert_attachment_id_' . $course_id );

    set_transient( 'mk20_cert_deleted_notice', __( 'Certificado eliminado correctamente.', 'mk20-custom-certificates' ), 30 );
    wp_safe_redirect( remove_query_arg( 'deleted', wp_get_referer() ) );
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
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
        $results = $wpdb->get_results( $wpdb->prepare(
            "SELECT meta_key, meta_value FROM {$wpdb->usermeta}
             WHERE user_id = %d AND (meta_key LIKE %s OR meta_key LIKE %s)
             ORDER BY meta_key ASC",
            $displayed_user_id,
            '_mk20_cert_path_%',
            '_mk20_ext_cert_path_%'
        ) );

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
    $is_admin            = current_user_can( 'manage_options' );
    $current_user_is_owner = bp_is_my_profile();

    echo '<table class="mk20-certificates-table" style="width:100%; border-collapse: collapse;">';
    echo '<thead><tr style="text-align:left; border-bottom:2px solid #e2e8f0;">
            <th style="padding:12px 8px;">' . esc_html__( 'Curso', 'mk20-custom-certificates' ) . '</th>
            <th style="padding:12px 8px;">' . esc_html__( 'Fecha de emisión', 'mk20-custom-certificates' ) . '</th>
            <th style="padding:12px 8px;">' . esc_html__( 'Descargar', 'mk20-custom-certificates' ) . '</th>';
    if ( $is_admin ) {
        echo '<th style="padding:12px 8px;">' . esc_html__( 'Acciones', 'mk20-custom-certificates' ) . '</th>';
    }
    echo '</tr></thead><tbody>';

    foreach ( $results as $row ) {
        $is_external = strpos( $row->meta_key, '_mk20_ext_cert_path_' ) === 0;

        if ( $is_external ) {
            $hash          = str_replace( '_mk20_ext_cert_path_', '', $row->meta_key );
            $course_title  = get_user_meta( $displayed_user_id, '_mk20_ext_course_title_' . $hash, true );
            $cert_date     = get_user_meta( $displayed_user_id, '_mk20_ext_cert_date_' . $hash, true );
            $cert_date_fmt = $cert_date ? date_i18n( 'd/m/Y', strtotime( $cert_date ) ) : '—';
            $download_url  = wp_nonce_url( add_query_arg( 'ext_cert', $hash, $ext_download_url ), 'mk20_download_ext_cert_' . $hash );
        } else {
            $course_id     = intval( str_replace( '_mk20_cert_path_', '', $row->meta_key ) );
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
        if ( $is_admin ) {
            echo '<td style="padding:10px 8px;">
                    <a href="#" class="button btn-delete" style="text-decoration:none; padding:6px 14px; border-radius:4px; background:#ef4444; color:#fff; display:inline-block; opacity:0.5; cursor:not-allowed;">
                       ' . esc_html__( 'Eliminar', 'mk20-custom-certificates' ) . '
                    </a>
                  </td>';
        }
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
        .mk20-certificates-table .btn-delete:hover {
            background: #dc2626 !important;
            color: #fff !important;
        }
    </style>';
}
