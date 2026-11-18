# LLMs.txt Generator — Plugin WordPress (v3.2.0)

Plugin de lead magnet. El visitante deja sus datos y las URLs de su sitio. El plugin genera un `llms.txt` con **OpenAI `gpt-5-mini` + búsqueda web** (la IA visita las URLs reales) y se lo envía por email como adjunto `llms.txt`.

## Instalación

1. Plugins → Añadir nuevo → Subir plugin → `llms-txt-generator.zip` → Activar.
2. **Ajustes → LLMs.txt Generator**: API key de OpenAI, tu email de aviso y nombre/email del remitente (mejor un email de tu dominio).
3. Pulsa **"Probar conexión con OpenAI"** en la sección Diagnóstico.
4. Pon el shortcode `[llmstxt_generator]` en una página.

## Cómo funciona

1. El formulario envía los datos por AJAX y se guarda el lead (estado *Pendiente*).
2. Se encola un trabajo de WP-Cron y se dispara al momento.
3. El trabajo llama a la Responses API (`/v1/responses`, herramienta `web_search`, `reasoning.effort = low`), genera el archivo y lo envía por email.
4. El lead queda *Enviado* o *Error*, con el motivo visible en **LLMs.txt Leads**. Ahí también puedes pulsar **"Reprocesar"**, que lo hace al momento sin depender de WP-Cron.

## Diagnóstico (Ajustes → LLMs.txt Generator)

Muestra el estado de la base de datos (tabla y columnas), la API key, el modelo, la última prueba de conexión, si WP-Cron está desactivado, los trabajos atrasados y los leads con error. Botones: **Reparar base de datos**, **Probar conexión con OpenAI** y **Limpiar límite de intentos**.

## Notas

- Límite: 5 envíos exitosos por IP y hora. Los administradores conectados no tienen límite.
- El modelo se cambia en Ajustes (campo "Modelo de OpenAI") o con el filtro `llmstxt_openai_model`. El cuerpo de la petición se puede ajustar con el filtro `llmstxt_openai_payload`.
- Si los emails no llegan, instala **WP Mail SMTP**. El motivo exacto aparece en la columna "Detalle" del lead.
- Si tu hosting tiene `DISABLE_WP_CRON`, configura un cron del sistema que llame a `https://tusitio.com/wp-cron.php` cada 1–5 minutos.
- Los errores también se escriben en `wp-content/debug.log` (con `WP_DEBUG_LOG` activo), con el prefijo `[LLMs.txt Generator]`.

## Personalizar

- Texto del CTA: busca `llmstxt-cta-box` en `llms-txt-generator.php`. El enlace se configura en Ajustes.
- Colores: en `assets/style.css`, cambia `#3b5bdb`.
