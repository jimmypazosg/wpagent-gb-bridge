<?php
/**
 * Plantilla Macro: Sección Hero en GenerateBlocks 2.4.x.
 *
 * Arquitectura atómica simplificada a un único contenedor semántico <section>
 * para evitar colisiones de ButtonBlockAppender y parpadeos en el editor Gutenberg.
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

if ( empty( $parsed_content ) && ! empty( $content_markdown ) && isset( $parser ) ) {
	$parsed_content = $parser->parse( $content_markdown );
}
?>
<!-- wp:generateblocks/element {"uniqueId":"{{UNIQUE_ID}}","tagName":"section","className":"gb-element gb-hero-section gb-container"} -->
<section class="gb-element gb-element-{{UNIQUE_ID}} gb-hero-section gb-container">
<?php echo trim( $parsed_content ); ?>
</section>
<!-- /wp:generateblocks/element -->
