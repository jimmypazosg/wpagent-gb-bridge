<?php
/**
 * Plantilla Macro: Cuadrícula de Tarjetas (Card Grid) en GenerateBlocks 2.4.x.
 *
 * Estructura limpia de 2 niveles (Contenedor Grid + Tarjetas individuales)
 * que garantiza compatibilidad nativa total con el editor Gutenberg y GenerateBlocks.
 *
 * @package WPAgent_GB_Bridge
 *
 * Variables disponibles en ámbito aislado:
 * @var string                     $content_markdown Contenido en Markdown de la sección.
 * @var string                     $parsed_content   Bloques parseados previamente.
 * @var WPAgent_GB_Markdown_Parser $parser           Instancia del parser de Markdown.
 * @var array<string, mixed>       $section          Array con los datos originales de la sección.
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Separación inteligente de las tarjetas a partir del Markdown estructurado.
$cards_raw = [];

if ( str_contains( $content_markdown, '---' ) ) {
	// 1. Separación explícita mediante reglas horizontales (---).
	$cards_raw = array_filter( array_map( 'trim', explode( '---', $content_markdown ) ) );
} elseif ( preg_match( '/\n(?=#{2,4}\s+)/', $content_markdown ) ) {
	// 2. Separación por encabezados (## o ### o ####).
	$cards_raw = array_filter( array_map( 'trim', preg_split( '/\n(?=#{2,4}\s+)/', $content_markdown ) ?: [] ) );
} elseif ( preg_match( '/^[-*]\s+/m', $content_markdown ) ) {
	// 3. Separación por elementos de lista (- o *).
	$cards_raw = array_filter( array_map( 'trim', preg_split( '/^[-*]\s+/m', $content_markdown ) ?: [] ) );
} else {
	// 4. Separación por párrafos dobles.
	$cards_raw = array_filter( array_map( 'trim', explode( "\n\n", $content_markdown ) ) );
}

if ( empty( $cards_raw ) ) {
	$cards_raw = [ $content_markdown ];
}
?>
<!-- wp:generateblocks/element {"uniqueId":"{{UNIQUE_ID}}","tagName":"section","className":"gb-element gb-card-grid-section gb-container"} -->
<section class="gb-element gb-element-{{UNIQUE_ID}} gb-card-grid-section gb-container">
<?php foreach ( $cards_raw as $card_markdown ) :
	$card_parsed = isset( $parser ) ? $parser->parse( $card_markdown ) : $card_markdown;
?>
<!-- wp:generateblocks/element {"uniqueId":"{{UNIQUE_ID}}","tagName":"div","className":"gb-element gb-card gb-card-item"} -->
<div class="gb-element gb-element-{{UNIQUE_ID}} gb-card gb-card-item">
<?php echo trim( $card_parsed ); ?>
</div>
<!-- /wp:generateblocks/element -->
<?php endforeach; ?>
</section>
<!-- /wp:generateblocks/element -->
