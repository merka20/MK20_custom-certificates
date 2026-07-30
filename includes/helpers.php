<?php
/**
 * Funciones auxiliares para MK20 Custom Certificates
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly.
}

/**
 * Obtiene el nombre completo del estudiante en base a su ID de usuario.
 * Prioriza "Nombre + Apellidos" de los metas del perfil. Si están vacíos, usa su Display Name.
 *
 * @param int $user_id ID del usuario.
 * @return string Nombre completo del estudiante.
 */
function mk20_get_student_name( $user_id ) {
    $first_name = get_user_meta( $user_id, 'first_name', true );
    $last_name  = get_user_meta( $user_id, 'last_name', true );
    
    $full_name = trim( "$first_name $last_name" );
    
    if ( empty( $full_name ) ) {
        $user = get_userdata( $user_id );
        $full_name = $user ? $user->display_name : '';
    }
    
    // Permitir a otros plugins o temas alterar el nombre a imprimir
    return apply_filters( 'mk20_cert_student_name', $full_name, $user_id );
}

/**
 * Obtiene un campo personalizado del perfil de BuddyBoss (xprofile) o un user meta de respaldo.
 *
 * @param int    $user_id   ID del usuario.
 * @param string $field_name Nombre del campo en BuddyBoss (ej: 'DNI', 'Empresa').
 * @param string $fallback   Valor de respaldo si no existe o está vacío.
 * @return string Valor del campo o el fallback.
 */
function mk20_get_buddyboss_field( $user_id, $field_name, $fallback = '' ) {
    if ( empty( $user_id ) ) {
        return $fallback;
    }

    $value = '';

    // Intentar BuddyBoss / BuddyPress xprofile data
    if ( function_exists( 'xprofile_get_field_data' ) ) {
        $value = xprofile_get_field_data( $field_name, $user_id );
    } elseif ( function_exists( 'bp_get_profile_field_data' ) ) {
        $value = bp_get_profile_field_data( array(
            'field'   => $field_name,
            'user_id' => $user_id,
        ) );
    }

    if ( ! empty( $value ) ) {
        return trim( $value );
    }

    // Como segundo recurso, buscar en los user meta convirtiendo el nombre del campo a slug (ej. "dni_nie" o "empresa")
    $meta_key = strtolower( preg_replace( '/[^a-zA-Z0-9_]/', '_', $field_name ) );
    $meta_key = preg_replace( '/_+/', '_', $meta_key );
    $meta_key = trim( $meta_key, '_' );
    
    if ( ! empty( $meta_key ) ) {
        $meta_val = get_user_meta( $user_id, $meta_key, true );
        if ( ! empty( $meta_val ) ) {
            return trim( $meta_val );
        }
    }

    return $fallback;
}

/**
 * Limpia el título del curso eliminando el prefijo antes de "|".
 * Ej: "2026-06 | Píldora 5" → "Píldora 5"
 */
function mk20_clean_course_title( $title ) {
    $pos = strpos( $title, '|' );
    if ( $pos !== false ) {
        $title = trim( substr( $title, $pos + 1 ) );
    }
    return $title;
}

function mk20_get_course_meta( $course_id, $meta_key, $fallback = '' ) {
    if ( empty( $course_id ) ) {
        return $fallback;
    }
    
    $value = get_post_meta( $course_id, $meta_key, true );
    return ! empty( $value ) ? trim( $value ) : $fallback;
}

/**
 * Devuelve los valores por defecto de todos los ajustes del plugin.
 * Sirve como fuente única de verdad para los valores predeterminados.
 */
function mk20_get_default_settings() {
    return [
        // Rutas de las imágenes (vacío = usar plantillas del plugin)
        'front_image' => '',
        'back_image'  => '',

        // Mapeo de BuddyBoss
        'bb_field_dni'     => 'DNI/NIE',
        'bb_field_company' => 'Empresa',
        'bb_field_cif'     => 'CIF',

        // Fallbacks
        'fallback_dni'             => '44123456X',
        'fallback_company'         => 'RESIDENCIA DE ANCIANOS VIRGEN DE LA CARIDAD',
        'fallback_cif'             => 'G31114141',
        'fallback_course_code'     => '26.3',
        'fallback_course_duration' => '3 horas',
        'fallback_course_modality' => 'Online',
        'fallback_course_start_date' => '26/02/2026',
        'fallback_course_end_date'   => '26/02/2026',
        'fallback_course_contents'   => 'Contenidos del curso',

        // 1. Nombre + DNI (Anverso)
        'name_x'      => 148.5,
        'name_y'      => 54.0,
        'name_size'   => 13,
        'name_color'  => '#1d4ed8',
        'name_center' => 1,

        // 2. Empresa + CIF (Anverso)
        'company_line_x'      => 148.5,
        'company_line_y'      => 78.0,
        'company_line_size'   => 13,
        'company_line_color'  => '#1d4ed8',
        'company_line_center' => 1,

        // 3. Título del curso (Anverso)
        'front_course_x'      => 148.5,
        'front_course_y'      => 102.0,
        'front_course_size'   => 13,
        'front_course_color'  => '#1d4ed8',
        'front_course_center' => 1,

        // 4. Código y Fechas (Anverso)
        'details_y'     => 115.5,
        'code_x'        => 123.0,
        'start_date_x'  => 176.0,
        'end_date_x'    => 208.0,
        'details_size'  => 11,
        'details_color' => '#1d4ed8',

        // 5. Duración y Modalidad (Anverso)
        'duration_y'     => 125.0,
        'duration_x'     => 130.0,
        'modality_x'     => 205.0,
        'duration_size'  => 11,
        'duration_color' => '#1d4ed8',

        // 6. Fecha de expedición (Anverso)
        'date_x'      => 147.0,
        'date_y'      => 168.0,
        'date_size'   => 8,
        'date_color'  => '#2c2c2c',
        'date_center' => 0,

        // 7. Contenidos del curso (Reverso)
        'contents_x'      => 60.0,
        'contents_y'      => 78.0,
        'contents_size'   => 13,
        'contents_color'  => '#383838',
        'contents_center' => 0,

        // 8. Conservación de datos al desinstalar
        'keep_data_on_uninstall' => 1,
    ];
}

/**
 * Importa un certificado PDF desde una fuente externa y lo registra
 * con el mismo formato que los certificados nativos del plugin.
 *
 * @param string $pdf_content  Contenido binario del PDF.
 * @param int    $user_id      ID del usuario en WordPress.
 * @param string $external_id  Identificador único del certificado en el sistema externo.
 * @param string $course_title Título del curso presencial.
 * @param string $issue_date   Fecha de emisión (formato MySQL o d/m/Y).
 * @return string|false Ruta absoluta del PDF guardado, o false si falla.
 */
function mk20_import_external_certificate( $pdf_content, $user_id, $external_id, $course_title, $issue_date = '' ) {
    if ( empty( $pdf_content ) || empty( $user_id ) || empty( $external_id ) ) {
        mk20_api_audit_log( "IMPORT_ERROR user_id={$user_id} external_id={$external_id} motivo=parametros_vacios" );
        return false;
    }

    if ( substr( $pdf_content, 0, 4 ) !== '%PDF' ) {
        mk20_api_audit_log( "IMPORT_ERROR user_id={$user_id} external_id={$external_id} motivo=no_es_pdf" );
        mk20_store_rejected_pdf( $user_id, $external_id, $course_title, 'no_es_pdf', '' );
        return false;
    }

    $hash = md5( $external_id );

    $existing_path = get_user_meta( $user_id, '_mk20_ext_cert_path_' . $hash, true );
    if ( $existing_path && file_exists( $existing_path ) ) {
        return $existing_path;
    }

    $upload_dir = wp_upload_dir();
    $cert_dir   = $upload_dir['basedir'] . '/mk20-certificates';
    if ( ! file_exists( $cert_dir ) ) {
        wp_mkdir_p( $cert_dir );
    }

    $student_name = mk20_get_student_name( $user_id );
    $slug_name    = mb_substr( sanitize_title( $student_name ), 0, 30 );
    $slug_course  = mb_substr( sanitize_title( $course_title ), 0, 40 );
    $slug_date    = sanitize_title( date_i18n( 'd-m-Y' ) );
    $filename     = sprintf( 'certificado_%s_%s_%s.pdf', $slug_course, $slug_name, $slug_date );
    $filepath     = $cert_dir . '/' . $filename;

    $bytes = file_put_contents( $filepath, $pdf_content );
    if ( false === $bytes ) {
        mk20_api_audit_log( "IMPORT_ERROR user_id={$user_id} external_id={$external_id} motivo=error_escritura" );
        return false;
    }

    if ( empty( $issue_date ) ) {
        $issue_date = current_time( 'mysql' );
    }

    $integrity_hash = hash_file( 'sha256', $filepath );
    if ( ! $integrity_hash ) {
        $integrity_hash = '';
    }

    update_user_meta( $user_id, '_mk20_ext_cert_path_' . $hash, $filepath );
    update_user_meta( $user_id, '_mk20_ext_cert_date_' . $hash, $issue_date );
    update_user_meta( $user_id, '_mk20_ext_cert_url_' . $hash, $upload_dir['baseurl'] . '/mk20-certificates/' . $filename );
    update_user_meta( $user_id, '_mk20_ext_course_title_' . $hash, $course_title );
    update_user_meta( $user_id, '_mk20_ext_cert_hash_' . $hash, $integrity_hash );
    update_user_meta( $user_id, '_mk20_ext_cert_ts_' . $hash, current_time( 'mysql' ) );

    $attach_id = mk20_register_external_certificate_attachment( $filepath, $user_id, $hash, $course_title, $student_name );
    if ( $attach_id ) {
        update_user_meta( $user_id, '_mk20_ext_cert_attachment_id_' . $hash, $attach_id );
    }

    return $filepath;
}

/**
 * Registra un certificado externo como attachment en la librería multimedia.
 */
function mk20_register_external_certificate_attachment( $pdf_path, $user_id, $hash, $course_title, $student_name ) {
    if ( ! function_exists( 'wp_generate_attachment_metadata' ) ) {
        require_once ABSPATH . 'wp-admin/includes/image.php';
    }
    if ( ! function_exists( 'wp_read_video_metadata' ) ) {
        require_once ABSPATH . 'wp-admin/includes/media.php';
    }

    $existing_attachment_id = get_user_meta( $user_id, '_mk20_ext_cert_attachment_id_' . $hash, true );
    if ( $existing_attachment_id && get_post( $existing_attachment_id ) ) {
        return $existing_attachment_id;
    }

    $filetype = wp_check_filetype( basename( $pdf_path ), null );

    $attachment = array(
        'guid'           => $pdf_path,
        'post_mime_type' => $filetype['type'] ?: 'application/pdf',
        'post_title'     => sprintf( 'Certificado externo: %s — %s', $student_name, $course_title ),
        'post_content'   => '',
        'post_status'    => 'inherit',
        'post_author'    => $user_id,
    );

    $attach_id = wp_insert_attachment( $attachment, $pdf_path );
    if ( is_wp_error( $attach_id ) ) {
        return false;
    }

    wp_generate_attachment_metadata( $attach_id, $pdf_path );
    update_post_meta( $attach_id, '_mk20_ext_cert_hash', $hash );
    update_post_meta( $attach_id, '_mk20_cert_user_id', $user_id );

    return $attach_id;
}

/**
 * Sincroniza los certificados externos del usuario consultando la API externa.
 * Usa el DNI/NIE del perfil de BuddyBoss como identificador.
 * Almacena un transient de 6 horas para no repetir la consulta en cada carga.
 *
 * @param int $user_id ID del usuario en WordPress.
 */
function mk20_store_rejected_pdf( $user_id, $external_id, $course_title, $reason, $extra = '' ) {
    $rejected = get_option( 'mk20_rejected_certificates', array() );
    $hash     = md5( $external_id );

    if ( isset( $rejected[ $hash ] ) ) {
        return;
    }

    $rejected[ $hash ] = array(
        'user_id'      => $user_id,
        'external_id'  => $external_id,
        'course_title' => $course_title,
        'reason'       => $reason,
        'extra'        => $extra,
        'date'         => current_time( 'mysql' ),
    );

    update_option( 'mk20_rejected_certificates', $rejected, false );
}

function mk20_sync_external_certificates( $user_id ) {
    if ( empty( MK20_EXT_API_URL ) ) {
        return;
    }

    $transient_key = 'mk20_ext_sync_' . $user_id;
    if ( get_transient( $transient_key ) ) {
        return;
    }

    mk20_api_audit_log( "INICIO user_id={$user_id}" );

    $options = wp_parse_args( get_option( 'mk20_cert_settings', array() ), mk20_get_default_settings() );
    $dni     = mk20_get_buddyboss_field( $user_id, $options['bb_field_dni'], '' );

    if ( empty( $dni ) ) {
        mk20_api_audit_log( "SALT user_id={$user_id} motivo=sin_dni" );
        return;
    }

    $api_url = add_query_arg( 'dni', urlencode( $dni ), MK20_EXT_API_URL );

    $headers = array(
        'Accept' => 'application/json',
    );
    if ( ! empty( MK20_EXT_API_TOKEN ) ) {
        $headers['Authorization'] = 'Bearer ' . MK20_EXT_API_TOKEN;
    }

    $response = wp_remote_get( $api_url, array(
        'timeout' => 30,
        'headers' => $headers,
    ) );

    if ( is_wp_error( $response ) || wp_remote_retrieve_response_code( $response ) !== 200 ) {
        $error_msg = is_wp_error( $response ) ? $response->get_error_message() : 'HTTP ' . wp_remote_retrieve_response_code( $response );
        set_transient( $transient_key, 'error', HOUR_IN_SECONDS );
        mk20_api_audit_log( "ERROR user_id={$user_id} dni={$dni} motivo={$error_msg}" );
        return;
    }

    $body = wp_remote_retrieve_body( $response );
    $data = json_decode( $body, true );

    if ( ! is_array( $data ) || empty( $data['certificates'] ) ) {
        set_transient( $transient_key, 'empty', 6 * HOUR_IN_SECONDS );
        mk20_api_audit_log( "VACIO user_id={$user_id} dni={$dni}" );
        return;
    }

    $imported = 0;
    foreach ( $data['certificates'] as $cert ) {
        $external_id  = isset( $cert['id'] ) ? sanitize_text_field( $cert['id'] ) : '';
        $course_title = isset( $cert['title'] ) ? sanitize_text_field( $cert['title'] ) : '';
        $issue_date   = isset( $cert['date'] ) ? sanitize_text_field( $cert['date'] ) : '';
        $pdf_url      = isset( $cert['pdf_url'] ) ? esc_url_raw( $cert['pdf_url'] ) : '';

        if ( empty( $external_id ) || empty( $pdf_url ) ) {
            continue;
        }

        $hash = md5( $external_id );
        if ( get_user_meta( $user_id, '_mk20_ext_cert_path_' . $hash, true ) ) {
            continue;
        }

        $pdf_response = wp_remote_get( $pdf_url, array(
            'timeout' => 30,
        ) );

        if ( is_wp_error( $pdf_response ) || wp_remote_retrieve_response_code( $pdf_response ) !== 200 ) {
            mk20_api_audit_log( "DOWNLOAD_ERROR user_id={$user_id} external_id={$external_id} motivo=error_http" );
            continue;
        }

        $content_type = wp_remote_retrieve_header( $pdf_response, 'content-type' );
        if ( strpos( $content_type, 'application/pdf' ) === false ) {
            mk20_api_audit_log( "DOWNLOAD_ERROR user_id={$user_id} external_id={$external_id} motivo=content_type_invalido valor={$content_type}" );
            mk20_store_rejected_pdf( $user_id, $external_id, $course_title, 'content_type_invalido', $content_type );
            continue;
        }

        $content_length = wp_remote_retrieve_header( $pdf_response, 'content-length' );
        if ( ! empty( $content_length ) && intval( $content_length ) > 10 * MB_IN_BYTES ) {
            mk20_api_audit_log( "DOWNLOAD_ERROR user_id={$user_id} external_id={$external_id} motivo=pdf_demasiado_grande bytes={$content_length}" );
            mk20_store_rejected_pdf( $user_id, $external_id, $course_title, 'pdf_demasiado_grande', $content_length . ' bytes' );
            continue;
        }

        $pdf_content = wp_remote_retrieve_body( $pdf_response );
        if ( empty( $pdf_content ) ) {
            mk20_api_audit_log( "DOWNLOAD_ERROR user_id={$user_id} external_id={$external_id} motivo=cuerpo_vacio" );
            mk20_store_rejected_pdf( $user_id, $external_id, $course_title, 'cuerpo_vacio', '' );
            continue;
        }

        $result = mk20_import_external_certificate( $pdf_content, $user_id, $external_id, $course_title, $issue_date );
        if ( $result ) {
            $imported++;
        }
    }

    set_transient( $transient_key, 'done_' . $imported, 6 * HOUR_IN_SECONDS );
    mk20_api_audit_log( "OK user_id={$user_id} dni={$dni} importados={$imported}" );
}
