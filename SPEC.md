# ESPECIFICACIÓN TÉCNICA Y ARQUITECTURA DE SISTEMA (SRS / RFC-001)

**Proyecto:** `WPAgent-GB-Bridge` (Extensión MCP y Motor de Bloques GenerateBlocks)

**Entorno de orquestación:** Google Antigravity 2.0

**Fecha de emisión:** 5 de octubre de 2026

**Estado:** Documento base para implementación autónoma

---

## 1. Objetivo Principal del Proyecto

Desarrollar un plugin nativo para WordPress (`wpagent-gb-bridge`) que extienda la **WordPress Abilities API** y el adaptador **MCP (Model Context Protocol)** estándar del núcleo de WordPress. Su función primordial es capacitar a modelos de IA (Gemini / agentes orquestados) para **crear, maquetar, estructurar y editar contenido exclusivamente en estado borrador (`draft`) utilizando la arquitectura atómica de GenerateBlocks Free (v2.4.x)**, optimizando al máximo el consumo de tokens y garantizando que el marcado resultante sea 100% editable por un usuario humano desde la interfaz estándar de bloques de WordPress (`wp-admin`).

---

## 2. Entorno Operativo y Pila Tecnológica Auditada

Toda implementación ejecutada por Antigravity 2.0 debe cumplir de manera estricta con las dependencias del servidor de producción:

### 2.1. Infraestructura de Servidor

* **Sistema Operativo:** Linux 6.8.0-134-generic x86_64 (Ubuntu 24.04 LTS).


* **Servidor Web:** Nginx 1.30.5.


* **Intérprete:** PHP 8.3.6 vía `fpm-fcgi`.


* **Límites de Ejecución:** `memory_limit: 256M`, `max_execution_time: 120s`, `max_input_vars: 10000`, `upload_max_filesize: 100M`.


* **Base de Datos:** MySQL 8.0.46 (`mysqli`, charset `utf8mb4`, collation `utf8mb4_unicode_520_ci`, prefijo `wp_`).



### 2.2. Núcleo, Tema y Plugins Activos

* **Instalación:** WordPress 7.1.2 monositio en producción (`[https://jimmypazos.es](https://jimmypazos.es)`).


* **Estructura de Enlaces:** `/%postname%/`.


* **Tema Principal:** GeneratePress 3.6.1 (directorio: `/var/www/jpazos/data/www/jimmypazos.es/wp-content/themes/generatepress`).


* **Constructor Visual:** GenerateBlocks 2.4.1 (versión Free/repositorio).


* **Caché y Persistencia:** Redis Object Cache 3.0.0 y WP Fastest Cache 1.5.2.


* **Ecosistema de IA Base:**
* Plugin oficial `AI` (v1.3.0) con conector `AI Provider for Google` (v1.2.0).


* Motor conectado: Google (Gemini 3.8 Flash para texto y Nano Banana 2 para imágenes).


* Estado de experimentos: `Explorador de capacidades: ON`, `Custom Abilities: ON`, `Registro de solicitudes de IA: ON`, `Aprobación de conectores: ON`.


* Adaptador MCP expuesto en: `/wp-json/mcp/mcp-adapter-default-server`.





---

## 3. Arquitectura del Plugin: `wpagent-gb-bridge`

El desarrollo no construirá un servidor HTTP/JSON-RPC independiente ni sobreescribirá la REST API básica; en su lugar, se registrará como un paquete de capacidades declarativas mediante `wp_register_ability()`, lo que habilitará su descubrimiento inmediato tanto en el Explorador de Capacidades interno de WordPress como a través de la herramienta MCP adapter.

```
                     ┌────────────────────────────────────────┐
                     │    Agente IA / Antigravity 2.0         │
                     └───────────────────┬────────────────────┘
                                         │ JSON Payload (MCP Call)
                                         ▼
                     ┌────────────────────────────────────────┐
                     │     WordPress Core MCP Adapter         │
                     │ (/wp-json/mcp/mcp-adapter-default-...) │
                     └───────────────────┬────────────────────┘
                                         │ Callback de Habilidad
                                         ▼
┌─────────────────────────────────────────────────────────────────────────────┐
│                       Plugin: wpagent-gb-bridge                             │
│                                                                             │
│  1. Controlador de Permisos (permission_callback: 'edit_posts')             │
│  2. Pipeline de Optimización de Tokens (Parser Markdown + Macro-Layouts)    │
│  3. Motor de Atributos GenerateBlocks v2.x (Inyector determinista uniqueId) │
│  4. Inserción de Datos Segura (wp_insert_post con post_status = 'draft')    │
│  5. Invalidador de Caché (Redis Object Cache + WP Fastest Cache)            │
└─────────────────────────────────────────────────────────────────────────────┘

```

---

## 4. Directivas Obligatorias de Ahorro y Eficiencia de Tokens

Para minimizar la sobrecarga computacional y reducir el consumo de tokens de entrada/salida (I/O tokens), el código desarrollado en Antigravity 2.0 debe implementar las siguientes 6 directivas a nivel de arquitectura PHP:

### Directiva 1: Inyección Determinista de Identificadores (`uniqueId`)

* **Problema:** GenerateBlocks 2.x requiere que cada bloque posea un hash alfanumérico único (`"uniqueId":"a1b2c3d4"`). Exigir a la IA calcular estos identificadores consume tokens de salida y aumenta el riesgo de colisiones.
* **Regla de Implementación:** El esquema de entrada (`input_schema`) de la habilidad prohíbe el envío de `uniqueId`. La función PHP de backend recorre la estructura de bloques o el marcado HTML y asigna dinámicamente hashes generados mediante `wp_generate_password(8, false, false)` antes de persistir en base de datos.

### Directiva 2: Abstracción Semántica por Plantillas (Macro-Bloques PHP)

* **Problema:** Enviar el código HTML completo con atributos anidados de contenedores Flexbox consume entre 200 y 400 tokens por sección visual.
* **Regla de Implementación:** El agente de IA únicamente envía el identificador de la macro-plantilla y sus variables de contenido:
```json
{
  "layout": "feature-card-grid",
  "columns": 2,
  "items": [
    { "title": "Velocidad Optimizada", "body": "Carga instantánea con Redis." },
    { "title": "Diseño Limpio", "body": "Sin sobrecarga de CSS externo." }
  ]
}

```


* PHP expande esta llamada en el marcado nativo de GenerateBlocks v2.x (`generateblocks/element` con propiedades Flexbox/CSS Grid).

### Directiva 3: Parser Híbrido Markdown a GenerateBlocks

* **Problema:** La estructura de texto normal de GenerateBlocks (`<!-- wp:generateblocks/text {"tagName":"p"} --><p>...</p><!-- /wp:generateblocks/text -->`) multiplica por tres el costo de tokens frente al texto plano.
* **Regla de Implementación:** El cuerpo del artículo o sección se envía en Markdown limpio. El procesador interno del plugin convierte sintácticamente:
* `# H2` / `## H3` ➔ `<!-- wp:generateblocks/text {"tagName":"h2"} --><h2>...</h2><!-- /wp:generateblocks/text -->`
* Párrafos estándar ➔ `<!-- wp:generateblocks/text {"tagName":"p"} --><p>...</p><!-- /wp:generateblocks/text -->`
* Listas nativas de Gutenberg ➔ `<!-- wp:list -->...<!-- /wp:list -->`



### Directiva 4: Operaciones de Edición por Deltas (Parches Atómicos)

* **Problema:** Para modificar un párrafo en un artículo existente de 2.000 palabras, enviar todo el post para su reescritura agota innecesariamente la ventana de contexto.
* **Regla de Implementación:** La habilidad de actualización (`update-post-block`) recibe únicamente:
* `post_id` (Entero).
* `block_index` o `target_heading` (Selector contextual).
* `replacement_markdown` (Solo el fragmento a sustituir).


* La lógica PHP parsea con `parse_blocks()`, reemplaza el nodo objetivo en memoria y guarda mediante `wp_update_post()`.

### Directiva 5: Centralización en Clases Globales

* El modelo no debe generar objetos `styles` con reglas CSS inline (`paddingTop`, `marginLeft`, etc.). El código generado en PHP asignará clases utilitarias (`gb-container`, `gb-element`, `gb-button-primary`), delegando los estilos a la hoja de estilo de GeneratePress o GenerateBlocks.

### Directiva 6: Esquemas JSON Schema Hiper-Concisos

* Las definiciones pasadas al MCP Adapter deben limitar el número de propiedades opcionales y utilizar enumeraciones breves para reducir la cantidad de tokens que el cliente MCP consume al leer los esquemas durante el descubrimiento (`tools/list`).

---

## 5. Especificación de Bloques: GenerateBlocks v2.4.x (Free)

El plugin debe generar marcado conforme a la arquitectura atómica de GenerateBlocks 2.4.x:

| Bloque Nativo | `tagName` soportados | Propósito Arquitectónico |
| --- | --- | --- |
| `generateblocks/element` | `div`, `section`, `header`, `footer`, `aside`, `a` | Contenedores flexibles, columnas y grillas CSS. Reemplaza a `container` y `grid`. Requiere `className: "gb-element"`. |
| `generateblocks/text` | `h1`, `h2`, `h3`, `h4`, `h5`, `h6`, `p`, `span`, `a` | Todos los textos, encabezados, párrafos y botones. Reemplaza a `headline` y `button`. |
| `generateblocks/media` | `figure`, `div` | Imágenes, recursos visuales con relación de aspecto fija y leyendas. |
| `generateblocks/shape` | `div` | Separadores de sección (SVG decorativos en cabeceras o pies de sección). |
| `generateblocks/query` | `div` | Bucle dinámico (contiene `looper`, `loop-item`, `no-results`). |

---

## 6. Especificación de Habilidades (`Abilities`) a Registrar

El plugin implementará el hook de WordPress `init` registrando las siguientes capacidades bajo la función estándar:

### 6.1. Habilidad: `wpagent/create-draft-post`

* **Etiqueta:** Crear Borrador con GenerateBlocks
* **Descripción:** Crea un post o página en borrador utilizando plantillas y marcado GenerateBlocks a partir de Markdown estructurado.
* **Configuración MCP:**
```php
'meta' => [
    'mcp' => [
        'public' => true
    ]
]

```


* **Esquema de Entrada (`input_schema`):**
```json
{
  "type": "object",
  "properties": {
    "title": { "type": "string", "description": "Título de la entrada" },
    "slug": { "type": "string", "description": "Slug SEO amigable (opcional)" },
    "post_type": { "type": "string", "enum": ["post", "page"], "default": "post" },
    "category_ids": { "type": "array", "items": { "type": "integer" } },
    "sections": {
      "type": "array",
      "items": {
        "type": "object",
        "properties": {
          "layout": { "type": "string", "enum": ["standard", "hero", "card_grid", "cta_box"] },
          "content_markdown": { "type": "string", "description": "Contenido en markdown de la sección" }
        },
        "required": ["content_markdown"]
      }
    }
  },
  "required": ["title", "sections"],
  "additionalProperties": false
}

```


* **Control de Seguridad:** `permission_callback: function() { return current_user_can('edit_posts'); }`. Fuerza incondicionalmente `post_status = 'draft'`.

### 6.2. Habilidad: `wpagent/patch-post-content`

* **Etiqueta:** Modificar Bloque Específico en Borrador
* **Descripción:** Aplica un cambio atómico sobre un bloque de una entrada existente sin reescribir el resto del documento.
* **Configuración MCP:** `meta.mcp.public = true`.
* **Esquema de Entrada (`input_schema`):**
```json
{
  "type": "object",
  "properties": {
    "post_id": { "type": "integer", "description": "ID del post a modificar" },
    "block_index": { "type": "integer", "description": "Índice posicional del bloque a reemplazar" },
    "new_markdown": { "type": "string", "description": "Nuevo contenido semántico en Markdown" }
  },
  "required": ["post_id", "block_index", "new_markdown"],
  "additionalProperties": false
}

```



### 6.3. Habilidad: `wpagent/get-post-block-tree`

* **Etiqueta:** Inspeccionar Árbol de Bloques de un Post
* **Descripción:** Devuelve un resumen simplificado de los bloques que componen una entrada (tipo, índice y extracto de texto), omitiendo configuraciones CSS innecesarias para ahorrar tokens de entrada.
* **Configuración MCP:** `meta.mcp.public = true`.

---

## 7. Control de Invalidación de Caché y Almacenamiento

El servidor cuenta con dos sistemas de almacenamiento en caché en ejecución: **Redis Object Cache (3.0.0)** y **WP Fastest Cache (1.5.2)**. Para garantizar que cualquier cambio realizado mediante llamadas MCP se visualice de inmediato en el panel de WordPress y en vistas previas:

1. **Vaciado de Caché de Objeto:**
```php
clean_post_cache( $post_id );
wp_cache_delete( $post_id, 'posts' );

```


2. **Purga de Caché de Página (WP Fastest Cache):**
```php
if ( class_exists( 'WpFastestCache' ) ) {
    $wpfc = new WpFastestCache();
    $wpfc->singleDeleteCache( false, $post_id );
}

```



---

## 8. Estructura de Archivos del Proyecto para Antigravity 2.0

Antigravity 2.0 debe estructurar el repositorio de código respetando la siguiente convención modular de WordPress:

```text
wpagent-gb-bridge/
├── wpagent-gb-bridge.php           # Encabezado del plugin, inicialización y hooks principales
├── includes/
│   ├── class-ability-registry.php   # Registro declarativo de wp_register_ability
│   ├── class-gb-block-builder.php   # Generador y serializador de bloques GenerateBlocks v2
│   ├── class-markdown-parser.php    # Transformador de Markdown semántico a AST de Gutenberg
│   ├── class-cache-purger.php       # Limpiador de Redis y WP Fastest Cache
│   └── class-sanitizer.php          # Generador de uniqueId e inyector de seguridad
└── templates/
    ├── macro-hero.php               # Plantilla predefinida para sección Hero en GB
    ├── macro-card-grid.php          # Plantilla para cuadrícula de tarjetas
    └── macro-cta-box.php            # Plantilla para bloque de llamada a la acción

```

---

## 9. Criterios de Aceptación (Definición de Terminado)

1. **Aprobación de Conector:** La solicitud pendiente del plugin `AI` hacia el conector Google debe estar autorizada desde *Herramientas > Aprobaciones de conectores* en `wp-admin` para desbloquear la ejecución de IA.


2. **Exposición en MCP:** Al consultar el endpoint `/wp-json/mcp/mcp-adapter-default-server`, las herramientas `wpagent/create-draft-post`, `wpagent/patch-post-content` y `wpagent/get-post-block-tree` deben figurar entre las capacidades descubiertas.
3. **Validación en Editor:** Cualquier borrador generado por el plugin debe abrirse en el editor de bloques de WordPress (`wp-admin/post.php`) **sin mostrar advertencias de recuperación de bloques** (*This block contains unexpected or invalid content / Attempt Block Recovery*).
4. **Política de Estado Estricta:** Ninguna capacidad del plugin permitirá la publicación directa (`post_status = 'publish'`). Toda entrada creada o editada debe mantenerse como borrador (`draft`).