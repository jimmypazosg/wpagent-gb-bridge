<?php
/**
 * Procesador híbrido de Markdown semántico a bloques GenerateBlocks v2.
 *
 * @package WPAgent_GB_Bridge
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Clase WPAgent_GB_Markdown_Parser.
 *
 * Transforma Markdown limpio a marcado de bloques Gutenberg y GenerateBlocks 2.x
 * (Directiva 3 y 5 de SPEC.md), optimizando el consumo de tokens y delegando
 * identificadores deterministas en WPAgent_GB_Sanitizer.
 */
class WPAgent_GB_Markdown_Parser {

	/**
	 * Instancia opcional del sanitizador para posprocesado.
	 *
	 * @var WPAgent_GB_Sanitizer|null
	 */
	private ?WPAgent_GB_Sanitizer $sanitizer;

	/**
	 * Constructor de la clase.
	 *
	 * @param WPAgent_GB_Sanitizer|null $sanitizer Instancia del sanitizador del plugin.
	 */
	public function __construct( ?WPAgent_GB_Sanitizer $sanitizer = null ) {
		$this->sanitizer = $sanitizer;
	}

	/**
	 * Parsea una cadena de texto en formato Markdown y la convierte en bloques
	 * serializados de Gutenberg y GenerateBlocks 2.4.x.
	 *
	 * @param string $markdown Texto con sintaxis Markdown sin procesar.
	 * @param bool   $inject_unique_ids Si es true y se dispone de Sanitizer, inyecta hashes reales de inmediato.
	 * @return string Cadena de bloques serializados lista para post_content.
	 */
	public function parse( string $markdown, bool $inject_unique_ids = false ): string {
		$clean_markdown = trim( str_replace( [ "\r\n", "\r" ], "\n", $markdown ) );
		if ( '' === $clean_markdown ) {
			return '';
		}

		$lines = explode( "\n", $clean_markdown );
		$total_lines = count( $lines );
		$blocks = [];
		$i = 0;

		while ( $i < $total_lines ) {
			$line = $lines[ $i ];
			$trimmed = trim( $line );

			// Línea vacía: omitir.
			if ( '' === $trimmed ) {
				$i++;
				continue;
			}

			// 1. Bloque de código cercado (```lang ... ```).
			if ( str_starts_with( $trimmed, '```' ) ) {
				$lang = trim( substr( $trimmed, 3 ) );
				$code_lines = [];
				$i++;

				while ( $i < $total_lines && ! str_starts_with( trim( $lines[ $i ] ), '```' ) ) {
					$code_lines[] = $lines[ $i ];
					$i++;
				}

				if ( $i < $total_lines ) {
					$i++; // Saltar la línea de cierre ```
				}

				$blocks[] = $this->render_code_block( implode( "\n", $code_lines ), $lang );
				continue;
			}

			// 2. Encabezados (# H1 a ###### H6).
			if ( preg_match( '/^(#{1,6})\s+(.+)$/', $trimmed, $heading_matches ) ) {
				$level = strlen( $heading_matches[1] );
				$heading_text = $heading_matches[2];

				// Mapear '#' a 'h2' para respetar el H1 reservado del título del post (Directiva 3).
				$target_level = ( 1 === $level ) ? 2 : $level;
				$tag_name = 'h' . $target_level;

				$blocks[] = $this->render_heading_block( $heading_text, $tag_name );
				$i++;
				continue;
			}

			// 3. Separador horizontal (---, ***, ___).
			if ( preg_match( '/^(?:-{3,}|\*{3,}|_{3,})$/', $trimmed ) ) {
				$blocks[] = $this->render_separator_block();
				$i++;
				continue;
			}

			// 4. Citas tipo bloque (> cita).
			if ( str_starts_with( $trimmed, '>' ) ) {
				$quote_lines = [];
				while ( $i < $total_lines && str_starts_with( trim( $lines[ $i ] ), '>' ) ) {
					$quote_line = (string) preg_replace( '/^>\s?/', '', trim( $lines[ $i ] ) );
					$quote_lines[] = $quote_line;
					$i++;
				}

				$blocks[] = $this->render_quote_block( implode( ' ', $quote_lines ) );
				continue;
			}

			// 5. Listas no ordenadas (- item o * item).
			if ( preg_match( '/^[-*]\s+(.+)$/', $trimmed, $list_match ) ) {
				$list_items = [];
				while ( $i < $total_lines && preg_match( '/^[-*]\s+(.+)$/', trim( $lines[ $i ] ), $item_match ) ) {
					$list_items[] = $item_match[1];
					$i++;
				}

				$blocks[] = $this->render_list_block( $list_items, false );
				continue;
			}

			// 6. Listas ordenadas (1. item).
			if ( preg_match( '/^\d+\.\s+(.+)$/', $trimmed, $list_match ) ) {
				$list_items = [];
				while ( $i < $total_lines && preg_match( '/^\d+\.\s+(.+)$/', trim( $lines[ $i ] ), $item_match ) ) {
					$list_items[] = $item_match[1];
					$i++;
				}

				$blocks[] = $this->render_list_block( $list_items, true );
				continue;
			}

			// 7. Párrafo estándar: agrupa líneas consecutivas hasta un corte de bloque.
			$para_lines = [];
			while ( $i < $total_lines ) {
				$cur_line = $lines[ $i ];
				$cur_trimmed = trim( $cur_line );

				if ( '' === $cur_trimmed
					|| str_starts_with( $cur_trimmed, '```' )
					|| preg_match( '/^#{1,6}\s+/', $cur_trimmed )
					|| str_starts_with( $cur_trimmed, '>' )
					|| preg_match( '/^(?:-{3,}|\*{3,}|_{3,})$/', $cur_trimmed )
					|| preg_match( '/^[-*]\s+/', $cur_trimmed )
					|| preg_match( '/^\d+\.\s+/', $cur_trimmed )
				) {
					break;
				}

				$para_lines[] = $cur_trimmed;
				$i++;
			}

			if ( ! empty( $para_lines ) ) {
				$blocks[] = $this->render_paragraph_block( implode( ' ', $para_lines ) );
			}
		}

		$output = implode( "\n\n", $blocks );

		// Inyección de identificadores deterministas si se solicita y el sanitizador está disponible.
		if ( $inject_unique_ids && null !== $this->sanitizer ) {
			return $this->sanitizer->process_and_sanitize_content( $output );
		}

		return $output;
	}

	/**
	 * Parsea formateo en línea (negritas, cursivas, enlaces, código inline).
	 *
	 * @param string $text Texto plano con formato inline.
	 * @return string Texto con marcado HTML semántico y seguro.
	 */
	public function parse_inline( string $text ): string {
		// 1. Proteger bloques de código inline (`código`) mediante tokens temporales.
		$code_tokens = [];
		$tokenized_text = (string) preg_replace_callback(
			'/`([^`]+)`/',
			static function ( array $matches ) use ( &$code_tokens ): string {
				$token = '%%INLINE_CODE_' . count( $code_tokens ) . '%%';
				$code_tokens[ $token ] = '<code>' . esc_html( $matches[1] ) . '</code>';
				return $token;
			},
			$text
		);

		// 2. Enlaces Markdown: [texto](url).
		$tokenized_text = (string) preg_replace_callback(
			'/\[([^\]]+)\]\(([^)\s]+)\)/',
			static function ( array $matches ): string {
				$label = esc_html( $matches[1] );
				$url   = esc_url( $matches[2] );
				return sprintf( '<a href="%s">%s</a>', $url, $label );
			},
			$tokenized_text
		);

		// 3. Negrita: **texto** o __texto__.
		$tokenized_text = (string) preg_replace( '/\*\*([^*]+)\*\*/', '<strong>$1</strong>', $tokenized_text );
		$tokenized_text = (string) preg_replace( '/__([^_]+)__/', '<strong>$1</strong>', $tokenized_text );

		// 4. Cursiva: *texto* o _texto_.
		$tokenized_text = (string) preg_replace( '/(?<!\*)\*([^*]+)\*(?!\*)/', '<em>$1</em>', $tokenized_text );
		$tokenized_text = (string) preg_replace( '/(?<!_)_([^_]+)_(?!_)/', '<em>$1</em>', $tokenized_text );

		// 5. Tachado: ~~texto~~.
		$tokenized_text = (string) preg_replace( '/~~([^~]+)~~/', '<del>$1</del>', $tokenized_text );

		// 6. Restaurar código inline protegido.
		if ( ! empty( $code_tokens ) ) {
			$tokenized_text = strtr( $tokenized_text, $code_tokens );
		}

		return $tokenized_text;
	}

	/**
	 * Genera un bloque atómico de encabezado GenerateBlocks v2 (generateblocks/text).
	 *
	 * @param string $text Texto del encabezado.
	 * @param string $tag_name Etiqueta HTML ('h2', 'h3', etc.).
	 * @return string Marcado Gutenberg serializado.
	 */
	private function render_heading_block( string $text, string $tag_name ): string {
		$parsed_content = $this->parse_inline( $text );
		$attrs = wp_json_encode(
			[
				'uniqueId' => '{{UNIQUE_ID}}',
				'tagName'  => $tag_name,
			],
			JSON_UNESCAPED_SLASHES
		);

		return sprintf(
			"<!-- wp:generateblocks/text %s -->\n<%s class=\"gb-text gb-text-{{UNIQUE_ID}}\">%s</%s>\n<!-- /wp:generateblocks/text -->",
			(string) $attrs,
			$tag_name,
			$parsed_content,
			$tag_name
		);
	}

	/**
	 * Genera un bloque atómico de párrafo GenerateBlocks v2 (generateblocks/text).
	 *
	 * @param string $text Texto del párrafo.
	 * @return string Marcado Gutenberg serializado.
	 */
	private function render_paragraph_block( string $text ): string {
		$parsed_content = $this->parse_inline( $text );
		$attrs = wp_json_encode(
			[
				'uniqueId' => '{{UNIQUE_ID}}',
				'tagName'  => 'p',
			],
			JSON_UNESCAPED_SLASHES
		);

		return sprintf(
			"<!-- wp:generateblocks/text %s -->\n<p class=\"gb-text gb-text-{{UNIQUE_ID}}\">%s</p>\n<!-- /wp:generateblocks/text -->",
			(string) $attrs,
			$parsed_content
		);
	}

	/**
	 * Genera un bloque de lista nativo de Gutenberg (core/list) con core/list-item.
	 *
	 * @param array<int, string> $items Elementos de la lista.
	 * @param bool               $ordered Indica si la lista es ordenada (ol) o no ordenada (ul).
	 * @return string Marcado Gutenberg serializado.
	 */
	private function render_list_block( array $items, bool $ordered ): string {
		$tag = $ordered ? 'ol' : 'ul';
		$list_attrs = $ordered ? ' {"ordered":true}' : '';

		$inner_items = [];
		foreach ( $items as $item ) {
			$inner_items[] = sprintf(
				"<!-- wp:list-item -->\n<li>%s</li>\n<!-- /wp:list-item -->",
				$this->parse_inline( $item )
			);
		}

		return sprintf(
			"<!-- wp:list%s -->\n<%s class=\"wp-block-list\">\n%s\n</%s>\n<!-- /wp:list -->",
			$list_attrs,
			$tag,
			implode( "\n", $inner_items ),
			$tag
		);
	}

	/**
	 * Genera un bloque de cita nativo de Gutenberg (core/quote).
	 *
	 * @param string $text Contenido de la cita.
	 * @return string Marcado Gutenberg serializado.
	 */
	private function render_quote_block( string $text ): string {
		$parsed_content = $this->parse_inline( $text );

		return sprintf(
			"<!-- wp:quote -->\n<blockquote class=\"wp-block-quote\"><p>%s</p></blockquote>\n<!-- /wp:quote -->",
			$parsed_content
		);
	}

	/**
	 * Genera un bloque de código nativo de Gutenberg (core/code).
	 *
	 * @param string $code Código en texto plano.
	 * @param string $lang Lenguaje opcional para la clase CSS.
	 * @return string Marcado Gutenberg serializado.
	 */
	private function render_code_block( string $code, string $lang = '' ): string {
		$escaped_code = esc_html( $code );
		$code_class = ! empty( $lang ) ? sprintf( ' class="language-%s"', esc_attr( $lang ) ) : '';

		return sprintf(
			"<!-- wp:code -->\n<pre class=\"wp-block-code\"><code%s>%s</code></pre>\n<!-- /wp:code -->",
			$code_class,
			$escaped_code
		);
	}

	/**
	 * Genera un separador horizontal nativo de Gutenberg (core/separator).
	 *
	 * @return string Marcado Gutenberg serializado.
	 */
	private function render_separator_block(): string {
		return "<!-- wp:separator -->\n<hr class=\"wp-block-separator has-alpha-channel-opacity\"/>\n<!-- /wp:separator -->";
	}
}
