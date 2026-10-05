<?php
/**
 * Constructor y serializador de bloques y macro-plantillas GenerateBlocks v2.
 *
 * @package WPAgent_GB_Bridge
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Clase WPAgent_GB_Block_Builder.
 *
 * Ensambla el contenido del post utilizando la arquitectura atómica de GenerateBlocks 2.4.x,
 * expandiendo macro-plantillas semánticas y resolviendo operaciones de parcheo por deltas
 * (Directivas 2, 4 y 5 de SPEC.md).
 */
class WPAgent_GB_Block_Builder {

	/**
	 * Instancia del sanitizador e inyector determinista de uniqueId.
	 *
	 * @var WPAgent_GB_Sanitizer|null
	 */
	private ?WPAgent_GB_Sanitizer $sanitizer;

	/**
	 * Instancia del parser de Markdown a marcado Gutenberg.
	 *
	 * @var WPAgent_GB_Markdown_Parser
	 */
	private WPAgent_GB_Markdown_Parser $markdown_parser;

	/**
	 * Ruta base del directorio de plantillas macro.
	 *
	 * @var string
	 */
	private string $templates_path;

	/**
	 * Constructor de la clase.
	 *
	 * @param WPAgent_GB_Sanitizer|null       $sanitizer Instancia del sanitizador.
	 * @param WPAgent_GB_Markdown_Parser|null $markdown_parser Instancia del parser Markdown.
	 * @param string                          $templates_path Ruta personalizada hacia las plantillas.
	 */
	public function __construct(
		?WPAgent_GB_Sanitizer $sanitizer = null,
		?WPAgent_GB_Markdown_Parser $markdown_parser = null,
		string $templates_path = ''
	) {
		$this->sanitizer       = $sanitizer ?? ( class_exists( 'WPAgent_GB_Sanitizer' ) ? new WPAgent_GB_Sanitizer() : null );
		$this->markdown_parser = $markdown_parser ?? new WPAgent_GB_Markdown_Parser( $this->sanitizer );

		if ( ! empty( $templates_path ) ) {
			$this->templates_path = trailingslashit( $templates_path );
		} elseif ( defined( 'WPAGENT_GB_TEMPLATES_PATH' ) ) {
			$this->templates_path = trailingslashit( WPAGENT_GB_TEMPLATES_PATH );
		} else {
			$this->templates_path = trailingslashit( dirname( __DIR__ ) ) . 'templates/';
		}
	}

	/**
	 * Construye el marcado completo del post a partir de un array de secciones estructuradas.
	 *
	 * Devuelve el contenido con los marcadores {{UNIQUE_ID}} listos para ser procesados
	 * por el sanitizador antes de guardarse en post_content.
	 *
	 * @param array<int, array{layout: string, content_markdown: string}> $sections Array de secciones validadas.
	 * @return string Marcado serializado con {{UNIQUE_ID}} preparados.
	 */
	public function build_post_content( array $sections ): string {
		$rendered_sections = [];

		foreach ( $sections as $section ) {
			$layout   = $section['layout'] ?? 'standard';
			$markdown = $section['content_markdown'] ?? '';

			if ( '' === trim( $markdown ) ) {
				continue;
			}

			if ( 'standard' === $layout ) {
				$parsed_markdown = $this->markdown_parser->parse( $markdown );
				$rendered_sections[] = sprintf(
					"<!-- wp:generateblocks/element {\"uniqueId\":\"{{UNIQUE_ID}}\",\"tagName\":\"div\",\"className\":\"gb-element gb-container\"} -->\n<div class=\"gb-element gb-element-{{UNIQUE_ID}} gb-container\">\n%s\n</div>\n<!-- /wp:generateblocks/element -->",
					$parsed_markdown
				);
			} else {
				$template_file = $this->resolve_template_path( $layout );
				$rendered_sections[] = $this->render_template(
					$template_file,
					[
						'content_markdown' => $markdown,
						'parsed_content'   => '',
						'parser'           => $this->markdown_parser,
						'section'          => $section,
					]
				);
			}
		}

		return implode( "\n\n", $rendered_sections );
	}

	/**
	 * Construye el contenido del post y ejecuta el pipeline de sanitización e inyección
	 * determinista de identificadores de forma directa.
	 *
	 * @param array<int, array{layout: string, content_markdown: string}> $sections Secciones a construir.
	 * @return string Marcado final 100% procesado y listo para wp_insert_post.
	 */
	public function build_and_sanitize_post_content( array $sections ): string {
		$raw_markup = $this->build_post_content( $sections );

		if ( null !== $this->sanitizer ) {
			return $this->sanitizer->process_and_sanitize_content( $raw_markup );
		}

		return $raw_markup;
	}

	/**
	 * Aplica un parche atómico sobre un bloque específico de una entrada existente (Directiva 4).
	 *
	 * @param int    $post_id ID del post a modificar.
	 * @param int    $block_index Índice posicional del bloque a reemplazar.
	 * @param string $new_markdown Nuevo contenido semántico en Markdown.
	 * @return bool True si el post fue actualizado exitosamente.
	 *
	 * @throws \InvalidArgumentException Si el post no existe.
	 * @throws \OutOfBoundsException Si el índice del bloque es inválido.
	 */
	public function patch_post_block( int $post_id, int $block_index, string $new_markdown ): bool {
		$post = get_post( $post_id );
		if ( ! $post instanceof \WP_Post ) {
			throw new \InvalidArgumentException( sprintf( 'No existe ninguna entrada con el ID %d.', $post_id ) );
		}

		$blocks = parse_blocks( $post->post_content );
		if ( ! isset( $blocks[ $block_index ] ) ) {
			throw new \OutOfBoundsException(
				sprintf( 'El bloque con índice %d no existe en el post %d (total bloques: %d).', $block_index, $post_id, count( $blocks ) )
			);
		}

		// Parsear el nuevo contenido Markdown a bloques Gutenberg.
		$parsed_markup      = $this->markdown_parser->parse( $new_markdown );
		$replacement_blocks = parse_blocks( $parsed_markup );

		// Inyectar uniqueIds deterministas en los bloques de reemplazo.
		if ( null !== $this->sanitizer ) {
			$replacement_blocks = $this->sanitizer->inject_unique_ids( $replacement_blocks );
		}

		// Reemplazar el bloque en el índice solicitado.
		array_splice( $blocks, $block_index, 1, $replacement_blocks );

		// Re-serializar y forzar incondicionalmente post_status = 'draft'.
		$updated_content = serialize_blocks( $blocks );
		$update_result   = wp_update_post(
			[
				'ID'           => $post_id,
				'post_content' => $updated_content,
				'post_status'  => 'draft',
			],
			true
		);

		return ! is_wp_error( $update_result );
	}

	/**
	 * Obtiene el árbol simplificado de bloques de una entrada para ahorrar tokens de contexto (SPEC 6.3).
	 *
	 * @param int $post_id ID de la entrada a inspeccionar.
	 * @return array<int, array{index: int, blockName: string, tagName: ?string, excerpt: string}> Resumen ligero del árbol.
	 *
	 * @throws \InvalidArgumentException Si el post no existe.
	 */
	public function get_post_block_tree( int $post_id ): array {
		$post = get_post( $post_id );
		if ( ! $post instanceof \WP_Post ) {
			throw new \InvalidArgumentException( sprintf( 'No existe ninguna entrada con el ID %d.', $post_id ) );
		}

		$blocks = parse_blocks( $post->post_content );
		$tree   = [];

		foreach ( $blocks as $index => $block ) {
			$block_name = (string) ( $block['blockName'] ?? '' );
			$inner_html = (string) ( $block['innerHTML'] ?? '' );

			// Omitir bloques de separación en blanco entre comentarios.
			if ( empty( $block_name ) && '' === trim( $inner_html ) ) {
				continue;
			}

			$excerpt = wp_trim_words( wp_strip_all_tags( $inner_html ), 15, '...' );

			$tree[] = [
				'index'     => $index,
				'blockName' => $block_name ?: 'core/freeform',
				'tagName'   => $block['attrs']['tagName'] ?? null,
				'excerpt'   => $excerpt,
			];
		}

		return $tree;
	}

	/**
	 * Resuelve la ruta del archivo de plantilla macro según el layout solicitado.
	 *
	 * @param string $layout Identificador de la macro-plantilla ('hero', 'card_grid', 'cta_box').
	 * @return string Ruta absoluta al archivo PHP de la plantilla.
	 */
	private function resolve_template_path( string $layout ): string {
		$normalized_layout = str_replace( '_', '-', sanitize_key( $layout ) );
		return $this->templates_path . 'macro-' . $normalized_layout . '.php';
	}

	/**
	 * Renderiza una plantilla macro en un ámbito de ejecución aislado.
	 *
	 * @param string               $template_file Ruta del archivo de plantilla.
	 * @param array<string, mixed> $context Variables inyectadas en la plantilla.
	 * @return string Marcado HTML de la plantilla renderizada.
	 */
	private function render_template( string $template_file, array $context ): string {
		if ( ! file_exists( $template_file ) ) {
			// Fallback de contingencia: parsear markdown estándar si la plantilla no existe.
			$markdown = (string) ( $context['content_markdown'] ?? '' );
			return $this->markdown_parser->parse( $markdown );
		}

		extract( $context, EXTR_SKIP );

		ob_start();
		include $template_file;
		return (string) ob_get_clean();
	}
}
