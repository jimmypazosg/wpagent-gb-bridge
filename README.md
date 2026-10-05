# WPAgent GB Bridge

> **Extensión MCP y Motor de Bloques GenerateBlocks 2.x para WordPress**  
> Diseñado para orquestación de IA con **Google Antigravity**, **Claude Desktop** y clientes **MCP (Model Context Protocol)**.

---

## 🎯 Objetivo

`WPAgent GB Bridge` es una solución integral dividida en dos componentes:
1. **Plugin de WordPress (`wpagent-gb-bridge`):** Registra capacidades declarativas en la nueva **WordPress Abilities API**, permitiendo generar y editar entradas en estado **Borrador (`draft`)** utilizando la arquitectura atómica de **GenerateBlocks 2.4.x (Free)** con máxima optimización de tokens y cero CSS inline.
2. **Servidor MCP Local (`mcp-server/`):** Puente en Node.js que implementa la especificación MCP sobre `stdio`, comunicándose con WordPress mediante peticiones REST autenticadas y superando las barreras de protección de Cloudflare WAF mediante cabeceras seguras precompartidas.

---

## 🏗️ Arquitectura del Sistema

```
┌──────────────────────────────────────────────┐
│  Cliente IA (Antigravity 2.0 / Claude / etc)  │
└──────────────────────┬───────────────────────┘
                       │ JSON-RPC vía stdio
                       ▼
┌──────────────────────────────────────────────┐
│       Servidor MCP Local (mcp-server)        │
│       - Exposición de herramientas MCP       │
│       - Gestión de Basic Auth y Tokens       │
└──────────────────────┬───────────────────────┘
                       │ HTTPS REST (x-bridge-secret + Application Password)
                       ▼
┌──────────────────────────────────────────────┐
│          Cloudflare WAF / Edge Layer         │
│          (Bypass condicional seguro)         │
└──────────────────────┬───────────────────────┘
                       │
                       ▼
┌──────────────────────────────────────────────┐
│       WordPress Abilities REST API           │
│       (/wp-json/wp-abilities/v1/...)         │
└──────────────────────┬───────────────────────┘
                       │
                       ▼
┌──────────────────────────────────────────────┐
│          Plugin: wpagent-gb-bridge           │
│  - Pipeline Markdown a GenerateBlocks v2     │
│  - Inyector determinista de uniqueId         │
│  - Forzado estricto post_status = 'draft'    │
│  - Purga de caché en Redis y WP Fastest      │
└──────────────────────────────────────────────┘
```

---

## 🛠️ Herramientas MCP Expuestas

| Herramienta | Descripción | Método |
| :--- | :--- | :--- |
| `wpagent_create_draft_post` | Crea una entrada/página en borrador con bloques GenerateBlocks a partir de Markdown estructurado. | `POST` |
| `wpagent_patch_post_content` | Modifica quirúrgicamente un bloque específico por su índice posicional. | `POST` |
| `wpagent_get_post_block_tree` | Inspecciona el árbol ligero de bloques de un post para ahorrar tokens. | `GET` |
| `wp_get_site_info` | Consulta los metadatos y configuración del sitio WordPress en producción. | `GET` |
| `wp_list_abilities` | Descubre dinámicamente todas las habilidades registradas en WordPress. | `GET` |
| `wp_run_ability` | Ejecuta cualquier habilidad genérica registrada en WordPress Abilities API. | `POST/GET` |

---

## 🚀 Instalación y Configuración

### 1. Plugin de WordPress
1. Sube el archivo `wpagent-gb-bridge.zip` (o copia la carpeta `wpagent-gb-bridge`) a `/wp-content/plugins/`.
2. Activa el plugin en el panel de WordPress (`wp-admin > Plugins`).
3. Asegúrate de tener activo **GenerateBlocks 2.4.x**.

### 2. Configuración de Credenciales (`.env`)
Copia `.env.example` a `.env` en la raíz del proyecto:
```bash
cp .env.example .env
```
Rellena tus credenciales:
```env
WP_SITE_URL=https://tudominio.com
WP_USERNAME=tu_usuario
WP_APPLICATION_PASSWORD=xxxx xxxx xxxx xxxx
CF_BRIDGE_SECRET=tu_clave_secreta_cloudflare
```

### 3. Servidor MCP Local
Instala las dependencias del servidor MCP:
```bash
cd mcp-server
npm install
```

### 4. Conexión con Antigravity / Claude Desktop
Añade el servidor a tu configuración MCP (ej. `~/.gemini/config/mcp_config.json`):
```json
{
  "mcpServers": {
    "wpagent-gb-bridge": {
      "command": "node",
      "args": [
        "C:/ruta/a/wpagent-gb-bridge/mcp-server/index.js"
      ]
    }
  }
}
```

---

## 🔒 Política de Seguridad

* **Forzado Incondicional de Borrador:** Ninguna herramienta MCP puede publicar entradas directamente (`post_status` siempre es `'draft'`).
* **Protección WAF por Doble Factor:** Peticiones filtradas en Cloudflare mediante la cabecera `x-bridge-secret` y validadas en WordPress mediante **Application Passwords**.
