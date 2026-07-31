<?php
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

$mk20_settings = get_option( 'mk20_cert_settings', [] );
$mk20_keep     = isset( $mk20_settings['keep_data_on_uninstall'] ) ? (int) $mk20_settings['keep_data_on_uninstall'] : 1;

if ( $mk20_keep ) {
    return;
}

delete_option( 'mk20_cert_settings' );
delete_option( 'mk20_rejected_certificates' );
delete_option( 'mk20_cert_index_db_version' );

$mk20_users = get_users( array(
    'fields' => 'ID',
) );

foreach ( $mk20_users as $mk20_user_id ) {
    $mk20_user_meta = get_user_meta( $mk20_user_id );
    foreach ( array_keys( $mk20_user_meta ) as $mk20_meta_key ) {
        if ( 0 === strpos( $mk20_meta_key, '_mk20_cert_' ) || 0 === strpos( $mk20_meta_key, '_mk20_ext_cert_' ) ) {
            delete_user_meta( $mk20_user_id, $mk20_meta_key );
        }
    }
}

delete_transient( 'mk20_fpdf_update_check' );

global $wpdb;
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- Drop de tabla propia en desinstalacion; limpieza intencional, no aplica cache.
$wpdb->query(
    'DROP TABLE IF EXISTS ' . $wpdb->prefix . 'mk20_certs'
);
// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange

global $wp_filesystem;
if ( empty( $wp_filesystem ) ) {
    require_once ABSPATH . 'wp-admin/includes/file.php';
    WP_Filesystem();
}

$mk20_upload_dir = wp_upload_dir();
$mk20_cert_dir   = $mk20_upload_dir['basedir'] . '/mk20-certificates';
if ( $wp_filesystem && $wp_filesystem->exists( $mk20_cert_dir ) ) {
    $wp_filesystem->delete( $mk20_cert_dir, true );
}
