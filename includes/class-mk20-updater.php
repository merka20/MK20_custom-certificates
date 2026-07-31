<?php
/**
 * MK20 Updater: inyecta en el listado de plugins de WordPress el aviso
 * de nueva version publicada en GitHub y permite actualizar desde el panel.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class MK20_Updater {

    const MANIFEST_URL = 'https://raw.githubusercontent.com/merka20/MK20_custom-certificates/master/mk20-update.json';
    const PLUGIN_SLUG  = 'mk20-custom-certificates';
    const PLUGIN_FILE  = 'mk20-custom-certificates/mk20-custom-certificates.php';
    const CACHE_KEY    = 'mk20_update_manifest';

    public static function iniciar() {
        add_filter( 'pre_set_site_transient_update_plugins', array( __CLASS__, 'inject_update' ) );
        add_filter( 'plugins_api', array( __CLASS__, 'plugins_api_info' ), 20, 3 );
    }

    public static function get_manifest() {
        $cached = get_transient( self::CACHE_KEY );
        if ( false !== $cached ) {
            return $cached;
        }

        $manifest_url = self::MANIFEST_URL;
        if ( ! wp_http_validate_url( $manifest_url ) ) {
            return null;
        }

        $response = wp_remote_get( $manifest_url, array( 'timeout' => 10 ) );
        if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
            return null;
        }

        $body = json_decode( wp_remote_retrieve_body( $response ), true );
        if ( ! is_array( $body ) || empty( $body['version'] ) || ! is_string( $body['version'] ) ) {
            return null;
        }

        $manifest = array(
            'version'      => sanitize_text_field( $body['version'] ),
            'requires'     => isset( $body['requires'] ) ? sanitize_text_field( $body['requires'] ) : '',
            'tested'       => isset( $body['tested'] ) ? sanitize_text_field( $body['tested'] ) : '',
            'requires_php' => isset( $body['requires_php'] ) ? sanitize_text_field( $body['requires_php'] ) : '',
            'download_url' => isset( $body['download_url'] ) ? esc_url_raw( $body['download_url'] ) : '',
            'fpdf'         => isset( $body['fpdf'] ) ? sanitize_text_field( $body['fpdf'] ) : '',
            'changelog'    => isset( $body['changelog'] ) ? sanitize_textarea_field( $body['changelog'] ) : '',
        );

        set_transient( self::CACHE_KEY, $manifest, 12 * HOUR_IN_SECONDS );

        return $manifest;
    }

    public static function get_installed_version() {
        $default_headers = array( 'Version' => 'Version' );
        $data            = get_file_data( WP_PLUGIN_DIR . '/' . self::PLUGIN_FILE, $default_headers );

        return isset( $data['Version'] ) && $data['Version'] ? $data['Version'] : '';
    }

    public static function inject_update( $transient ) {
        if ( ! is_object( $transient ) ) {
            return $transient;
        }

        $manifest = self::get_manifest();
        if ( empty( $manifest['version'] ) || empty( $manifest['download_url'] ) ) {
            return $transient;
        }
        if ( ! wp_http_validate_url( $manifest['download_url'] ) ) {
            return $transient;
        }

        $installed = self::get_installed_version();
        if ( ! $installed || version_compare( $manifest['version'], $installed, '<=' ) ) {
            return $transient;
        }

        $transient->response[ self::PLUGIN_FILE ] = (object) array(
            'slug'         => self::PLUGIN_SLUG,
            'plugin'       => self::PLUGIN_FILE,
            'new_version'  => $manifest['version'],
            'url'          => 'https://github.com/merka20/MK20_custom-certificates',
            'package'      => $manifest['download_url'],
            'requires'     => $manifest['requires'],
            'tested'       => $manifest['tested'],
            'requires_php' => $manifest['requires_php'],
        );

        return $transient;
    }

    public static function plugins_api_info( $result, $action, $args ) {
        if ( 'plugin_information' !== $action ) {
            return $result;
        }
        if ( empty( $args->slug ) || self::PLUGIN_SLUG !== $args->slug ) {
            return $result;
        }

        $manifest = self::get_manifest();
        if ( empty( $manifest['version'] ) ) {
            return $result;
        }

        $sections = array(
            'description' => __( 'Genera certificados PDF de dos caras (anverso y reverso) personalizados mediante FPDF al completar cursos de LearnDash.', 'mk20-custom-certificates' ),
            'changelog'   => ! empty( $manifest['changelog'] ) ? wp_kses_post( $manifest['changelog'] ) : __( 'Changelog disponible en GitHub.', 'mk20-custom-certificates' ),
        );

        if ( ! empty( $manifest['fpdf'] ) ) {
            $sections['fpdf'] = __( 'Librería FPDF integrada: ', 'mk20-custom-certificates' ) . '<strong>' . esc_html( $manifest['fpdf'] ) . '</strong>.';
        }

        return (object) array(
            'name'          => 'MK20 Custom Certificates',
            'slug'          => self::PLUGIN_SLUG,
            'version'       => $manifest['version'],
            'author'        => '<a href="https://merka20.com">Merka2.0</a>',
            'homepage'      => 'https://github.com/merka20/MK20_custom-certificates',
            'requires'      => $manifest['requires'],
            'tested'        => $manifest['tested'],
            'requires_php'  => $manifest['requires_php'],
            'download_link' => $manifest['download_url'],
            'sections'      => $sections,
        );
    }
}
