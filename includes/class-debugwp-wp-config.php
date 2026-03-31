<?php
/**
 * WP Config reader/writer — manages debug constants in wp-config.php.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class DebugWP_WP_Config {

    const BACKUP_SUFFIX = '.debugwp-backup';

    private static $constants = [
        'WP_DEBUG',
        'WP_DEBUG_LOG',
        'WP_DEBUG_DISPLAY',
        'SCRIPT_DEBUG',
    ];

    /**
     * Read the current state of debug constants from wp-config.php.
     *
     * @return array [ 'WP_DEBUG' => true|false|null, ... ]
     */
    public static function read() {
        $values = [];
        foreach ( self::$constants as $const ) {
            if ( defined( $const ) ) {
                $values[ $const ] = constant( $const );
            } else {
                $values[ $const ] = null;
            }
        }
        return $values;
    }

    /**
     * Update a single constant in wp-config.php.
     *
     * @param string $constant  Constant name (must be in allowed list).
     * @param bool   $value     New value.
     * @return true|WP_Error
     */
    public static function update( $constant, $value ) {
        if ( ! in_array( $constant, self::$constants, true ) ) {
            return new WP_Error( 'invalid_constant', 'Constant not supported.' );
        }

        $config_path = self::get_config_path();
        if ( ! $config_path ) {
            return new WP_Error( 'no_config', 'Cannot locate wp-config.php.' );
        }

        if ( ! is_writable( $config_path ) ) {
            return new WP_Error( 'not_writable', 'wp-config.php is not writable.' );
        }

        // Create backup before first modification.
        $backup_path = $config_path . self::BACKUP_SUFFIX;
        if ( ! file_exists( $backup_path ) ) {
            if ( ! copy( $config_path, $backup_path ) ) {
                return new WP_Error( 'backup_failed', 'Could not create wp-config.php backup.' );
            }
        }

        $contents = file_get_contents( $config_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
        if ( false === $contents ) {
            return new WP_Error( 'read_failed', 'Could not read wp-config.php.' );
        }

        $php_value = $value ? 'true' : 'false';

        // Try to replace existing define.
        $pattern = '/define\s*\(\s*[\'"]' . preg_quote( $constant, '/' ) . '[\'"]\s*,\s*[^)]+\)/';

        if ( preg_match( $pattern, $contents ) ) {
            $replacement = "define( '{$constant}', {$php_value} )";
            $contents    = preg_replace( $pattern, $replacement, $contents, 1 );
        } else {
            // Add before "That's all, stop editing" comment or before wp-settings require.
            $anchor_patterns = [
                "/(\/\*\s*That's all.*?\*\/)/s",
                '/(require_once.*wp-settings\.php)/',
            ];

            $inserted = false;
            foreach ( $anchor_patterns as $anchor ) {
                if ( preg_match( $anchor, $contents ) ) {
                    $line     = "define( '{$constant}', {$php_value} );\n";
                    $contents = preg_replace( $anchor, $line . '$1', $contents, 1 );
                    $inserted = true;
                    break;
                }
            }

            if ( ! $inserted ) {
                return new WP_Error( 'anchor_not_found', 'Could not find insertion point in wp-config.php.' );
            }
        }

        $written = file_put_contents( $config_path, $contents ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
        if ( false === $written ) {
            return new WP_Error( 'write_failed', 'Could not write wp-config.php.' );
        }

        return true;
    }

    /**
     * Check if a backup exists.
     */
    public static function has_backup() {
        $path = self::get_config_path();
        return $path && file_exists( $path . self::BACKUP_SUFFIX );
    }

    /**
     * Restore wp-config.php from backup.
     *
     * @return true|WP_Error
     */
    public static function restore_backup() {
        $config_path = self::get_config_path();
        $backup_path = $config_path . self::BACKUP_SUFFIX;

        if ( ! file_exists( $backup_path ) ) {
            return new WP_Error( 'no_backup', 'No backup file found.' );
        }

        if ( ! copy( $backup_path, $config_path ) ) {
            return new WP_Error( 'restore_failed', 'Could not restore wp-config.php from backup.' );
        }

        unlink( $backup_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
        return true;
    }

    private static function get_config_path() {
        if ( file_exists( ABSPATH . 'wp-config.php' ) ) {
            return ABSPATH . 'wp-config.php';
        }
        if ( file_exists( dirname( ABSPATH ) . '/wp-config.php' ) ) {
            return dirname( ABSPATH ) . '/wp-config.php';
        }
        return null;
    }
}
