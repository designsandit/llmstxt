# Traspaso: Plugin WordPress "LLMs.txt Generator"

Documento para continuar el trabajo en una charla nueva de Claude Code.
Última versión trabajada: **3.1.0** · Rama: `claude/fix-llms-txt-plugin-87rrD`

---

## 1. Qué es el plugin

Plugin de WordPress que funciona como *lead magnet*. Genera un archivo `llms.txt` para el sitio del visitante y se lo manda por email.

**Flujo:**
1. Shortcode `[llmstxt_generator]` → formulario con nombre, email, dominio, NAP (nombre/dirección/teléfono) y URLs (máx. 20).
2. `assets/app.js` hace un POST por AJAX a `admin-ajax.php` (`action=llmstxt_generate`, nonce `llmstxt_nonce`).
3. `llmstxt_ajax_generate()` aplica el rate limit (5/hora por IP, transients `llmstxt_rl_*`), valida los datos, guarda el lead en la tabla `{prefix}llmstxt_leads` con `status='pending'`, avisa a la agencia por email, programa el evento WP-Cron `llmstxt_process_job` y lanza `wp-cron.php` sin esperar respuesta.
4. `llmstxt_process_job()` llama a la **OpenAI Responses API** (`POST https://api.openai.com/v1/responses`, herramienta `web_search_preview`, timeout 180 s), extrae el texto con `llmstxt_extract_content()`, envía el `.txt` como adjunto con `wp_mail` y marca el lead como `status='done'`.

**Archivos:**
```
llms-txt-generator/
├── llms-txt-generator.php   ← todo el PHP (activación, admin, shortcode, AJAX, cron, emails)
├── assets/app.js            ← formulario (jQuery)
├── assets/style.css
└── README.md
```

**Opciones en `wp_options`:** `llmstxt_openai_key`, `llmstxt_notify_email`, `llmstxt_from_name`, `llmstxt_from_email`, `llmstxt_db_version`.

**Tabla esperada:** `id, email, name, website, urls_submitted, ip_address, status, created_at`. En el sitio real el prefijo es `hRCkkY_`, así que la tabla es `hRCkkY_llmstxt_leads`.

---

## 2. Qué se hizo en la sesión anterior (cronológico)

| Versión | Síntoma reportado | Causa raíz | Cambio |
|---|---|---|---|
| — | "No funciona" | Se creyó, por error, que `gpt-5-mini` no existía | Se cambió a `gpt-4o-mini` |
| — | El usuario mostró la documentación oficial de `gpt-5-mini` | `gpt-5-mini` **sí existe** | Se revirtió a `gpt-5-mini` |
| 3.0.0 | Sale "¡Solicitud recibida!", pero no llega email y no hay leads | La tabla no existía (el hook de activación no se ejecutó) y `$wpdb->insert` fallaba sin avisar; aun así el código devolvía éxito | `llmstxt_maybe_create_table()` en `plugins_loaded` (compara `llmstxt_db_version`), control del retorno del insert y `error_log` en cada punto de fallo del job |
| 3.0.0 | "El plugin no tiene una cabecera válida" y WP-Admin roto | ZIP mal empaquetado: quedó como `llms-txt-generator-v31/llms-txt-generator/…` | ZIP nuevo con una sola carpeta raíz `llms-txt-generator/` |
| 3.0.0 | "Error interno al guardar la solicitud" | Pendiente de diagnosticar | Sección **Diagnóstico** en Ajustes → LLMs.txt Generator (tabla, columnas, API key, versión de BD y último error de BD guardado en el transient `llmstxt_last_db_error`) |
| 3.0.1 | `Unknown column 'ip_address'` | La tabla venía de una versión antigua y `dbDelta` no añadió la columna | Subida de versión para forzar la migración (no bastó) |
| 3.0.2–3.0.3 | Mismo error | `dbDelta` no altera tablas existentes de forma fiable | `DESCRIBE` + `ALTER TABLE ADD COLUMN ip_address`; el diagnóstico pasa a listar las columnas reales. **Resultado: `ip_address` se añadió** |
| 3.0.3 | "Has alcanzado el límite de generaciones" | Las pruebas agotaron el cupo de 5 por hora | Botón "Limpiar límite de intentos" en Diagnóstico |
| 3.0.4 | "Error interno al guardar…" | El diagnóstico mostró que **también faltaba `status`** | `ALTER TABLE ADD COLUMN status` |
| 3.1.0 | Revisión general | — | Bucle de migraciones (`ip_address`, `status`) con `?: []` como respaldo; el insert usa `$table`. ⚠️ **Por error, el modelo volvió a `gpt-4o-mini`** |

---

## 3. Estado actual

- **No se ha confirmado** que la v3.1.0 funcione de punta a punta (formulario → lead → email).
- La última lectura del diagnóstico (v3.0.3) mostraba: `id, email, name, website, urls_submitted, ip_address, created_at`, es decir, **sin `status`**. Con la v3.0.4/3.1.0 instalada deberían aparecer las 8 columnas.
- El **código usa `gpt-4o-mini`** y el **README dice `gpt-5-mini`**. Hay que corregir el código (ver P1).
- El repo tiene copias duplicadas: `llms-txt-generator-v3.zip` (estructura plana, **no usar**), `llms-txt-generator.zip` (el bueno) y `plugin_extracted/llms-txt-generator/` (código fuente).

---

## 4. Pendientes y bugs conocidos (por prioridad)

### P1: necesarios para que funcione
1. **Restaurar el modelo** a `'model' => 'gpt-5-mini'` en `llmstxt_process_job()`. Comprobar en la documentación actual de OpenAI si la herramienta se llama `web_search` o `web_search_preview` y si conviene `reasoning: { effort: "low" }`.
2. **Los fallos del job no se ven.** Si OpenAI falla, el lead se queda en "Pendiente" para siempre. Añadir la columna `error_message`, guardar `status='error'` y el mensaje, mostrarlo en *LLMs.txt Leads* y añadir un botón **"Reprocesar"**.
3. **Dependencia de WP-Cron.** Si `DISABLE_WP_CRON` está activo o el hosting bloquea las peticiones a sí mismo (loopback), el job no se ejecuta nunca. Mostrar esto en Diagnóstico, avisar de los eventos `llmstxt_process_job` atascados (`_get_cron_array()`) y documentar el cron del sistema. Otra opción: Action Scheduler.

### P2: robustez y seguridad
4. `llmstxt_rate_ok()` suma el intento **antes** de validar, así que los errores gastan cupo. Contar solo los envíos que se guardan bien y no limitar a los administradores.
5. Las acciones de admin por GET (`?llmstxt_reset_ratelimit=1`, `?llmstxt_create_table=1`) **no tienen nonce** (CSRF). Usar `wp_nonce_url()` + `check_admin_referer()`.
6. El diagnóstico solo comprueba `ip_address`. Debe comprobar **todas** las columnas requeridas y que la migración use una sola lista de columnas.
7. El adjunto llega como `llms-AbC123….txt`. Debería llamarse **`llms.txt`**: crear una carpeta temporal única y dentro el archivo `llms.txt`.
8. Timeout de 180 s con un modelo de razonamiento + búsqueda web: medir el tiempo real. Valorar el background mode de la Responses API o `reasoning.effort` bajo.
9. Comprobar `wp_mail()` (el valor de retorno) y registrar si el envío falla. Recomendar WP Mail SMTP.

### P3: calidad
10. Un plugin de caché de página puede servir un nonce caducado; la respuesta `-1` se ve como "Error de conexión". Mostrar un mensaje claro o refrescar el nonce por AJAX.
11. Sincronizar el README con el código: modelo, versión, flujo real (asíncrono, sin vista previa en pantalla) y costos.
12. Limpiar el repo: dejar solo `llms-txt-generator/` como fuente y un script `build-zip.sh` que genere `llms-txt-generator.zip` con la carpeta raíz correcta. Borrar `llms-txt-generator-v3.zip` y `plugin_extracted/`.
13. Cuando todo funcione, quitar o esconder las herramientas de depuración (último error de BD, etc.).

---

## 5. Instalar y probar en WordPress

1. Por FTP/cPanel, en `wp-content/plugins/`, **borrar** las carpetas viejas: `llms-txt-generator-v3`, `llms-txt-generator-v31`, etc.
2. Plugins → Añadir nuevo → Subir plugin → `llms-txt-generator.zip` → *Reemplazar* si lo pide → Activar.
3. Ajustes → LLMs.txt Generator → **Diagnóstico**: la versión de BD debe coincidir con la del plugin y deben estar las 8 columnas.
4. Pulsar **"Limpiar límite de intentos"**.
5. Enviar el formulario desde la página con el shortcode.
6. Comprobar que el lead aparece en *LLMs.txt Leads* y que llega el email en 1–3 minutos.
7. Si no llega, activar en `wp-config.php` `define('WP_DEBUG', true); define('WP_DEBUG_LOG', true); define('WP_DEBUG_DISPLAY', false);` y buscar líneas `[LLMs.txt Generator]` en `wp-content/debug.log`.

---

## 6. Prompt para pegar en una charla nueva de Claude Code

```
Estoy trabajando en mi plugin de WordPress "LLMs.txt Generator" (genera llms.txt con la
OpenAI Responses API + web search y lo envía por email vía WP-Cron). Lee primero el archivo
HANDOFF-llms-txt-generator.md: tiene todo el contexto de la sesión anterior.

El código está en la rama claude/fix-llms-txt-plugin-87rrD (carpeta
plugin_extracted/llms-txt-generator/ y llms-txt-generator.zip). Si no la encuentras, dímelo
y te subo el ZIP.

Antes de cambiar nada:
1. Lee el archivo PHP completo y los assets, y haz UNA revisión integral (no por partes).
2. Dame la lista de todo lo que vas a cambiar.

Después implementa, en este orden, los pendientes P1 y P2 del HANDOFF (el modelo correcto es
gpt-5-mini, que SÍ existe; comprueba en la documentación de OpenAI el nombre actual de la
herramienta de web search). Sube la versión del plugin para forzar la migración de BD, genera
llms-txt-generator.zip con UNA sola carpeta raíz "llms-txt-generator/" y verifica la sintaxis
con `php -l` antes de entregar. Resume al final qué debo probar en WordPress.
```
