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
 * Obtiene los metadatos de un curso de LearnDash.
 *
 * @param int    $course_id ID del curso.
 * @param string $meta_key  Clave del meta.
 * @param string $fallback  Valor de respaldo.
 * @return string Valor del meta o el fallback.
 */
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
    ];
}

