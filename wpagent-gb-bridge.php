<?php
/**
 * Plugin Name:       WPAgent GB Bridge
 * Plugin URI:        https://jimmypazos.es
 * Description:       Extensión MCP y motor de bloques GenerateBlocks 2.x para crear y editar borradores vía WordPress Abilities API con máxima optimización de tokens.
 * Version:           1.0.6
 * Requires at least: 6.5
 * Requires PHP:      8.3
 * Author:            Jimmy Pazos
 * Author URI:        https://jimmypazos.es
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       wpagent-gb-bridge
 * Domain Path:       /languages
 *
 * @package WPAgent_GB_Bridge
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Constantes fundamentales del plugin.
 */
define( 'WPAGENT_GB_VERSION', '1.0.6' );
define( 'WPAGENT_GB_MIN_PHP_VERSION', '8.3.0' );
define( 'WPAGENT_GB_MIN_WP_VERSION', '6.5' );
define( 'WPAGENT_GB_FILE', __FILE__ );
define( 'WPAGENT_GB_PATH', plugin_dir_path( __FILE__ ) );
define( 'WPAGENT_GB_URL', plugin_dir_url( __FILE__ ) );
define( 'WPAGENT_GB_INCLUDES_PATH', WPAGENT_GB_PATH . 'includes/' );
define( 'WPAGENT_GB_TEMPLATES_PATH', WPAGENT_GB_PATH . 'templates/' );

/**
 * Verificación inmediata de versión de PHP requerida en tiempo de carga.
 */
if ( version_compare( PHP_VERSION, WPAGENT_GB_MIN_PHP_VERSION, '<' ) ) {
	add_action(
		'admin_notices',
		static function (): void {
			printf(
				'<div class="notice notice-error"><p>%s</p></div>',
				esc_html(
					sprintf(
						/* translators: 1: Current PHP version, 2: Required PHP version */
						__( 'WPAgent GB Bridge requiere PHP %2$s o superior para operar. Versión actual detectada: %1$s.', 'wpagent-gb-bridge' ),
						PHP_VERSION,
						WPAGENT_GB_MIN_PHP_VERSION
					)
				)
			);
		}
	);
	return;
}

/**
 * Clase principal orquestadora del plugin WPAgent GB Bridge.
 *
 * Sigue el patrón Singleton para centralizar la carga modular de componentes,
 * el registro en la Abilities API y el ciclo de vida del plugin.
 */
final class WPAgent_GB_Bridge {

	/**
	 * Instancia única de la clase.
	 *
	 * @var self|null
	 */
	private static ?self $instance = null;

	/**
	 * Instancia del Sanitizador e Inyector determinista de uniqueId.
	 *
	 * @var WPAgent_GB_Sanitizer|null
	 */
	public ?WPAgent_GB_Sanitizer $sanitizer = null;

	/**
	 * Instancia del Parser de Markdown semántico a bloques.
	 *
	 * @var WPAgent_GB_Markdown_Parser|null
	 */
	public ?WPAgent_GB_Markdown_Parser $markdown_parser = null;

	/**
	 * Instancia del Constructor y serializador de bloques GenerateBlocks v2.
	 *
	 * @var WPAgent_GB_Block_Builder|null
	 */
	public ?WPAgent_GB_Block_Builder $block_builder = null;

	/**
	 * Instancia del Purgador de caché multinivel (Redis + WP Fastest Cache).
	 *
	 * @var WPAgent_GB_Cache_Purger|null
	 */
	public ?WPAgent_GB_Cache_Purger $cache_purger = null;

	/**
	 * Instancia del Registro de Habilidades para la Abilities API / MCP.
	 *
	 * @var WPAgent_GB_Ability_Registry|null
	 */
	public ?WPAgent_GB_Ability_Registry $ability_registry = null;

	/**
	 * Instancia de la herramienta de Diagnóstico Forense.
	 *
	 * @var WPAgent_GB_Diagnostics|null
	 */
	public ?WPAgent_GB_Diagnostics $diagnostics = null;

	/**
	 * Obtiene la instancia única de la clase (Singleton).
	 *
	 * @return self
	 */
	public static function get_instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor privado para prevenir instanciación externa.
	 */
	private function __construct() {
		$this->load_dependencies();
		$this->init_components();
		$this->setup_hooks();
	}

	/**
	 * Previene clonar la instancia.
	 */
	private function __clone() {}

	/**
	 * Previene deserializar la instancia.
	 *
	 * @throws \Exception Si se intenta deserializar.
	 */
	public function __wakeup(): void {
		throw new \Exception( 'No está permitido deserializar la clase Singleton ' . __CLASS__ );
	}

	/**
	 * Carga de forma segura y modular los archivos de dependencias del plugin
	 * respetando estrictamente el orden de resolución de clases.
	 */
	public function load_dependencies(): void {
		$dependencies = [
			WPAGENT_GB_INCLUDES_PATH . 'class-sanitizer.php',
			WPAGENT_GB_INCLUDES_PATH . 'class-markdown-parser.php',
			WPAGENT_GB_INCLUDES_PATH . 'class-gb-block-builder.php',
			WPAGENT_GB_INCLUDES_PATH . 'class-cache-purger.php',
			WPAGENT_GB_INCLUDES_PATH . 'class-ability-registry.php',
			WPAGENT_GB_INCLUDES_PATH . 'class-diagnostics.php',
		];

		foreach ( $dependencies as $file ) {
			if ( file_exists( $file ) ) {
				require_once $file;
			}
		}
	}

	/**
	 * Configura los ganchos (hooks) del ciclo de vida del plugin.
	 */
	private function setup_hooks(): void {
		add_action( 'enqueue_block_assets', [ $this, 'enqueue_block_assets' ] );
		add_action( 'admin_notices', [ $this, 'check_environment_notices' ] );

		register_activation_hook( WPAGENT_GB_FILE, [ self::class, 'activate' ] );
		register_deactivation_hook( WPAGENT_GB_FILE, [ self::class, 'deactivate' ] );
	}

	/**
	 * Encola la hoja de estilos de compatibilidad y espaciado para el editor Gutenberg y el frontend.
	 */
	public function enqueue_block_assets(): void {
		$css_file = WPAGENT_GB_PATH . 'assets/css/gb-bridge.css';
		if ( file_exists( $css_file ) ) {
			wp_enqueue_style(
				'wpagent-gb-bridge',
				WPAGENT_GB_URL . 'assets/css/gb-bridge.css',
				[],
				WPAGENT_GB_VERSION
			);
		}
	}

	/**
	 * Inicializa los subsistemas del plugin tras comprobar dependencias activas.
	 */
	public function init_components(): void {
		// 1. Cargar dependencias en orden estricto.
		$this->load_dependencies();

		// 2. Instanciación e interconexión de componentes.
		if ( class_exists( 'WPAgent_GB_Sanitizer' ) ) {
			$this->sanitizer = new WPAgent_GB_Sanitizer();
		}

		if ( class_exists( 'WPAgent_GB_Markdown_Parser' ) ) {
			$this->markdown_parser = new WPAgent_GB_Markdown_Parser( $this->sanitizer );
		}

		if ( class_exists( 'WPAgent_GB_Block_Builder' ) ) {
			$this->block_builder = new WPAgent_GB_Block_Builder(
				$this->sanitizer,
				$this->markdown_parser
			);
		}

		if ( class_exists( 'WPAgent_GB_Cache_Purger' ) ) {
			$this->cache_purger = new WPAgent_GB_Cache_Purger();
		}

		if ( class_exists( 'WPAgent_GB_Ability_Registry' ) ) {
			$this->ability_registry = new WPAgent_GB_Ability_Registry(
				$this->block_builder,
				$this->cache_purger,
				$this->sanitizer,
				$this->markdown_parser
			);
			$this->ability_registry->init();
		}

		if ( class_exists( 'WPAgent_GB_Diagnostics' ) ) {
			$this->diagnostics = new WPAgent_GB_Diagnostics();
			$this->diagnostics->init();
		}
	}

	/**
	 * Comprueba la presencia de GenerateBlocks y emite aviso administrativo si falta.
	 */
	public function check_environment_notices(): void {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}

		if ( ! $this->is_generateblocks_active() ) {
			printf(
				'<div class="notice notice-warning is-dismissible"><p><strong>%s:</strong> %s</p></div>',
				esc_html__( 'WPAgent GB Bridge', 'wpagent-gb-bridge' ),
				esc_html__( 'GenerateBlocks no parece estar activo. El plugin seguirá disponible vía MCP pero los estilos nativos atómicos requieren GenerateBlocks 2.4.x para su renderizado óptimo.', 'wpagent-gb-bridge' )
			);
		}
	}

	/**
	 * Determina si GenerateBlocks está activo en la instalación actual.
	 *
	 * @return bool
	 */
	public function is_generateblocks_active(): bool {
		return defined( 'GENERATEBLOCKS_VERSION' ) || class_exists( 'GenerateBlocks' );
	}

	/**
	 * Gancho de activación del plugin con validación estricta de entorno.
	 */
	public static function activate(): void {
		global $wp_version;

		// 1. Verificación de versión mínima de PHP.
		if ( version_compare( PHP_VERSION, WPAGENT_GB_MIN_PHP_VERSION, '<' ) ) {
			deactivate_plugins( plugin_basename( WPAGENT_GB_FILE ) );
			wp_die(
				esc_html(
					sprintf(
						/* translators: 1: Required PHP version, 2: Current PHP version */
						__( 'No es posible activar WPAgent GB Bridge. Se requiere PHP %1$s o superior. Su versión actual es %2$s.', 'wpagent-gb-bridge' ),
						WPAGENT_GB_MIN_PHP_VERSION,
						PHP_VERSION
					)
				)
			);
		}

		// 2. Verificación de versión mínima de WordPress.
		$current_wp_version = ! empty( $wp_version ) ? $wp_version : get_bloginfo( 'version' );
		if ( version_compare( $current_wp_version, WPAGENT_GB_MIN_WP_VERSION, '<' ) ) {
			deactivate_plugins( plugin_basename( WPAGENT_GB_FILE ) );
			wp_die(
				esc_html(
					sprintf(
						/* translators: 1: Required WordPress version, 2: Current WordPress version */
						__( 'No es posible activar WPAgent GB Bridge. Se requiere WordPress %1$s o superior. Su versión actual es %2$s.', 'wpagent-gb-bridge' ),
						WPAGENT_GB_MIN_WP_VERSION,
						$current_wp_version
					)
				)
			);
		}
	}

	/**
	 * Gancho de desactivación del plugin.
	 */
	public static function deactivate(): void {
		// Limpieza de estados temporales si aplica.
	}
}

/**
 * Arranque oficial de la instancia del plugin.
 */
function wpagent_gb_bridge(): WPAgent_GB_Bridge {
	return WPAgent_GB_Bridge::get_instance();
}

// Inicializar orquestador.
wpagent_gb_bridge();
