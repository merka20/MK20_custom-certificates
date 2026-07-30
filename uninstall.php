<?php
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

$settings = get_option( 'mk20_cert_settings', [] );
$keep     = isset( $settings['keep_data_on_uninstall'] ) ? (int) $settings['keep_data_on_uninstall'] : 1;

if ( $keep ) {
    return;
}

delete_option( 'mk20_cert_settings' );
delete_option( 'mk20_rejected_certificates' );

global $wpdb;

$wpdb->query(
    $wpdb->prepare(
        "DELETE FROM {$wpdb->usermeta} WHERE meta_key LIKE %s",
        $wpdb->esc_like( '_mk20_cert_' ) . '%'
    )
);

$wpdb->query(
    $wpdb->prepare(
        "DELETE FROM {$wpdb->usermeta} WHERE meta_key LIKE %s",
        $wpdb->esc_like( '_mk20_ext_cert_' ) . '%'
    )
);

delete_transient( 'mk20_fpdf_update_check' );

$upload_dir = wp_upload_dir();
$cert_dir   = $upload_dir['basedir'] . '/mk20-certificates';
if ( file_exists( $cert_dir ) ) {
    $files = glob( $cert_dir . '/*.pdf' );
    if ( is_array( $files ) ) {
        foreach ( $files as $file ) {
            unlink( $file );
        }
    }
    @rmdir( $cert_dir );
}
