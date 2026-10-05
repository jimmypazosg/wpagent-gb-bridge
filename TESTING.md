# Protocolo de Verificación y Pruebas: WPAgent-GB-Bridge

Este documento detalla el procedimiento paso a paso para auditar y verificar el correcto funcionamiento del plugin **WPAgent-GB-Bridge** en el entorno de producción (`https://jimmypazos.es`).

---

## 1. Activación del Plugin en WordPress

1. Inicia sesión en el panel de administración (`/wp-admin`) con una cuenta con privilegios de Administrador.
2. Navega a **Plugins > Plugins instalados**.
3. Localiza **WPAgent GB Bridge** y haz clic en **Activar**.
4. **Verificaciones automáticas durante la activación:**
   * El plugin comprobará que el servidor ejecute **PHP >= 8.3** y **WordPress >= 6.5**. Si no se cumplieran los requisitos, la activación se detendrá limpiamente emitiendo un mensaje descriptivo vía `wp_die`.
   * Si GenerateBlocks 2.4.x no estuviera activo, se mostrará una advertencia administrativa (`admin_notices`) recomendando su activación para un renderizado óptimo de clases atómicas.

---

## 2. Comprobación en el Explorador de Capacidades (Abilities Explorer)

1. En el menú lateral de WordPress, ve a **Herramientas > Explorador de capacidades** (provisto por el plugin oficial `AI` v1.3.0).
2. Localiza el grupo o categoría **`wpagent`** (**WPAgent GenerateBlocks**).
3. Confirma la presencia de las 3 capacidades registradas:

| Capacidad / Slug | Etiqueta | Permiso Requerido | Meta MCP |
| :--- | :--- | :--- | :--- |
| `wpagent/create-draft-post` | **Crear Borrador con GenerateBlocks** | `edit_posts` | `public: true` |
| `wpagent/patch-post-content` | **Modificar Bloque Específico en Borrador** | `edit_post` | `public: true` |
| `wpagent/get-post-block-tree` | **Inspeccionar Árbol de Bloques de un Post** | `edit_post` | `public: true` |

4. Verifica que cada habilidad muestre su esquema JSON de entrada (`input_schema`) correctamente tipado y conciso.

---

## 3. Certificación del Descubrimiento MCP Adapter

Para comprobar que el cliente MCP (Gemini / Antigravity) descubre automáticamente las herramientas expuestas por el servidor:

### 3.1. Petición GET al Endpoint MCP

Ejecuta desde tu terminal o cliente REST (sustituyendo tu token de autenticación de WordPress / Application Password):

```bash
curl -X GET "https://jimmypazos.es/wp-json/mcp/mcp-adapter-default-server" \
  -H "Authorization: Basic $(echo -n 'usuario:tu-app-password' | base64)"
```

### 3.2. Criterio de Éxito
En la lista devuelta en JSON bajo `tools` deben figurar:
- `name: "wpagent/create-draft-post"`
- `name: "wpagent/patch-post-content"`
- `name: "wpagent/get-post-block-tree"`

---

## 4. Pruebas Funcionales con Payloads JSON

### Prueba A: Creación de Entrada en Borrador con Macro-Plantillas

Envía el siguiente payload JSON para invocar la herramienta `wpagent/create-draft-post`:

```json
{
  "title": "Demostración de Arquitectura Atómica con GenerateBlocks v2",
  "slug": "demo-generateblocks-v2",
  "post_type": "post",
  "category_ids": [1],
  "sections": [
    {
      "layout": "hero",
      "content_markdown": "# Potencia Atómica con GenerateBlocks 2.4\nDiseño sin sobrecarga de estilos CSS inline, optimizado para alto rendimiento y mínima latencia.\n[Conocer Más](https://jimmypazos.es)"
    },
    {
      "layout": "standard",
      "content_markdown": "## ¿Por qué la arquitectura atómica?\n\nAl desacoplar el contenido en bloques limpios `generateblocks/element` y `generateblocks/text`, eliminamos la redundancia y reducimos en más de un 60% el consumo de tokens.\n\n* **Cero CSS inline**: Estilos delegados al motor visual del tema.\n* **uniqueId determinista**: Calculado en backend sin coste para el modelo de IA.\n* **Caché instantánea**: Vaciado automático en Redis y WP Fastest Cache."
    },
    {
      "layout": "card_grid",
      "content_markdown": "### Velocidad Extrema\nCarga instantánea con Redis Object Cache 3.0.\n---\n### Edición Modular\n100% editable desde el editor nativo Gutenberg.\n---\n### Seguridad Total\nForzado estricto de borrador sin riesgo de publicación accidental."
    },
    {
      "layout": "cta_box",
      "content_markdown": "## ¿Listo para orquestar con IA?\nComprueba la integración de herramientas MCP en tu panel WordPress ahora mismo.\n[Ver en WordPress](https://jimmypazos.es/wp-admin)"
    }
  ]
}
```

#### Respuesta Esperada:
```json
{
  "success": true,
  "post_id": 123,
  "title": "Demostración de Arquitectura Atómica con GenerateBlocks v2",
  "post_type": "post",
  "post_status": "draft",
  "edit_url": "https://jimmypazos.es/wp-admin/post.php?post=123&action=edit",
  "preview_url": "https://jimmypazos.es/?p=123&preview=true",
  "message": "Borrador creado exitosamente con arquitectura atómica GenerateBlocks 2.4.x."
}
```

---

### Prueba B: Inspección Ligera del Árbol de Bloques

Invoca `wpagent/get-post-block-tree` con el `post_id` recién creado:

```json
{
  "post_id": 123
}
```

#### Respuesta Esperada:
Lista reducida con índices posicionales, nombres de bloque y extractos textuales (sin el código JSON verboso ni atributos de estilo).

---

### Prueba C: Modificación Atómica de un Bloque (Directiva 4)

Modifica exclusivamente un bloque del post mediante `wpagent/patch-post-content`:

```json
{
  "post_id": 123,
  "block_index": 1,
  "new_markdown": "## Arquitectura Actualizada\n\nEl bloque ha sido actualizado atómicamente mediante un parche por deltas sin reescribir el resto del documento."
}
```

---

## 5. Criterios de Aceptación y Validación de Calidad

1. **Apertura en el Editor Gutenberg:**
   * Abre la URL `edit_url` en tu navegador (`wp-admin/post.php?post=...&action=edit`).
   * **Criterio obligatorio:** No debe aparecer ningún cartel de *«Este bloque contiene contenido inesperado o no válido»* (*Attempt Block Recovery*). Cada bloque debe ser completamente editable como un bloque atómico nativo de GenerateBlocks 2.4.x.
2. **Política Incondicional de Borrador:**
   * Comprueba en la barra lateral del documento que el estado de la entrada sea `'Borrador'` (`draft`). Ninguna llamada de la IA tiene capacidad de publicar directamente.
3. **Invalidación de Caché:**
   * Al acceder a la vista previa, el contenido debe reflejar instantáneamente los cambios sin requerir purgas manuales de Redis ni WP Fastest Cache.
