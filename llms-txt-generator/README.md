# LLMs.txt Generator — Plugin WordPress (v3.5.0)

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

Muestra el estado de la base de datos (tabla y columnas), la API key, el modelo, la última prueba de conexión, si WP-Cron está desactivado, los trabajos atrasados y los leads con error. También muestra el estado del envío de email, de las actualizaciones y de la retención de leads. Botones: **Reparar base de datos**, **Probar conexión con OpenAI**, **Enviarme un email de prueba**, **Limpiar límite de intentos** y **Buscar actualizaciones**.

## Actualizaciones automáticas (desde GitHub)

El plugin se actualiza desde el repositorio `designsandit/llmstxt`, rama `main`.

1. **Si el repositorio es privado**, crea un token en https://github.com/settings/personal-access-tokens/new:
   - *Repository access* → **Only select repositories** → `llmstxt`.
   - *Permissions* → *Repository permissions* → **Contents: Read-only**.
   - Pégalo en Ajustes → LLMs.txt Generator → **Token de GitHub**. También puedes definirlo en `wp-config.php` con `define( 'LLMSTXT_GITHUB_TOKEN', '...' );`.
   - El token caduca en la fecha que elijas. Cuando caduque, el Diagnóstico mostrará un error 401: crea uno nuevo y pégalo.
2. Pulsa **"Buscar actualizaciones"** en el Diagnóstico para comprobar la conexión.
3. En **Plugins**, pulsa **"Activar actualizaciones automáticas"** en LLMs.txt Generator para que se instalen solas. Si no, aparecerá "Actualizar ahora".

Se publica una versión nueva cuando llega a `main` un commit que sube la versión del plugin y regenera `dist/llms-txt-generator.zip`.

## Leads y privacidad

- *LLMs.txt Leads* → **Exportar todos a CSV**, **Borrar** un lead o **Borrar leads con más de N días**.
- Ajustes → **Borrar leads automáticamente después de N días** (`0` = nunca).
- Las solicitudes de acceso o borrado de datos (RGPD) se atienden desde **Herramientas → Exportar datos personales / Borrar datos personales**, buscando por email.

## Notas

- Límite: 5 envíos exitosos por IP y hora. Los administradores conectados no tienen límite.
- El modelo se cambia en Ajustes (campo "Modelo de OpenAI") o con el filtro `llmstxt_openai_model`. El cuerpo de la petición se puede ajustar con el filtro `llmstxt_openai_payload`.
- Si los emails no llegan aunque el lead diga "Enviado", el servidor no los está entregando. Usa "Enviarme un email de prueba", revisa Diagnóstico → Email e instala **WP Mail SMTP** con un remitente de tu dominio. Mientras tanto, cada lead tiene el botón "Descargar llms.txt".
- Si tu hosting tiene `DISABLE_WP_CRON`, configura un cron del sistema que llame a `https://tusitio.com/wp-cron.php` cada 1–5 minutos.
- Los errores también se escriben en `wp-content/debug.log` (con `WP_DEBUG_LOG` activo), con el prefijo `[LLMs.txt Generator]`.

## Diseño del formulario

Es un asistente en 3 pasos (**Tú → Tu negocio → Tus páginas**) con el estilo de designsandit.com. Usa las tipografías del sitio (Exo 2, Montserrat, DM Sans); si un sitio no las tiene, usa las del sistema. Los colores se cambian redefiniendo las variables `--llms-*` sobre `#llmstxt-app` en el CSS del tema.

## Personalizar

- Textos del formulario: en el shortcode `llmstxt_generator` de `llms-txt-generator.php`. El enlace del botón final se configura en Ajustes.
- Colores: variables `--llms-*` al principio de `assets/style.css`.
