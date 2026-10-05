<?php
/**
 * Gestor de invalidación de caché multinivel (Redis Object Cache y WP Fastest Cache).
 *
 * @package WPAgent_GB_Bridge
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Clase WPAgent_GB_Cache_Purger.
 *
 * Se encarga de la invalidación atómica de memoria en Redis y purga de páginas
 * HTML estáticas en WP Fastest Cache tras la creación o modificación de entradas vía MCP
 * (Sección 7 de SPEC.md).
 */
class WPAgent_GB_Cache_Purger {

	/**
	 * Purga exhaustiva de la caché asociada a una entrada específica.
	 *
	 * Invalida tanto los registros de memoria de objeto (Redis) como la caché de página
	 * de disco generada por WP Fastest Cache para asegurar visibilidad inmediata.
	 *
	 * @param int $post_id ID de la entrada cuya caché se debe purgar.
	 * @return void
	 */
	public function purge_post_cache( int $post_id ): void {
		if ( $post_id <= 0 ) {
			return;
		}

		// 1. Vaciado de Caché de Objeto en memoria (Redis Object Cache 3.0.0).
		clean_post_cache( $post_id );
		wp_cache_delete( (string) $post_id, 'posts' );
		wp_cache_delete( 'post_meta_' . $post_id, 'post_meta' );

		// 2. Purga de Caché de Página estática (WP Fastest Cache 1.5.2).
		$this->purge_wp_fastest_cache( $post_id );

		// Gancho de acción para integraciones o invalidaciones adicionales.
		do_action( 'wpagent_gb_after_purge_post_cache', $post_id );
	}

	/**
	 * Ejecuta la purga específica en WP Fastest Cache de manera defensiva.
	 *
	 * @param int $post_id ID del post a invalidar.
	 * @return void
	 */
	private function purge_wp_fastest_cache( int $post_id ): void {
		if ( ! class_exists( 'WpFastestCache' ) ) {
			return;
		}

		try {
			$wpfc = new \WpFastestCache();
			if ( method_exists( $wpfc, 'singleDeleteCache' ) ) {
				$wpfc->singleDeleteCache( false, $post_id );
			}
		} catch ( \Throwable $e ) {
			// Registro silencioso en log de depuración sin interrumpir la ejecución del pipeline.
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				error_log(
					sprintf(
						'[WPAgent GB Bridge] Error purgando WP Fastest Cache para post ID %d: %s',
						$post_id,
						$e->getMessage()
					)
				);
			}
		}
	}

	/**
	 * Limpia datos transitorios del sistema y cachés de términos taxonómicos.
	 *
	 * Útil al crear categorías dinámicamente o cuando se requiere refrescar
	 * los esquemas y metadatos de bloques en llamadas MCP.
	 *
	 * @return void
	 */
	public function purge_transients(): void {
		// Purgar posibles transitorios de taxonomías o esquemas del plugin.
		delete_transient( 'wpagent_gb_block_schemas' );
		delete_transient( 'wpagent_gb_categories_cache' );

		// Limpieza de relaciones de términos en memoria de objetos si está disponible.
		if ( function_exists( 'clean_taxonomy_cache' ) ) {
			clean_taxonomy_cache( 'category' );
			clean_taxonomy_cache( 'post_tag' );
		}

		do_action( 'wpagent_gb_after_purge_transients' );
	}

	/**
	 * Comprueba si la caché de objetos externa (Redis) está activa.
	 *
	 * @return bool True si WordPress está utilizando Redis u otro object cache externo.
	 */
	public function is_redis_active(): bool {
		return wp_using_ext_object_cache();
	}

	/**
	 * Comprueba si el plugin WP Fastest Cache se encuentra activo y disponible.
	 *
	 * @return bool True si la clase WpFastestCache existe en el runtime.
	 */
	public function is_wp_fastest_cache_active(): bool {
		return class_exists( 'WpFastestCache' );
	}
}
