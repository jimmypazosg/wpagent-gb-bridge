<?php
/**
 * Registrador de Habilidades para la WordPress Abilities API y MCP Adapter.
 *
 * @package WPAgent_GB_Bridge
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Clase WPAgent_GB_Ability_Registry.
 *
 * Registra de forma declarativa las capacidades de IA en la WordPress Abilities API
 * mediante wp_register_ability() exclusivamente en el hook canónico 'wp_abilities_api_init',
 * y su categoría en 'wp_abilities_api_categories_init' (conforme a wp-includes/abilities-api.php).
 */
class WPAgent_GB_Ability_Registry {

	/**
	 * Slug de la categoría en la Abilities API.
	 */
	public const CATEGORY_SLUG = 'wpagent';

	/**
	 * Instancia del constructor de bloques GenerateBlocks.
	 *
	 * @var WPAgent_GB_Block_Builder
	 */
	private WPAgent_GB_Block_Builder $block_builder;

	/**
	 * Instancia del purgador de caché multinivel.
	 *
	 * @var WPAgent_GB_Cache_Purger
	 */
	private WPAgent_GB_Cache_Purger $cache_purger;

	/**
	 * Instancia del sanitizador e inyector de identificadores.
	 *
	 * @var WPAgent_GB_Sanitizer
	 */
	private WPAgent_GB_Sanitizer $sanitizer;

	/**
	 * Instancia del parser de Markdown.
	 *
	 * @var WPAgent_GB_Markdown_Parser
	 */
	private WPAgent_GB_Markdown_Parser $markdown_parser;

	/**
	 * Constructor con inyección de dependencias.
	 *
	 * @param WPAgent_GB_Block_Builder|null   $block_builder Instancia del constructor de bloques.
	 * @param WPAgent_GB_Cache_Purger|null    $cache_purger Instancia del purgador de caché.
	 * @param WPAgent_GB_Sanitizer|null       $sanitizer Instancia del sanitizador.
	 * @param WPAgent_GB_Markdown_Parser|null $markdown_parser Instancia del parser Markdown.
	 */
	public function __construct(
		?WPAgent_GB_Block_Builder $block_builder = null,
		?WPAgent_GB_Cache_Purger $cache_purger = null,
		?WPAgent_GB_Sanitizer $sanitizer = null,
		?WPAgent_GB_Markdown_Parser $markdown_parser = null
	) {
		$this->sanitizer       = $sanitizer ?? new WPAgent_GB_Sanitizer();
		$this->markdown_parser = $markdown_parser ?? new WPAgent_GB_Markdown_Parser( $this->sanitizer );
		$this->block_builder   = $block_builder ?? new WPAgent_GB_Block_Builder( $this->sanitizer, $this->markdown_parser );
		$this->cache_purger    = $cache_purger ?? new WPAgent_GB_Cache_Purger();
	}

	/**
	 * Conecta a los ganchos canónicos exigidos por el núcleo de WordPress:
	 * - 'wp_abilities_api_categories_init' para categorías
	 * - 'wp_abilities_api_init' para habilidades
	 * Y un gancho de contingencia en 'init' (prioridad 99) que valida el registro global.
	 *
	 * @return void
	 */
	public function init(): void {
		add_action( 'wp_abilities_api_categories_init', [ $this, 'register_categories' ], 10 );
		add_action( 'wp_abilities_api_init', [ $this, 'register_abilities' ], 10 );
		add_action( 'init', [ $this, 'maybe_fallback_register' ], 99 );
	}

	/**
	 * Registra un evento del ciclo de vida en la opción temporal de auditoría.
	 *
	 * @param string $key Clave del evento.
	 * @param mixed  $data Datos asociados.
	 * @return void
	 */
	private function log_boot_event( string $key, mixed $data ): void {
		$log = get_option( 'wpagent_gb_boot_log', [] );
		if ( ! is_array( $log ) ) {
			$log = [];
		}
		$log[ $key ] = [
			'time'   => current_time( 'mysql' ),
			'action' => current_action(),
			'data'   => $data,
		];
		update_option( 'wpagent_gb_boot_log', $log, false );
	}

	/**
	 * Registra de forma segura una categoría en la Abilities API.
	 *
	 * Primero intenta la API canónica wp_register_ability_category() si doing_action() es activo;
	 * si retorna null o no está en el gancho, interactúa directamente con WP_Ability_Categories_Registry.
	 *
	 * @param string               $slug Identificador único de la categoría.
	 * @param array<string, mixed> $args Argumentos de la categoría.
	 * @return object|null Objeto de la categoría registrada o null.
	 */
	public function register_category_safely( string $slug, array $args ): ?object {
		$res = null;

		// 1. Intento canónico vía wrapper del núcleo
		if ( function_exists( 'wp_register_ability_category' ) && doing_action( 'wp_abilities_api_categories_init' ) ) {
			try {
				$res = wp_register_ability_category( $slug, $args );
			} catch ( \Throwable $e ) {
				$this->log_boot_event( 'category_exception_' . $slug, $e->getMessage() );
			}
		}

		// 2. Fallback directo al Registry singleton si el wrapper no pudo registrarlo
		if ( ! $res && class_exists( 'WP_Ability_Categories_Registry' ) ) {
			try {
				$registry = WP_Ability_Categories_Registry::get_instance();
				if ( $registry && method_exists( $registry, 'register' ) ) {
					$res = $registry->register( $slug, $args );
				}
			} catch ( \Throwable $e ) {
				$this->log_boot_event( 'category_registry_exception_' . $slug, $e->getMessage() );
			}
		}

		$this->log_boot_event(
			'category_' . $slug,
			[
				'status' => $res ? 'SUCCESS' : 'NULL',
				'class'  => is_object( $res ) ? get_class( $res ) : gettype( $res ),
			]
		);

		return is_object( $res ) ? $res : null;
	}

	/**
	 * Registra de forma segura una habilidad en la Abilities API.
	 *
	 * Primero intenta la API canónica wp_register_ability() si doing_action() es activo;
	 * si retorna null o no está en el gancho, interactúa directamente con WP_Abilities_Registry.
	 *
	 * @param string               $name Nombre cualificado de la habilidad.
	 * @param array<string, mixed> $args Argumentos de la habilidad.
	 * @return object|null Objeto de la habilidad registrada o null.
	 */
	public function register_ability_safely( string $name, array $args ): ?object {
		$res = null;

		// 1. Intento canónico vía wrapper del núcleo
		if ( function_exists( 'wp_register_ability' ) && doing_action( 'wp_abilities_api_init' ) ) {
			try {
				$res = wp_register_ability( $name, $args );
			} catch ( \Throwable $e ) {
				$this->log_boot_event( 'ability_exception_' . $name, $e->getMessage() );
			}
		}

		// 2. Fallback directo al Registry singleton si el wrapper no pudo registrarlo
		if ( ! $res && class_exists( 'WP_Abilities_Registry' ) ) {
			try {
				$registry = WP_Abilities_Registry::get_instance();
				if ( $registry && method_exists( $registry, 'register' ) ) {
					$res = $registry->register( $name, $args );
				}
			} catch ( \Throwable $e ) {
				$this->log_boot_event( 'ability_registry_exception_' . $name, $e->getMessage() );
			}
		}

		$this->log_boot_event(
			'ability_' . $name,
			[
				'status' => $res ? 'SUCCESS' : 'NULL',
				'class'  => is_object( $res ) ? get_class( $res ) : gettype( $res ),
			]
		);

		return is_object( $res ) ? $res : null;
	}

	/**
	 * Verifica en 'init' (prio 99) si las habilidades están registradas en el registro global;
	 * si alguna falta, la inyecta directamente vía el singleton de WP_Abilities_Registry.
	 */
	public function maybe_fallback_register(): void {
		$needs_reg = false;
		if ( function_exists( 'wp_has_ability' ) ) {
			$needs_reg = ! wp_has_ability( 'wpagent/create-draft-post' );
		} elseif ( class_exists( 'WP_Abilities_Registry' ) ) {
			$registry  = WP_Abilities_Registry::get_instance();
			$needs_reg = $registry && method_exists( $registry, 'has' ) ? ! $registry->has( 'wpagent/create-draft-post' ) : true;
		}

		if ( $needs_reg ) {
			$this->log_boot_event( 'fallback_trigger', [ 'hook' => 'init', 'priority' => 99 ] );
			$this->register_categories();
			$this->register_abilities();
		}
	}

	/**
	 * Registra la categoría del plugin en la Abilities API.
	 *
	 * @return void
	 */
	public function register_categories(): void {
		$this->register_category_safely(
			self::CATEGORY_SLUG,
			[
				'label'       => __( 'WPAgent GenerateBlocks', 'wpagent-gb-bridge' ),
				'description' => __( 'Herramientas de maquetación y edición de borradores atómicos con GenerateBlocks 2.x para IA.', 'wpagent-gb-bridge' ),
			]
		);
	}

	/**
	 * Registra las habilidades nativas del plugin ante la Abilities API.
	 *
	 * @return void
	 */
	public function register_abilities(): void {
		$this->register_create_draft_post_ability();
		$this->register_patch_post_content_ability();
		$this->register_get_post_block_tree_ability();
	}

	/**
	 * Habilidad 6.1: wpagent/create-draft-post
	 *
	 * Crea un post o página en borrador utilizando plantillas y marcado GenerateBlocks v2.
	 *
	 * @return void
	 */
	private function register_create_draft_post_ability(): void {
		$this->register_ability_safely(
			'wpagent/create-draft-post',
			[
				'label'               => __( 'Crear Borrador con GenerateBlocks', 'wpagent-gb-bridge' ),
				'description'         => __( 'Crea un post o página en borrador utilizando plantillas y marcado GenerateBlocks a partir de Markdown estructurado.', 'wpagent-gb-bridge' ),
				'category'            => self::CATEGORY_SLUG,
				'meta'                => [
					'show_in_rest' => true,
					'public'       => true,
					'annotations'  => [
						'readonly'    => false,
						'destructive' => false,
						'idempotent'  => false,
					],
					'mcp'          => [
						'public' => true,
					],
				],
				'input_schema'        => [
					'type'                 => 'object',
					'properties'           => [
						'title'        => [
							'type'        => 'string',
							'description' => 'Título de la entrada',
						],
						'slug'         => [
							'type'        => 'string',
							'description' => 'Slug SEO amigable (opcional)',
						],
						'post_type'    => [
							'type'    => 'string',
							'enum'    => [ 'post', 'page' ],
							'default' => 'post',
						],
						'category_ids' => [
							'type'        => 'array',
							'items'       => [ 'type' => 'integer' ],
							'description' => 'IDs de categorías asociadas (opcional)',
						],
						'sections'     => [
							'type'        => 'array',
							'description' => 'Lista de secciones estructuradas que componen el contenido',
							'items'       => [
								'type'       => 'object',
								'properties' => [
									'layout'           => [
										'type' => 'string',
										'enum' => [ 'standard', 'hero', 'card_grid', 'cta_box' ],
									],
									'content_markdown' => [
										'type'        => 'string',
										'description' => 'Contenido en markdown de la sección',
									],
								],
								'required'   => [ 'content_markdown' ],
							],
						],
					],
					'required'             => [ 'title', 'sections' ],
					'additionalProperties' => false,
				],
				'output_schema'       => [
					'type'       => 'object',
					'properties' => [
						'success'     => [ 'type' => 'boolean' ],
						'post_id'     => [ 'type' => 'integer' ],
						'title'       => [ 'type' => 'string' ],
						'post_type'   => [ 'type' => 'string' ],
						'post_status' => [ 'type' => 'string' ],
						'edit_url'    => [ 'type' => 'string' ],
						'preview_url' => [ 'type' => 'string' ],
						'message'     => [ 'type' => 'string' ],
						'error'       => [ 'type' => 'string' ],
					],
				],
				'permission_callback' => static function (): bool {
					return current_user_can( 'edit_posts' );
				},
				'execute_callback'    => [ $this, 'execute_create_draft_post' ],
			]
		);
	}

	/**
	 * Habilidad 6.2: wpagent/patch-post-content
	 *
	 * Modifica quirúrgicamente un bloque específico en borrador por su índice posicional.
	 *
	 * @return void
	 */
	private function register_patch_post_content_ability(): void {
		$this->register_ability_safely(
			'wpagent/patch-post-content',
			[
				'label'               => __( 'Modificar Bloque Específico en Borrador', 'wpagent-gb-bridge' ),
				'description'         => __( 'Aplica un cambio atómico sobre un bloque de una entrada existente sin reescribir todo el documento (Directiva 4 de ahorro de tokens).', 'wpagent-gb-bridge' ),
				'category'            => self::CATEGORY_SLUG,
				'meta'                => [
					'show_in_rest' => true,
					'public'       => true,
					'annotations'  => [
						'readonly'    => false,
						'destructive' => false,
						'idempotent'  => false,
					],
					'mcp'          => [
						'public' => true,
					],
				],
				'input_schema'        => [
					'type'                 => 'object',
					'properties'           => [
						'post_id'      => [
							'type'        => 'integer',
							'description' => 'ID del post a modificar',
						],
						'block_index'  => [
							'type'        => 'integer',
							'description' => 'Índice posicional del bloque a reemplazar',
						],
						'new_markdown' => [
							'type'        => 'string',
							'description' => 'Nuevo contenido semántico en Markdown',
						],
					],
					'required'             => [ 'post_id', 'block_index', 'new_markdown' ],
					'additionalProperties' => false,
				],
				'output_schema'       => [
					'type'       => 'object',
					'properties' => [
						'success'     => [ 'type' => 'boolean' ],
						'post_id'     => [ 'type' => 'integer' ],
						'block_index' => [ 'type' => 'integer' ],
						'post_status' => [ 'type' => 'string' ],
						'edit_url'    => [ 'type' => 'string' ],
						'message'     => [ 'type' => 'string' ],
						'error'       => [ 'type' => 'string' ],
					],
				],
				'permission_callback' => static function ( array $input = [] ): bool {
					$post_id = absint( $input['post_id'] ?? 0 );
					return $post_id > 0 ? current_user_can( 'edit_post', $post_id ) : current_user_can( 'edit_posts' );
				},
				'execute_callback'    => [ $this, 'execute_patch_post_content' ],
			]
		);
	}

	/**
	 * Habilidad 6.3: wpagent/get-post-block-tree
	 *
	 * Inspecciona el árbol de bloques simplificado de una entrada existente.
	 *
	 * @return void
	 */
	private function register_get_post_block_tree_ability(): void {
		$this->register_ability_safely(
			'wpagent/get-post-block-tree',
			[
				'label'               => __( 'Inspeccionar Árbol de Bloques de un Post', 'wpagent-gb-bridge' ),
				'description'         => __( 'Devuelve el árbol simplificado de bloques de una entrada (tipo, índice y extracto de texto) para ahorrar tokens de entrada.', 'wpagent-gb-bridge' ),
				'category'            => self::CATEGORY_SLUG,
				'meta'                => [
					'show_in_rest' => true,
					'public'       => true,
					'annotations'  => [
						'readonly'    => true,
						'destructive' => false,
						'idempotent'  => true,
					],
					'mcp'          => [
						'public' => true,
					],
				],
				'input_schema'        => [
					'type'                 => 'object',
					'properties'           => [
						'post_id' => [
							'type'        => 'integer',
							'description' => 'ID del post a inspeccionar',
						],
					],
					'required'             => [ 'post_id' ],
					'additionalProperties' => false,
				],
				'output_schema'       => [
					'type'       => 'object',
					'properties' => [
						'success'      => [ 'type' => 'boolean' ],
						'post_id'      => [ 'type' => 'integer' ],
						'post_title'   => [ 'type' => 'string' ],
						'post_status'  => [ 'type' => 'string' ],
						'total_blocks' => [ 'type' => 'integer' ],
						'blocks'       => [
							'type'  => 'array',
							'items' => [
								'type'       => 'object',
								'properties' => [
									'index'     => [ 'type' => 'integer' ],
									'blockName' => [ 'type' => 'string' ],
									'tagName'   => [ 'type' => [ 'string', 'null' ] ],
									'excerpt'   => [ 'type' => 'string' ],
								],
							],
						],
						'error'        => [ 'type' => 'string' ],
					],
				],
				'permission_callback' => static function ( array $input = [] ): bool {
					$post_id = absint( $input['post_id'] ?? 0 );
					return $post_id > 0 ? ( current_user_can( 'read_post', $post_id ) || current_user_can( 'edit_post', $post_id ) ) : current_user_can( 'edit_posts' );
				},
				'execute_callback'    => [ $this, 'execute_get_post_block_tree' ],
			]
		);
	}

	/**
	 * Ejecuta la creación del post en borrador forzando incondicionalmente post_status = 'draft'.
	 *
	 * @param array<string, mixed> $input Carga de argumentos de la llamada MCP.
	 * @return array<string, mixed> Respuesta estructurada para el modelo o cliente MCP.
	 */
	public function execute_create_draft_post( array $input ): array {
		try {
			$sanitized = $this->sanitizer->sanitize_create_post_input( $input );

			// Construir y procesar el contenido con identificación determinista de bloques.
			$post_content = $this->block_builder->build_and_sanitize_post_content( $sanitized['sections'] );

			// Inserción forzando incondicionalmente el estado 'draft' (Criterio 4 de SPEC.md).
			$post_id = wp_insert_post(
				[
					'post_title'   => $sanitized['title'],
					'post_name'    => $sanitized['slug'],
					'post_type'    => $sanitized['post_type'],
					'post_status'  => 'draft',
					'post_content' => $post_content,
					'post_author'  => get_current_user_id() ?: 1,
				],
				true
			);

			if ( is_wp_error( $post_id ) ) {
				return [
					'success' => false,
					'error'   => $post_id->get_error_message(),
				];
			}

			// Asignar categorías si corresponde.
			if ( ! empty( $sanitized['category_ids'] ) && 'post' === $sanitized['post_type'] ) {
				wp_set_post_categories( (int) $post_id, $sanitized['category_ids'] );
			}

			// Invalidador de caché multinivel (Redis + WP Fastest Cache).
			$this->cache_purger->purge_post_cache( (int) $post_id );

			return [
				'success'     => true,
				'post_id'     => (int) $post_id,
				'title'       => $sanitized['title'],
				'post_type'   => $sanitized['post_type'],
				'post_status' => 'draft',
				'edit_url'    => (string) get_edit_post_link( (int) $post_id, 'raw' ),
				'preview_url' => (string) get_preview_post_link( (int) $post_id ),
				'message'     => 'Borrador creado exitosamente con arquitectura atómica GenerateBlocks 2.4.x.',
			];
		} catch ( \Throwable $e ) {
			return [
				'success' => false,
				'error'   => $e->getMessage(),
			];
		}
	}

	/**
	 * Ejecuta el parche atómico sobre un bloque de una entrada existente.
	 *
	 * @param array<string, mixed> $input Carga de argumentos de la llamada MCP.
	 * @return array<string, mixed> Respuesta estructurada.
	 */
	public function execute_patch_post_content( array $input ): array {
		try {
			$sanitized = $this->sanitizer->sanitize_patch_post_input( $input );
			$post_id   = $sanitized['post_id'];

			$post = get_post( $post_id );
			if ( ! $post instanceof \WP_Post ) {
				return [
					'success' => false,
					'error'   => sprintf( 'No existe ninguna entrada con el ID %d.', $post_id ),
				];
			}

			// Política estricta: Solo modificar borradores para proteger contenido en producción.
			if ( 'draft' !== $post->post_status ) {
				return [
					'success' => false,
					'error'   => sprintf( 'Por política de seguridad, la entrada %d tiene estado "%s". Solo se permite modificar entradas en estado "draft".', $post_id, $post->post_status ),
				];
			}

			$patched = $this->block_builder->patch_post_block(
				$post_id,
				$sanitized['block_index'],
				$sanitized['new_markdown']
			);

			if ( ! $patched ) {
				return [
					'success' => false,
					'error'   => 'Ocurrió un error al persistir la modificación del bloque en la base de datos.',
				];
			}

			// Invalidar caché tras la actualización.
			$this->cache_purger->purge_post_cache( $post_id );

			return [
				'success'     => true,
				'post_id'     => $post_id,
				'block_index' => $sanitized['block_index'],
				'post_status' => 'draft',
				'edit_url'    => (string) get_edit_post_link( $post_id, 'raw' ),
				'message'     => sprintf( 'Bloque en índice %d reemplazado y serializado atómicamente.', $sanitized['block_index'] ),
			];
		} catch ( \Throwable $e ) {
			return [
				'success' => false,
				'error'   => $e->getMessage(),
			];
		}
	}

	/**
	 * Ejecuta la inspección ligera del árbol de bloques de un post.
	 *
	 * @param array<string, mixed> $input Carga de argumentos de la llamada MCP.
	 * @return array<string, mixed> Árbol de bloques simplificado para consumo mínimo de tokens.
	 */
	public function execute_get_post_block_tree( array $input ): array {
		try {
			$post_id = absint( $input['post_id'] ?? 0 );
			if ( $post_id <= 0 ) {
				return [
					'success' => false,
					'error'   => 'El parámetro "post_id" debe ser un entero positivo.',
				];
			}

			$post = get_post( $post_id );
			if ( ! $post instanceof \WP_Post ) {
				return [
					'success' => false,
					'error'   => sprintf( 'No existe ninguna entrada con el ID %d.', $post_id ),
				];
			}

			$tree = $this->block_builder->get_post_block_tree( $post_id );

			return [
				'success'      => true,
				'post_id'      => $post_id,
				'post_title'   => $post->post_title,
				'post_status'  => $post->post_status,
				'total_blocks' => count( $tree ),
				'blocks'       => $tree,
			];
		} catch ( \Throwable $e ) {
			return [
				'success' => false,
				'error'   => $e->getMessage(),
			];
		}
	}
}
