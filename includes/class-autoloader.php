<?php
namespace Jetonomy;

defined( 'ABSPATH' ) || exit;

class Autoloader {

    private static array $map = [
        'Jetonomy\\Models\\'        => 'includes/models/',
        'Jetonomy\\Permissions\\'   => 'includes/permissions/',
        'Jetonomy\\Trust\\'         => 'includes/trust/',
        'Jetonomy\\API\\'           => 'includes/api/',
        'Jetonomy\\Adapters\\'      => 'includes/adapters/',
        'Jetonomy\\Search\\'        => 'includes/search/',
        'Jetonomy\\Moderation\\'    => 'includes/moderation/',
        'Jetonomy\\Notifications\\' => 'includes/notifications/',
        'Jetonomy\\Import\\'        => 'includes/import/',
        'Jetonomy\\Widgets\\'       => 'includes/widgets/',
        'Jetonomy\\Admin\\Ajax\\'   => 'includes/admin/ajax/',
        'Jetonomy\\Admin\\'         => 'includes/admin/',
        'Jetonomy\\SEO\\'           => 'includes/seo/',
        'Jetonomy\\Captcha\\'       => 'includes/captcha/',
        'Jetonomy\\DB\\'            => 'includes/db/',
        'Jetonomy\\DB\\Migrations\\' => 'includes/db/migrations/',
        'Jetonomy\\QA\\'            => 'includes/qa/',
        'Jetonomy\\'               => 'includes/',
    ];

    public static function register(): void {
        spl_autoload_register( [ __CLASS__, 'autoload' ] );
    }

    public static function autoload( string $class ): void {
        // Only handle Jetonomy namespace
        if ( 0 !== strpos( $class, 'Jetonomy\\' ) ) {
            return;
        }

        // Check each namespace prefix
        foreach ( self::$map as $prefix => $dir ) {
            if ( 0 === strpos( $class, $prefix ) ) {
                $relative = substr( $class, strlen( $prefix ) );
                $file = JETONOMY_DIR . $dir . 'class-' . self::class_to_file( $relative ) . '.php';

                if ( file_exists( $file ) ) {
                    require_once $file;
                    return;
                }

                // Try interface files
                $file = JETONOMY_DIR . $dir . 'interface-' . self::class_to_file( $relative ) . '.php';
                if ( file_exists( $file ) ) {
                    require_once $file;
                    return;
                }
            }
        }
    }

    /**
     * Convert class name to file name.
     * Examples:
     *   Permission_Engine -> permission-engine
     *   UserProfile -> user-profile
     *   WP_Roles_Adapter -> wp-roles-adapter
     *   BBPress_Importer -> bbpress-importer
     *   WP_Mail_Adapter -> wp-mail-adapter
     *   Migration_1_0_0 -> migration_1_0_0
     */
    private static function class_to_file( string $class ): string {
        // Handle underscore-separated names (e.g., Permission_Engine -> permission-engine)
        // But preserve version underscores (e.g., Migration_1_0_0 -> migration-1_0_0)

        // First, insert hyphens before uppercase letters that follow lowercase
        $file = preg_replace( '/([a-z])([A-Z])/', '$1-$2', $class );

        // Replace underscores with hyphens, but preserve numeric version patterns
        $file = preg_replace( '/_(?=[a-zA-Z])/', '-', $file );

        return strtolower( $file );
    }
}
