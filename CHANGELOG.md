# Registro de Cambios (Changelog) - WPAgent GB Bridge

Todas las modificaciones notables de este proyecto serán documentadas en este archivo siguiendo el estándar de [Semantic Versioning](https://semver.org/).

---

## [1.0.6] - 2026-10-05

### Corregido
* **Exposición en WordPress Abilities REST API (`show_in_rest`):** Añadido `'show_in_rest' => true` y metadatos de comportamiento semántico (`annotations`: `readonly`, `destructive`, `idempotent`) en la definición declarativa de las tres habilidades (`wpagent/create-draft-post`, `wpagent/patch-post-content`, `wpagent/get-post-block-tree`). Esto permite su descubrimiento público inmediato en `/wp-json/wp-abilities/v1/abilities` y ejecución remota vía `/run`.

---

## [1.0.5] - 2026-10-05

### Corregido
* **Resolución de Parpadeo del Inserter de Gutenberg (`ButtonBlockAppender`):** Identificada la causa del parpadeo visual en el botón «+» del editor de bloques. Se producía debido a la colisión de coordenadas entre dos `ButtonBlockAppender` generados por el anidamiento redundante de contenedores sin espaciado en `macro-hero.php` (`<section>` conteniendo directamente un `<div>`). La arquitectura atómica se ha simplificado a un único contenedor semántico `<section>` GenerateBlocks 2.4.x.
* **Saneamiento Exhaustivo de Atributos (`class-sanitizer.php`):** Añadido recorrido recursivo (`array_walk_recursive`) sobre todos los atributos del bloque para garantizar que ningún marcador `{{UNIQUE_ID}}` persista sin resolver en `attrs.className` ni en ningún otro atributo JSON.
* **Separación Estricta de Clases CSS:** Eliminada la inyección de `gb-element-{uniqueId}` dentro del atributo `className` del bloque JSON (reservado para clases personalizadas del usuario/tema), manteniendo la clase de alcance únicamente en el marcado HTML, tal como exige el motor interno de GenerateBlocks 2.0.
* **Hoja de Estilos de Compatibilidad (`assets/css/gb-bridge.css`):** Creada y encolada mediante el hook oficial `enqueue_block_assets` para proporcionar espaciado base (padding, margin, min-height) a los contenedores tanto en el frontend como en el iframe del editor Gutenberg, eliminando el colapso visual de los botones de inserción.

---

## [1.0.4] - 2026-10-05

### Corregido
* **Resuelto Conflicto Estricto de `doing_action()` en el Núcleo:** Tras auditar el código fuente del núcleo `/wp-includes/abilities-api.php` en producción, se identificó que `wp_register_ability()` y `wp_register_ability_category()` abortan estrictamente y retornan `null` si no se invocan dentro de sus respectivos ganchos canónicos (`wp_abilities_api_init` y `wp_abilities_api_categories_init`).
* **Mecanismo Bulletproof Dual (Wrapper + Direct Singleton Fallback):** `WPAgent_GB_Ability_Registry` implementa `register_ability_safely()` y `register_category_safely()`, que intentan primero la función canónica de WordPress Core durante su hook respectivo, y en caso de contingencia o invocación tardía (`init` prioridad 99) acceden directamente al singleton `WP_Abilities_Registry::get_instance()->register()`, eludiendo el bloqueo sin alterar la integridad del registro global.
* **Eliminación de Inicialización Duplicada:** Corregido `WPAgent_GB_Bridge::setup_hooks()` para evitar doble llamada a `init_components()` en `plugins_loaded`.
* **Auditoría Forense en Tiempo de Arranque (`wpagent_gb_boot_log`):** Cada evento de registro almacena marcas de tiempo, hook de ejecución y estado exacto en una opción persistente de WordPress para ser inspeccionada en la pantalla de diagnóstico.

---

## [1.0.3] - 2026-10-05

### Añadido
* **Prueba de Registro en Vivo y Volcado del Núcleo:** La pantalla de diagnóstico ahora ejecuta en vivo `wp_register_ability_category()` y `wp_register_ability()`, mostrando el valor de retorno o mensaje de error exacto (`WP_Error::get_error_message`), junto con el código fuente real de `/wp-includes/abilities-api.php` y los callbacks activos en `$wp_filter`.

---

## [1.0.2] - 2026-10-05

### Añadido
* **Herramienta de Diagnóstico Forense (`WPAgent_GB_Diagnostics`):** Añadida pantalla administrativa en **Herramientas > Diagnóstico WPAgent** (`tools.php?page=wpagent-gb-diagnostics`) que audita en tiempo real las funciones activas de la Abilities API, archivos de declaración (vía Reflection), ganchos en `$wp_filter`, lectura del código fuente del plugin oficial `AI` en disco y últimas líneas de `debug.log`.
* **Copiado Rápido:** Botón en interfaz administrativa para copiar el reporte completo al portapapeles con un solo clic.

---

## [1.0.1] - 2026-10-05

### Corregido
* **Registro en Abilities API:** Implementado el registro formal de la categoría `'wpagent'` mediante `wp_register_ability_category()` en el gancho canónico `wp_abilities_api_categories_init` para garantizar su visualización en **Herramientas > Explorador de capacidades**.
* **Ganchos Canónicos:** Añadida suscripción al hook oficial `wp_abilities_api_init` y hooks de contingencia para resolver la colisión de tiempos (*hook timing*) con el plugin oficial `AI` v1.3.0.
* **Momento de Carga:** Movida la carga de dependencias e inicialización de hooks directamente al constructor del Singleton `WPAgent_GB_Bridge`, asegurando que ningún evento del ciclo de vida se pierda antes de `plugins_loaded`.
* **Esquemas y Callbacks:** Suministrados simultáneamente `callback` y `execute_callback`, así como `output_schema` formal para cada una de las 3 habilidades.
* **Trazas de Diagnóstico:** Incorporados mensajes descriptivos con `error_log` para auditar el hook de registro y capturar posibles instancias de `WP_Error`.

---

## [1.0.0] - 2026-10-05

### Añadido
* **Arquitectura Atómica:** Soporte nativo para bloques `generateblocks/element` y `generateblocks/text` de GenerateBlocks 2.4.x (Free).
* **Directiva 1 (Ahorro de Tokens):** Inyector recursivo determinista de `uniqueId` de 8 caracteres alfanuméricos mediante `WPAgent_GB_Sanitizer`.
* **Directiva 2 (Macro-Plantillas):** Plantillas reutilizables en `/templates` (`hero`, `card_grid`, `cta_box`) que minimizan el consumo de tokens de entrada/salida.
* **Directiva 3 (Parser Semántico):** Conversor híbrido de Markdown limpio a marcado nativo de bloques Gutenberg mediante `WPAgent_GB_Markdown_Parser`.
* **Directiva 4 (Edición Quirúrgica):** Capacidad de parcheo atómico por deltas mediante sustitución posicional en `WPAgent_GB_Block_Builder::patch_post_block`.
* **Directiva 5 (Clases Globales):** Eliminación de objetos CSS inline; delegación en clases utilitarias del tema y constructor.
* **Directiva 6 (Esquemas Concisos):** Definición hiper-concisa de esquemas JSON para el adaptador MCP.
* **Invalidación Multinivel:** Purga atómica de memoria en Redis Object Cache 3.0.0 y de páginas estáticas en WP Fastest Cache 1.5.2 (`WPAgent_GB_Cache_Purger`).
* **Política de Seguridad:** Forzado incondicional de estado `post_status = 'draft'` en todas las operaciones.
