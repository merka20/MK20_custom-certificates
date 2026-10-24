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

    $meta_keys = array( $meta_key );

    if ( in_array( $meta_key, array( 'nif_nie', 'dni_nie', 'dni', 'nif', 'nie' ), true ) ) {
        $meta_keys = array_merge( $meta_keys, array( 'dni_nie', 'nif_nie', 'dni', 'nif', 'nie' ) );
    }

    $meta_keys = array_unique( array_filter( $meta_keys ) );

    foreach ( $meta_keys as $meta_key ) {
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
    return html_entity_decode( $title, ENT_QUOTES, 'UTF-8' );
}

function mk20_get_course_meta( $course_id, $meta_key, $fallback = '' ) {
    if ( empty( $course_id ) ) {
        return $fallback;
    }
    
    $value = get_post_meta( $course_id, $meta_key, true );
    return ! empty( $value ) ? trim( $value ) : $fallback;
}

/**
 * Indica si un curso debe generar certificado MK20 al completarse.
 *
 * Por defecto todos los cursos generan certificado. Solo se desactiva si el
 * curso tiene marcada la casilla "No generar certificado" en su metabox.
 *
 * @param int $course_id ID del curso.
 * @return bool True si el curso genera certificado.
 */
function mk20_course_certificate_enabled( $course_id ) {
    $course_id = absint( $course_id );

    if ( ! $course_id ) {
        return false;
    }

    $disabled = get_post_meta( $course_id, '_mk20_course_certificate_disabled', true );

    /**
     * Permite alterar desde código si un curso concreto genera certificado.
     *
     * @param bool $enabled   True si el curso genera certificado.
     * @param int  $course_id ID del curso.
     */
    return (bool) apply_filters( 'mk20_course_certificate_enabled', '1' !== (string) $disabled, $course_id );
}

/**
 * Comprueba (con caché por petición) si existe la tabla de actividad de LearnDash.
 *
 * @return bool
 */
function mk20_learndash_activity_table_exists() {
    static $exists = null;

    if ( null !== $exists ) {
        return $exists;
    }

    global $wpdb;
    $table = $wpdb->prefix . 'learndash_user_activity';

    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Comprobación de esquema; el resultado se cachea en estático durante la petición.
    $exists = (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );

    return $exists;
}

/**
 * Devuelve el timestamp de la primera actividad del alumno en un curso.
 *
 * Usa la tabla de actividad de LearnDash y, si no hay datos, cae a la fecha
 * de acceso al curso y, por último, al acceso por grupo.
 *
 * @param int $user_id   ID del usuario.
 * @param int $course_id ID del curso.
 * @return int Timestamp Unix o 0 si no hay datos.
 */
function mk20_get_user_course_start_ts( $user_id, $course_id ) {
    $user_id   = absint( $user_id );
    $course_id = absint( $course_id );

    if ( ! $user_id || ! $course_id ) {
        return 0;
    }

    global $wpdb;

    if ( mk20_learndash_activity_table_exists() ) {
        $table = esc_sql( $wpdb->prefix . 'learndash_user_activity' );

        // Solo actividades de pasos (lección, tema, cuestionario, etc.).
        // Se excluyen 'course' y 'access' porque LearnDash puede fecharlas al
        // crear o matricular el curso, no cuando el alumno empieza de verdad.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Lectura puntual de la tabla de actividad de LearnDash; sin caché aplicable.
        $started = $wpdb->get_var(
            $wpdb->prepare(
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Nombre de tabla construido con $wpdb->prefix.
                "SELECT MIN(activity_started) FROM {$table} WHERE user_id = %d AND course_id = %d AND activity_started > 0 AND activity_type NOT IN ( 'course', 'access', 'group_progress', 'expired' )",
                $user_id,
                $course_id
            )
        );

        if ( $started ) {
            return (int) $started;
        }
    }

    $access_from = (int) get_user_meta( $user_id, 'course_' . $course_id . '_access_from', true );
    if ( $access_from > 0 ) {
        return $access_from;
    }

    if ( function_exists( 'learndash_get_course_groups' ) && function_exists( 'learndash_group_access_from' ) ) {
        $group_start = 0;

        foreach ( (array) learndash_get_course_groups( $course_id ) as $group_id ) {
            $group_ts = (int) learndash_group_access_from( (int) $group_id, $user_id );

            if ( $group_ts > 0 && ( 0 === $group_start || $group_ts < $group_start ) ) {
                $group_start = $group_ts;
            }
        }

        if ( $group_start > 0 ) {
            return $group_start;
        }
    }

    return 0;
}

/**
 * Devuelve el timestamp de finalización del curso guardado por LearnDash.
 *
 * @param int $user_id   ID del usuario.
 * @param int $course_id ID del curso.
 * @return int Timestamp Unix o 0 si no hay datos.
 */
function mk20_get_user_course_end_ts( $user_id, $course_id ) {
    $user_id   = absint( $user_id );
    $course_id = absint( $course_id );

    if ( ! $user_id || ! $course_id ) {
        return 0;
    }

    $completed = (int) get_user_meta( $user_id, 'course_completed_' . $course_id, true );

    return $completed > 0 ? $completed : 0;
}

/**
 * Formatea un timestamp como fecha del certificado (d/m/Y).
 *
 * @param int $timestamp Timestamp Unix.
 * @return string Fecha formateada o cadena vacía.
 */
function mk20_format_cert_date( $timestamp ) {
    $timestamp = (int) $timestamp;

    return $timestamp > 0 ? date_i18n( 'd/m/Y', $timestamp ) : '';
}

/**
 * Indica si un usuario puede descargar el certificado de otro usuario.
 *
 * Por defecto solo puede el propio interesado y los administradores. Otros
 * plugins (por ejemplo un panel de lideres de grupo) pueden autorizarlo
 * mediante el filtro `mk20_user_can_download_certificate`.
 *
 * @param int $target_user_id  Usuario propietario del certificado.
 * @param int $current_user_id Usuario que intenta la descarga (0 = actual).
 * @return bool True si la descarga esta autorizada.
 */
function mk20_can_download_certificate_for_user( $target_user_id, $current_user_id = 0 ) {
    $target_user_id  = absint( $target_user_id );
    $current_user_id = $current_user_id ? absint( $current_user_id ) : get_current_user_id();

    if ( ! $target_user_id || ! $current_user_id ) {
        return false;
    }

    if ( $target_user_id === $current_user_id ) {
        return true;
    }

    if ( user_can( $current_user_id, 'manage_options' ) ) {
        return true;
    }

    /**
     * Permite autorizar la descarga del certificado de otro usuario.
     *
     * @param bool $allowed         Autorizacion. Por defecto false.
     * @param int  $target_user_id  Usuario propietario del certificado.
     * @param int  $current_user_id Usuario que intenta la descarga.
     */
    return (bool) apply_filters( 'mk20_user_can_download_certificate', false, $target_user_id, $current_user_id );
}

/**
 * Devuelve los certificados de un usuario, generados por el plugin y externos.
 *
 * Cada entrada incluye el tipo, el curso, la fecha, la ruta del PDF, un enlace
 * de descarga con nonce y, cuando existe, el enlace publico de verificacion.
 * No comprueba permisos: eso es responsabilidad de quien consume la lista.
 *
 * @param int $user_id ID del usuario.
 * @return array<int,array<string,mixed>> Lista ordenada del mas reciente al mas antiguo.
 */
function mk20_get_user_certificates( $user_id ) {
    $user_id = absint( $user_id );

    if ( ! $user_id ) {
        return array();
    }

    $all_meta = get_user_meta( $user_id );

    if ( ! is_array( $all_meta ) ) {
        return array();
    }

    $certificates = array();
    $native_url   = admin_url( 'admin-post.php?action=mk20_download_cert' );
    $external_url = admin_url( 'admin-post.php?action=mk20_download_ext_cert' );

    foreach ( $all_meta as $meta_key => $meta_values ) {
        $value = is_array( $meta_values ) && isset( $meta_values[0] ) ? (string) $meta_values[0] : '';

        if ( 0 === strpos( $meta_key, '_mk20_cert_path_' ) ) {
            $course_id = absint( substr( $meta_key, strlen( '_mk20_cert_path_' ) ) );

            if ( ! $course_id || '' === $value ) {
                continue;
            }

            $raw_date  = (string) get_user_meta( $user_id, '_mk20_cert_date_' . $course_id, true );
            $ts_raw    = (string) get_user_meta( $user_id, '_mk20_cert_ts_' . $course_id, true );
            $timestamp = 0;

            if ( '' !== $ts_raw ) {
                $timestamp = ctype_digit( $ts_raw ) ? (int) $ts_raw : (int) strtotime( $ts_raw );
            }

            if ( ! $timestamp && '' !== $raw_date ) {
                $timestamp = (int) strtotime( $raw_date );
            }

            $verify_hash = sanitize_key( (string) get_user_meta( $user_id, '_mk20_cert_verify_' . $course_id, true ) );

            $certificates[] = array(
                'type'           => 'native',
                'course_id'      => $course_id,
                'course_title'   => mk20_clean_course_title( (string) get_the_title( $course_id ) ),
                'date'           => $timestamp > 0 ? mk20_format_cert_date( $timestamp ) : '',
                'raw_date'       => $raw_date,
                'timestamp'      => $timestamp,
                'path'           => $value,
                'available'      => file_exists( $value ),
                'download_url'   => wp_nonce_url(
                    add_query_arg(
                        array(
                            'course_id' => $course_id,
                            'user_id'   => $user_id,
                        ),
                        $native_url
                    ),
                    'mk20_download_cert_' . $course_id
                ),
                'verify_hash'    => $verify_hash,
                'verify_url'     => '' !== $verify_hash ? home_url( '/verificar/' . $verify_hash ) : '',
                'integrity_hash' => (string) get_user_meta( $user_id, '_mk20_cert_hash_' . $course_id, true ),
            );
        } elseif ( 0 === strpos( $meta_key, '_mk20_ext_cert_path_' ) ) {
            $hash = sanitize_key( substr( $meta_key, strlen( '_mk20_ext_cert_path_' ) ) );

            if ( '' === $hash || '' === $value ) {
                continue;
            }

            $raw_date  = (string) get_user_meta( $user_id, '_mk20_ext_cert_date_' . $hash, true );
            $ts_raw    = (string) get_user_meta( $user_id, '_mk20_ext_cert_ts_' . $hash, true );
            $timestamp = 0;

            if ( '' !== $ts_raw ) {
                $timestamp = ctype_digit( $ts_raw ) ? (int) $ts_raw : (int) strtotime( $ts_raw );
            }

            if ( ! $timestamp && '' !== $raw_date ) {
                $timestamp = (int) strtotime( $raw_date );
            }

            $certificates[] = array(
                'type'           => 'external',
                'course_id'      => 0,
                'course_title'   => mk20_clean_course_title( (string) get_user_meta( $user_id, '_mk20_ext_course_title_' . $hash, true ) ),
                'date'           => $timestamp > 0 ? mk20_format_cert_date( $timestamp ) : '',
                'raw_date'       => $raw_date,
                'timestamp'      => $timestamp,
                'path'           => $value,
                'available'      => file_exists( $value ),
                'download_url'   => wp_nonce_url(
                    add_query_arg(
                        array(
                            'ext_cert' => $hash,
                            'user_id'  => $user_id,
                        ),
                        $external_url
                    ),
                    'mk20_download_ext_cert_' . $hash
                ),
                'verify_hash'    => '',
                'verify_url'     => '',
                'integrity_hash' => (string) get_user_meta( $user_id, '_mk20_ext_cert_hash_' . $hash, true ),
                'external_hash'  => $hash,
            );
        }
    }

    usort(
        $certificates,
        static function ( $a, $b ) {
            return (int) $b['timestamp'] <=> (int) $a['timestamp'];
        }
    );

    /**
     * Filtra la lista de certificados de un usuario.
     *
     * @param array<int,array<string,mixed>> $certificates Certificados encontrados.
     * @param int                            $user_id      ID del usuario.
     */
    return apply_filters( 'mk20_get_user_certificates', $certificates, $user_id );
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
        'verify_logo' => '',

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

        // 9. Integración API LaresFormación (ver documento_formacion.pdf).
        // El usuario/password SOLO viven en wp-config.php (constantes
        // MK20_LARES_API_USER/PASS) y nunca se guardan en la BD.
        'lares_enabled'      => 0,
        'lares_api_base'     => '',
        'lares_sync_ttl'     => 21600, // 6 horas en segundos.
        'lares_force_sync'   => 0, // 1 = ignorar caché y llamar siempre a la API.
        'lares_compress_pdf' => 1, // 1 = comprimir PDFs externos si Ghostscript está disponible.
        'lares_pdf_quality'  => 'ebook', // screen | ebook | printer.
    ];
}

/**
 * Obtiene la URL base de la API LaresFormación (sin barra final).
 *
 * Prioridad: constante MK20_LARES_API_BASE (wp-config.php) > ajuste
 * 'lares_api_base' > cadena vacía (integración desactivada).
 *
 * @return string URL base o cadena vacía si no está configurada.
 */
function mk20_lares_get_base() {
    if ( defined( 'MK20_LARES_API_BASE' ) && '' !== trim( MK20_LARES_API_BASE ) && 'https://formacion.lares.example' !== untrailingslashit( MK20_LARES_API_BASE ) ) {
        return untrailingslashit( MK20_LARES_API_BASE );
    }

    $options = wp_parse_args( get_option( 'mk20_cert_settings', array() ), mk20_get_default_settings() );
    if ( ! empty( $options['lares_api_base'] ) ) {
        return untrailingslashit( $options['lares_api_base'] );
    }

    return '';
}

/**
 * Obtiene el usuario de la API LaresFormación (solo constante, nunca BD).
 *
 * @return string Usuario o cadena vacía si no está configurado.
 */
function mk20_lares_get_user() {
    if ( defined( 'MK20_LARES_API_USER' ) && 'usuario_api' !== MK20_LARES_API_USER ) {
        return MK20_LARES_API_USER;
    }

    return '';
}

/**
 * Obtiene la contraseña de la API LaresFormación (solo constante, nunca BD).
 *
 * @return string Contraseña o cadena vacía si no está configurada.
 */
function mk20_lares_get_pass() {
    if ( defined( 'MK20_LARES_API_PASS' ) && '' !== MK20_LARES_API_PASS ) {
        return MK20_LARES_API_PASS;
    }

    return '';
}

/**
 * Indica si la integración LaresFormación está activada y configurada.
 *
 * @return bool True si está activada en ajustes y hay base + credenciales.
 */
function mk20_lares_is_configured() {
    $options = wp_parse_args( get_option( 'mk20_cert_settings', array() ), mk20_get_default_settings() );

    if ( empty( $options['lares_enabled'] ) ) {
        return false;
    }

    return '' !== mk20_lares_get_base() && '' !== mk20_lares_get_user() && '' !== mk20_lares_get_pass();
}

/**
 * Ejecuta un proceso externo sin pasar por el shell y con timeout.
 *
 * @param array $command Comando y argumentos.
 * @param int   $timeout Segundos máximos de ejecución.
 * @return array{ok:bool,exit:int,stdout:string,stderr:string,timed_out:bool,launch_failed:bool}
 */
function mk20_pdf_run_process( $command, $timeout = 20 ) {
    $result = array(
        'ok'            => false,
        'exit'          => -1,
        'stdout'        => '',
        'stderr'        => '',
        'timed_out'     => false,
        'launch_failed' => false,
    );

    if ( ! function_exists( 'proc_open' ) || ! is_array( $command ) || empty( $command ) ) {
        return $result;
    }

    $descriptors = array(
        0 => array( 'pipe', 'r' ),
        1 => array( 'pipe', 'w' ),
        2 => array( 'pipe', 'w' ),
    );

    // phpcs:ignore Generic.PHP.ForbiddenFunctions.Found,WordPress.PHP.DiscouragedPHPFunctions.system_calls_proc_open -- Necesario para invocar Ghostscript; el comando se pasa como array, sin shell.
    $process = @proc_open( $command, $descriptors, $pipes );
    if ( ! is_resource( $process ) ) {
        $result['launch_failed'] = true;
        return $result;
    }

    // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Cierre de la tubería del proceso; WP_Filesystem no gestiona pipes.
    fclose( $pipes[0] );
    stream_set_blocking( $pipes[1], false );
    stream_set_blocking( $pipes[2], false );

    $started   = microtime( true );
    $stdout    = '';
    $stderr    = '';
    $exit_code = -1;

    while ( true ) {
        $stdout .= (string) stream_get_contents( $pipes[1] );
        $stderr .= (string) stream_get_contents( $pipes[2] );

        $status = proc_get_status( $process );
        if ( ! is_array( $status ) || empty( $status['running'] ) ) {
            if ( is_array( $status ) && isset( $status['exitcode'] ) && (int) $status['exitcode'] >= 0 ) {
                $exit_code = (int) $status['exitcode'];
            }
            break;
        }

        if ( ( microtime( true ) - $started ) > $timeout ) {
            $result['timed_out'] = true;
            @proc_terminate( $process );
            break;
        }

        usleep( 100000 );
    }

    $stdout .= (string) stream_get_contents( $pipes[1] );
    $stderr .= (string) stream_get_contents( $pipes[2] );

    // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Cierre de la tubería del proceso; WP_Filesystem no gestiona pipes.
    fclose( $pipes[1] );
    // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Cierre de la tubería del proceso; WP_Filesystem no gestiona pipes.
    fclose( $pipes[2] );

    $close_code = proc_close( $process );
    if ( $exit_code < 0 && $close_code >= 0 ) {
        $exit_code = (int) $close_code;
    }

    $result['stdout'] = $stdout;
    $result['stderr'] = $stderr;
    $result['exit']   = $exit_code;
    $result['ok']     = ! $result['timed_out'] && 0 === $exit_code;

    return $result;
}

/**
 * Devuelve los binarios de Ghostscript candidatos, por orden de prioridad.
 *
 * Prioridad: constante MK20_GS_BINARY > filtro > candidatos habituales.
 *
 * @return string[]
 */
function mk20_pdf_gs_candidates() {
    $candidates = array();

    if ( defined( 'MK20_GS_BINARY' ) && is_string( MK20_GS_BINARY ) && '' !== MK20_GS_BINARY ) {
        $candidates[] = MK20_GS_BINARY;
    }

    $filtered = apply_filters( 'mk20_pdf_compress_gs_binary', '' );
    if ( is_string( $filtered ) && '' !== $filtered ) {
        $candidates[] = $filtered;
    }

    $candidates = array_merge(
        $candidates,
        array( 'gs', '/usr/bin/gs', '/usr/local/bin/gs', '/bin/gs', '/opt/homebrew/bin/gs', 'gswin64c.exe', 'gswin32c.exe' )
    );

    foreach ( (array) glob( 'C:\\Program Files\\gs\\gs*\\bin\\gswin64c.exe' ) as $windows_gs ) {
        $candidates[] = $windows_gs;
    }

    return array_values( array_unique( array_filter( array_map( 'strval', $candidates ) ) ) );
}

/**
 * Prueba cada binario candidato con --version y devuelve el resultado.
 *
 * Se detiene en el primer binario válido (no vacío y código de salida 0).
 *
 * @return array<int,array{binary:string,ok:bool,exit:int,stdout:string,stderr:string,timed_out:bool,launch_failed:bool}>
 */
function mk20_pdf_gs_probe() {
    $diagnostics = array();

    if ( ! function_exists( 'proc_open' ) ) {
        return $diagnostics;
    }

    foreach ( mk20_pdf_gs_candidates() as $binary ) {
        $result = mk20_pdf_run_process( array( $binary, '--version' ), 3 );

        $works = ! empty( $result['ok'] ) && '' !== trim( (string) $result['stdout'] );

        $diagnostics[] = array(
            'binary'        => $binary,
            'ok'            => $works,
            'exit'          => (int) $result['exit'],
            'stdout'        => trim( (string) $result['stdout'] ),
            'stderr'        => trim( (string) $result['stderr'] ),
            'timed_out'     => ! empty( $result['timed_out'] ),
            'launch_failed' => ! empty( $result['launch_failed'] ),
        );

        if ( $works ) {
            break;
        }
    }

    return $diagnostics;
}

/**
 * Devuelve el binario de Ghostscript disponible, o cadena vacía.
 *
 * Prioridad: constante MK20_GS_BINARY > filtro > candidatos habituales.
 * El resultado se cachea 12 horas en un transient.
 *
 * @return string
 */
function mk20_pdf_compress_gs_binary() {
    static $cached = null;

    if ( null !== $cached ) {
        return $cached;
    }

    if ( ! function_exists( 'proc_open' ) ) {
        $cached = '';
        return $cached;
    }

    $transient = get_transient( 'mk20_gs_binary' );
    if ( false !== $transient ) {
        $cached = is_string( $transient ) ? $transient : '';
        return $cached;
    }

    $found       = '';
    $diagnostics = mk20_pdf_gs_probe();

    foreach ( $diagnostics as $diagnostic ) {
        if ( ! empty( $diagnostic['ok'] ) ) {
            $found = $diagnostic['binary'];
            break;
        }
    }

    set_transient( 'mk20_gs_binary', $found, 12 * HOUR_IN_SECONDS );
    set_transient( 'mk20_gs_diag', $diagnostics, 12 * HOUR_IN_SECONDS );
    $cached = $found;

    return $cached;
}

/**
 * Devuelve el diagnóstico cacheado de la última comprobación de Ghostscript.
 *
 * @return array<int,array<string,mixed>>
 */
function mk20_pdf_gs_diagnostics() {
    $diagnostics = get_transient( 'mk20_gs_diag' );

    return is_array( $diagnostics ) ? $diagnostics : array();
}

/**
 * Comprime un PDF externo con Ghostscript si está disponible.
 *
 * Si no hay Ghostscript, falla la ejecución o la salida no es válida,
 * devuelve el contenido original sin interrumpir la importación.
 *
 * @param string     $pdf_content Contenido binario del PDF.
 * @param array|null $settings    Ajustes del plugin (opcional).
 * @return string Contenido comprimido o el original.
 */
function mk20_compress_pdf_content( $pdf_content, $settings = null ) {
    if ( ! is_string( $pdf_content ) || '' === $pdf_content || substr( $pdf_content, 0, 4 ) !== '%PDF' ) {
        return $pdf_content;
    }

    if ( null === $settings ) {
        $settings = wp_parse_args( get_option( 'mk20_cert_settings', array() ), mk20_get_default_settings() );
    }

    if ( empty( $settings['lares_compress_pdf'] ) ) {
        return $pdf_content;
    }

    $quality = isset( $settings['lares_pdf_quality'] ) ? (string) $settings['lares_pdf_quality'] : 'ebook';
    if ( ! in_array( $quality, array( 'screen', 'ebook', 'printer' ), true ) ) {
        $quality = 'ebook';
    }

    $gs_binary = mk20_pdf_compress_gs_binary();
    if ( '' === $gs_binary ) {
        mk20_api_audit_log( 'IMPORT_PDF_COMPRESS_SKIP motivo=ghostscript_no_disponible' );
        return $pdf_content;
    }

    $temp_dir = get_temp_dir();
    $tmp_in   = $temp_dir . wp_unique_filename( $temp_dir, 'mk20-pdf-in.pdf' );
    $tmp_out  = $temp_dir . wp_unique_filename( $temp_dir, 'mk20-pdf-out.pdf' );

    $written = file_put_contents( $tmp_in, $pdf_content );
    if ( false === $written ) {
        wp_delete_file( $tmp_in );
        mk20_api_audit_log( 'IMPORT_PDF_COMPRESS_SKIP motivo=error_temporal' );
        return $pdf_content;
    }

    $command = array(
        $gs_binary,
        '-q',
        '-dNOPAUSE',
        '-dBATCH',
        '-dSAFER',
        '-sDEVICE=pdfwrite',
        '-dCompatibilityLevel=1.4',
        '-dPDFSETTINGS=/' . $quality,
        '-dEmbedAllFonts=true',
        '-dSubsetFonts=true',
        '-dAutoRotatePages=/None',
        '-sOutputFile=' . $tmp_out,
        $tmp_in,
    );

    $result = mk20_pdf_run_process( $command, 20 );

    wp_delete_file( $tmp_in );

    $compressed = '';
    if ( ! empty( $result['ok'] ) && file_exists( $tmp_out ) ) {
        $compressed = (string) file_get_contents( $tmp_out );
    }
    wp_delete_file( $tmp_out );

    if ( '' === $compressed || substr( $compressed, 0, 4 ) !== '%PDF' || false === strpos( $compressed, '%%EOF' ) ) {
        mk20_api_audit_log(
            sprintf(
                'IMPORT_PDF_COMPRESS_SKIP motivo=salida_invalida gs=%s exit=%d timed_out=%d',
                $gs_binary,
                (int) $result['exit'],
                ! empty( $result['timed_out'] ) ? 1 : 0
            )
        );
        return $pdf_content;
    }

    $in_pages  = (int) preg_match_all( '#/Type\s*/Page[^s]#', $pdf_content );
    $out_pages = (int) preg_match_all( '#/Type\s*/Page[^s]#', $compressed );
    if ( $in_pages > 0 && $in_pages !== $out_pages ) {
        mk20_api_audit_log(
            sprintf(
                'IMPORT_PDF_COMPRESS_SKIP motivo=paginas_distintas antes=%d despues=%d',
                $in_pages,
                $out_pages
            )
        );
        return $pdf_content;
    }

    if ( strlen( $compressed ) >= strlen( $pdf_content ) ) {
        mk20_api_audit_log(
            sprintf(
                'IMPORT_PDF_COMPRESS_SKIP motivo=sin_ahorro antes=%d despues=%d',
                strlen( $pdf_content ),
                strlen( $compressed )
            )
        );
        return $pdf_content;
    }

    mk20_api_audit_log(
        sprintf(
            'IMPORT_PDF_COMPRESS_OK gs=%s quality=%s antes=%d despues=%d',
            $gs_binary,
            $quality,
            strlen( $pdf_content ),
            strlen( $compressed )
        )
    );

    return $compressed;
}

/**
 * Importa un certificado PDF desde una fuente externa y lo registra
 * con el mismo formato que los certificados nativos del plugin.
 * Antes de guardarlo, intenta comprimirlo con Ghostscript si está disponible.
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

    $pdf_content = mk20_compress_pdf_content( $pdf_content );

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

    // No se generan previews del PDF: el plugin no las usa y añaden peso.
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

/**
 * Sincroniza los diplomas LaresFormación del usuario (formaciones presenciales).
 *
 * Flujo (documento_formacion.pdf): DNI del perfil BuddyBoss -> formacion_list
 * -> generar_pdf por cada formación no importada -> importación con
 * mk20_import_external_certificate(). El PDF se guarda tal cual lo envía
 * Lares (su diseño), salvo que la compresión con Ghostscript esté activa.
 * Ante cualquier fallo no rompe el perfil: lo registra en el audit log y/o
 * en rechazados. Respeta el TTL configurable.
 *
 * @param int $user_id ID del usuario en WordPress.
 * @param int $budget  Segundos máximos a dedicar a la sincronización.
 * @return array Estado devuelto por el último lote.
 */
function mk20_sync_external_certificates( $user_id, $budget = 25 ) {
    $user_id  = absint( $user_id );
    $deadline = microtime( true ) + max( 5, absint( $budget ) );
    $result   = array( 'done' => true, 'busy' => false, 'imported' => 0, 'pending' => 0, 'total' => 0 );

    if ( 0 === $user_id || ! mk20_lares_is_configured() ) {
        return $result;
    }

    do {
        $result = mk20_lares_sync_batch( $user_id, 20, 1 );
        if ( ! empty( $result['busy'] ) ) {
            break;
        }
    } while ( empty( $result['done'] ) && microtime( true ) < $deadline );

    return $result;
}

/**
 * Devuelve los valores por defecto del estado de sincronización externa.
 *
 * @return array
 */
function mk20_sync_state_defaults() {
    return array(
        'pending'  => array(),
        'deferred' => array(),
        'total'    => 0,
        'list_at'  => 0,
        'attempts' => array(),
        'errors'   => array(),
        'avg_ms'   => 12000,
        'dni'      => '',
    );
}

/**
 * Lee el estado de sincronización externa de un usuario.
 *
 * @param int $user_id ID del usuario.
 * @return array
 */
function mk20_sync_state_get( $user_id ) {
    $state = get_user_meta( absint( $user_id ), '_mk20_ext_sync_state', true );

    if ( ! is_array( $state ) ) {
        return mk20_sync_state_defaults();
    }

    $state = wp_parse_args( $state, mk20_sync_state_defaults() );

    $state['pending']  = is_array( $state['pending'] ) ? array_values( $state['pending'] ) : array();
    $state['deferred'] = is_array( $state['deferred'] ) ? array_values( $state['deferred'] ) : array();
    $state['attempts'] = is_array( $state['attempts'] ) ? $state['attempts'] : array();
    $state['errors']   = is_array( $state['errors'] ) ? $state['errors'] : array();

    return $state;
}

/**
 * Guarda el estado de sincronización externa de un usuario.
 *
 * @param int   $user_id ID del usuario.
 * @param array $state   Estado a persistir.
 */
function mk20_sync_state_save( $user_id, $state ) {
    update_user_meta( absint( $user_id ), '_mk20_ext_sync_state', $state );
}

/**
 * Indica si hay ítems diferidos cuyo backoff ya venció (listos para reintento).
 *
 * @param array $state Estado de sincronización.
 * @return bool
 */
function mk20_sync_state_has_due_deferred( $state ) {
    $now = time();

    foreach ( (array) $state['deferred'] as $deferred ) {
        if ( isset( $deferred['next_try'] ) && (int) $deferred['next_try'] <= $now ) {
            return true;
        }
    }

    return false;
}

/**
 * Adquiere el bloqueo de sincronización de un usuario (evita procesos
 * concurrentes en perfil, REST y cron). Caduca a los 5 minutos.
 *
 * @param int $user_id ID del usuario.
 * @return bool True si se obtuvo el bloqueo.
 */
function mk20_sync_lock_acquire( $user_id ) {
    $user_id = absint( $user_id );
    $key     = 'mk20_sync_lock_' . $user_id;
    $now     = time();

    if ( add_option( $key, $now, '', 'no' ) ) {
        return true;
    }

    $current = (int) get_option( $key, 0 );
    if ( $current && ( $now - $current ) > 300 ) {
        update_option( $key, $now, false );
        return true;
    }

    return false;
}

/**
 * Libera el bloqueo de sincronización de un usuario.
 *
 * @param int $user_id ID del usuario.
 */
function mk20_sync_lock_release( $user_id ) {
    delete_option( 'mk20_sync_lock_' . absint( $user_id ) );
}

/**
 * Indica si un certificado externo ya está importado y su archivo existe.
 *
 * @param int    $user_id ID del usuario.
 * @param string $hash    Hash del certificado externo.
 * @return bool
 */
function mk20_ext_cert_exists( $user_id, $hash ) {
    $path = get_user_meta( absint( $user_id ), '_mk20_ext_cert_path_' . $hash, true );

    return ( $path && file_exists( $path ) );
}

/**
 * Indica si el usuario tiene trabajo de sincronización pendiente o la caché
 * está caducada. No realiza ninguna llamada de red.
 *
 * @param int $user_id ID del usuario.
 * @return bool
 */
function mk20_lares_sync_needed( $user_id ) {
    if ( ! mk20_lares_is_configured() ) {
        return false;
    }

    $options = wp_parse_args( get_option( 'mk20_cert_settings', array() ), mk20_get_default_settings() );
    $ttl     = isset( $options['lares_sync_ttl'] ) ? max( 300, absint( $options['lares_sync_ttl'] ) ) : 21600;
    $state   = mk20_sync_state_get( $user_id );

    if ( ! empty( $state['pending'] ) || mk20_sync_state_has_due_deferred( $state ) ) {
        return true;
    }

    if ( ! empty( $options['lares_force_sync'] ) ) {
        return true;
    }

    return ( time() - (int) $state['list_at'] ) >= $ttl;
}

/**
 * Prepara el estado: reintroduce los diferidos con backoff vencido y, si
 * corresponde, refresca la lista de formaciones desde Lares.
 *
 * @param int   $user_id ID del usuario.
 * @param array $state   Estado actual.
 * @param int   $ttl     TTL de caché en segundos.
 * @param array $options Ajustes del plugin.
 * @return array Estado preparado.
 */
function mk20_lares_sync_prepare( $user_id, $state, $ttl, $options ) {
    $now = time();

    // Reintroducir los diferidos cuyo backoff ya venció; descartar tras 3 intentos.
    $still_deferred = array();
    foreach ( $state['deferred'] as $deferred ) {
        if ( ! isset( $deferred['item'], $deferred['next_try'], $deferred['attempts'] ) ) {
            continue;
        }

        if ( (int) $deferred['attempts'] >= 3 ) {
            continue;
        }

        if ( (int) $deferred['next_try'] <= $now ) {
            $state['pending'][] = $deferred['item'];
        } else {
            $still_deferred[] = $deferred;
        }
    }
    $state['deferred'] = $still_deferred;

    // Si ya hay trabajo pendiente, no se vuelve a consultar la lista.
    if ( ! empty( $state['pending'] ) ) {
        return $state;
    }

    $force = ! empty( $options['lares_force_sync'] );
    $stale = ( $now - (int) $state['list_at'] ) >= $ttl;

    if ( ! $force && ! $stale ) {
        return $state;
    }

    $dni = mk20_get_buddyboss_field( $user_id, $options['bb_field_dni'], '' );
    $state['list_at'] = $now;

    if ( '' === $dni ) {
        mk20_api_audit_log( "LARES_SYNC_SALT user_id={$user_id} motivo=sin_dni" );
        return $state;
    }

    $state['dni'] = $dni;

    $formaciones = mk20_lares_formacion_list( $dni );
    $pending     = array();

    foreach ( $formaciones as $formacion ) {
        $idformacion = absint( $formacion['idFormacion'] );

        // Sin diploma emitido en Lares: no generar ni importar hasta que tenga fecha.
        if ( 0 === $idformacion || '' === $formacion['FechaDiploma'] ) {
            continue;
        }

        $external_id = $dni . '_' . $idformacion;
        $hash        = md5( $external_id );

        if ( mk20_ext_cert_exists( $user_id, $hash ) ) {
            continue; // Ya importado y el archivo sigue en disco.
        }

        $pending[] = array(
            'idFormacion'  => $idformacion,
            'titulo'       => isset( $formacion['titulo'] ) ? $formacion['titulo'] : '',
            'FechaDiploma' => $formacion['FechaDiploma'],
            'hash'         => $hash,
            'external_id'  => $external_id,
        );
    }

    $state['pending'] = $pending;
    $state['total']   = count( $pending );

    return $state;
}

/**
 * Marca un ítem fallido y lo difiere con backoff (1h, 6h). Tras 3 intentos
 * se descarta (el motivo ya queda en el registro de rechazados).
 *
 * @param array  $state  Estado (por referencia).
 * @param array  $item   Ítem fallido.
 * @param string $reason Motivo del fallo.
 */
function mk20_lares_mark_failure( &$state, $item, $reason ) {
    $idformacion = absint( $item['idFormacion'] );
    $attempts    = isset( $state['attempts'][ $idformacion ] ) ? (int) $state['attempts'][ $idformacion ] + 1 : 1;

    $state['attempts'][ $idformacion ] = $attempts;
    $state['errors'][ $idformacion ]   = $reason;

    if ( $attempts >= 3 ) {
        return;
    }

    $delays = array( 1 => HOUR_IN_SECONDS, 2 => 6 * HOUR_IN_SECONDS );
    $delay  = isset( $delays[ $attempts ] ) ? $delays[ $attempts ] : DAY_IN_SECONDS;

    $state['deferred'][] = array(
        'item'     => $item,
        'attempts' => $attempts,
        'next_try' => time() + $delay,
    );
}

/**
 * Genera e importa un único certificado pendiente.
 *
 * @param int    $user_id ID del usuario.
 * @param array  $item    Ítem pendiente.
 * @param string $dni     DNI/NIF del alumno.
 * @param array  $state   Estado (por referencia, para anotar fallos).
 * @return bool True si se importó correctamente.
 */
function mk20_lares_import_pending_item( $user_id, $item, $dni, &$state ) {
    $idformacion = absint( $item['idFormacion'] );
    $external_id = $item['external_id'];

    if ( '' !== $item['titulo'] ) {
        $titulo = $item['titulo'];
    } else {
        /* translators: %d: identificador de la formación en Lares. */
        $titulo = sprintf( __( 'Formación Lares %d', 'mk20-custom-certificates' ), $idformacion );
    }
    $titulo = apply_filters( 'mk20_ext_course_title', $titulo, $idformacion, $dni, $user_id );

    $pdf = mk20_lares_generar_pdf( $dni, $idformacion, $user_id );
    if ( false === $pdf ) {
        mk20_lares_mark_failure( $state, $item, 'generar_pdf' );
        return false;
    }

    $result = mk20_import_external_certificate( $pdf['contenido'], $user_id, $external_id, $titulo, $item['FechaDiploma'] );
    if ( ! $result ) {
        mk20_lares_mark_failure( $state, $item, 'importar' );
        return false;
    }

    return true;
}

/**
 * Procesa un lote acotado de la sincronización externa de un usuario.
 *
 * @param int $user_id   ID del usuario.
 * @param int $budget    Segundos máximos por lote.
 * @param int $max_items Máximo de certificados por lote.
 * @return array { done, busy, imported, pending, total }
 */
function mk20_lares_sync_batch( $user_id, $budget = 20, $max_items = 1 ) {
    $user_id = absint( $user_id );

    if ( 0 === $user_id || ! mk20_lares_is_configured() ) {
        return array( 'done' => true, 'busy' => false, 'imported' => 0, 'pending' => 0, 'total' => 0 );
    }

    $state = mk20_sync_state_get( $user_id );

    if ( ! mk20_sync_lock_acquire( $user_id ) ) {
        return array(
            'done'     => empty( $state['pending'] ) && ! mk20_sync_state_has_due_deferred( $state ),
            'busy'     => true,
            'imported' => 0,
            'pending'  => count( $state['pending'] ),
            'total'    => (int) $state['total'],
        );
    }

    $options = wp_parse_args( get_option( 'mk20_cert_settings', array() ), mk20_get_default_settings() );
    $ttl     = isset( $options['lares_sync_ttl'] ) ? max( 300, absint( $options['lares_sync_ttl'] ) ) : 21600;

    $state = mk20_lares_sync_prepare( $user_id, $state, $ttl, $options );

    $imported = 0;
    $start    = microtime( true );

    while ( ! empty( $state['pending'] ) && $imported < max( 1, absint( $max_items ) ) ) {
        $item    = array_shift( $state['pending'] );
        $elapsed = microtime( true ) - $start;

        if ( $imported > 0 && ( $elapsed + ( (int) $state['avg_ms'] / 1000 ) ) > $budget ) {
            array_unshift( $state['pending'], $item );
            break;
        }

        $t0 = microtime( true );
        $ok = mk20_lares_import_pending_item( $user_id, $item, $state['dni'], $state );

        $state['avg_ms'] = max( 1000, (int) round( ( microtime( true ) - $t0 ) * 1000 ) );

        if ( $ok ) {
            $imported++;
        }

        // Se persiste tras cada ítem para no perder progreso si el proceso muere.
        mk20_sync_state_save( $user_id, $state );
    }

    $done = empty( $state['pending'] ) && ! mk20_sync_state_has_due_deferred( $state );

    if ( $done ) {
        $state['list_at'] = time();
    }

    mk20_sync_state_save( $user_id, $state );
    mk20_sync_lock_release( $user_id );

    if ( $done ) {
        set_transient( 'mk20_ext_sync_' . $user_id, 'done_' . $imported, $ttl );
    }

    mk20_api_audit_log( "LARES_BATCH user_id={$user_id} importados={$imported} pendientes=" . count( $state['pending'] ) . ' done=' . ( $done ? '1' : '0' ) );

    return array(
        'done'     => $done,
        'busy'     => false,
        'imported' => $imported,
        'pending'  => count( $state['pending'] ),
        'total'    => (int) $state['total'],
    );
}

/**
 * Construye el cuerpo multipart/form-data para subir un archivo con campos.
 *
 * @param string $boundary  Delimitador multipart.
 * @param string $file_path Ruta absoluta del archivo PDF.
 * @param array  $fields    Campos del formulario (clave => valor).
 * @return string Cuerpo de la peticion multipart.
 */
function mk20_build_multipart_body( $boundary, $file_path, $fields ) {
    $body            = '';
    $file_contents   = file_get_contents( $file_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
    $filename        = basename( $file_path );

    $body .= '--' . $boundary . "\r\n";
    $body .= 'Content-Disposition: form-data; name="pdf_file"; filename="' . $filename . '"' . "\r\n";
    $body .= 'Content-Type: application/pdf' . "\r\n";
    $body .= 'Content-Transfer-Encoding: binary' . "\r\n\r\n";
    $body .= $file_contents . "\r\n";

    foreach ( $fields as $key => $value ) {
        $body .= '--' . $boundary . "\r\n";
        $body .= 'Content-Disposition: form-data; name="' . $key . '"' . "\r\n\r\n";
        $body .= $value . "\r\n";
    }

    $body .= '--' . $boundary . "--\r\n";

    return $body;
}

/**
 * Devuelve el nombre de la tabla de indice de certificados.
 *
 * @return string
 */
function mk20_get_cert_index_table() {
    global $wpdb;

    return $wpdb->prefix . 'mk20_certs';
}

/**
 * Crea la tabla de indice de certificados.
 * Contiene el hash de verificacion (code_hash) indexado para acelerar las
 * busquedas publicas y evitar escanear toda la usermeta en cada request.
 */
function mk20_create_cert_index_table() {
    global $wpdb;

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';

    $table          = mk20_get_cert_index_table();
    $charset_collate = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE {$table} (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        user_id bigint(20) unsigned NOT NULL,
        course_id bigint(20) unsigned NOT NULL,
        code_hash char(64) NOT NULL,
        issued_at datetime NOT NULL,
        PRIMARY KEY  (id),
        KEY code_hash (code_hash),
        KEY user_course (user_id, course_id)
    ) {$charset_collate};";

    dbDelta( $sql );

    update_option( 'mk20_cert_index_db_version', '1.0.0' );
}

/**
 * Crea la tabla si falta (usado tambien en upgrades sin reactivacion).
 */
function mk20_ensure_cert_index_table() {
    if ( get_option( 'mk20_cert_index_db_version' ) === '1.0.0' ) {
        return;
    }
    mk20_create_cert_index_table();
    mk20_backfill_cert_index();
}

/**
 * Inserta o actualiza el indice de un certificado nativo.
 *
 * @param int    $user_id     ID del usuario.
 * @param int    $course_id   ID del curso.
 * @param string $verify_hash Hash SHA-256 de verificacion.
 */
function mk20_index_certificate( $user_id, $course_id, $verify_hash ) {
    if ( empty( $verify_hash ) ) {
        return;
    }

    global $wpdb;

    $wpdb->replace( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Escritura UPSERT sobre tabla propia; no aplica cache de lectura.
        mk20_get_cert_index_table(),
        array(
            'user_id'   => absint( $user_id ),
            'course_id' => absint( $course_id ),
            'code_hash' => strtolower( sanitize_text_field( $verify_hash ) ),
            'issued_at' => current_time( 'mysql' ),
        ),
        array( '%d', '%d', '%s', '%s' )
    );
}

/**
 * Elimina el indice de un certificado nativo.
 *
 * @param int $user_id   ID del usuario.
 * @param int $course_id ID del curso.
 */
function mk20_remove_certificate_index( $user_id, $course_id ) {
    global $wpdb;

    $wpdb->delete( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Escritura DELETE sobre tabla propia; no aplica cache de lectura.
        mk20_get_cert_index_table(),
        array(
            'user_id'   => absint( $user_id ),
            'course_id' => absint( $course_id ),
        ),
        array( '%d', '%d' )
    );
}

/**
 * Puebla la tabla de indice con los certificados nativos ya emitidos
 * (migracion desde user meta). Solo se ejecuta una vez.
 */
function mk20_backfill_cert_index() {
    $users = get_users( array( 'fields' => 'ID' ) );

    foreach ( $users as $user_id ) {
        $user_meta = get_user_meta( $user_id );

        foreach ( $user_meta as $meta_key => $meta_values ) {
            if ( 0 !== strpos( $meta_key, '_mk20_cert_verify_' ) ) {
                continue;
            }

            $course_id = intval( str_replace( '_mk20_cert_verify_', '', $meta_key ) );
            foreach ( (array) $meta_values as $meta_value ) {
                if ( $course_id && $meta_value ) {
                    mk20_index_certificate( $user_id, $course_id, $meta_value );
                }
            }
        }
    }
}

/**
 * Sube un certificado PDF generado localmente a la API externa.
 *
 * La URL de la API se configura mediante la constante MK20_EXT_UPLOAD_URL.
 * Si la constante esta vacia o no definida, la funcion no hace nada.
 *
 * @param string $pdf_path        Ruta absoluta del PDF generado.
 * @param int    $user_id         ID del usuario en WordPress.
 * @param int    $course_id       ID del curso completado.
 * @param string $course_title    Titulo del curso (limpio).
 * @param string $student_name    Nombre completo del alumno.
 * @param string $completion_date Fecha de finalizacion (formato d/m/Y).
 */
function mk20_upload_certificate_to_external_api( $pdf_path, $user_id, $course_id, $course_title, $student_name, $completion_date ) {
    if ( empty( MK20_EXT_UPLOAD_URL ) ) {
        return;
    }

    $uploaded_id = get_user_meta( $user_id, '_mk20_cert_uploaded_id_' . $course_id, true );
    if ( $uploaded_id ) {
        mk20_api_audit_log( "UPLOAD_SKIP user_id={$user_id} course_id={$course_id} motivo=ya_subido external_id={$uploaded_id}" );
        return;
    }

    if ( ! file_exists( $pdf_path ) ) {
        mk20_api_audit_log( "UPLOAD_ERROR user_id={$user_id} course_id={$course_id} motivo=archivo_no_existe" );
        return;
    }

    $options = wp_parse_args( get_option( 'mk20_cert_settings', array() ), mk20_get_default_settings() );
    $dni     = mk20_get_buddyboss_field( $user_id, $options['bb_field_dni'], '' );

    if ( empty( $dni ) ) {
        mk20_api_audit_log( "UPLOAD_ERROR user_id={$user_id} course_id={$course_id} motivo=sin_dni" );
        return;
    }

    $headers = array();
    if ( ! empty( MK20_EXT_API_TOKEN ) ) {
        $headers['Authorization'] = 'Bearer ' . MK20_EXT_API_TOKEN;
    }

    $boundary               = wp_generate_password( 24, false );
    $headers['Content-Type'] = 'multipart/form-data; boundary=' . $boundary;

    $body = mk20_build_multipart_body( $boundary, $pdf_path, array(
        'dni'             => $dni,
        'student_name'    => $student_name,
        'course_id'       => $course_id,
        'course_title'    => $course_title,
        'completion_date' => $completion_date,
        'issue_date'      => current_time( 'mysql' ),
    ) );

    $response = wp_remote_post( MK20_EXT_UPLOAD_URL, array(
        'timeout' => 60,
        'headers' => $headers,
        'body'    => $body,
    ) );

    if ( is_wp_error( $response ) ) {
        mk20_api_audit_log( "UPLOAD_ERROR user_id={$user_id} course_id={$course_id} motivo=" . $response->get_error_message() );
        return;
    }

    $status_code = wp_remote_retrieve_response_code( $response );
    $body_resp   = json_decode( wp_remote_retrieve_body( $response ), true );
    $external_id = isset( $body_resp['id'] ) ? sanitize_text_field( $body_resp['id'] ) : '';

    if ( 201 === $status_code ) {
        update_user_meta( $user_id, '_mk20_cert_uploaded_id_' . $course_id, $external_id );
        mk20_api_audit_log( "UPLOAD_OK user_id={$user_id} course_id={$course_id} external_id={$external_id}" );
    } elseif ( 409 === $status_code ) {
        update_user_meta( $user_id, '_mk20_cert_uploaded_id_' . $course_id, $external_id ?: 'duplicated' );
        mk20_api_audit_log( "UPLOAD_DUP user_id={$user_id} course_id={$course_id} motivo=ya_existe_en_externo" );
    } elseif ( 401 === $status_code ) {
        mk20_api_audit_log( "UPLOAD_ERROR user_id={$user_id} course_id={$course_id} motivo=token_invalido http={$status_code}" );
    } else {
        mk20_api_audit_log( "UPLOAD_ERROR user_id={$user_id} course_id={$course_id} motivo=http_error http={$status_code}" );
    }
}

/**
 * Cliente API LaresFormación (documento_formacion.pdf, apartado 2).
 *
 * Flujo: login (wpapilogin/index.php) -> token en transient ->
 * peticiones POST JSON a wpapigetpdf/index.php con campo "request".
 * Las credenciales viven SOLO en wp-config.php (ver mk20_lares_get_user/pass).
 * Los PDFs externos se devuelven tal cual (diseño de Lares); este cliente
 * no guarda nada ni remaqueta: solo habla con la API y valida respuestas.
 */

/**
 * Extrae el objeto JSON de una respuesta contaminada con volcado de debug.
 *
 * La API Lares puede devolver trazas (SQL, var_dump) pegadas al JSON
 * (ajuste app_debug_con_get activado en su lado). Localiza '{"status"'
 * y recorta hasta su llave de cierre equilibrada. Si no hay marca,
 * devuelve el cuerpo tal cual (respuesta limpia o formato desconocido).
 *
 * @param string $raw Cuerpo crudo de la respuesta.
 * @return string Subcadena JSON candidata (o el cuerpo original).
 */
function mk20_lares_extract_json( $raw ) {
    if ( ! is_string( $raw ) || '' === $raw ) {
        return '';
    }

    $start = strpos( $raw, '{"status"' );
    if ( false === $start ) {
        return $raw;
    }

    $len     = strlen( $raw );
    $depth   = 0;
    $in_str  = false;
    $escaped = false;

    for ( $i = $start; $i < $len; $i++ ) {
        $c = $raw[ $i ];

        if ( $in_str ) {
            if ( $escaped ) {
                $escaped = false;
            } elseif ( '\\' === $c ) {
                $escaped = true;
            } elseif ( '"' === $c ) {
                $in_str = false;
            }
            continue;
        }

        if ( '"' === $c ) {
            $in_str = true;
        } elseif ( '{' === $c ) {
            $depth++;
        } elseif ( '}' === $c ) {
            $depth--;
            if ( 0 === $depth ) {
                return substr( $raw, $start, $i - $start + 1 );
            }
        }
    }

    return $raw;
}

/**
 * Indica si se verifica el TLS en las llamadas a LaresFormación.
 *
 * Lares usa un certificado autofirmado con clave débil (verificado el
 * 2026-10-09: "EE certificate key too weak"). Por defecto NO se verifica
 * (equivale a curl -k): el tráfico sigue cifrado pero sin autenticar al
 * servidor (riesgo MITM asumido por indicación de Lares). Si instalan un
 * certificado válido, definir MK20_LARES_SSLVERIFY como true en wp-config.php.
 *
 * @return bool
 */
function mk20_lares_sslverify() {
    if ( defined( 'MK20_LARES_SSLVERIFY' ) ) {
        return (bool) MK20_LARES_SSLVERIFY;
    }

    return false;
}

/**
 * Obtiene un token válido de la API Lares (acción "login").
 * Reutiliza el transient mk20_lares_token (8h, según Lares) salvo $forzar.
 *
 * @param bool $forzar True para ignorar caché y pedir token nuevo.
 * @return string|false Token o false si falla.
 */
function mk20_lares_login( $forzar = false ) {
    if ( ! $forzar ) {
        $cacheado = get_transient( 'mk20_lares_token' );
        if ( is_string( $cacheado ) && '' !== $cacheado ) {
            return $cacheado;
        }
    }

    $base = mk20_lares_get_base();
    $user = mk20_lares_get_user();
    $pass = mk20_lares_get_pass();

    if ( '' === $base || '' === $user || '' === $pass ) {
        mk20_api_audit_log( 'LARES_LOGIN_SKIP motivo=sin_configuracion' );
        return false;
    }

    $response = wp_remote_post( $base . '/wpapilogin/index.php', array(
        'timeout'   => 10,
        'sslverify' => mk20_lares_sslverify(),
        'headers'   => array( 'Content-Type' => 'application/json' ),
        'body'    => wp_json_encode( array(
            'request'  => 'login',
            'user'     => $user,
            'password' => $pass,
        ) ),
    ) );

    if ( is_wp_error( $response ) ) {
        mk20_api_audit_log( 'LARES_LOGIN_ERROR motivo=' . $response->get_error_message() );
        return false;
    }

    $status = wp_remote_retrieve_response_code( $response );
    if ( 200 !== $status ) {
        // La documentación avisa: el 400 de login puede venir sin cuerpo JSON.
        mk20_api_audit_log( 'LARES_LOGIN_ERROR motivo=http_error http=' . $status );
        return false;
    }

    $data = json_decode( mk20_lares_extract_json( wp_remote_retrieve_body( $response ) ), true );
    if ( ! is_array( $data ) || empty( $data['token'] ) || ! is_string( $data['token'] ) ) {
        mk20_api_audit_log( 'LARES_LOGIN_ERROR motivo=respuesta_invalida' );
        return false;
    }

    set_transient( 'mk20_lares_token', $data['token'], 8 * HOUR_IN_SECONDS );
    mk20_api_audit_log( 'LARES_LOGIN_OK' );

    return $data['token'];
}

/**
 * Envía una petición POST JSON a wpapigetpdf/index.php.
 * Uso interno: preferir mk20_lares_request() (gestiona el token).
 *
 * @param string $url    URL completa del endpoint.
 * @param string $accion Valor del campo "request".
 * @param string $token  Token obtenido en el login.
 * @param array  $campos Campos adicionales del cuerpo JSON.
 * @return array|WP_Error Respuesta decodificada o error con detalles.
 */
function mk20_lares_post( $url, $accion, $token, $campos ) {
    $response = wp_remote_post( $url, array(
        'timeout'   => 20,
        'sslverify' => mk20_lares_sslverify(),
        'headers'   => array( 'Content-Type' => 'application/json' ),
        'body'    => wp_json_encode( array_merge( array(
            'request' => $accion,
            'token'   => $token,
        ), $campos ) ),
    ) );

    if ( is_wp_error( $response ) ) {
        mk20_api_audit_log( "LARES_API_ERROR accion={$accion} motivo=" . $response->get_error_message() );

        return new WP_Error(
            'mk20_lares_http',
            $response->get_error_message(),
            array( 'timeout' => ( 'http_request_failed' === $response->get_error_code() ) )
        );
    }

    $status = wp_remote_retrieve_response_code( $response );
    $data   = json_decode( mk20_lares_extract_json( wp_remote_retrieve_body( $response ) ), true );

    if ( 200 !== $status ) {
        $mensaje = ( is_array( $data ) && isset( $data['message'] ) && is_string( $data['message'] ) ) ? sanitize_text_field( $data['message'] ) : 'sin_mensaje';
        mk20_api_audit_log( "LARES_API_ERROR accion={$accion} http={$status} mensaje={$mensaje}" );

        return new WP_Error( 'mk20_lares_status', $mensaje, array( 'status' => (int) $status ) );
    }

    if ( ! is_array( $data ) ) {
        mk20_api_audit_log( "LARES_API_ERROR accion={$accion} motivo=respuesta_no_json" );
        return new WP_Error( 'mk20_lares_json', 'respuesta_no_json' );
    }

    return $data;
}

/**
 * Petición genérica a la API Lares con gestión automática de token.
 * Ante un fallo, hace un re-login y un único reintento (token caducado).
 *
 * @param string $accion Valor del campo "request" (formacion_list, generar_pdf).
 * @param array  $campos Campos adicionales del cuerpo JSON.
 * @return array|false Respuesta decodificada o false si falla.
 */
function mk20_lares_request( $accion, $campos = array() ) {
    $base = mk20_lares_get_base();
    if ( '' === $base ) {
        return false;
    }

    $token = mk20_lares_login();
    if ( false === $token ) {
        return false;
    }

    $resultado = mk20_lares_post( $base . '/wpapigetpdf/index.php', $accion, $token, $campos );
    if ( ! is_wp_error( $resultado ) ) {
        return $resultado;
    }

    // Solo se reintenta si el token pudo caducar (401/403). Un timeout o un
    // error de red no se reintentan para no duplicar la espera.
    $error_data = $resultado->get_error_data();
    $reintentar = is_array( $error_data ) && isset( $error_data['status'] ) && in_array( (int) $error_data['status'], array( 401, 403 ), true );

    if ( ! $reintentar ) {
        return false;
    }

    delete_transient( 'mk20_lares_token' );
    $token = mk20_lares_login( true );
    if ( false === $token ) {
        return false;
    }

    $resultado = mk20_lares_post( $base . '/wpapigetpdf/index.php', $accion, $token, $campos );

    return is_wp_error( $resultado ) ? false : $resultado;
}

/**
 * Lista las formaciones/diplomas de un alumno por su DNI (acción "formacion_list").
 * Nunca rompe la página: ante cualquier fallo devuelve array vacío y lo registra.
 *
 * @param string $dni DNI/NIF del alumno.
 * @return array Lista de arrays con idFormacion, idAlumno, FechaDiploma y titulo
 *               (titulo queda vacío hasta que Lares añada el campo).
 */
function mk20_lares_formacion_list( $dni ) {
    $dni = is_string( $dni ) ? trim( $dni ) : '';
    if ( '' === $dni ) {
        return array();
    }

    $data = mk20_lares_request( 'formacion_list', array( 'dni' => $dni ) );
    if ( ! is_array( $data ) || ! isset( $data['datos'] ) || ! is_array( $data['datos'] ) ) {
        return array();
    }

    $lista = array();
    foreach ( $data['datos'] as $item ) {
        if ( ! is_array( $item ) || ! isset( $item['idFormacion'] ) ) {
            continue;
        }
        $lista[] = array(
            'idFormacion'  => absint( $item['idFormacion'] ),
            'idAlumno'     => isset( $item['idAlumno'] ) ? absint( $item['idAlumno'] ) : 0,
            'FechaDiploma' => ( isset( $item['FechaDiploma'] ) && is_string( $item['FechaDiploma'] ) ) ? sanitize_text_field( $item['FechaDiploma'] ) : '',
            'titulo'       => ( isset( $item['nombreCurso'] ) && is_string( $item['nombreCurso'] ) ) ? sanitize_text_field( $item['nombreCurso'] ) : ( ( isset( $item['titulo'] ) && is_string( $item['titulo'] ) ) ? sanitize_text_field( $item['titulo'] ) : '' ),
        );
    }

    return $lista;
}

/**
 * Genera el diploma de una formación y lo devuelve decodificado (acción "generar_pdf").
 * El PDF se devuelve tal cual lo envía Lares (su diseño); no se guarda ni se modifica aquí.
 *
 * @param string $dni         DNI/NIF del alumno.
 * @param int    $idformacion Identificador de la formación.
 * @param int    $user_id     ID de WP para el registro de rechazados (0 si se desconoce).
 * @return array|false Array con 'nombre' y 'contenido' (binario PDF), o false si falla.
 */
function mk20_lares_generar_pdf( $dni, $idformacion, $user_id = 0 ) {
    $dni         = is_string( $dni ) ? trim( $dni ) : '';
    $idformacion = absint( $idformacion );
    if ( '' === $dni || 0 === $idformacion ) {
        return false;
    }

    $external_id = $dni . '_' . $idformacion;
    $titulo_tmp  = 'Formación Lares ' . $idformacion;

    $data = mk20_lares_request( 'generar_pdf', array(
        'dni'         => $dni,
        'idformacion' => $idformacion,
    ) );
    if ( ! is_array( $data ) || empty( $data['documento'] ) || ! is_string( $data['documento'] ) ) {
        mk20_api_audit_log( "LARES_PDF_ERROR dni={$dni} idformacion={$idformacion} motivo=respuesta_invalida" );
        mk20_store_rejected_pdf( $user_id, $external_id, $titulo_tmp, 'respuesta_invalida', '' );
        return false;
    }

    $contenido = base64_decode( $data['documento'], true );
    if ( false === $contenido || substr( $contenido, 0, 4 ) !== '%PDF' ) {
        mk20_api_audit_log( "LARES_PDF_ERROR dni={$dni} idformacion={$idformacion} motivo=no_es_pdf" );
        mk20_store_rejected_pdf( $user_id, $external_id, $titulo_tmp, 'no_es_pdf', '' );
        return false;
    }

    if ( strlen( $contenido ) > 10 * MB_IN_BYTES ) {
        mk20_api_audit_log( "LARES_PDF_ERROR dni={$dni} idformacion={$idformacion} motivo=pdf_demasiado_grande bytes=" . strlen( $contenido ) );
        mk20_store_rejected_pdf( $user_id, $external_id, $titulo_tmp, 'pdf_demasiado_grande', strlen( $contenido ) . ' bytes' );
        return false;
    }

    $nombre = ( isset( $data['nombre_documento'] ) && is_string( $data['nombre_documento'] ) ) ? sanitize_file_name( $data['nombre_documento'] ) : '';
    if ( '' === $nombre ) {
        $nombre = sprintf( 'diploma_%s_%d.pdf', sanitize_title( $dni ), $idformacion );
    }

    return array(
        'nombre'    => $nombre,
        'contenido' => $contenido,
    );
}
