<?php
/**
 * Panel de Ajustes del Backend para MK20 Custom Certificates
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly.
}

class MK20_Admin {

    /**
     * Constructor del panel de administración.
     */
    public function __construct() {
        // Registrar menús y ajustes
        add_action( 'admin_menu', [ $this, 'add_settings_page' ] );
        add_action( 'admin_init', [ $this, 'register_settings' ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_media_uploader' ] );
        add_action( 'admin_notices', [ $this, 'show_rejected_notice' ] );

        // Procesar acción de generación de PDF de prueba
        add_action( 'admin_post_mk20_preview_cert', [ $this, 'generate_preview_pdf' ] );

        // Meta Box para cursos de LearnDash (sfwd-courses)
        add_action( 'add_meta_boxes', [ $this, 'add_course_meta_box' ] );
        add_action( 'save_post_sfwd-courses', [ $this, 'save_course_meta' ] );
    }

    /**
     * Añade el menú principal y subpáginas
     */
    public function add_settings_page() {
        add_menu_page(
            __( 'Certificados cursos', 'mk20-custom-certificates' ),
            __( 'Certificados cursos', 'mk20-custom-certificates' ),
            'manage_options',
            'mk20-certificates',
            [ $this, 'render_all_certificates_page' ],
            'dashicons-awards',
            80
        );

        add_submenu_page(
            'mk20-certificates',
            __( 'Todos los Certificados', 'mk20-custom-certificates' ),
            __( 'Todos los Certificados', 'mk20-custom-certificates' ),
            'manage_options',
            'mk20-certificates',
            [ $this, 'render_all_certificates_page' ]
        );

        add_submenu_page(
            'mk20-certificates',
            __( 'Ajustes', 'mk20-custom-certificates' ),
            __( 'Ajustes', 'mk20-custom-certificates' ),
            'manage_options',
            'mk20-certificates-settings',
            [ $this, 'render_settings_page' ]
        );
    }

    /**
     * Encola el framework multimedia de WordPress para la subida de imágenes
     */
    public function enqueue_media_uploader( $hook ) {
        if ( 'certificates_page_mk20-certificates-settings' !== $hook && 'toplevel_page_mk20-certificates' !== $hook ) {
            return;
        }
        wp_enqueue_media();
    }

    public function show_rejected_notice() {
        $rejected = get_option( 'mk20_rejected_certificates', array() );
        if ( empty( $rejected ) ) {
            return;
        }

        $settings_url = admin_url( 'options-general.php?page=mk20-certificates' );
        ?>
        <div class="notice notice-warning is-dismissible">
            <p>
                <strong><?php esc_html_e( 'MK20 Certificados:', 'mk20-custom-certificates' ); ?></strong>
                <?php
                /* translators: %d: number of rejected external certificates. */
                echo esc_html( sprintf( _n( 'Hay %d certificado externo rechazado por seguridad.', 'Hay %d certificados externos rechazados por seguridad.', count( $rejected ), 'mk20-custom-certificates' ), count( $rejected ) ) );
                ?>
                <a href="<?php echo esc_url( $settings_url ); ?>"><?php esc_html_e( 'Ver detalles', 'mk20-custom-certificates' ); ?></a>
            </p>
        </div>
        <?php
    }

    /**
     * Registra los ajustes del plugin
     */
    public function register_settings() {
        register_setting(
            'mk20_cert_settings_group',
            'mk20_cert_settings',
            [
                'type'              => 'array',
                'sanitize_callback' => [ $this, 'sanitize_settings' ],
                'default'           => mk20_get_default_settings(),
            ]
        );
    }

    /**
     * Sanitiza los inputs guardados en la base de datos
     */
    public function sanitize_settings( $input ) {
        $defaults = mk20_get_default_settings();

        if ( ! is_array( $input ) ) {
            return $defaults;
        }

        $output = [];

        // Rutas de las imágenes
        $output['front_image'] = isset( $input['front_image'] ) ? esc_url_raw( $input['front_image'] ) : $defaults['front_image'];
        $output['back_image']  = isset( $input['back_image'] )  ? esc_url_raw( $input['back_image'] )  : $defaults['back_image'];

        // Mapeo de campos de BuddyBoss (xprofile)
        $bb_text = [ 'bb_field_dni', 'bb_field_company', 'bb_field_cif' ];
        foreach ( $bb_text as $f ) {
            $output[ $f ] = isset( $input[ $f ] ) ? sanitize_text_field( $input[ $f ] ) : $defaults[ $f ];
        }

        // Valores genéricos de respaldo (fallbacks)
        $fb_text = [ 'fallback_dni', 'fallback_company', 'fallback_cif', 'fallback_course_code', 'fallback_course_duration', 'fallback_course_modality', 'fallback_course_start_date', 'fallback_course_end_date' ];
        foreach ( $fb_text as $f ) {
            $output[ $f ] = isset( $input[ $f ] ) ? sanitize_text_field( $input[ $f ] ) : $defaults[ $f ];
        }
        $output['fallback_course_contents'] = isset( $input['fallback_course_contents'] ) ? sanitize_textarea_field( $input['fallback_course_contents'] ) : $defaults['fallback_course_contents'];

        // Coordenadas X / Y (float)
        $float_fields = [ 'name_x', 'name_y', 'company_line_x', 'company_line_y', 'front_course_x', 'front_course_y', 'details_y', 'code_x', 'start_date_x', 'end_date_x', 'duration_y', 'duration_x', 'modality_x', 'date_x', 'date_y', 'contents_x', 'contents_y' ];
        foreach ( $float_fields as $f ) {
            $output[ $f ] = isset( $input[ $f ] ) ? floatval( $input[ $f ] ) : $defaults[ $f ];
        }

        // Tamaños de fuente (int)
        $int_fields = [ 'name_size', 'company_line_size', 'front_course_size', 'details_size', 'duration_size', 'date_size', 'contents_size' ];
        foreach ( $int_fields as $f ) {
            $output[ $f ] = isset( $input[ $f ] ) ? intval( $input[ $f ] ) : $defaults[ $f ];
        }

        // Colores (hex)
        $color_fields = [ 'name_color', 'company_line_color', 'front_course_color', 'details_color', 'duration_color', 'date_color', 'contents_color' ];
        foreach ( $color_fields as $f ) {
            $output[ $f ] = isset( $input[ $f ] ) ? sanitize_hex_color( $input[ $f ] ) : $defaults[ $f ];
        }

        // Checkboxes (centrar) — 1 si está marcado, 0 si no
        $cb_fields = [ 'name_center', 'company_line_center', 'front_course_center', 'date_center', 'contents_center', 'keep_data_on_uninstall' ];
        foreach ( $cb_fields as $f ) {
            $output[ $f ] = isset( $input[ $f ] ) ? 1 : 0;
        }

        return $output;
    }

    /**
     * Renderiza el panel de opciones
     */
    public function render_settings_page() {
        // Obtener valores guardados combinados con los valores por defecto
        $settings = wp_parse_args( get_option( 'mk20_cert_settings', [] ), mk20_get_default_settings() );

        // Plantillas predeterminadas si no hay nada guardado
        $default_front = MK20_CERT_URL . 'templates/diploma-anverso.jpg';
        $default_back  = MK20_CERT_URL . 'templates/diploma-reverso.jpg';

        $front_val = ! empty( $settings['front_image'] ) ? $settings['front_image'] : '';
        $back_val  = ! empty( $settings['back_image'] )  ? $settings['back_image']  : '';

        $front_preview = ! empty( $front_val ) ? $front_val : $default_front;
        $back_preview  = ! empty( $back_val )  ? $back_val  : $default_back;
        ?>
        <div class="wrap mk20-admin-wrap">
            <h1 class="wp-heading-inline"><?php echo esc_html( get_admin_page_title() ); ?></h1>
            <hr class="wp-header-end">

            <!-- Estilos Modernos Embebidos para el Panel de Control -->
            <style>
                .mk20-admin-wrap {
                    max-width: 1100px;
                    margin: 20px auto 0 auto;
                    font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Oxygen-Sans, Ubuntu, Cantarell, "Helvetica Neue", sans-serif;
                }
                .mk20-container {
                    display: grid;
                    grid-template-columns: 1fr;
                    gap: 24px;
                    margin-top: 20px;
                }
                @media (min-width: 768px) {
                    .mk20-container {
                        grid-template-columns: 2fr 1fr;
                    }
                }
                .mk20-card {
                    background: #fff;
                    border: 1px solid #e2e8f0;
                    border-radius: 8px;
                    box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05), 0 2px 4px -1px rgba(0, 0, 0, 0.03);
                    padding: 24px;
                    margin-bottom: 24px;
                }
                .mk20-card h2 {
                    margin-top: 0;
                    border-bottom: 1px solid #edf2f7;
                    padding-bottom: 12px;
                    font-size: 1.25rem;
                    color: #1e293b;
                }
                .mk20-field-group {
                    display: flex;
                    flex-direction: column;
                    margin-bottom: 16px;
                }
                .mk20-field-group label {
                    font-weight: 600;
                    margin-bottom: 6px;
                    color: #475569;
                }
                .mk20-field-grid {
                    display: grid;
                    grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
                    gap: 16px;
                    margin-top: 10px;
                }
                .mk20-field-grid .mk20-field-group {
                    margin-bottom: 0;
                }
                .mk20-image-preview-container {
                    display: flex;
                    gap: 20px;
                    flex-wrap: wrap;
                }
                .mk20-image-card {
                    flex: 1;
                    min-width: 250px;
                    border: 1px solid #cbd5e1;
                    border-radius: 6px;
                    padding: 12px;
                    background: #f8fafc;
                    text-align: center;
                }
                .mk20-image-card img {
                    max-width: 100%;
                    height: 150px;
                    object-fit: contain;
                    border: 1px solid #e2e8f0;
                    margin-bottom: 12px;
                    background: #fff;
                }
                .mk20-btn-secondary {
                    background: #f1f5f9;
                    border: 1px solid #cbd5e1;
                    padding: 8px 14px;
                    border-radius: 4px;
                    cursor: pointer;
                    font-weight: 500;
                    color: #334155;
                    transition: all 0.2s ease;
                }
                .mk20-btn-secondary:hover {
                    background: #e2e8f0;
                    color: #0f172a;
                }
                .mk20-sidebar-info {
                    background: #eff6ff;
                    border: 1px solid #bfdbfe;
                    border-left-width: 4px;
                    border-left-color: #3b82f6;
                    border-radius: 6px;
                    padding: 18px;
                    color: #1e3a8a;
                }
                .mk20-sidebar-info h3 {
                    margin-top: 0;
                    color: #1e3a8a;
                    font-size: 1.1rem;
                }
                .mk20-sidebar-info p {
                    line-height: 1.5;
                    font-size: 0.9rem;
                }
                .mk20-preview-btn-container {
                    text-align: center;
                    margin-top: 20px;
                }
                .mk20-btn-preview {
                    display: inline-block;
                    background: #10b981;
                    color: #fff;
                    text-decoration: none;
                    padding: 12px 20px;
                    border-radius: 6px;
                    font-weight: bold;
                    font-size: 1rem;
                    box-shadow: 0 4px 6px -1px rgba(16, 185, 129, 0.2);
                    transition: all 0.2s ease;
                }
                .mk20-btn-preview:hover {
                    background: #059669;
                    color: #fff;
                    box-shadow: 0 4px 6px -1px rgba(5, 150, 105, 0.4);
                }
                .align-checkbox-label {
                    display: inline-flex;
                    align-items: center;
                    margin-top: 28px;
                    cursor: pointer;
                }
                .align-checkbox-label input {
                    margin-right: 8px;
                }
            </style>

            <?php
            $reset_notice  = get_transient( 'mk20_cert_reset_notice' );
            $deleted_notice = get_transient( 'mk20_cert_deleted_notice' );
            if ( $reset_notice ) :
                delete_transient( 'mk20_cert_reset_notice' );
                ?>
                <div class="notice notice-success is-dismissible"><p><?php echo esc_html( $reset_notice ); ?></p></div>
            <?php endif; ?>
            <?php if ( $deleted_notice ) :
                delete_transient( 'mk20_cert_deleted_notice' );
                ?>
                <div class="notice notice-success is-dismissible"><p><?php echo esc_html( $deleted_notice ); ?></p></div>
            <?php endif; ?>

            <form method="post" action="options.php">
                <?php settings_fields( 'mk20_cert_settings_group' ); ?>

                <div class="mk20-container">
                    <!-- Configuración Principal -->
                    <div class="mk20-main-content">
                        
                        <!-- Tarjeta de Plantillas de Imagen -->
                        <div class="mk20-card">
                            <h2><?php esc_html_e( '1. Plantillas de Fondo (JPG, A4 Landscape, 297x210 mm)', 'mk20-custom-certificates' ); ?></h2>
                            <p class="description"><?php esc_html_e( 'Sube tus propios diseños o deja los campos vacíos para usar los fondos premium por defecto.', 'mk20-custom-certificates' ); ?></p>
                            
                            <div class="mk20-image-preview-container" style="margin-top: 20px;">
                                <!-- Anverso -->
                                <div class="mk20-image-card">
                                    <h3><?php esc_html_e( 'Anverso (Cara 1)', 'mk20-custom-certificates' ); ?></h3>
                                    <img id="front-image-preview" src="<?php echo esc_url( $front_preview ); ?>" alt="Anverso">
                                    <input type="hidden" name="mk20_cert_settings[front_image]" id="front-image-input" value="<?php echo esc_attr( $front_val ); ?>">
                                    <div>
                                        <button type="button" class="mk20-upload-button mk20-btn-secondary" data-input="front-image-input" data-preview="front-image-preview"><?php esc_html_e( 'Seleccionar Imagen', 'mk20-custom-certificates' ); ?></button>
                                        <?php if ( ! empty( $front_val ) ) : ?>
                                            <button type="button" class="mk20-btn-secondary" onclick="document.getElementById('front-image-input').value=''; document.getElementById('front-image-preview').src='<?php echo esc_url( $default_front ); ?>'; this.style.display='none';" style="color: #ef4444;"><?php esc_html_e( 'Quitar', 'mk20-custom-certificates' ); ?></button>
                                        <?php endif; ?>
                                    </div>
                                </div>

                                <!-- Reverso -->
                                <div class="mk20-image-card">
                                    <h3><?php esc_html_e( 'Reverso (Cara 2)', 'mk20-custom-certificates' ); ?></h3>
                                    <img id="back-image-preview" src="<?php echo esc_url( $back_preview ); ?>" alt="Reverso">
                                    <input type="hidden" name="mk20_cert_settings[back_image]" id="back-image-input" value="<?php echo esc_attr( $back_val ); ?>">
                                    <div>
                                        <button type="button" class="mk20-upload-button mk20-btn-secondary" data-input="back-image-input" data-preview="back-image-preview"><?php esc_html_e( 'Seleccionar Imagen', 'mk20-custom-certificates' ); ?></button>
                                        <?php if ( ! empty( $back_val ) ) : ?>
                                            <button type="button" class="mk20-btn-secondary" onclick="document.getElementById('back-image-input').value=''; document.getElementById('back-image-preview').src='<?php echo esc_url( $default_back ); ?>'; this.style.display='none';" style="color: #ef4444;"><?php esc_html_e( 'Quitar', 'mk20-custom-certificates' ); ?></button>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Tarjeta de Integración con BuddyBoss -->
                        <div class="mk20-card">
                            <h2><?php esc_html_e( '2. Mapeo de Campos de Perfil de BuddyBoss', 'mk20-custom-certificates' ); ?></h2>
                            <p class="description"><?php esc_html_e( 'Define el nombre exacto de los campos de perfil (xprofile) personalizados en BuddyBoss.', 'mk20-custom-certificates' ); ?></p>
                            <div class="mk20-field-grid">
                                <div class="mk20-field-group">
                                    <label><?php esc_html_e( 'Campo DNI/NIE', 'mk20-custom-certificates' ); ?></label>
                                    <input type="text" name="mk20_cert_settings[bb_field_dni]" value="<?php echo esc_attr( $settings['bb_field_dni'] ); ?>" class="regular-text">
                                </div>
                                <div class="mk20-field-group">
                                    <label><?php esc_html_e( 'Campo Nombre Empresa', 'mk20-custom-certificates' ); ?></label>
                                    <input type="text" name="mk20_cert_settings[bb_field_company]" value="<?php echo esc_attr( $settings['bb_field_company'] ); ?>" class="regular-text">
                                </div>
                                <div class="mk20-field-group">
                                    <label><?php esc_html_e( 'Campo CIF Empresa', 'mk20-custom-certificates' ); ?></label>
                                    <input type="text" name="mk20_cert_settings[bb_field_cif]" value="<?php echo esc_attr( $settings['bb_field_cif'] ); ?>" class="regular-text">
                                </div>
                            </div>
                        </div>

                        <!-- Tarjeta de Valores de Respaldo (Fallbacks) -->
                        <div class="mk20-card">
                            <h2><?php esc_html_e( '3. Valores Genéricos de Respaldo (si no están rellenos)', 'mk20-custom-certificates' ); ?></h2>
                            <p class="description"><?php esc_html_e( 'Estos valores se imprimirán en el certificado si el alumno no los tiene definidos en su perfil o si el curso no tiene metadatos personalizados.', 'mk20-custom-certificates' ); ?></p>
                            <div class="mk20-field-grid">
                                <div class="mk20-field-group">
                                    <label><?php esc_html_e( 'DNI Genérico', 'mk20-custom-certificates' ); ?></label>
                                    <input type="text" name="mk20_cert_settings[fallback_dni]" value="<?php echo esc_attr( $settings['fallback_dni'] ); ?>" class="regular-text">
                                </div>
                                <div class="mk20-field-group">
                                    <label><?php esc_html_e( 'Empresa Genérica', 'mk20-custom-certificates' ); ?></label>
                                    <input type="text" name="mk20_cert_settings[fallback_company]" value="<?php echo esc_attr( $settings['fallback_company'] ); ?>" class="regular-text">
                                </div>
                                <div class="mk20-field-group">
                                    <label><?php esc_html_e( 'CIF Genérico', 'mk20-custom-certificates' ); ?></label>
                                    <input type="text" name="mk20_cert_settings[fallback_cif]" value="<?php echo esc_attr( $settings['fallback_cif'] ); ?>" class="regular-text">
                                </div>
                            </div>
                            <div class="mk20-field-grid" style="margin-top: 15px;">
                                <div class="mk20-field-group">
                                    <label><?php esc_html_e( 'Código AF Genérico', 'mk20-custom-certificates' ); ?></label>
                                    <input type="text" name="mk20_cert_settings[fallback_course_code]" value="<?php echo esc_attr( $settings['fallback_course_code'] ); ?>" class="regular-text">
                                </div>
                                <div class="mk20-field-group">
                                    <label><?php esc_html_e( 'Duración Genérica', 'mk20-custom-certificates' ); ?></label>
                                    <input type="text" name="mk20_cert_settings[fallback_course_duration]" value="<?php echo esc_attr( $settings['fallback_course_duration'] ); ?>" class="regular-text">
                                </div>
                                <div class="mk20-field-group">
                                    <label><?php esc_html_e( 'Modalidad Genérica', 'mk20-custom-certificates' ); ?></label>
                                    <input type="text" name="mk20_cert_settings[fallback_course_modality]" value="<?php echo esc_attr( $settings['fallback_course_modality'] ); ?>" class="regular-text">
                                </div>
                            </div>
                            <div class="mk20-field-grid" style="margin-top: 15px;">
                                <div class="mk20-field-group">
                                    <label><?php esc_html_e( 'Fecha Inicio Genérica', 'mk20-custom-certificates' ); ?></label>
                                    <input type="text" name="mk20_cert_settings[fallback_course_start_date]" value="<?php echo esc_attr( $settings['fallback_course_start_date'] ); ?>" class="regular-text">
                                </div>
                                <div class="mk20-field-group">
                                    <label><?php esc_html_e( 'Fecha Fin Genérica', 'mk20-custom-certificates' ); ?></label>
                                    <input type="text" name="mk20_cert_settings[fallback_course_end_date]" value="<?php echo esc_attr( $settings['fallback_course_end_date'] ); ?>" class="regular-text">
                                </div>
                            </div>
                            <div class="mk20-field-grid" style="margin-top: 15px;">
                                <div class="mk20-field-group" style="grid-column: 1 / -1;">
                                    <label><?php esc_html_e( 'Contenidos del Curso Genéricos (Reverso)', 'mk20-custom-certificates' ); ?></label>
                                    <textarea name="mk20_cert_settings[fallback_course_contents]" rows="3" class="large-text"><?php echo esc_textarea( $settings['fallback_course_contents'] ); ?></textarea>
                                </div>
                            </div>
                        </div>

                        <!-- Tarjeta de Ajustes del Nombre y DNI (Anverso) -->
                        <div class="mk20-card">
                            <h2><?php esc_html_e( '4. Línea del Alumno y DNI (Anverso - Cara 1)', 'mk20-custom-certificates' ); ?></h2>
                            <p class="description"><?php esc_html_e( 'Configura la posición y formato del texto: "D./DÑA. [Nombre] CON DNI/NIE [DNI]"', 'mk20-custom-certificates' ); ?></p>
                            <div class="mk20-field-grid">
                                <div class="mk20-field-group">
                                    <label><?php esc_html_e( 'Coordenada X (mm)', 'mk20-custom-certificates' ); ?></label>
                                    <input type="number" step="0.1" name="mk20_cert_settings[name_x]" value="<?php echo esc_attr( $settings['name_x'] ); ?>" class="regular-text">
                                </div>
                                <div class="mk20-field-group">
                                    <label><?php esc_html_e( 'Coordenada Y (mm)', 'mk20-custom-certificates' ); ?></label>
                                    <input type="number" step="0.1" name="mk20_cert_settings[name_y]" value="<?php echo esc_attr( $settings['name_y'] ); ?>" class="regular-text">
                                </div>
                                <div class="mk20-field-group">
                                    <label><?php esc_html_e( 'Tamaño de Fuente (pt)', 'mk20-custom-certificates' ); ?></label>
                                    <input type="number" name="mk20_cert_settings[name_size]" value="<?php echo esc_attr( $settings['name_size'] ); ?>" class="regular-text">
                                </div>
                                <div class="mk20-field-group">
                                    <label><?php esc_html_e( 'Color (HEX)', 'mk20-custom-certificates' ); ?></label>
                                    <input type="color" name="mk20_cert_settings[name_color]" value="<?php echo esc_attr( $settings['name_color'] ); ?>" style="height: 35px; width: 100%;">
                                </div>
                                <div class="mk20-field-group">
                                    <label class="align-checkbox-label">
                                         <input type="checkbox" name="mk20_cert_settings[name_center]" value="1" <?php checked( ! isset( $settings['name_center'] ) || ! empty( $settings['name_center'] ) ); ?>>
                                        <?php esc_html_e( 'Centrar', 'mk20-custom-certificates' ); ?>
                                    </label>
                                </div>
                            </div>
                        </div>

                        <!-- Tarjeta de Ajustes de la Empresa (Anverso) -->
                        <div class="mk20-card">
                            <h2><?php esc_html_e( '5. Línea de la Empresa y CIF (Anverso - Cara 1)', 'mk20-custom-certificates' ); ?></h2>
                            <p class="description"><?php esc_html_e( 'Configura la posición y formato del texto: "[Empresa] CON CIF [CIF]"', 'mk20-custom-certificates' ); ?></p>
                            <div class="mk20-field-grid">
                                <div class="mk20-field-group">
                                    <label><?php esc_html_e( 'Coordenada X (mm)', 'mk20-custom-certificates' ); ?></label>
                                    <input type="number" step="0.1" name="mk20_cert_settings[company_line_x]" value="<?php echo esc_attr( $settings['company_line_x'] ); ?>" class="regular-text">
                                </div>
                                <div class="mk20-field-group">
                                    <label><?php esc_html_e( 'Coordenada Y (mm)', 'mk20-custom-certificates' ); ?></label>
                                    <input type="number" step="0.1" name="mk20_cert_settings[company_line_y]" value="<?php echo esc_attr( $settings['company_line_y'] ); ?>" class="regular-text">
                                </div>
                                <div class="mk20-field-group">
                                    <label><?php esc_html_e( 'Tamaño de Fuente (pt)', 'mk20-custom-certificates' ); ?></label>
                                    <input type="number" name="mk20_cert_settings[company_line_size]" value="<?php echo esc_attr( $settings['company_line_size'] ); ?>" class="regular-text">
                                </div>
                                <div class="mk20-field-group">
                                    <label><?php esc_html_e( 'Color (HEX)', 'mk20-custom-certificates' ); ?></label>
                                    <input type="color" name="mk20_cert_settings[company_line_color]" value="<?php echo esc_attr( $settings['company_line_color'] ); ?>" style="height: 35px; width: 100%;">
                                </div>
                                <div class="mk20-field-group">
                                    <label class="align-checkbox-label">
                                         <input type="checkbox" name="mk20_cert_settings[company_line_center]" value="1" <?php checked( ! isset( $settings['company_line_center'] ) || ! empty( $settings['company_line_center'] ) ); ?>>
                                        <?php esc_html_e( 'Centrar', 'mk20-custom-certificates' ); ?>
                                    </label>
                                </div>
                            </div>
                        </div>

                        <!-- Tarjeta de Ajustes del Título del Curso (Anverso) -->
                        <div class="mk20-card">
                            <h2><?php esc_html_e( '6. Acción Formativa / Título del Curso (Anverso - Cara 1)', 'mk20-custom-certificates' ); ?></h2>
                            <p class="description"><?php esc_html_e( 'Configura la posición y formato del título del curso en el anverso.', 'mk20-custom-certificates' ); ?></p>
                            <div class="mk20-field-grid">
                                <div class="mk20-field-group">
                                    <label><?php esc_html_e( 'Coordenada X (mm)', 'mk20-custom-certificates' ); ?></label>
                                    <input type="number" step="0.1" name="mk20_cert_settings[front_course_x]" value="<?php echo esc_attr( $settings['front_course_x'] ); ?>" class="regular-text">
                                </div>
                                <div class="mk20-field-group">
                                    <label><?php esc_html_e( 'Coordenada Y (mm)', 'mk20-custom-certificates' ); ?></label>
                                    <input type="number" step="0.1" name="mk20_cert_settings[front_course_y]" value="<?php echo esc_attr( $settings['front_course_y'] ); ?>" class="regular-text">
                                </div>
                                <div class="mk20-field-group">
                                    <label><?php esc_html_e( 'Tamaño de Fuente (pt)', 'mk20-custom-certificates' ); ?></label>
                                    <input type="number" name="mk20_cert_settings[front_course_size]" value="<?php echo esc_attr( $settings['front_course_size'] ); ?>" class="regular-text">
                                </div>
                                <div class="mk20-field-group">
                                    <label><?php esc_html_e( 'Color (HEX)', 'mk20-custom-certificates' ); ?></label>
                                    <input type="color" name="mk20_cert_settings[front_course_color]" value="<?php echo esc_attr( $settings['front_course_color'] ); ?>" style="height: 35px; width: 100%;">
                                </div>
                                <div class="mk20-field-group">
                                    <label class="align-checkbox-label">
                                         <input type="checkbox" name="mk20_cert_settings[front_course_center]" value="1" <?php checked( ! isset( $settings['front_course_center'] ) || ! empty( $settings['front_course_center'] ) ); ?>>
                                        <?php esc_html_e( 'Centrar', 'mk20-custom-certificates' ); ?>
                                    </label>
                                </div>
                            </div>
                        </div>

                        <!-- Tarjeta de Ajustes de Código AF y Fechas (Anverso) -->
                        <div class="mk20-card">
                            <h2><?php esc_html_e( '7. Huecos de Código AF y Fechas (Anverso - Cara 1)', 'mk20-custom-certificates' ); ?></h2>
                            <p class="description"><?php esc_html_e( 'Define las posiciones X para colocar los valores sobre los huecos en la línea: "Código AF/ Grupo [X1] durante los días [X2] al [X3]"', 'mk20-custom-certificates' ); ?></p>
                            <div class="mk20-field-grid">
                                <div class="mk20-field-group">
                                    <label><?php esc_html_e( 'Coordenada Y (Línea) (mm)', 'mk20-custom-certificates' ); ?></label>
                                    <input type="number" step="0.1" name="mk20_cert_settings[details_y]" value="<?php echo esc_attr( $settings['details_y'] ); ?>" class="regular-text">
                                </div>
                                <div class="mk20-field-group">
                                    <label><?php esc_html_e( 'Código AF Posición X (mm)', 'mk20-custom-certificates' ); ?></label>
                                    <input type="number" step="0.1" name="mk20_cert_settings[code_x]" value="<?php echo esc_attr( $settings['code_x'] ); ?>" class="regular-text">
                                </div>
                                <div class="mk20-field-group">
                                    <label><?php esc_html_e( 'Fecha Inicio Posición X (mm)', 'mk20-custom-certificates' ); ?></label>
                                    <input type="number" step="0.1" name="mk20_cert_settings[start_date_x]" value="<?php echo esc_attr( $settings['start_date_x'] ); ?>" class="regular-text">
                                </div>
                                <div class="mk20-field-group">
                                    <label><?php esc_html_e( 'Fecha Fin Posición X (mm)', 'mk20-custom-certificates' ); ?></label>
                                    <input type="number" step="0.1" name="mk20_cert_settings[end_date_x]" value="<?php echo esc_attr( $settings['end_date_x'] ); ?>" class="regular-text">
                                </div>
                            </div>
                            <div class="mk20-field-grid" style="margin-top: 15px;">
                                <div class="mk20-field-group">
                                    <label><?php esc_html_e( 'Tamaño de Fuente (pt)', 'mk20-custom-certificates' ); ?></label>
                                    <input type="number" name="mk20_cert_settings[details_size]" value="<?php echo esc_attr( $settings['details_size'] ); ?>" class="regular-text">
                                </div>
                                <div class="mk20-field-group">
                                    <label><?php esc_html_e( 'Color Fuente (HEX)', 'mk20-custom-certificates' ); ?></label>
                                    <input type="color" name="mk20_cert_settings[details_color]" value="<?php echo esc_attr( $settings['details_color'] ); ?>" style="height: 35px; width: 100%;">
                                </div>
                            </div>
                        </div>

                        <!-- Tarjeta de Ajustes de Duración y Modalidad (Anverso) -->
                        <div class="mk20-card">
                            <h2><?php esc_html_e( '8. Huecos de Duración y Modalidad (Anverso - Cara 1)', 'mk20-custom-certificates' ); ?></h2>
                            <p class="description"><?php esc_html_e( 'Define las posiciones X para colocar los valores sobre los huecos en la línea: "Con una duración total de [X1], en la modalidad formativa [X2]"', 'mk20-custom-certificates' ); ?></p>
                            <div class="mk20-field-grid">
                                <div class="mk20-field-group">
                                    <label><?php esc_html_e( 'Coordenada Y (Línea) (mm)', 'mk20-custom-certificates' ); ?></label>
                                    <input type="number" step="0.1" name="mk20_cert_settings[duration_y]" value="<?php echo esc_attr( $settings['duration_y'] ); ?>" class="regular-text">
                                </div>
                                <div class="mk20-field-group">
                                    <label><?php esc_html_e( 'Duración Posición X (mm)', 'mk20-custom-certificates' ); ?></label>
                                    <input type="number" step="0.1" name="mk20_cert_settings[duration_x]" value="<?php echo esc_attr( $settings['duration_x'] ); ?>" class="regular-text">
                                </div>
                                <div class="mk20-field-group">
                                    <label><?php esc_html_e( 'Modalidad Posición X (mm)', 'mk20-custom-certificates' ); ?></label>
                                    <input type="number" step="0.1" name="mk20_cert_settings[modality_x]" value="<?php echo esc_attr( $settings['modality_x'] ); ?>" class="regular-text">
                                </div>
                            </div>
                            <div class="mk20-field-grid" style="margin-top: 15px;">
                                <div class="mk20-field-group">
                                    <label><?php esc_html_e( 'Tamaño de Fuente (pt)', 'mk20-custom-certificates' ); ?></label>
                                    <input type="number" name="mk20_cert_settings[duration_size]" value="<?php echo esc_attr( $settings['duration_size'] ); ?>" class="regular-text">
                                </div>
                                <div class="mk20-field-group">
                                    <label><?php esc_html_e( 'Color Fuente (HEX)', 'mk20-custom-certificates' ); ?></label>
                                    <input type="color" name="mk20_cert_settings[duration_color]" value="<?php echo esc_attr( $settings['duration_color'] ); ?>" style="height: 35px; width: 100%;">
                                </div>
                            </div>
                        </div>

                        <!-- Tarjeta de Ajustes de la Fecha de Expedición (Anverso) -->
                        <div class="mk20-card">
                            <h2><?php esc_html_e( '9. Fecha de Expedición (Anverso - Cara 1)', 'mk20-custom-certificates' ); ?></h2>
                            <p class="description"><?php esc_html_e( 'Configura la posición y formato de la fecha de expedición que se muestra abajo al centro.', 'mk20-custom-certificates' ); ?></p>
                            <div class="mk20-field-grid">
                                <div class="mk20-field-group">
                                    <label><?php esc_html_e( 'Coordenada X (mm)', 'mk20-custom-certificates' ); ?></label>
                                    <input type="number" step="0.1" name="mk20_cert_settings[date_x]" value="<?php echo esc_attr( $settings['date_x'] ); ?>" class="regular-text">
                                </div>
                                <div class="mk20-field-group">
                                    <label><?php esc_html_e( 'Coordenada Y (mm)', 'mk20-custom-certificates' ); ?></label>
                                    <input type="number" step="0.1" name="mk20_cert_settings[date_y]" value="<?php echo esc_attr( $settings['date_y'] ); ?>" class="regular-text">
                                </div>
                                <div class="mk20-field-group">
                                    <label><?php esc_html_e( 'Tamaño de Fuente (pt)', 'mk20-custom-certificates' ); ?></label>
                                    <input type="number" name="mk20_cert_settings[date_size]" value="<?php echo esc_attr( $settings['date_size'] ); ?>" class="regular-text">
                                </div>
                                <div class="mk20-field-group">
                                    <label><?php esc_html_e( 'Color (HEX)', 'mk20-custom-certificates' ); ?></label>
                                    <input type="color" name="mk20_cert_settings[date_color]" value="<?php echo esc_attr( $settings['date_color'] ); ?>" style="height: 35px; width: 100%;">
                                </div>
                                <div class="mk20-field-group">
                                    <label class="align-checkbox-label">
                                        <input type="checkbox" name="mk20_cert_settings[date_center]" value="1" <?php checked( ! isset( $settings['date_center'] ) || ! empty( $settings['date_center'] ) ); ?>>
                                        <?php esc_html_e( 'Centrar', 'mk20-custom-certificates' ); ?>
                                    </label>
                                </div>
                            </div>
                        </div>

                        <!-- Tarjeta de Ajustes de Contenidos del Curso (Reverso) -->
                        <div class="mk20-card">
                            <h2><?php esc_html_e( '10. Contenidos del Curso (Reverso - Cara 2)', 'mk20-custom-certificates' ); ?></h2>
                            <div class="mk20-field-grid">
                                <div class="mk20-field-group">
                                    <label><?php esc_html_e( 'Coordenada X (mm)', 'mk20-custom-certificates' ); ?></label>
                                    <input type="number" step="0.1" name="mk20_cert_settings[contents_x]" value="<?php echo esc_attr( $settings['contents_x'] ); ?>" class="regular-text">
                                </div>
                                <div class="mk20-field-group">
                                    <label><?php esc_html_e( 'Coordenada Y (mm)', 'mk20-custom-certificates' ); ?></label>
                                    <input type="number" step="0.1" name="mk20_cert_settings[contents_y]" value="<?php echo esc_attr( $settings['contents_y'] ); ?>" class="regular-text">
                                </div>
                                <div class="mk20-field-group">
                                    <label><?php esc_html_e( 'Tamaño de Fuente (pt)', 'mk20-custom-certificates' ); ?></label>
                                    <input type="number" name="mk20_cert_settings[contents_size]" value="<?php echo esc_attr( $settings['contents_size'] ); ?>" class="regular-text">
                                </div>
                                <div class="mk20-field-group">
                                    <label><?php esc_html_e( 'Color (HEX)', 'mk20-custom-certificates' ); ?></label>
                                    <input type="color" name="mk20_cert_settings[contents_color]" value="<?php echo esc_attr( $settings['contents_color'] ); ?>" style="height: 35px; width: 100%;">
                                </div>
                                <div class="mk20-field-group">
                                    <label class="align-checkbox-label">
                                        <input type="checkbox" name="mk20_cert_settings[contents_center]" value="1" <?php checked( ! empty( $settings['contents_center'] ) ); ?>>
                                        <?php esc_html_e( 'Centrar', 'mk20-custom-certificates' ); ?>
                                    </label>
                                </div>
                            </div>
                        </div>

                        <?php submit_button( __( 'Guardar Configuración', 'mk20-custom-certificates' ), 'primary' ); ?>
                    </div>

                    <!-- Sidebar de Información y Vista Previa -->
                    <div class="mk20-sidebar">
                        <div class="mk20-card mk20-sidebar-info">
                            <h3><?php esc_html_e( 'Instrucciones de Uso', 'mk20-custom-certificates' ); ?></h3>
                            <p><?php esc_html_e( 'Este plugin genera automáticamente un certificado de dos caras cuando un estudiante completa un curso de LearnDash.', 'mk20-custom-certificates' ); ?></p>
                            <p><strong><?php esc_html_e( 'Coordenadas en mm:', 'mk20-custom-certificates' ); ?></strong></p>
                            <p><?php esc_html_e( 'El lienzo tiene dimensiones A4 apaisado: 297 mm de ancho por 210 mm de alto.', 'mk20-custom-certificates' ); ?></p>
                            <p><?php esc_html_e( 'Por ejemplo, para centrar horizontalmente de forma exacta, puedes marcar el checkbox correspondiente, o ingresar 148.5 en la coordenada X.', 'mk20-custom-certificates' ); ?></p>
                            <p><strong><?php esc_html_e( 'Soporte de Caracteres:', 'mk20-custom-certificates' ); ?></strong></p>
                            <p><?php esc_html_e( 'El plugin cuenta con traducción automática de caracteres UTF-8 (tildes, eñes) para evitar errores de renderizado de texto en FPDF.', 'mk20-custom-certificates' ); ?></p>
                        </div>

                        <?php
                        $rejected = get_option( 'mk20_rejected_certificates', array() );
                        if ( ! empty( $rejected ) ) :
                            $dismiss_url = admin_url( 'admin-post.php?action=mk20_dismiss_rejected' );
                            ?>
                            <div class="mk20-card" style="border-left: 4px solid #f59e0b;">
                                <h3 style="color: #d97706;"><?php esc_html_e( 'Certificados Rechazados', 'mk20-custom-certificates' ); ?></h3>
                                <table style="width:100%; font-size:13px; border-collapse:collapse;">
                                    <tr style="border-bottom:1px solid #e2e8f0;">
                                        <th style="text-align:left; padding:4px;"><?php esc_html_e( 'Usuario', 'mk20-custom-certificates' ); ?></th>
                                        <th style="text-align:left; padding:4px;"><?php esc_html_e( 'Curso', 'mk20-custom-certificates' ); ?></th>
                                        <th style="text-align:left; padding:4px;"><?php esc_html_e( 'Motivo', 'mk20-custom-certificates' ); ?></th>
                                    </tr>
                                    <?php foreach ( $rejected as $item ) : ?>
                                        <tr style="border-bottom:1px solid #f1f5f9;">
                                            <td style="padding:4px;"><?php echo esc_html( '#' . $item['user_id'] ); ?></td>
                                            <td style="padding:4px;"><?php echo esc_html( mb_substr( $item['course_title'], 0, 25 ) . ( mb_strlen( $item['course_title'] ) > 25 ? '...' : '' ) ); ?></td>
                                            <td style="padding:4px;"><?php echo esc_html( $item['reason'] . ( ! empty( $item['extra'] ) ? ' (' . $item['extra'] . ')' : '' ) ); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </table>
                                <p style="margin:8px 0 0; font-size:12px; color:#64748b;">
                                    <?php esc_html_e( 'Estos certificados no se mostraron a los alumnos.', 'mk20-custom-certificates' ); ?>
                                    <a href="<?php echo esc_url( wp_nonce_url( $dismiss_url, 'mk20_dismiss_rejected' ) ); ?>" style="color:#ef4444;"><?php esc_html_e( 'Descartar', 'mk20-custom-certificates' ); ?></a>
                                </p>
                            </div>
                        <?php endif; ?>

                        <div class="mk20-card" style="text-align: center;">
                            <h3><?php esc_html_e( 'Vista Previa del Certificado', 'mk20-custom-certificates' ); ?></h3>
                            <p class="description"><?php esc_html_e( 'Genera un certificado de muestra (PDF de 2 páginas) con los ajustes actuales para validar las posiciones y colores en tiempo real.', 'mk20-custom-certificates' ); ?></p>
                            <div class="mk20-preview-btn-container">
                                <a href="<?php echo esc_url( admin_url( 'admin-post.php?action=mk20_preview_cert' ) ); ?>" class="mk20-btn-preview" target="_blank">
                                    <?php esc_html_e( '👁️ Ver PDF de Prueba', 'mk20-custom-certificates' ); ?>
                                </a>
                            </div>
                        </div>

                        <div class="mk20-card">
                            <h3><?php esc_html_e( 'Protección de Datos', 'mk20-custom-certificates' ); ?></h3>
                            <label class="align-checkbox-label">
                                <input type="checkbox" name="mk20_cert_settings[keep_data_on_uninstall]" value="1" <?php checked( ! empty( $settings['keep_data_on_uninstall'] ) ); ?>>
                                <?php esc_html_e( 'Conservar datos al desinstalar', 'mk20-custom-certificates' ); ?>
                            </label>
                            <p class="description" style="margin-top:10px;">
                                <?php esc_html_e( 'Si está marcado, los certificados generados y sus metadatos se conservarán aunque desactives y elimines el plugin.', 'mk20-custom-certificates' ); ?>
                            </p>
                        </div>

                        <div class="mk20-card" style="text-align: center;">
                            <h3 style="color: #ef4444;"><?php esc_html_e( 'Restaurar Valores', 'mk20-custom-certificates' ); ?></h3>
                            <p class="description"><?php esc_html_e( 'Vuelve a los valores de posición, tamaño y color que trae el plugin por defecto.', 'mk20-custom-certificates' ); ?></p>
                            <div class="mk20-preview-btn-container">
                                <a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=mk20_reset_settings' ), 'mk20_reset_settings' ) ); ?>"
                                   class="mk20-btn-preview"
                                   style="background: #ef4444;"
                                   onclick="return confirm('<?php echo esc_js( __( '¿Restaurar valores por defecto? Se perderán tus cambios personalizados.', 'mk20-custom-certificates' ) ); ?>');">
                                    <?php esc_html_e( '↺ Restaurar por defecto', 'mk20-custom-certificates' ); ?>
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
            </form>
        </div>

        <!-- Javascript para interactuar con la biblioteca de medios -->
        <script>
            jQuery(document).ready(function($){
                $('.mk20-upload-button').click(function(e) {
                    e.preventDefault();
                    var button = $(this);
                    var input_id = button.data('input');
                    var preview_id = button.data('preview');
                    
                    var file_frame = wp.media.frames.file_frame = wp.media({
                        title: '<?php echo esc_js( __( 'Seleccionar imagen de plantilla', 'mk20-custom-certificates' ) ); ?>',
                        button: {
                            text: '<?php echo esc_js( __( 'Usar esta imagen', 'mk20-custom-certificates' ) ); ?>'
                        },
                        multiple: false
                    });
                    
                    file_frame.on('select', function() {
                        var attachment = file_frame.state().get('selection').first().toJSON();
                        $('#' + input_id).val(attachment.url);
                        $('#' + preview_id).attr('src', attachment.url);
                    });
                    
                    file_frame.open();
                });
            });
        </script>
        <?php
    }

    /**
     * Renderiza la página de administración con todos los certificados del sistema.
     */
    public function render_all_certificates_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'No tienes permisos suficientes.', 'mk20-custom-certificates' ) );
        }

        $current_page = max( 1, absint( get_query_var( 'paged', 1 ) ) );
        $per_page     = 20;
        $offset       = ( $current_page - 1 ) * $per_page;

        $rows  = array();
        $users = get_users( array( 'fields' => 'ID' ) );

        foreach ( $users as $user_id ) {
            $user_meta = get_user_meta( $user_id );

            foreach ( $user_meta as $meta_key => $meta_values ) {
                if ( 0 === strpos( $meta_key, '_mk20_cert_path_' ) || 0 === strpos( $meta_key, '_mk20_ext_cert_path_' ) ) {
                    foreach ( (array) $meta_values as $meta_value ) {
                        $rows[] = (object) array(
                            'user_id' => $user_id,
                            'mk20_key' => $meta_key,
                            'mk20_val' => $meta_value,
                        );
                    }
                }
            }
        }

        usort( $rows, static function ( $a, $b ) {
            if ( (int) $a->user_id === (int) $b->user_id ) {
                return strcmp( $a->mk20_key, $b->mk20_key );
            }
            return (int) $a->user_id <=> (int) $b->user_id;
        } );

        $total   = count( $rows );
        $results = array_slice( $rows, $offset, $per_page );

        $deleted_notice = get_transient( 'mk20_cert_deleted_notice' );
        if ( $deleted_notice ) {
            delete_transient( 'mk20_cert_deleted_notice' );
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( $deleted_notice ) . '</p></div>';
        }

        $total_pages = ceil( $total / $per_page );
        $page_links  = paginate_links( [
            'base'      => add_query_arg( 'paged', '%#%' ),
            'format'    => '',
            'prev_text' => '&laquo;',
            'next_text' => '&raquo;',
            'total'     => $total_pages,
            'current'   => $current_page,
        ] );

        ?>
        <div class="wrap">
            <h1 class="wp-heading-inline"><?php esc_html_e( 'Todos los Certificados', 'mk20-custom-certificates' ); ?></h1>
            <p class="description"><?php esc_html_e( 'Listado completo de certificados generados por el plugin en todos los usuarios.', 'mk20-custom-certificates' ); ?></p>

            <?php if ( empty( $results ) ) : ?>
                <div class="notice notice-info"><p><?php esc_html_e( 'No hay certificados generados aún.', 'mk20-custom-certificates' ); ?></p></div>
                <?php return; ?>
            <?php endif; ?>

            <?php if ( $page_links ) : ?>
                <div class="tablenav top" style="margin: 12px 0;">
                    <div class="tablenav-pages"><?php echo wp_kses_post( $page_links ); ?></div>
                </div>
            <?php endif; ?>

            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th style="width:40px;"><?php esc_html_e( 'ID', 'mk20-custom-certificates' ); ?></th>
                        <th><?php esc_html_e( 'Usuario', 'mk20-custom-certificates' ); ?></th>
                        <th><?php esc_html_e( 'Curso', 'mk20-custom-certificates' ); ?></th>
                        <th style="width:90px;"><?php esc_html_e( 'Tipo', 'mk20-custom-certificates' ); ?></th>
                        <th style="width:100px;"><?php esc_html_e( 'Fecha', 'mk20-custom-certificates' ); ?></th>
                        <th style="width:100px;"><?php esc_html_e( 'Descargar', 'mk20-custom-certificates' ); ?></th>
                        <th style="width:80px;"><?php esc_html_e( 'Eliminar', 'mk20-custom-certificates' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ( $results as $row ) :
                    $is_external = strpos( $row->mk20_key, '_mk20_ext_cert_path_' ) === 0;
                    $user_info   = get_userdata( $row->user_id );

                    if ( ! $user_info ) {
                        continue;
                    }

                    if ( $is_external ) {
                        $hash         = str_replace( '_mk20_ext_cert_path_', '', $row->mk20_key );
                        $course_title = get_user_meta( $row->user_id, '_mk20_ext_course_title_' . $hash, true );
                        $cert_date    = get_user_meta( $row->user_id, '_mk20_ext_cert_date_' . $hash, true );
                        $cert_fmt     = $cert_date ? date_i18n( 'd/m/Y', strtotime( $cert_date ) ) : '—';
                        $download_url = wp_nonce_url( add_query_arg( [ 'ext_cert' => $hash, 'user_id' => $row->user_id ], admin_url( 'admin-post.php?action=mk20_download_ext_cert' ) ), 'mk20_download_ext_cert_' . $hash );
                    } else {
                        $course_id    = intval( str_replace( '_mk20_cert_path_', '', $row->mk20_key ) );
                        if ( ! $course_id ) {
                            continue;
                        }
                        $course_title = mk20_clean_course_title( get_the_title( $course_id ) );
                        $cert_date    = get_user_meta( $row->user_id, '_mk20_cert_date_' . $course_id, true );
                        $cert_fmt     = $cert_date ? date_i18n( 'd/m/Y', strtotime( $cert_date ) ) : '—';
                        $download_url = wp_nonce_url( add_query_arg( [ 'course_id' => $course_id, 'user_id' => $row->user_id ], admin_url( 'admin-post.php?action=mk20_download_cert' ) ), 'mk20_download_cert_' . $course_id );
                    }

                    $user_name   = $user_info->display_name;
                    $user_url    = admin_url( 'user-edit.php?user_id=' . $row->user_id );
                    $profile_url = bp_core_get_userlink( $row->user_id, false, true );
                    ?>
                    <tr>
                        <td><?php echo absint( $row->user_id ); ?></td>
                        <td>
                            <a href="<?php echo esc_url( $profile_url ? $profile_url : $user_url ); ?>" target="_blank">
                                <?php echo get_avatar( $row->user_id, 24 ); ?>
                                <?php echo esc_html( $user_name ); ?>
                            </a>
                        </td>
                        <td><?php echo esc_html( $course_title ?: '—' ); ?></td>
                        <td><span class="mk20-badge <?php echo $is_external ? 'mk20-badge-ext' : 'mk20-badge-native'; ?>"><?php echo $is_external ? esc_html__( 'Externo', 'mk20-custom-certificates' ) : esc_html__( 'Online', 'mk20-custom-certificates' ); ?></span></td>
                        <td><?php echo esc_html( $cert_fmt ); ?></td>
                        <td>
                            <a href="<?php echo esc_url( $download_url ); ?>" class="button button-small" style="background:#10b981;color:#fff;border:none;text-decoration:none;">
                                <?php esc_html_e( 'PDF', 'mk20-custom-certificates' ); ?>
                            </a>
                        </td>
                        <td>
                            <?php if ( ! $is_external ) :
                                $delete_url = wp_nonce_url( add_query_arg( [ 'course_id' => $course_id, 'user_id' => $row->user_id ], admin_url( 'admin-post.php?action=mk20_delete_cert' ) ), 'mk20_delete_cert_' . $course_id );
                                ?>
                                <a href="<?php echo esc_url( $delete_url ); ?>"
                                   class="button button-small"
                                   style="background:#ef4444;color:#fff;border:none;text-decoration:none;"
                                   onclick="return confirm('<?php echo esc_js( __( '¿Eliminar este certificado permanentemente?', 'mk20-custom-certificates' ) ); ?>');">
                                    <?php esc_html_e( 'Eliminar', 'mk20-custom-certificates' ); ?>
                                </a>
                            <?php else : ?>
                                <span style="color:#94a3b8;">—</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>

            <?php if ( $page_links ) : ?>
                <div class="tablenav bottom" style="margin:12px 0;">
                    <div class="tablenav-pages"><?php echo wp_kses_post( $page_links ); ?></div>
                </div>
            <?php endif; ?>
        </div>

        <style>
            .mk20-badge {
                display: inline-block;
                padding: 3px 8px;
                border-radius: 4px;
                font-size: 11px;
                font-weight: 600;
                text-transform: uppercase;
            }
            .mk20-badge-native {
                background: #dbeafe;
                color: #1e40af;
            }
            .mk20-badge-ext {
                background: #fef3c7;
                color: #92400e;
            }
            .mk20-certificates-table td { vertical-align: middle; }
        </style>
        <?php
    }

    /**
     * Maneja la generación del PDF de prueba mediante la acción admin_post
     */
    public function generate_preview_pdf() {
        // Verificar capacidades de administración
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'No tienes permisos suficientes para acceder a esta página.', 'mk20-custom-certificates' ) );
        }

        // Cargar motor PDF
        $pdf_engine = new MK20_PDF_Engine();

        // Obtener configuraciones guardadas para obtener los fallbacks
        $settings = get_option( 'mk20_cert_settings', [] );

        // Datos ficticios para la demostración
        $test_student      = 'Jose María Nuñez de la Vega';
        $test_course       = 'INTRODUCCIÓN A LADISFAGIA';
        $test_date         = '26/02/2026';
        $test_dni          = ! empty( $settings['fallback_dni'] ) ? $settings['fallback_dni'] : '44123456X';
        $test_company      = ! empty( $settings['fallback_company'] ) ? $settings['fallback_company'] : 'RESIDENCIA DE ANCIANOS VIRGEN DE LA CARIDAD';
        $test_cif          = ! empty( $settings['fallback_cif'] ) ? $settings['fallback_cif'] : 'G31114141';
        $test_course_code  = ! empty( $settings['fallback_course_code'] ) ? $settings['fallback_course_code'] : '26.3';
        $test_start_date   = ! empty( $settings['fallback_course_start_date'] ) ? $settings['fallback_course_start_date'] : '26/02/2026';
        $test_end_date     = ! empty( $settings['fallback_course_end_date'] ) ? $settings['fallback_course_end_date'] : '26/02/2026';
        $test_duration     = ! empty( $settings['fallback_course_duration'] ) ? $settings['fallback_course_duration'] : '3 horas';
        $test_modality     = ! empty( $settings['fallback_course_modality'] ) ? $settings['fallback_course_modality'] : 'Online';
        $test_contents     = ! empty( $settings['fallback_course_contents'] ) ? $settings['fallback_course_contents'] : "Módulo 1: Introducción\nMódulo 2: Desarrollo\nMódulo 3: Evaluación final";
 
        // Generar certificado temporal
        // Usamos ID 0 para usuario y curso para identificar que es una prueba
        $pdf_path = $pdf_engine->generate(
            $test_student,
            $test_course,
            $test_date,
            0,
            0,
            $test_dni,
            $test_company,
            $test_cif,
            $test_course_code,
            $test_start_date,
            $test_end_date,
            $test_duration,
            $test_modality,
            $test_contents
        );

        if ( $pdf_path && file_exists( $pdf_path ) ) {
            // Cabeceras para enviar PDF inline
            header( 'Content-Type: application/pdf' );
            header( 'Content-Disposition: attachment; filename="certificado_prueba.pdf"' );
            header( 'Content-Length: ' . filesize( $pdf_path ) );
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile
            readfile( $pdf_path );
            
            // Eliminar archivo de prueba inmediatamente para evitar basura
            wp_delete_file( $pdf_path );
            exit;
        } else {
            wp_die( esc_html__( 'Error al generar la vista previa del PDF. Comprueba los permisos de escritura o la disponibilidad de FPDF.', 'mk20-custom-certificates' ) );
        }
    }

    /**
     * Registra el Meta Box en el editor de cursos de LearnDash
     */
    public function add_course_meta_box() {
        add_meta_box(
            'mk20_course_certificate_data',
            __( 'Datos para Certificado (MK20)', 'mk20-custom-certificates' ),
            [ $this, 'render_course_meta_box' ],
            'sfwd-courses',
            'normal',
            'high'
        );
    }

    /**
     * Renderiza el contenido del Meta Box de curso
     */
    public function render_course_meta_box( $post ) {
        // Añadir un nonce de seguridad
        wp_nonce_field( 'mk20_save_course_meta_action', 'mk20_course_meta_nonce' );

        // Obtener valores guardados
        $course_code     = get_post_meta( $post->ID, '_mk20_course_code', true );
        $start_date      = get_post_meta( $post->ID, '_mk20_course_start_date', true );
        $end_date        = get_post_meta( $post->ID, '_mk20_course_end_date', true );
        $duration        = get_post_meta( $post->ID, '_mk20_course_duration', true );
        $modality        = get_post_meta( $post->ID, '_mk20_course_modality', true );
        $course_contents = get_post_meta( $post->ID, '_mk20_course_contents', true );
        
        ?>
        <style>
            .mk20-meta-grid {
                display: grid;
                grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
                gap: 15px;
                padding: 10px 0;
            }
            .mk20-meta-field {
                display: flex;
                flex-direction: column;
            }
            .mk20-meta-field label {
                font-weight: 600;
                margin-bottom: 5px;
                color: #32373c;
            }
            .mk20-meta-field input,
            .mk20-meta-field textarea {
                padding: 6px;
                border-radius: 4px;
                border: 1px solid #7e8993;
            }
        </style>
        <div class="mk20-meta-grid">
            <div class="mk20-meta-field">
                <label for="mk20_course_code"><?php esc_html_e( 'Código AF / Grupo', 'mk20-custom-certificates' ); ?></label>
                <input type="text" id="mk20_course_code" name="mk20_course_code" value="<?php echo esc_attr( $course_code ); ?>" placeholder="Ej: 26.3">
            </div>
            <div class="mk20-meta-field">
                <label for="mk20_course_start_date"><?php esc_html_e( 'Fecha de Inicio', 'mk20-custom-certificates' ); ?></label>
                <input type="text" id="mk20_course_start_date" name="mk20_course_start_date" value="<?php echo esc_attr( $start_date ); ?>" placeholder="Ej: 26/02/2026">
            </div>
            <div class="mk20-meta-field">
                <label for="mk20_course_end_date"><?php esc_html_e( 'Fecha de Fin', 'mk20-custom-certificates' ); ?></label>
                <input type="text" id="mk20_course_end_date" name="mk20_course_end_date" value="<?php echo esc_attr( $end_date ); ?>" placeholder="Ej: 26/02/2026">
            </div>
            <div class="mk20-meta-field">
                <label for="mk20_course_duration"><?php esc_html_e( 'Duración (Horas)', 'mk20-custom-certificates' ); ?></label>
                <input type="text" id="mk20_course_duration" name="mk20_course_duration" value="<?php echo esc_attr( $duration ); ?>" placeholder="Ej: 3 horas">
            </div>
            <div class="mk20-meta-field">
                <label for="mk20_course_modality"><?php esc_html_e( 'Modalidad Formativa', 'mk20-custom-certificates' ); ?></label>
                <input type="text" id="mk20_course_modality" name="mk20_course_modality" value="<?php echo esc_attr( $modality ); ?>" placeholder="Ej: Online o Presencial">
            </div>
        </div>
        <div class="mk20-meta-field" style="margin-top: 15px;">
            <label for="mk20_course_contents"><?php esc_html_e( 'Contenidos del Curso (Reverso)', 'mk20-custom-certificates' ); ?></label>
            <textarea id="mk20_course_contents" name="mk20_course_contents" rows="4" style="width: 100%;"><?php echo esc_textarea( $course_contents ); ?></textarea>
        </div>
        <p class="description" style="margin-top: 10px;">
            <?php esc_html_e( 'Estos valores sobrescribirán los datos genéricos del certificado para este curso en particular.', 'mk20-custom-certificates' ); ?>
        </p>
        <?php
    }

    /**
     * Guarda los datos del Meta Box al guardar el curso
     */
    public function save_course_meta( $post_id ) {
        // Verificar nonce de seguridad
        if ( ! isset( $_POST['mk20_course_meta_nonce'] ) || ! wp_verify_nonce( wp_unslash( $_POST['mk20_course_meta_nonce'] ), 'mk20_save_course_meta_action' ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
            return;
        }

        // Evitar autoguardados
        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
            return;
        }

        // Verificar permisos
        if ( ! current_user_can( 'edit_post', $post_id ) ) {
            return;
        }

        // Guardar cada campo sanitizado
        $fields = [
            'mk20_course_code'       => '_mk20_course_code',
            'mk20_course_start_date' => '_mk20_course_start_date',
            'mk20_course_end_date'   => '_mk20_course_end_date',
            'mk20_course_duration'   => '_mk20_course_duration',
            'mk20_course_modality'   => '_mk20_course_modality',
            'mk20_course_contents'   => '_mk20_course_contents',
        ];

        foreach ( $fields as $post_key => $meta_key ) {
            if ( isset( $_POST[ $post_key ] ) ) {
                $sanitized = $post_key === 'mk20_course_contents' ? sanitize_textarea_field( wp_unslash( $_POST[ $post_key ] ) ) : sanitize_text_field( wp_unslash( $_POST[ $post_key ] ) );
                update_post_meta( $post_id, $meta_key, $sanitized );
            }
        }
    }
}
