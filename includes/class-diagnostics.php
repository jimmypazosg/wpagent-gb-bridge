<?php
/**
 * Herramienta de Diagnóstico Forense para la WordPress Abilities API y MCP.
 *
 * @package WPAgent_GB_Bridge
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Clase WPAgent_GB_Diagnostics.
 *
 * Añade una pantalla de diagnóstico bajo "Herramientas > Diagnóstico WPAgent"
 * para auditar las funciones, hooks y archivos reales del plugin AI en producción.
 */
class WPAgent_GB_Diagnostics {

	/**
	 * Inicializa el hook para el menú de administración.
	 */
	public function init(): void {
		add_action( 'admin_menu', [ $this, 'register_admin_menu' ] );
	}

	/**
	 * Registra la página de submenú bajo "Herramientas".
	 */
	public function register_admin_menu(): void {
		add_submenu_page(
			'tools.php',
			__( 'Diagnóstico WPAgent', 'wpagent-gb-bridge' ),
			__( 'Diagnóstico WPAgent', 'wpagent-gb-bridge' ),
			'manage_options',
			'wpagent-gb-diagnostics',
			[ $this, 'render_page' ]
		);
	}

	/**
	 * Callback ficticio para pruebas de registro en vivo.
	 */
	public static function dummy_callback(): array {
		return [ 'success' => true ];
	}

	/**
	 * Renderiza la interfaz de diagnóstico en el panel de WordPress.
	 */
	public function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'No tienes permisos suficientes para acceder a esta página.', 'wpagent-gb-bridge' ) );
		}

		$report = $this->generate_diagnostic_report();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Diagnóstico Forense: WPAgent GB Bridge', 'wpagent-gb-bridge' ); ?></h1>
			<p><?php esc_html_e( 'Esta herramienta analiza el entorno real de tu servidor, la Abilities API y el plugin oficial AI para resolver con precisión el registro de capacidades.', 'wpagent-gb-bridge' ); ?></p>

			<div style="margin: 20px 0;">
				<button type="button" class="button button-primary" onclick="navigator.clipboard.writeText(document.getElementById('wpagent-diag-output').value); alert('¡Informe copiado al portapapeles!');">
					📋 <?php esc_html_e( 'Copiar Informe al Portapapeles', 'wpagent-gb-bridge' ); ?>
				</button>
			</div>

			<textarea id="wpagent-diag-output" readonly style="width: 100%; height: 650px; font-family: Consolas, Monaco, monospace; font-size: 13px; line-height: 1.5; background: #1e1e1e; color: #d4d4d4; padding: 15px; border-radius: 6px; box-sizing: border-box;"><?php echo esc_textarea( $report ); ?></textarea>
		</div>
		<?php
	}

	/**
	 * Genera el informe forense completo en texto plano.
	 *
	 * @return string Informe formateado.
	 */
	public function generate_diagnostic_report(): string {
		global $wp_version, $wp_filter;
		$lines = [];

		$lines[] = '=======================================================';
		$lines[] = '       INFORME DE DIAGNÓSTICO: WPAGENT-GB-BRIDGE       ';
		$lines[] = '=======================================================';
		$lines[] = 'Fecha/Hora: ' . current_time( 'mysql' );
		$lines[] = 'Sitio:      ' . get_site_url();
		$lines[] = 'PHP:        ' . PHP_VERSION;
		$lines[] = 'WordPress:  ' . ( $wp_version ?? get_bloginfo( 'version' ) );
		$lines[] = 'Servidor:   ' . ( $_SERVER['SERVER_SOFTWARE'] ?? 'Nginx/Desconocido' );
		$lines[] = '';

		// 0. Log de Registro en el Bootstrap del ciclo de vida.
		$lines[] = '--- 0. LOG DE REGISTRO EN EL BOOTSTRAP (wpagent_gb_boot_log) ---';
		$boot_log = get_option( 'wpagent_gb_boot_log', [] );
		if ( ! empty( $boot_log ) && is_array( $boot_log ) ) {
			foreach ( $boot_log as $key => $entry ) {
				$time = $entry['time'] ?? 'N/A';
				$act  = $entry['action'] ?? 'N/A';
				$data = $entry['data'] ?? '';
				$lines[] = sprintf( ' [%s] Evento: %-30s | Action: %-30s | Data: %s', $time, $key, $act, is_scalar( $data ) ? (string) $data : json_encode( $data, JSON_UNESCAPED_SLASHES ) );
			}
		} else {
			$lines[] = ' No se encontró historial de arranque previo en "wpagent_gb_boot_log".';
		}
		$lines[] = '';

		// 1. Prueba de Registro en Vivo con salida exacta de error y fallback al Registry.
		$lines[] = '--- 1. TEST DE REGISTRO EN VIVO DIRECTO (EN ESTE REQUEST) ---';
		$cat_res = null;
		if ( function_exists( 'wp_register_ability_category' ) ) {
			try {
				$cat_res = wp_register_ability_category(
					'wpagent',
					[
						'label'       => 'WPAgent GenerateBlocks',
						'description' => 'Categoría de prueba directa',
					]
				);
				$lines[] = ' [WRAPPER] wp_register_ability_category("wpagent"): ' . ( is_object( $cat_res ) ? get_class( $cat_res ) : var_export( $cat_res, true ) );
			} catch ( \Throwable $e ) {
				$lines[] = ' [WRAPPER EXCEPCIÓN] wp_register_ability_category: ' . $e->getMessage();
			}
		}

		if ( ! $cat_res && class_exists( 'WP_Ability_Categories_Registry' ) ) {
			try {
				$cat_reg = WP_Ability_Categories_Registry::get_instance();
				if ( $cat_reg && method_exists( $cat_reg, 'register' ) ) {
					$direct_cat = $cat_reg->register(
						'wpagent',
						[
							'label'       => 'WPAgent GenerateBlocks',
							'description' => 'Categoría de prueba directa vía Singleton',
						]
					);
					$lines[] = ' [DIRECT SINGLETON] WP_Ability_Categories_Registry->register("wpagent"): ' . ( is_object( $direct_cat ) ? get_class( $direct_cat ) : var_export( $direct_cat, true ) );
				}
			} catch ( \Throwable $e ) {
				$lines[] = ' [DIRECT SINGLETON EXCEPCIÓN] WP_Ability_Categories_Registry: ' . $e->getMessage();
			}
		}

		$ab_res = null;
		$test_args = [
			'label'               => 'Crear Borrador con GenerateBlocks',
			'description'         => 'Habilidad de prueba directa',
			'category'            => 'wpagent',
			'input_schema'        => [
				'type'       => 'object',
				'properties' => [
					'title' => [ 'type' => 'string' ],
				],
				'required'   => [ 'title' ],
			],
			'execute_callback'    => [ self::class, 'dummy_callback' ],
			'permission_callback' => '__return_true',
			'meta'                => [
				'mcp' => [ 'public' => true ],
			],
		];

		if ( function_exists( 'wp_register_ability' ) ) {
			try {
				$ab_res = wp_register_ability( 'wpagent/create-draft-post', $test_args );
				$lines[] = ' [WRAPPER] wp_register_ability("wpagent/create-draft-post"): ' . ( is_object( $ab_res ) ? get_class( $ab_res ) : var_export( $ab_res, true ) );
			} catch ( \Throwable $e ) {
				$lines[] = ' [WRAPPER EXCEPCIÓN] wp_register_ability: ' . $e->getMessage();
			}
		}

		if ( ! $ab_res && class_exists( 'WP_Abilities_Registry' ) ) {
			try {
				$registry = WP_Abilities_Registry::get_instance();
				if ( $registry && method_exists( $registry, 'register' ) ) {
					$direct_ab = $registry->register( 'wpagent/create-draft-post', $test_args );
					$lines[] = ' [DIRECT SINGLETON] WP_Abilities_Registry->register("wpagent/create-draft-post"): ' . ( is_object( $direct_ab ) ? get_class( $direct_ab ) : var_export( $direct_ab, true ) );
				}
			} catch ( \Throwable $e ) {
				$lines[] = ' [DIRECT SINGLETON EXCEPCIÓN] WP_Abilities_Registry: ' . $e->getMessage();
			}
		}
		$lines[] = '';

		// 2. Reflexión de Clases del Núcleo (Ubicación, Constructor y Métodos).
		$lines[] = '--- 2. REFLEXIÓN DE CLASES DEL NÚCLEO DE ABILITIES ---';
		$target_classes = [ 'WP_Abilities_Registry', 'WP_Ability', 'WP_Ability_Categories_Registry', 'WP_Ability_Category' ];
		foreach ( $target_classes as $cls ) {
			if ( class_exists( $cls ) ) {
				try {
					$ref = new \ReflectionClass( $cls );
					$lines[] = sprintf( ' Clase: %s | Archivo: %s (Líneas %d-%d)', $cls, $ref->getFileName() ?: 'N/A', $ref->getStartLine(), $ref->getEndLine() );
					$methods = [];
					foreach ( $ref->getMethods( \ReflectionMethod::IS_PUBLIC ) as $m ) {
						$methods[] = $m->getName();
					}
					$lines[] = '   Métodos públicos: ' . implode( ', ', array_slice( $methods, 0, 15 ) );
				} catch ( \Throwable $e ) {
					$lines[] = ' Error reflejando ' . $cls . ': ' . $e->getMessage();
				}
			} else {
				$lines[] = ' Clase ' . $cls . ' NO EXISTE en memoria.';
			}
		}
		$lines[] = '';

		// 3. Inspección de Habilidad Existente en el Núcleo.
		$lines[] = '--- 3. ESTRUCTURA DE HABILIDADES EXISTENTES EN EL SISTEMA ---';
		$sample_names = [ 'core/get-site-info', 'ai/image-generation', 'ai/get-post-details' ];
		$found_sample = false;
		if ( function_exists( 'wp_get_ability' ) ) {
			foreach ( $sample_names as $sname ) {
				$sample = wp_get_ability( $sname );
				if ( $sample ) {
					$found_sample = true;
					$lines[] = '>>> Muestra encontrada: ' . $sname . ' (' . get_class( $sample ) . ')';
					$sample_dump = [];
					foreach ( (array) $sample as $pk => $pv ) {
						// Limpiar prefijo de propiedades privadas/protegidas de PHP
						$clean_k = trim( str_replace( "\0", ':', (string) $pk ) );
						if ( is_scalar( $pv ) || null === $pv ) {
							$sample_dump[ $clean_k ] = $pv;
						} elseif ( is_array( $pv ) ) {
							$sample_dump[ $clean_k ] = '(array ' . count( $pv ) . ' items)';
						} elseif ( is_object( $pv ) ) {
							$sample_dump[ $clean_k ] = '(' . get_class( $pv ) . ')';
						}
					}
					$lines[] = '    Propiedades: ' . json_encode( $sample_dump, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT );
					break;
				}
			}
		}
		if ( ! $found_sample ) {
			$lines[] = ' No se pudo obtener ninguna habilidad de muestra vía wp_get_ability().';
		}
		$lines[] = '';

		// 4. Detalle de Callbacks suscritos a los hooks clave en $wp_filter.
		$lines[] = '--- 4. CALLBACKS SUSCRITOS A LOS HOOKS DE ABILITIES ---';
		$target_hooks = [
			'wp_abilities_api_categories_init',
			'wp_abilities_api_init',
			'init',
		];

		foreach ( $target_hooks as $thook ) {
			if ( isset( $wp_filter[ $thook ] ) ) {
				$lines[] = 'Hook: ' . $thook;
				$hook_obj = $wp_filter[ $thook ];
				if ( is_object( $hook_obj ) && isset( $hook_obj->callbacks ) ) {
					foreach ( $hook_obj->callbacks as $prio => $callbacks ) {
						foreach ( $callbacks as $cb_id => $cb_data ) {
							$cb_name = $this->format_callback_name( $cb_data['function'] ?? $cb_id );
							$lines[] = sprintf( '   Prio %-4d: %s', $prio, $cb_name );
						}
					}
				}
			} else {
				$lines[] = 'Hook: ' . $thook . ' (sin callbacks registrados)';
			}
		}
		$lines[] = '';

		// 5. Verificación de habilidades en wp_get_abilities().
		$lines[] = '--- 5. HABILIDADES REGISTRADAS DESPUÉS DEL TEST ---';
		if ( function_exists( 'wp_get_abilities' ) ) {
			$now_abilities = wp_get_abilities();
			$lines[] = 'Total habilidades ahora: ' . count( (array) $now_abilities );
			foreach ( (array) $now_abilities as $ab_key => $ab_val ) {
				$name = is_string( $ab_key ) ? $ab_key : ( is_object( $ab_val ) && isset( $ab_val->name ) ? $ab_val->name : (string) $ab_val );
				if ( str_starts_with( $name, 'wpagent' ) ) {
					$lines[] = '  ★ ¡ENCONTRADA!: ' . $name;
				} else {
					$lines[] = '  - ' . $name;
				}
			}
		}

		return implode( "\n", $lines );
	}

	/**
	 * Formatea un callable de WordPress a cadena legible.
	 *
	 * @param mixed $callback Callback crudo.
	 * @return string Nombre legible.
	 */
	private function format_callback_name( mixed $callback ): string {
		if ( is_string( $callback ) ) {
			return $callback;
		}

		if ( is_array( $callback ) ) {
			$class = is_object( $callback[0] ) ? get_class( $callback[0] ) : (string) $callback[0];
			$method = (string) ( $callback[1] ?? 'unknown' );
			return $class . '::' . $method;
		}

		if ( $callback instanceof \Closure ) {
			return 'Closure';
		}

		if ( is_object( $callback ) ) {
			return get_class( $callback );
		}

		return var_export( $callback, true );
	}
}
