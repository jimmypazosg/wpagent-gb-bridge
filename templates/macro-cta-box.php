<?php
/**
 * Plantilla Macro: Caja de Llamada a la Acción (CTA Box) en GenerateBlocks 2.4.x.
 *
 * Estructura atómica semántica basada en <aside> que garantiza un flujo
 * de bloques directo, predecible y compatible con inserción manual en Gutenberg.
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

$cta_raw_text = trim( $content_markdown );
$cta_title    = '';
$button_label = '';
$button_url   = '#';

// 1. Extraer botón de acción si existe enlace Markdown: [Texto](url).
if ( preg_match( '/\[([^\]]+)\]\(([^)\s]+)\)/', $cta_raw_text, $link_matches ) ) {
	$button_label = trim( $link_matches[1] );
	$button_url   = esc_url( trim( $link_matches[2] ) );
	// Retirar el enlace del markdown para no duplicarlo como párrafo.
	$cta_raw_text = trim( str_replace( $link_matches[0], '', $cta_raw_text ) );
}

// 2. Extraer titular si existe un encabezado (# Titular).
$lines = explode( "\n", $cta_raw_text );
$body_lines = [];

foreach ( $lines as $line ) {
	$trimmed = trim( $line );
	if ( '' === $trimmed ) {
		continue;
	}

	if ( empty( $cta_title ) && preg_match( '/^#{1,6}\s+(.+)$/', $trimmed, $h_matches ) ) {
		$cta_title = trim( $h_matches[1] );
	} else {
		$body_lines[] = $trimmed;
	}
}

// Si no había encabezado explícito pero hay texto, usar la primera línea como titular.
if ( empty( $cta_title ) && ! empty( $body_lines ) ) {
	$cta_title = array_shift( $body_lines );
}

$body_markdown = implode( "\n\n", $body_lines );
$parsed_body   = ( isset( $parser ) && ! empty( $body_markdown ) ) ? $parser->parse( $body_markdown ) : '';
?>
<!-- wp:generateblocks/element {"uniqueId":"{{UNIQUE_ID}}","tagName":"aside","className":"gb-element gb-cta-box gb-container"} -->
<aside class="gb-element gb-element-{{UNIQUE_ID}} gb-cta-box gb-container">
<?php if ( ! empty( $cta_title ) ) : ?>
<!-- wp:generateblocks/text {"uniqueId":"{{UNIQUE_ID}}","tagName":"h2"} -->
<h2 class="gb-text gb-text-{{UNIQUE_ID}} gb-cta-heading"><?php echo esc_html( $cta_title ); ?></h2>
<!-- /wp:generateblocks/text -->
<?php endif; ?>

<?php if ( ! empty( $parsed_body ) ) : ?>
<?php echo trim( $parsed_body ); ?>
<?php endif; ?>

<?php if ( ! empty( $button_label ) ) : ?>
<!-- wp:generateblocks/text {"uniqueId":"{{UNIQUE_ID}}","tagName":"a","className":"gb-button gb-button-primary"} -->
<a class="gb-text gb-button gb-button-primary gb-text-{{UNIQUE_ID}}" href="<?php echo $button_url; ?>"><?php echo esc_html( $button_label ); ?></a>
<!-- /wp:generateblocks/text -->
<?php endif; ?>
</aside>
<!-- /wp:generateblocks/element -->
