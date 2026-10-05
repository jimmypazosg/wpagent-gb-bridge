#!/usr/bin/env node

/**
 * WPAgent GB Bridge - Local MCP Server
 *
 * Expone capacidades de WordPress y GenerateBlocks al protocolo MCP (Model Context Protocol)
 * comunicándose a través de la WordPress Abilities REST API con autenticación segura y Cloudflare WAF Bypass.
 */

import { McpServer } from '@modelcontextprotocol/sdk/server/mcp.js';
import { StdioServerTransport } from '@modelcontextprotocol/sdk/server/stdio.js';
import { z } from 'zod';
import dotenv from 'dotenv';
import path from 'path';
import { fileURLToPath } from 'url';

const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);

// Cargar variables de entorno desde ../.env o .env local (quiet: true para no contaminar stdout de MCP)
dotenv.config({ path: path.resolve(__dirname, '../.env'), quiet: true });
dotenv.config({ path: path.resolve(__dirname, '.env'), quiet: true });

const WP_SITE_URL = (process.env.WP_SITE_URL || 'https://jimmypazos.es').replace(/\/+$/, '');
const WP_USERNAME = process.env.WP_USERNAME || '';
const WP_APPLICATION_PASSWORD = process.env.WP_APPLICATION_PASSWORD || '';
const CF_BRIDGE_SECRET = process.env.CF_BRIDGE_SECRET || '';

function getAuthHeaders() {
  const headers = {
    'Accept': 'application/json',
  };

  if (WP_USERNAME && WP_APPLICATION_PASSWORD) {
    const credentials = Buffer.from(`${WP_USERNAME}:${WP_APPLICATION_PASSWORD.replace(/\s+/g, '')}`).toString('base64');
    headers['Authorization'] = `Basic ${credentials}`;
  }

  if (CF_BRIDGE_SECRET) {
    headers['x-bridge-secret'] = CF_BRIDGE_SECRET;
  }

  return headers;
}

/**
 * Invoca una habilidad registrada en la WordPress Abilities API.
 */
async function callWpAbility(abilityName, input = null, method = 'POST') {
  const url = `${WP_SITE_URL}/wp-json/wp-abilities/v1/abilities/${abilityName}/run`;
  const headers = getAuthHeaders();

  const options = {
    method: method.toUpperCase(),
    headers: { ...headers },
  };

  if (options.method === 'POST' || options.method === 'PUT' || options.method === 'PATCH') {
    options.headers['Content-Type'] = 'application/json';
    options.body = JSON.stringify({ input: input || {} });
  }

  const response = await fetch(url, options);
  const text = await response.text();

  let data;
  try {
    data = JSON.parse(text);
  } catch {
    data = { raw: text };
  }

  if (!response.ok) {
    const errorMsg = data.message || data.code || `Error HTTP ${response.status}`;
    throw new Error(`WordPress Ability Error (${abilityName}): ${errorMsg}`);
  }

  return data;
}

// Inicializar Servidor MCP
const server = new McpServer({
  name: 'wpagent-gb-bridge',
  version: '1.0.6',
});

// Herramienta 1: Crear Borrador con GenerateBlocks
server.tool(
  'wpagent_create_draft_post',
  'Crea una entrada o página en WordPress exclusivamente en estado BORRADOR (draft) utilizando macro-plantillas y arquitectura atómica de GenerateBlocks 2.4.x.',
  {
    title: z.string().describe('Título de la entrada o página'),
    slug: z.string().optional().describe('Slug amigable para la URL (opcional)'),
    post_type: z.enum(['post', 'page']).default('post').describe('Tipo de contenido: post o page'),
    category_ids: z.array(z.number()).optional().describe('IDs de categorías de WordPress asociadas'),
    sections: z.array(
      z.object({
        layout: z.enum(['standard', 'hero', 'card_grid', 'cta_box']).describe('Estructura visual de GenerateBlocks a aplicar'),
        content_markdown: z.string().describe('Contenido en Markdown semántico limpio de la sección'),
      })
    ).describe('Lista de secciones estructuradas que componen el contenido'),
  },
  async (args) => {
    try {
      const result = await callWpAbility('wpagent/create-draft-post', args, 'POST');
      return {
        content: [
          {
            type: 'text',
            text: JSON.stringify(result, null, 2),
          },
        ],
      };
    } catch (err) {
      return {
        isError: true,
        content: [{ type: 'text', text: `Error al crear borrador: ${err.message}` }],
      };
    }
  }
);

// Herramienta 2: Modificar Bloque Quirúrgicamente en Borrador
server.tool(
  'wpagent_patch_post_content',
  'Modifica atómicamente un bloque específico de una entrada existente mediante su índice posicional (ahorro extremo de tokens por deltas).',
  {
    post_id: z.number().describe('ID de la entrada o página en WordPress'),
    block_index: z.number().describe('Índice posicional del bloque a reemplazar (0-indexed)'),
    new_markdown: z.string().describe('Nuevo contenido semántico en Markdown para el bloque'),
  },
  async (args) => {
    try {
      const result = await callWpAbility('wpagent/patch-post-content', args, 'POST');
      return {
        content: [
          {
            type: 'text',
            text: JSON.stringify(result, null, 2),
          },
        ],
      };
    } catch (err) {
      return {
        isError: true,
        content: [{ type: 'text', text: `Error al parchear bloque: ${err.message}` }],
      };
    }
  }
);

// Herramienta 3: Inspeccionar Árbol de Bloques de un Post
server.tool(
  'wpagent_get_post_block_tree',
  'Devuelve un árbol estructurado y resumido de los bloques que componen una entrada (índices, tipo de bloque y extracto).',
  {
    post_id: z.number().describe('ID de la entrada o página a inspeccionar'),
  },
  async (args) => {
    try {
      const result = await callWpAbility('wpagent/get-post-block-tree', args, 'GET');
      return {
        content: [
          {
            type: 'text',
            text: JSON.stringify(result, null, 2),
          },
        ],
      };
    } catch (err) {
      return {
        isError: true,
        content: [{ type: 'text', text: `Error al obtener árbol de bloques: ${err.message}` }],
      };
    }
  }
);

// Herramienta 4: Información del Sitio WordPress
server.tool(
  'wp_get_site_info',
  'Obtiene la información técnica general y metadatos del sitio WordPress en producción.',
  {},
  async () => {
    try {
      const result = await callWpAbility('core/get-site-info', null, 'GET');
      return {
        content: [
          {
            type: 'text',
            text: JSON.stringify(result, null, 2),
          },
        ],
      };
    } catch (err) {
      return {
        isError: true,
        content: [{ type: 'text', text: `Error al consultar info del sitio: ${err.message}` }],
      };
    }
  }
);

// Herramienta 5: Listar Habilidades Activas
server.tool(
  'wp_list_abilities',
  'Lista todas las habilidades registradas y disponibles en la WordPress Abilities API.',
  {},
  async () => {
    try {
      const url = `${WP_SITE_URL}/wp-json/wp-abilities/v1/abilities`;
      const response = await fetch(url, { headers: getAuthHeaders() });
      const data = await response.json();
      return {
        content: [
          {
            type: 'text',
            text: JSON.stringify(data, null, 2),
          },
        ],
      };
    } catch (err) {
      return {
        isError: true,
        content: [{ type: 'text', text: `Error al listar habilidades: ${err.message}` }],
      };
    }
  }
);

// Herramienta 6: Ejecutar Cualquier Habilidad Genérica
server.tool(
  'wp_run_ability',
  'Ejecuta cualquier habilidad disponible en la WordPress Abilities API proporcionando su nombre y parámetros de entrada.',
  {
    ability_name: z.string().describe('Nombre cualificado de la habilidad (ej. core/get-site-info, ai/summarization)'),
    input: z.any().optional().describe('Parámetros de entrada para la habilidad'),
    method: z.enum(['GET', 'POST']).default('POST').describe('Método HTTP a utilizar (GET para lectura, POST para acción)'),
  },
  async ({ ability_name, input, method }) => {
    try {
      const result = await callWpAbility(ability_name, input, method);
      return {
        content: [
          {
            type: 'text',
            text: JSON.stringify(result, null, 2),
          },
        ],
      };
    } catch (err) {
      return {
        isError: true,
        content: [{ type: 'text', text: `Error ejecutando ${ability_name}: ${err.message}` }],
      };
    }
  }
);

async function main() {
  const transport = new StdioServerTransport();
  await server.connect(transport);
  console.error('[wpagent-gb-bridge] Servidor MCP conectado exitosamente vía stdio');
}

main().catch((err) => {
  console.error('[wpagent-gb-bridge] Error fatal en servidor MCP:', err);
  process.exit(1);
});
