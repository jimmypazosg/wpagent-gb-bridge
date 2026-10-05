<?php
/**
 * Sanitizador e Inyector determinista de identificadores de GenerateBlocks.
 *
 * @package WPAgent_GB_Bridge
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Clase WPAgent_GB_Sanitizer.
 *
 * Responsable de la generación determinista de uniqueId (Directiva 1),
 * inyección recursiva en árboles de bloques Gutenberg / GenerateBlocks 2.x
 * y sanitización exhaustiva de entradas y atributos para prevenir HTML malformado.
 */
class WPAgent_GB_Sanitizer {

	/**
	 * Tipos de post admitidos por el puente de IA.
	 *
	 * @var array<string>
	 */
	private const ALLOWED_POST_TYPES = [ 'post', 'page' ];

	/**
	 * Plantillas de maquetación válidas para secciones.
	 *
	 * @var array<string>
	 */
	private const ALLOWED_LAYOUTS = [ 'standard', 'hero', 'card_grid', 'cta_box' ];

	/**
	 * Genera un identificador único determinista de 8 caracteres alfanuméricos en minúsculas.
	 *
	 * Cumple con la Directiva 1 de SPEC.md evitando que el modelo de IA
	 * gaste tokens calculando identificadores o genere colisiones de atributos.
	 *
	 * @return string Identificador alfanumérico de 8 caracteres (ej. 'a1b2c3d4').
	 */
	public static function generate_unique_id(): string {
		return strtolower( wp_generate_password( 8, false, false ) );
	}

	/**
	 * Procesa recursivamente un árbol de bloques parseados de Gutenberg
	 * e inyecta uniqueId deterministas en todos los bloques del namespace GenerateBlocks.
	 *
	 * @param array<int, array<string, mixed>> $blocks Árbol de bloques generado por parse_blocks().
	 * @return array<int, array<string, mixed>> Árbol de bloques con uniqueId asegurados y sincronizados.
	 */
	public function inject_unique_ids( array $blocks ): array {
		foreach ( $blocks as $index => &$block ) {
			if ( ! is_array( $block ) ) {
				continue;
			}

			$block_name = $block['blockName'] ?? '';

			if ( is_string( $block_name ) && str_starts_with( $block_name, 'generateblocks/' ) ) {
				if ( ! isset( $block['attrs'] ) || ! is_array( $block['attrs'] ) ) {
					$block['attrs'] = [];
				}

				$unique_id = $block['attrs']['uniqueId'] ?? '';
				if ( empty( $unique_id ) || ! is_string( $unique_id ) || str_contains( $unique_id, 'UNIQUE_ID' ) ) {
					$unique_id = self::generate_unique_id();
					$block['attrs']['uniqueId'] = $unique_id;
				}

				// Reemplazar de forma exhaustiva {{UNIQUE_ID}} en todos los atributos del bloque.
				array_walk_recursive(
					$block['attrs'],
					static function ( mixed &$val ) use ( $unique_id ): void {
						if ( is_string( $val ) ) {
							$val = str_replace( '{{UNIQUE_ID}}', $unique_id, $val );
						}
					}
				);

				// Limpiar cualquier repetición redundante de gb-element-{id} en la propiedad className.
				if ( isset( $block['attrs']['className'] ) && is_string( $block['attrs']['className'] ) ) {
					$cleaned_classes = preg_replace( '/\bgb-element-[a-z0-9]+\b/i', '', $block['attrs']['className'] );
					$cleaned_classes = preg_replace( '/\s+/', ' ', trim( (string) $cleaned_classes ) );
					$block['attrs']['className'] = $cleaned_classes;
				}

				// Sincronizar identificador con el marcado HTML interno.
				$block = $this->sync_block_unique_id_markup( $block, $unique_id );
			}

			// Inyección recursiva en bloques anidados (innerBlocks).
			if ( ! empty( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ) {
				$block['innerBlocks'] = $this->inject_unique_ids( $block['innerBlocks'] );
			}
		}
		unset( $block );

		return $blocks;
	}

	/**
	 * Sincroniza el uniqueId generado dentro de innerHTML e innerContent del bloque.
	 *
	 * Asegura que tanto los atributos JSON como las clases CSS del contenedor
	 * contengan el identificador para evitar avisos de recuperación de bloques en el editor.
	 *
	 * @param array<string, mixed> $block Bloque individual de Gutenberg.
	 * @param string               $unique_id Identificador determinista.
	 * @return array<string, mixed> Bloque con marcado sincronizado.
	 */
	private function sync_block_unique_id_markup( array $block, string $unique_id ): array {
		$replacements = [
			'{{UNIQUE_ID}}'         => $unique_id,
			'gb-unique-placeholder' => $unique_id,
			'gb-placeholder-id'     => $unique_id,
		];

		$block_name = $block['blockName'] ?? '';

		if ( ! empty( $block['innerHTML'] ) && is_string( $block['innerHTML'] ) ) {
			$block['innerHTML'] = strtr( $block['innerHTML'], $replacements );

			if ( 'generateblocks/element' === $block_name && ! str_contains( $block['innerHTML'], "gb-element-{$unique_id}" ) ) {
				$block['innerHTML'] = (string) preg_replace(
					'/(<[a-z0-9]+\s+class="[^"]*gb-element)([^"]*")/i',
					'$1 gb-element-' . $unique_id . '$2',
					$block['innerHTML'],
					1
				);
			} elseif ( 'generateblocks/text' === $block_name && ! str_contains( $block['innerHTML'], "gb-text-{$unique_id}" ) && ! str_contains( $block['innerHTML'], "gb-headline-{$unique_id}" ) ) {
				$block['innerHTML'] = (string) preg_replace(
					'/(<[a-z0-9]+\s+class="[^"]*(?:gb-headline|gb-text))([^"]*")/i',
					'$1 gb-text-' . $unique_id . '$2',
					$block['innerHTML'],
					1
				);
			}
		}

		if ( ! empty( $block['innerContent'] ) && is_array( $block['innerContent'] ) ) {
			foreach ( $block['innerContent'] as $key => $fragment ) {
				if ( is_string( $fragment ) ) {
					$fragment = strtr( $fragment, $replacements );

					if ( 'generateblocks/element' === $block_name && ! str_contains( $fragment, "gb-element-{$unique_id}" ) ) {
						$fragment = (string) preg_replace(
							'/(<[a-z0-9]+\s+class="[^"]*gb-element)([^"]*")/i',
							'$1 gb-element-' . $unique_id . '$2',
							$fragment,
							1
						);
					} elseif ( 'generateblocks/text' === $block_name && ! str_contains( $fragment, "gb-text-{$unique_id}" ) && ! str_contains( $fragment, "gb-headline-{$unique_id}" ) ) {
						$fragment = (string) preg_replace(
							'/(<[a-z0-9]+\s+class="[^"]*(?:gb-headline|gb-text))([^"]*")/i',
							'$1 gb-text-' . $unique_id . '$2',
							$fragment,
							1
						);
					}

					$block['innerContent'][ $key ] = $fragment;
				}
			}
		}

		return $block;
	}

	/**
	 * Procesa una cadena de marcado Gutenberg completa, parsea sus bloques,
	 * asegura identificadores únicos deterministas y re-serializa el contenido.
	 *
	 * @param string $content Marcado completo con comentarios Gutenberg.
	 * @return string Marcado serializado con IDs inyectados.
	 */
	public function process_and_sanitize_content( string $content ): string {
		if ( empty( trim( $content ) ) ) {
			return '';
		}

		$blocks = parse_blocks( $content );
		$blocks = $this->inject_unique_ids( $blocks );

		return serialize_blocks( $blocks );
	}

	/**
	 * Sanitiza el título de una entrada.
	 *
	 * @param string $title Título sin procesar.
	 * @return string Título limpio sin etiquetas ni caracteres de control.
	 */
	public function sanitize_title( string $title ): string {
		return sanitize_text_field( wp_unslash( $title ) );
	}

	/**
	 * Sanitiza el slug de una entrada con soporte de fallback.
	 *
	 * @param string $slug Slug enviado por la IA o usuario.
	 * @param string $fallback Cadena alternativa en caso de slug vacío.
	 * @return string Slug limpio en formato URL amigable.
	 */
	public function sanitize_slug( string $slug, string $fallback = '' ): string {
		$target = ! empty( trim( $slug ) ) ? $slug : $fallback;
		return sanitize_title( wp_unslash( $target ) );
	}

	/**
	 * Valida y sanitiza el post_type según los tipos admitidos.
	 *
	 * @param string $post_type Tipo de entrada a validar.
	 * @return string 'post' o 'page'.
	 */
	public function sanitize_post_type( string $post_type ): string {
		$cleaned = sanitize_key( $post_type );
		return in_array( $cleaned, self::ALLOWED_POST_TYPES, true ) ? $cleaned : 'post';
	}

	/**
	 * Sanitiza un array de IDs de categorías asegurando enteros positivos válidos.
	 *
	 * @param mixed $categories Array de identificadores de categoría.
	 * @return array<int> Lista de IDs numéricos limpios.
	 */
	public function sanitize_category_ids( mixed $categories ): array {
		if ( ! is_array( $categories ) ) {
			return [];
		}

		$cleaned = array_map( 'absint', $categories );
		return array_values( array_filter( $cleaned, static fn( int $id ): bool => $id > 0 ) );
	}

	/**
	 * Sanitiza texto en formato Markdown eliminando caracteres nulos y controlando saltos de línea.
	 *
	 * @param string $markdown Texto con sintaxis Markdown.
	 * @return string Texto Markdown saneado.
	 */
	public function sanitize_markdown( string $markdown ): string {
		// Eliminar bytes nulos y caracteres no imprimibles peligrosos.
		$cleaned = str_replace( "\0", '', $markdown );
		// Normalizar saltos de línea a formato Unix.
		$cleaned = str_replace( [ "\r\n", "\r" ], "\n", $cleaned );

		return trim( $cleaned );
	}

	/**
	 * Sanitiza fragmentos de contenido HTML preservando etiquetas seguras para posts.
	 *
	 * @param string $html Marcado HTML.
	 * @return string HTML balanceado y saneado vía wp_kses_post.
	 */
	public function sanitize_html_content( string $html ): string {
		return wp_kses_post( $html );
	}

	/**
	 * Sanitiza y valida la carga completa de parámetros para la habilidad 'wpagent/create-draft-post'.
	 *
	 * @param array<string, mixed> $input Argumentos recibidos desde el adaptador MCP.
	 * @return array{
	 *     title: string,
	 *     slug: string,
	 *     post_type: string,
	 *     category_ids: array<int>,
	 *     sections: array<int, array{layout: string, content_markdown: string}>
	 * } Datos rigurosamente sanitizados.
	 *
	 * @throws \InvalidArgumentException Si faltan campos obligatorios.
	 */
	public function sanitize_create_post_input( array $input ): array {
		$raw_title = $input['title'] ?? '';
		if ( ! is_string( $raw_title ) || '' === trim( $raw_title ) ) {
			throw new \InvalidArgumentException( 'El parámetro "title" es obligatorio y debe ser una cadena no vacía.' );
		}

		$raw_sections = $input['sections'] ?? null;
		if ( ! is_array( $raw_sections ) || empty( $raw_sections ) ) {
			throw new \InvalidArgumentException( 'El parámetro "sections" es obligatorio y debe contener al menos una sección.' );
		}

		$sanitized_sections = [];
		foreach ( $raw_sections as $section ) {
			if ( ! is_array( $section ) ) {
				continue;
			}

			$layout = sanitize_key( (string) ( $section['layout'] ?? 'standard' ) );
			if ( ! in_array( $layout, self::ALLOWED_LAYOUTS, true ) ) {
				$layout = 'standard';
			}

			$markdown = $this->sanitize_markdown( (string) ( $section['content_markdown'] ?? '' ) );
			if ( '' === $markdown ) {
				continue;
			}

			$sanitized_sections[] = [
				'layout'           => $layout,
				'content_markdown' => $markdown,
			];
		}

		if ( empty( $sanitized_sections ) ) {
			throw new \InvalidArgumentException( 'Ninguna de las secciones proporcionadas contiene "content_markdown" válido.' );
		}

		$title = $this->sanitize_title( $raw_title );

		return [
			'title'        => $title,
			'slug'         => $this->sanitize_slug( (string) ( $input['slug'] ?? '' ), $title ),
			'post_type'    => $this->sanitize_post_type( (string) ( $input['post_type'] ?? 'post' ) ),
			'category_ids' => $this->sanitize_category_ids( $input['category_ids'] ?? [] ),
			'sections'     => $sanitized_sections,
		];
	}

	/**
	 * Sanitiza y valida la carga de parámetros para la habilidad 'wpagent/patch-post-content'.
	 *
	 * @param array<string, mixed> $input Argumentos recibidos desde el adaptador MCP.
	 * @return array{post_id: int, block_index: int, new_markdown: string}
	 *
	 * @throws \InvalidArgumentException Si los parámetros requeridos son inválidos.
	 */
	public function sanitize_patch_post_input( array $input ): array {
		$post_id = absint( $input['post_id'] ?? 0 );
		if ( $post_id <= 0 ) {
			throw new \InvalidArgumentException( 'El parámetro "post_id" debe ser un entero positivo válido.' );
		}

		if ( ! isset( $input['block_index'] ) || ! is_numeric( $input['block_index'] ) ) {
			throw new \InvalidArgumentException( 'El parámetro "block_index" es obligatorio y debe ser un número entero >= 0.' );
		}
		$block_index = max( 0, (int) $input['block_index'] );

		$raw_markdown = $input['new_markdown'] ?? '';
		if ( ! is_string( $raw_markdown ) || '' === trim( $raw_markdown ) ) {
			throw new \InvalidArgumentException( 'El parámetro "new_markdown" es obligatorio y no puede estar vacío.' );
		}

		return [
			'post_id'      => $post_id,
			'block_index'  => $block_index,
			'new_markdown' => $this->sanitize_markdown( $raw_markdown ),
		];
	}
}
