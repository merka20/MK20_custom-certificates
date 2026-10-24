<?php
/**
 * Integración con LearnDash: enlace y aviso del certificado.
 *
 * @package MK20_Custom_Certificates
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Sustituye el certificado nativo de LearnDash por el PDF generado por MK20.
 *
 * LearnDash solo aplica el filtro del enlace cuando el curso está completado.
 * Si no hay PDF de MK20 se devuelve una cadena vacía para no ofrecer un botón
 * que llevaría a un certificado en blanco.
 */
class MK20_Learndash {

    /**
     * Registra los hooks de LearnDash.
     */
    public function __construct() {
        add_filter( 'learndash_course_certificate_link', [ $this, 'filtrar_enlace' ], 10, 3 );
        add_action( 'learndash-course-certificate-link-before', [ $this, 'mostrar_aviso' ], 10, 2 );
    }

    /**
     * Devuelve la URL de descarga del PDF de MK20 para un curso y usuario.
     *
     * @param int $course_id ID del curso.
     * @param int $user_id   ID del usuario titular (0 = usuario actual).
     * @return string URL con nonce, o cadena vacía si no hay PDF disponible.
     */
    public static function url_descarga( $course_id, $user_id = 0 ) {
        $course_id = absint( $course_id );
        $user_id   = $user_id ? absint( $user_id ) : get_current_user_id();

        if ( ! $course_id || ! $user_id ) {
            return '';
        }

        $cert_path = get_user_meta( $user_id, '_mk20_cert_path_' . $course_id, true );
        if ( ! $cert_path || ! file_exists( $cert_path ) ) {
            return '';
        }

        $link = add_query_arg(
            'course_id',
            $course_id,
            admin_url( 'admin-post.php?action=mk20_download_cert' )
        );

        // Un administrador puede estar viendo el certificado de otra persona.
        if ( $user_id !== get_current_user_id() && current_user_can( 'manage_options' ) ) {
            $link = add_query_arg( 'user_id', $user_id, $link );
        }

        return wp_nonce_url( $link, 'mk20_download_cert_' . $course_id );
    }

    /**
     * Sustituye el enlace del certificado de LearnDash por la descarga de MK20.
     *
     * @param string $url       Enlace original de LearnDash.
     * @param int    $course_id ID del curso.
     * @param int    $user_id   ID del usuario titular.
     * @return string
     */
    public function filtrar_enlace( $url, $course_id, $user_id ) {
        return self::url_descarga( $course_id, $user_id );
    }

    /**
     * Pinta el aviso de certificado en cursos que NO tienen certificado de
     * LearnDash asignado (en los que sí lo tienen, el tema ya pinta el suyo
     * con el enlace de MK20).
     *
     * @param int $course_id ID del curso.
     * @param int $user_id   ID del usuario.
     */
    public function mostrar_aviso( $course_id, $user_id ) {
        if ( ! function_exists( 'learndash_get_setting' ) || ! function_exists( 'learndash_course_status' ) || ! function_exists( 'learndash_get_template_part' ) ) {
            return;
        }

        // El curso tiene certificado de LearnDash: el tema ya muestra su banner.
        if ( ! empty( learndash_get_setting( $course_id, 'certificate' ) ) ) {
            return;
        }

        if ( 'completed' !== learndash_course_status( $course_id, $user_id, true ) ) {
            return;
        }

        $url = self::url_descarga( $course_id, $user_id );
        if ( ! $url ) {
            return;
        }

        learndash_get_template_part(
            'modules/alert.php',
            [
                'type'    => 'success ld-alert-certificate',
                'icon'    => 'certificate',
                'message' => __( '¡Has obtenido un certificado!', 'mk20-custom-certificates' ),
                'button'  => [
                    'url'    => $url,
                    'icon'   => 'download',
                    'label'  => __( 'Descargar certificado', 'mk20-custom-certificates' ),
                    'target' => '_new',
                ],
            ],
            true
        );
    }
}
