<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class MK20_REST {

    public function __construct() {
        add_action( 'rest_api_init', [ $this, 'register_routes' ] );
        add_action( 'init', [ $this, 'add_rewrite_rule' ] );
        add_action( 'template_redirect', [ $this, 'handle_verificar_page' ], 0 );
        add_filter( 'bbp_is_page_public', [ $this, 'make_verification_public' ], 10, 2 );
        add_filter( 'bp_restrict_access_to_logged_in_members_only', [ $this, 'bypass_restriction' ] );
    }

    public function make_verification_public( $is_public, $page_id ) {
        if ( $this->is_verification_request() ) {
            return true;
        }
        return $is_public;
    }

    public function bypass_restriction( $restrict ) {
        if ( $this->is_verification_request() ) {
            return false;
        }
        return $restrict;
    }

    private function is_verification_request() {
        if ( is_admin() ) {
            return false;
        }
        $path = isset( $_SERVER['REQUEST_URI'] ) ? wp_parse_url( esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ), PHP_URL_PATH ) : '';
        return (bool) preg_match( '#/verificar(/?|/[a-f0-9]+/?)$#', $path );
    }

    public function add_rewrite_rule() {
        add_rewrite_rule( '^verificar/([a-f0-9]{12,})/?$', 'index.php?mk20_verificar=$matches[1]', 'top' );
        add_rewrite_tag( '%mk20_verificar%', '([a-f0-9]{12,})' );
    }

    private function get_logo_html() {
        $logo_id = get_theme_mod( 'custom_logo' );
        if ( $logo_id ) {
            $logo_url = wp_get_attachment_image_url( $logo_id, 'medium' );
            if ( $logo_url ) {
                return '<img src="' . esc_url( $logo_url ) . '" alt="' . esc_attr( get_bloginfo( 'name' ) ) . '" style="max-height:60px; margin-bottom:12px;">';
            }
        }
        $icon_url = get_site_icon_url( 128 );
        if ( $icon_url ) {
            return '<img src="' . esc_url( $icon_url ) . '" alt="' . esc_attr( get_bloginfo( 'name' ) ) . '" style="max-height:60px; margin-bottom:12px;">';
        }
        return '';
    }

    private function get_code_from_request() {
        $code = get_query_var( 'mk20_verificar' );
        if ( empty( $code ) ) {
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Pagina publica de verificacion de solo lectura; no cambia estado.
            $candidate = isset( $_GET['mk20_verificar'] ) ? sanitize_text_field( wp_unslash( $_GET['mk20_verificar'] ) ) : '';
            if ( preg_match( '#^[a-f0-9]{12,}$#', $candidate ) ) {
                $code = $candidate;
            }
        }
        if ( empty( $code ) ) {
            $path = isset( $_SERVER['REQUEST_URI'] ) ? wp_parse_url( esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ), PHP_URL_PATH ) : '';
            if ( preg_match( '#/verificar/([a-f0-9]{12,})/?$#', $path, $m ) ) {
                $code = $m[1];
            }
        }
        return $code;
    }

    public function handle_verificar_page() {
        if ( is_admin() ) {
            return;
        }

        $request_path = isset( $_SERVER['REQUEST_URI'] ) ? wp_parse_url( esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ), PHP_URL_PATH ) : '';
        if ( ! preg_match( '#/verificar(/?|/[a-f0-9]+/?)$#', $request_path ) ) {
            return;
        }

        $code = $this->get_code_from_request();
        header( 'X-Robots-Tag: noindex, nofollow' );

        if ( $this->is_rate_limited() ) {
            $this->render_rate_limited();
            exit;
        }

        if ( ! empty( $code ) && ctype_xdigit( $code ) && strlen( $code ) >= 12 ) {
            $data = $this->lookup_certificate( $code );
            $this->render_result( $data, $code );
            exit;
        }

        $this->render_form( $code );
        exit;
    }

    private function render_result( $data, $code ) {
        $logo_html = $this->get_logo_html();
        ?>
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <title><?php esc_html_e( 'Verificar Certificado', 'mk20-custom-certificates' ); ?></title>
            <style>
                * { margin: 0; padding: 0; box-sizing: border-box; }
                body {
                    font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
                    background: #f1f5f9;
                    display: flex; justify-content: center; align-items: center;
                    min-height: 100vh; padding: 20px;
                }
                .card {
                    background: #fff; border-radius: 12px;
                    box-shadow: 0 4px 24px rgba(0,0,0,0.1);
                    padding: 40px; max-width: 480px; width: 100%;
                    text-align: center;
                }
                .valido { color: #059669; }
                .invalido { color: #dc2626; }
                .icono { font-size: 64px; margin-bottom: 16px; }
                h1 { font-size: 24px; margin-bottom: 8px; }
                p { color: #64748b; margin-bottom: 4px; font-size: 14px; }
                .label { font-weight: 600; color: #334155; }
                hr { border: none; border-top: 1px solid #e2e8f0; margin: 20px 0; }
                .info { text-align: left; }
                .info p { font-size: 15px; margin-bottom: 8px; }
            </style>
        </head>
        <body>
            <div class="card">
                <?php echo wp_kses_post( $logo_html ); ?>
                <?php if ( $data ) : ?>
                    <div class="icono valido">&#10004;</div>
                    <h1 class="valido"><?php esc_html_e( 'Certificado VÁLIDO', 'mk20-custom-certificates' ); ?></h1>
                    <hr>
                    <div class="info">
                        <p><span class="label"><?php esc_html_e( 'Titular:', 'mk20-custom-certificates' ); ?></span> <?php echo esc_html( $data['titular'] ); ?></p>
                        <p><span class="label"><?php esc_html_e( 'Curso:', 'mk20-custom-certificates' ); ?></span> <?php echo esc_html( $data['curso'] ); ?></p>
                        <p><span class="label"><?php esc_html_e( 'Emitido:', 'mk20-custom-certificates' ); ?></span> <?php echo esc_html( $data['emitido'] ); ?></p>
                        <p><span class="label"><?php esc_html_e( 'Código:', 'mk20-custom-certificates' ); ?></span> <?php echo esc_html( $code ); ?></p>
                    </div>
                <?php else : ?>
                    <div class="icono invalido">&#10008;</div>
                    <h1 class="invalido"><?php esc_html_e( 'Certificado NO VÁLIDO', 'mk20-custom-certificates' ); ?></h1>
                    <hr>
                    <p><?php esc_html_e( 'El código introducido no coincide con ningún certificado registrado o ha sido eliminado.', 'mk20-custom-certificates' ); ?></p>
                <?php endif; ?>
                <hr>
                <p style="font-size:12px; color:#94a3b8;"><?php esc_html_e( 'Sistema de verificación de certificados Lares Navarra', 'mk20-custom-certificates' ); ?></p>
            </div>
        </body>
        </html>
        <?php
    }

    private function render_form( $prefilled_code = '' ) {
        $logo_html = $this->get_logo_html();
        ?>
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <title><?php esc_html_e( 'Verificar Certificado', 'mk20-custom-certificates' ); ?></title>
            <style>
                * { margin: 0; padding: 0; box-sizing: border-box; }
                body {
                    font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
                    background: #f1f5f9;
                    display: flex; justify-content: center; align-items: center;
                    min-height: 100vh; padding: 20px;
                }
                .card {
                    background: #fff; border-radius: 12px;
                    box-shadow: 0 4px 24px rgba(0,0,0,0.1);
                    padding: 40px; max-width: 420px; width: 100%;
                    text-align: center;
                }
                h1 { font-size: 20px; margin-bottom: 8px; }
                p { color: #64748b; margin-bottom: 20px; font-size: 14px; }
                input {
                    width: 100%; padding: 12px 16px; font-size: 18px;
                    border: 2px solid #e2e8f0; border-radius: 8px;
                    text-align: center; letter-spacing: 2px;
                    font-family: monospace; outline: none;
                }
                input:focus { border-color: #3b82f6; }
                button {
                    margin-top: 16px; width: 100%; padding: 12px;
                    background: #3b82f6; color: #fff; border: none;
                    border-radius: 8px; font-size: 16px; font-weight: 600;
                    cursor: pointer;
                }
                button:hover { background: #2563eb; }
            </style>
        </head>
        <body>
            <div class="card">
                <?php echo wp_kses_post( $logo_html ); ?>
                <h1><?php esc_html_e( 'Verificar Certificado', 'mk20-custom-certificates' ); ?></h1>
                <p><?php esc_html_e( 'Introduce el código ID que aparece en la parte inferior del certificado.', 'mk20-custom-certificates' ); ?></p>
                <form method="get" action="">
                    <input type="text" name="mk20_verificar" placeholder="Ej: d4d0aa13e726" value="<?php echo esc_attr( $prefilled_code ); ?>" required autocomplete="off" pattern="[a-f0-9]{12,}" title="12 caracteres hexadecimales">
                    <button type="submit"><?php esc_html_e( 'Verificar', 'mk20-custom-certificates' ); ?></button>
                </form>
                <p style="margin-top:20px; font-size:12px; color:#94a3b8;"><?php esc_html_e( 'Sistema de verificación de certificados Lares Navarra', 'mk20-custom-certificates' ); ?></p>
            </div>
        </body>
        </html>
        <?php
    }

    private function render_rate_limited() {
        $logo_html = $this->get_logo_html();
        ?>
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <title><?php esc_html_e( 'Verificar Certificado', 'mk20-custom-certificates' ); ?></title>
            <style>
                * { margin: 0; padding: 0; box-sizing: border-box; }
                body {
                    font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
                    background: #f1f5f9;
                    display: flex; justify-content: center; align-items: center;
                    min-height: 100vh; padding: 20px;
                }
                .card {
                    background: #fff; border-radius: 12px;
                    box-shadow: 0 4px 24px rgba(0,0,0,0.1);
                    padding: 40px; max-width: 420px; width: 100%;
                    text-align: center;
                }
                h1 { font-size: 20px; margin-bottom: 8px; color: #dc2626; }
                p { color: #64748b; margin-bottom: 20px; font-size: 14px; }
            </style>
        </head>
        <body>
            <div class="card">
                <?php echo wp_kses_post( $logo_html ); ?>
                <h1><?php esc_html_e( 'Demasiados intentos', 'mk20-custom-certificates' ); ?></h1>
                <p><?php esc_html_e( 'Se ha superado el límite de verificaciones. Espera un minuto e inténtalo de nuevo.', 'mk20-custom-certificates' ); ?></p>
                <p style="margin-top:20px; font-size:12px; color:#94a3b8;"><?php esc_html_e( 'Sistema de verificación de certificados Lares Navarra', 'mk20-custom-certificates' ); ?></p>
            </div>
        </body>
        </html>
        <?php
    }

    public function register_routes() {
        register_rest_route( 'mk20/v1', '/verificar', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'verify_certificate' ],
            'permission_callback' => '__return_true',
            'args'                => [
                'hash' => [
                    'required'          => true,
                    'sanitize_callback' => 'sanitize_text_field',
                ],
            ],
        ] );
    }

    public function verify_certificate( $request ) {
        header( 'X-Robots-Tag: noindex, nofollow' );

        if ( $this->is_rate_limited() ) {
            return new WP_REST_Response( [
                'valido' => false,
                'error'  => 'Demasiados intentos. Inténtalo de nuevo más tarde.',
            ], 429 );
        }

        $hash = $request->get_param( 'hash' );
        if ( empty( $hash ) || ! ctype_xdigit( $hash ) ) {
            return new WP_REST_Response( [
                'valido' => false,
                'error'  => 'Código inválido.',
            ], 400 );
        }

        $data = $this->lookup_certificate( $hash );

        if ( ! $data ) {
            return new WP_REST_Response( [
                'valido' => false,
                'error'  => 'Certificado no encontrado o fue eliminado.',
            ], 404 );
        }

        return new WP_REST_Response( array_merge( [ 'valido' => true ], $data ), 200 );
    }

    /**
     * Limita las peticiones públicas de verificación por IP (transient de 1 minuto).
     *
     * @return bool True si la IP superó el límite.
     */
    private function is_rate_limited() {
        $ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
        if ( empty( $ip ) ) {
            $ip = 'unknown';
        }

        $transient_key = 'mk20_verify_rl_' . md5( $ip );
        $count         = (int) get_transient( $transient_key );

        if ( $count >= 20 ) {
            return true;
        }

        set_transient( $transient_key, $count + 1, MINUTE_IN_SECONDS );

        return false;
    }

    /**
     * Busca un certificado por su código de verificación.
     * Usa la tabla indexada mk20_certs (code_hash) y cachea el resultado.
     *
     * @param string $code Código hexadecimal (12+ caracteres o SHA-256 completo).
     * @return array|null Datos del certificado o null si no existe.
     */
    private function lookup_certificate( $code ) {
        global $wpdb;

        $code = strtolower( $code );

        $cache_key = 'mk20_verify_' . md5( $code );
        $cached    = wp_cache_get( $cache_key, 'mk20_certs' );
        if ( false !== $cached ) {
            return $cached;
        }

        if ( strlen( $code ) === 64 ) {
            $row = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
                $wpdb->prepare(
                    "SELECT user_id, course_id FROM {$wpdb->prefix}mk20_certs WHERE code_hash = %s LIMIT 1",
                    $code
                )
            );
        } else {
            $row = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
                $wpdb->prepare(
                    "SELECT user_id, course_id FROM {$wpdb->prefix}mk20_certs WHERE code_hash LIKE %s LIMIT 1",
                    $wpdb->esc_like( $code ) . '%'
                )
            );
        }

        if ( ! $row ) {
            wp_cache_set( $cache_key, null, 'mk20_certs', 300 );
            return null;
        }

        $user_id   = absint( $row->user_id );
        $course_id = absint( $row->course_id );

        $user_info    = get_userdata( $user_id );
        $course_title = get_the_title( $course_id );
        $cert_ts      = get_user_meta( $user_id, '_mk20_cert_ts_' . $course_id, true );

        if ( ! $user_info || ! $course_title ) {
            wp_cache_set( $cache_key, null, 'mk20_certs', 300 );
            return null;
        }

        $result = [
            'titular' => $user_info->display_name,
            'curso'   => $course_title,
            'emitido' => $cert_ts ? get_date_from_gmt( $cert_ts, 'd/m/Y H:i:s' ) : '',
        ];

        wp_cache_set( $cache_key, $result, 'mk20_certs', 600 );

        return $result;
    }
}
