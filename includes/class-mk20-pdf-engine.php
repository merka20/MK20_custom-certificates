<?php
/**
 * Motor de renderizado PDF para MK20 Custom Certificates
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly.
}

class MK20_PDF_Engine {

    private $last_integrity_hash = '';
    private $last_timestamp = '';
    private $last_verify_hash = '';

    public function __construct() {
        if ( ! class_exists( 'FPDF' ) ) {
            $fpdf_path = MK20_CERT_PATH . 'lib/fpdf/fpdf.php';
            if ( file_exists( $fpdf_path ) ) {
                require_once $fpdf_path;
            }
        }
    }

    public function get_last_integrity_hash() {
        return $this->last_integrity_hash;
    }

    public function get_last_timestamp() {
        return $this->last_timestamp;
    }

    public function get_last_verify_hash() {
        return $this->last_verify_hash;
    }

    /**
     * Genera un certificado en PDF de dos caras y lo guarda en el directorio de subidas.
     *
     * @param string $student_name Nombre completo del estudiante.
     * @param string $course_title Título del curso.
     * @param string $date Fecha de emisión del certificado.
     * @param int    $user_id ID de usuario (para nombrar el archivo).
     * @param int    $course_id ID del curso (para nombrar el archivo).
     * @return string|false Ruta completa del archivo PDF generado, o false en caso de error.
     */
    public function generate( 
        $student_name, 
        $course_title, 
        $date, 
        $user_id, 
        $course_id, 
        $dni = '', 
        $company = '', 
        $cif = '', 
        $course_code = '', 
        $start_date = '', 
        $end_date = '', 
        $duration = '', 
        $modality = '',
        $course_contents = '' 
    ) {
        if ( ! class_exists( 'FPDF' ) ) {
            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
                error_log( 'MK20 Custom Certificates: La librería FPDF no está disponible.' );
            }
            return false;
        }

        // Obtener configuraciones guardadas combinadas con valores por defecto
        $options = wp_parse_args( get_option( 'mk20_cert_settings', [] ), mk20_get_default_settings() );

        // Configurar valores predeterminados de las plantillas (coincidiendo con los archivos reales)
        $default_front = MK20_CERT_PATH . 'templates/diploma-anverso.jpg';
        $default_back  = MK20_CERT_PATH . 'templates/diploma-reverso.jpg';

        $front_img = ! empty( $options['front_image'] ) ? $options['front_image'] : $default_front;
        $back_img  = ! empty( $options['back_image'] ) ? $options['back_image'] : $default_back;

        $front_img = $this->resolve_image_path( $front_img );
        $back_img  = $this->resolve_image_path( $back_img );

        // Recuperar dinámicamente los campos si no se han pasado explícitamente (flujo automático)
        if ( empty( $dni ) && $user_id > 0 ) {
            $dni = mk20_get_buddyboss_field( $user_id, $options['bb_field_dni'], $options['fallback_dni'] );
        } elseif ( empty( $dni ) ) {
            $dni = $options['fallback_dni'];
        }

        if ( empty( $company ) && $user_id > 0 ) {
            $company = mk20_get_buddyboss_field( $user_id, $options['bb_field_company'], $options['fallback_company'] );
        } elseif ( empty( $company ) ) {
            $company = $options['fallback_company'];
        }

        if ( empty( $cif ) && $user_id > 0 ) {
            $cif = mk20_get_buddyboss_field( $user_id, $options['bb_field_cif'], $options['fallback_cif'] );
        } elseif ( empty( $cif ) ) {
            $cif = $options['fallback_cif'];
        }

        if ( empty( $course_code ) && $course_id > 0 ) {
            $course_code = mk20_get_course_meta( $course_id, '_mk20_course_code', $options['fallback_course_code'] );
        } elseif ( empty( $course_code ) ) {
            $course_code = $options['fallback_course_code'];
        }

        if ( empty( $start_date ) && $course_id > 0 ) {
            $start_date = mk20_get_course_meta( $course_id, '_mk20_course_start_date', $options['fallback_course_start_date'] );
        } elseif ( empty( $start_date ) ) {
            $start_date = $options['fallback_course_start_date'];
        }

        if ( empty( $end_date ) && $course_id > 0 ) {
            $end_date = mk20_get_course_meta( $course_id, '_mk20_course_end_date', $options['fallback_course_end_date'] );
        } elseif ( empty( $end_date ) ) {
            $end_date = $options['fallback_course_end_date'];
        }

        if ( empty( $duration ) && $course_id > 0 ) {
            $duration = mk20_get_course_meta( $course_id, '_mk20_course_duration', $options['fallback_course_duration'] );
        } elseif ( empty( $duration ) ) {
            $duration = $options['fallback_course_duration'];
        }

        if ( empty( $modality ) && $course_id > 0 ) {
            $modality = mk20_get_course_meta( $course_id, '_mk20_course_modality', $options['fallback_course_modality'] );
        } elseif ( empty( $modality ) ) {
            $modality = $options['fallback_course_modality'];
        }

        if ( empty( $course_contents ) && $course_id > 0 ) {
            $course_contents = mk20_get_course_meta( $course_id, '_mk20_course_contents', $options['fallback_course_contents'] );
        } elseif ( empty( $course_contents ) ) {
            $course_contents = $options['fallback_course_contents'];
        }

        // Cargar coordenadas de los ajustes (ya combinados con valores por defecto)

        // 1. Nombre y DNI del alumno
        $name_x      = floatval( $options['name_x'] );
        $name_y      = floatval( $options['name_y'] );
        $name_size   = intval( $options['name_size'] );
        $name_color  = $options['name_color'];
        $name_center = ! empty( $options['name_center'] );

        // 2. Empresa y CIF
        $company_line_x      = floatval( $options['company_line_x'] );
        $company_line_y      = floatval( $options['company_line_y'] );
        $company_line_size   = intval( $options['company_line_size'] );
        $company_line_color  = $options['company_line_color'];
        $company_line_center = ! empty( $options['company_line_center'] );

        // 3. Título del curso (Acción Formativa en el Anverso)
        $front_course_x      = floatval( $options['front_course_x'] );
        $front_course_y      = floatval( $options['front_course_y'] );
        $front_course_size   = intval( $options['front_course_size'] );
        $front_course_color  = $options['front_course_color'];
        $front_course_center = ! empty( $options['front_course_center'] );

        // 4. Coordenadas individuales para los huecos de la línea Código y Fechas
        $details_y     = floatval( $options['details_y'] );
        $code_x        = floatval( $options['code_x'] );
        $start_date_x  = floatval( $options['start_date_x'] );
        $end_date_x    = floatval( $options['end_date_x'] );
        $details_size  = intval( $options['details_size'] );
        $details_color = $options['details_color'];

        // 5. Coordenadas individuales para los huecos de la línea Duración y Modalidad
        $duration_y     = floatval( $options['duration_y'] );
        $duration_x     = floatval( $options['duration_x'] );
        $modality_x     = floatval( $options['modality_x'] );
        $duration_size  = intval( $options['duration_size'] );
        $duration_color = $options['duration_color'];

        // 6. Fecha de expedición (Fecha de emisión abajo al centro)
        $date_x      = floatval( $options['date_x'] );
        $date_y      = floatval( $options['date_y'] );
        $date_size   = intval( $options['date_size'] );
        $date_color  = $options['date_color'];
        $date_center = ! empty( $options['date_center'] );

        // 7. Contenidos del curso (Reverso)
        $contents_x      = floatval( $options['contents_x'] );
        $contents_y      = floatval( $options['contents_y'] );
        $contents_size   = intval( $options['contents_size'] );
        $contents_color  = $options['contents_color'];
        $contents_center = ! empty( $options['contents_center'] );

        try {
            // Inicializar FPDF: Landscape (L), milímetros (mm), tamaño A4 (297x210 mm)
            $pdf = new FPDF( 'L', 'mm', 'A4' );
            $pdf->SetMargins( 0, 0, 0 );
            $pdf->SetAutoPageBreak( false );

            // --- CARA 1: ANVERSO ---
            $pdf->AddPage();
            if ( file_exists( $front_img ) ) {
                $pdf->Image( $front_img, 0, 0, 297, 210 );
            } else {
                if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                    // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
                    error_log( "MK20 Custom Certificates: Plantilla anverso no encontrada en $front_img" );
                }
            }

            // 1. Nombre y DNI del alumno
            $student_segments = [
                [ 'text' => 'D./DÑA. ', 'bold' => false, 'color' => $name_color ],
                [ 'text' => $student_name, 'bold' => true, 'color' => $name_color ],
                [ 'text' => ' CON DNI/NIE ', 'bold' => false, 'color' => $name_color ],
                [ 'text' => $dni, 'bold' => true, 'color' => $name_color ],
            ];
            $this->write_mixed_line( $pdf, $name_x, $name_y, 'Arial', $name_size, $name_center, 297, $student_segments );

            // 2. Empresa y CIF
            $company_segments = [
                [ 'text' => $company, 'bold' => true, 'color' => $company_line_color ],
                [ 'text' => ' CON CIF ', 'bold' => false, 'color' => $company_line_color ],
                [ 'text' => $cif, 'bold' => true, 'color' => $company_line_color ],
            ];
            $this->write_mixed_line( $pdf, $company_line_x, $company_line_y, 'Arial', $company_line_size, $company_line_center, 297, $company_segments );

            // 3. Título del curso (Acción Formativa en el Anverso)
            $this->write_text( $pdf, $course_title, $front_course_x, $front_course_y, 'Arial', 'B', $front_course_size, $front_course_color, $front_course_center );

            // 4. Código AF, Fecha Inicio y Fecha Fin sobre los huecos
            $this->write_text( $pdf, $course_code, $code_x, $details_y, 'Arial', 'B', $details_size, $details_color, false );
            $this->write_text( $pdf, $start_date, $start_date_x, $details_y, 'Arial', 'B', $details_size, $details_color, false );
            $this->write_text( $pdf, $end_date, $end_date_x, $details_y, 'Arial', 'B', $details_size, $details_color, false );

            // 5. Duración y Modalidad sobre los huecos
            $this->write_text( $pdf, $duration, $duration_x, $duration_y, 'Arial', 'B', $duration_size, $duration_color, false );
            $this->write_text( $pdf, $modality, $modality_x, $duration_y, 'Arial', 'B', $duration_size, $duration_color, false );

            // 6. Fecha de expedición (Fecha de emisión abajo al centro)
            $this->write_text( $pdf, $date, $date_x, $date_y, 'Arial', '', $date_size, $date_color, $date_center );

            // --- CARA 2: REVERSO ---
            $pdf->AddPage();
            if ( file_exists( $back_img ) ) {
                $pdf->Image( $back_img, 0, 0, 297, 210 );
            } else {
                if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                    // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
                    error_log( "MK20 Custom Certificates: Plantilla reverso no encontrada en $back_img" );
                }
            }

            // Contenidos del Curso en el Reverso (lista numerada, alineada a la izquierda)
            $this->write_numbered_list( $pdf, $course_contents, $contents_x, $contents_y, 'Arial', $contents_size, $contents_color, false, 297, 7 );

            // Pie de integridad: hash completo + timestamp + URL + QR
            $this->last_verify_hash = hash( 'sha256', $user_id . '|' . $course_id . '|' . $student_name . '|' . $course_title . '|' . $date );
            $footer_hash   = 'HASH: ' . $this->last_verify_hash;
            $footer_ts     = 'TS: ' . gmdate( 'Y-m-d H:i:s \U\T\C' );
            $verify_page   = home_url( '/verificar/' );

            $this->write_text( $pdf, $footer_hash, 10, 197, 'Arial', '', 6, '#666666', false );
            $this->write_text( $pdf, $footer_ts, 10, 201, 'Arial', '', 6, '#666666', false );
            $short_code  = substr( $this->last_verify_hash, 0, 12 );
            $this->write_text( $pdf, $verify_page . $short_code, 10, 205, 'Arial', '', 6, '#666666', false );
            $qr_data    = home_url( '/verificar/' . $short_code );
            $qr_url  = 'https://api.qrserver.com/v1/create-qr-code/?size=100x100&data=' . urlencode( $qr_data );
            $qr_temp = download_url( $qr_url, 5 );
            if ( ! is_wp_error( $qr_temp ) ) {
                $pdf->Image( $qr_temp, 257, 188, 20, 20 );
                unlink( $qr_temp );
            }

            // Crear el directorio en WordPress si no existe
            $upload_dir = wp_upload_dir();
            $cert_dir   = $upload_dir['basedir'] . '/mk20-certificates';
            if ( ! file_exists( $cert_dir ) ) {
                wp_mkdir_p( $cert_dir );
            }

            $slug_name  = sanitize_title( $student_name );
            $slug_course = sanitize_title( $course_title );
            $slug_date   = sanitize_title( $date );
            $slug_name   = mb_substr( $slug_name, 0, 30 );
            $slug_course = mb_substr( $slug_course, 0, 40 );
            $filename    = sprintf( 'certificado_%s_%s_%s.pdf', $slug_course, $slug_name, $slug_date );
            $filepath  = $cert_dir . '/' . $filename;

            $pdf->SetTitle( sprintf( 'Certificado: %s', $course_title ) );
            $pdf->SetSubject( 'Certificado de formacion' );
            $pdf->SetAuthor( $student_name );
            $pdf->SetCreator( 'MK20 Custom Certificates' );
            $pdf->SetKeywords( sprintf(
                'certificado,%s,%s,sha256',
                sanitize_title( $course_title ),
                sanitize_title( $student_name )
            ) );

            $pdf->Output( 'F', $filepath );

            $this->last_integrity_hash = hash_file( 'sha256', $filepath );
            $this->last_timestamp      = gmdate( 'Y-m-d\TH:i:s\Z' );

            return $filepath;

        } catch ( Exception $e ) {
            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
                error_log( 'MK20 Custom Certificates PDF Generation Error: ' . $e->getMessage() );
            }
            return false;
        }
    }

    /**
     * Escribe un texto en FPDF aplicando formato, color y soporte para acentos
     */
    private function write_text( $pdf, $text, $x, $y, $font, $style, $size, $hex_color, $center = false, $page_width = 297 ) {
        $pdf->SetFont( $font, $style, $size );

        list( $r, $g, $b ) = $this->hex_to_rgb( $hex_color );
        $pdf->SetTextColor( $r, $g, $b );

        $encoded_text = $this->encode_text( $text );

        if ( $center ) {
            $text_width = $pdf->GetStringWidth( $encoded_text );
            $x = ( $page_width - $text_width ) / 2;
        }

        $pdf->Text( $x, $y, $encoded_text );
    }

    /**
     * Convierte color HEX (#FFFFFF o FFFFFF) a un array RGB [R, G, B]
     */
    private function hex_to_rgb( $hex ) {
        if ( empty( $hex ) || ! is_string( $hex ) ) {
            return [ 0, 0, 0 ];
        }
        $hex = str_replace( '#', '', $hex );
        if ( strlen( $hex ) === 3 ) {
            $r = hexdec( substr( $hex, 0, 1 ) . substr( $hex, 0, 1 ) );
            $g = hexdec( substr( $hex, 1, 1 ) . substr( $hex, 1, 1 ) );
            $b = hexdec( substr( $hex, 2, 1 ) . substr( $hex, 2, 1 ) );
        } else {
            $r = hexdec( substr( $hex, 0, 2 ) );
            $g = hexdec( substr( $hex, 2, 2 ) );
            $b = hexdec( substr( $hex, 4, 2 ) );
        }
        return [ $r, $g, $b ];
    }

    /**
     * Resuelve la ruta absoluta del archivo a partir de una URL si pertenece a la misma instalación de WordPress
     */
    private function resolve_image_path( $image_url ) {
        if ( empty( $image_url ) ) {
            return '';
        }

        // Si es una ruta local del sistema, retornarla directamente
        if ( file_exists( $image_url ) ) {
            return $image_url;
        }

        // Si es una URL del sitio, intentar resolver la ruta física en base a la biblioteca multimedia
        $upload_dir = wp_upload_dir();
        $base_url   = $upload_dir['baseurl'];
        $base_dir   = $upload_dir['basedir'];

        if ( strpos( $image_url, $base_url ) === 0 ) {
            $relative_path = str_replace( $base_url, '', $image_url );
            $local_path    = $base_dir . $relative_path;
            if ( file_exists( $local_path ) ) {
                return $local_path;
            }
        }

        // Si no se encuentra localmente o no coincide la base, retornamos el string tal cual (por si FPDF puede abrirlo como URL remota)
        return $image_url;
    }

    /**
     * Escribe una línea de texto con estilos y colores mixtos en FPDF
     */
    private function write_mixed_line( $pdf, $x, $y, $font, $size, $center, $page_width, $segments ) {
        $total_width = 0;
        $encoded_segments = [];
        
        foreach ( $segments as $seg ) {
            $style = ! empty( $seg['bold'] ) ? 'B' : '';
            $pdf->SetFont( $font, $style, $size );
            
            $encoded_text = $this->encode_text( $seg['text'] );
            
            $w = $pdf->GetStringWidth( $encoded_text );
            $total_width += $w;
            
            $encoded_segments[] = [
                'text'  => $encoded_text,
                'bold'  => $seg['bold'],
                'color' => $seg['color'],
                'width' => $w,
            ];
        }
        
        if ( $center ) {
            $current_x = ( $page_width - $total_width ) / 2;
        } else {
            $current_x = $x;
        }
        
        foreach ( $encoded_segments as $eseg ) {
            $style = $eseg['bold'] ? 'B' : '';
            $pdf->SetFont( $font, $style, $size );
            
            list( $r, $g, $b ) = $this->hex_to_rgb( $eseg['color'] );
            $pdf->SetTextColor( $r, $g, $b );
            
            $pdf->Text( $current_x, $y, $eseg['text'] );
            $current_x += $eseg['width'];
        }
    }

    /**
     * Escribe una lista numerada a partir de un texto multilínea
     */
    private function write_numbered_list( $pdf, $text, $x, $y, $font, $size, $hex_color, $center, $page_width, $line_spacing ) {
        if ( empty( $text ) || ! is_string( $text ) ) {
            return;
        }

        list( $r, $g, $b ) = $this->hex_to_rgb( $hex_color );
        $pdf->SetTextColor( $r, $g, $b );

        $lines = explode( "\n", str_replace( "\r\n", "\n", $text ) );
        $lines = array_filter( $lines, function( $l ) { return trim( $l ) !== ''; } );
        $lines = array_values( $lines );

        $current_y = $y;

        foreach ( $lines as $line ) {
            $label = '• ' . trim( $line );
            $this->write_text( $pdf, $label, $x, $current_y, $font, '', $size, $hex_color, $center, $page_width );
            $current_y += $size * 0.4;
        }
    }

    private function encode_text( $text ) {
        if ( ! is_string( $text ) || $text === '' ) {
            return '';
        }

        $encoded = iconv( 'UTF-8', 'windows-1252//TRANSLIT', $text );
        if ( $encoded !== false ) {
            return $encoded;
        }

        $encoded = mb_convert_encoding( $text, 'Windows-1252', 'UTF-8' );
        if ( $encoded !== false ) {
            return $encoded;
        }

        return $text;
    }
}
