# Traspaso: Plugin WordPress "LLMs.txt Generator"

Documento para continuar el trabajo en una charla nueva de Claude Code.
Versión actual: **3.3.1** · Repositorio: `designsandit/llmstxt` · Rama de trabajo: `claude/fix-llms-txt-plugin-87rrD`

---

## 1. Qué es el plugin

Plugin de WordPress que funciona como *lead magnet*. El visitante deja nombre, email, dominio, NAP y hasta 20 URLs mediante el shortcode `[llmstxt_generator]`. El plugin:

1. Valida los datos (AJAX `llmstxt_generate`, nonce `llmstxt_nonce`) y guarda el lead en `{prefix}llmstxt_leads` con estado `pending`.
2. Encola el evento WP-Cron `llmstxt_process_job` con `[lead_id]` y llama a `spawn_cron()`.
3. `llmstxt_run_job()` llama a la OpenAI Responses API (`/v1/responses`, modelo `gpt-5-mini`, herramienta `web_search`, `reasoning.effort: low`), genera el llms.txt y lo envía por `wp_mail` como adjunto `llms.txt`.
4. El lead pasa a `done` o a `error`, con el motivo en `error_message`. Desde *LLMs.txt Leads* → **Reprocesar** se ejecuta al momento, sin WP-Cron.

**Repositorio:**
```
llms-txt-generator/            código fuente (llms-txt-generator.php, assets/app.js, assets/style.css, README.md)
dist/llms-txt-generator.zip    ZIP para WordPress (generado con ./build-zip.sh)
CHANGELOG.md, README.md, HANDOFF-llms-txt-generator.md
```

**Esquema:** definido en `llmstxt_schema_columns()`. Es la única fuente de verdad y se usa en la creación, la migración y el diagnóstico. Columnas: `id, email, name, website, nap, urls_submitted, ip_address, status, error_message, attempts, created_at, updated_at`. El sitio real usa el prefijo `hRCkkY_`.

**Opciones:** `llmstxt_openai_key`, `llmstxt_model`, `llmstxt_notify_email`, `llmstxt_from_name`, `llmstxt_from_email`, `llmstxt_cta_url`, `llmstxt_db_version`, `llmstxt_rl_gen` (generación del rate limit).

**Regla de versiones:** cualquier cambio sube la versión en la cabecera **y** en `LLMSTXT_VERSION`, porque `plugins_loaded` ejecuta `llmstxt_install_db()` cuando la versión cambia. Hay que añadir una entrada en `CHANGELOG.md` y regenerar el ZIP con `./build-zip.sh`.

---

## 2. Historia (resumen)

| Versión | Problema | Causa | Solución |
|---|---|---|---|
| ≤3.0.0 | Salía "Solicitud recibida" pero no llegaba email ni se guardaba el lead | La tabla no existía y el guardado fallaba sin avisar | Creación de la tabla en `plugins_loaded` y control de errores |
| 3.0.0 | "Cabecera no válida" y WP-Admin roto | ZIP con carpetas anidadas | ZIP con una sola carpeta raíz (ahora `build-zip.sh`) |
| 3.0.1–3.0.4 | `Unknown column 'ip_address'` y luego `status` | Tabla antigua y `dbDelta` no añadía columnas | `ALTER TABLE` explícito |
| 3.0.3 | Límite de intentos agotado en las pruebas | Se contaba cada intento, incluso los fallidos | Botón de reinicio |
| 3.1.0 | — | Por error se volvió a `gpt-4o-mini` | — |
| **3.2.0** | Revisión completa | — | Ver `CHANGELOG.md` |

En noviembre de 2026 el repositorio se borró y se recreó. La v3.1.0 se reconstruyó a partir de la conversación (commit "Reconstruir plugin v3.1.0") y sobre ella se hizo la v3.2.0.

---

## 3. Estado actual

- ✅ **Funciona de punta a punta (v3.3.1, 17-nov-2026):** al reprocesar el lead #1 se generó el llms.txt y el email llegó. La entrega se resolvió configurando **WP Mail SMTP by WPForms** con "Otro SMTP": `smtp.gmail.com:465` SSL, usuario `info@designsandit.com` con contraseña de aplicación. El correo del dominio está en Google Workspace. Con `mail()` del servidor, Google descartaba los correos.
- Pendiente opcional: endurecer la política DMARC (hoy `p=none`).

- **Prueba real (v3.2.0):** el flujo funcionó (lead #1, estado Enviado, 3 intentos), pero **el email no llegó a Gmail**. `wp_mail()` devolvió true, así que el fallo está en la entrega del servidor: falta SMTP o el remitente/DMARC no coincide. La v3.3.0 añade el diagnóstico de email, el email de prueba y la descarga del llms.txt desde el admin. Siguiente paso: configurar WP Mail SMTP con un remitente del dominio.
- **Prueba v3.3.0:** el Diagnóstico mostró remitente `info@designsandit.com` ✓ y "un plugin modifica el envío", pero el email de prueba tampoco llegó. La v3.3.1 muestra la vía real del último envío (SMTP host:puerto o mail()) y qué plugins intervienen.

- La v3.2.0 pasa `php -l` y las pruebas de funciones puras. **No se ha probado en WordPress real** (en el entorno de desarrollo no hay WordPress ni MySQL).
- Hay que confirmar en el sitio real: que la migración añada `nap`, `error_message`, `attempts` y `updated_at`; que "Probar conexión con OpenAI" funcione; y el flujo completo formulario → email.

---

## 4. Pendientes

1. **Probar la v3.2.0 en el sitio** y corregir lo que aparezca en Diagnóstico o en la columna "Detalle" de los leads.
2. **Actualizaciones automáticas desde GitHub** (pendiente de que el usuario lo confirme): que WordPress muestre "nueva versión disponible" sin subir el ZIP a mano. El repo es privado, así que haría falta un token de solo lectura o publicar las releases en otro lugar. Opción habitual: la librería *Plugin Update Checker* (YahnisElsts).
3. Opcional: borrar los leads antiguos / cumplir con privacidad (los datos personales se guardan sin caducidad).
4. Opcional: exportar los leads a CSV.
5. Opcional: quitar del CSS las clases que ya no se usan (`#llmstxt-step-loading`, `.llmstxt-result-box`, `.llmstxt-actions`).

---

## 5. Instalar y probar en WordPress

1. Descarga `dist/llms-txt-generator.zip` desde GitHub (abre el archivo → *Download raw file*).
2. Plugins → Añadir nuevo → Subir plugin → *Reemplazar el actual por el subido* → Activar.
3. Ajustes → LLMs.txt Generator → **Diagnóstico**: la versión del plugin debe ser igual a la de la BD y deben estar todas las columnas. Si no, pulsa "Reparar base de datos".
4. Pulsa **Probar conexión con OpenAI**.
5. Envía el formulario desde la página con el shortcode (como administrador conectado no hay límite de intentos).
6. Revisa *LLMs.txt Leads*: el estado y el detalle del error. Si queda en "Pendiente" más de 5 minutos, WP-Cron no funciona: pulsa **Reprocesar** y revisa el aviso en Diagnóstico.

---

## 6. Prompt para pegar en una charla nueva de Claude Code

```
Estoy trabajando en mi plugin de WordPress "LLMs.txt Generator" (repo designsandit/llmstxt).
Lee primero HANDOFF-llms-txt-generator.md y CHANGELOG.md: tienen todo el contexto.

Reglas:
1. Antes de cambiar nada, lee el plugin completo (llms-txt-generator/) y dame la lista completa de
   cambios. Revisión integral, no por partes.
2. El modelo es gpt-5-mini (existe). No lo cambies sin que te lo pida.
3. En cada entrega: sube la versión (cabecera + LLMSTXT_VERSION), añade la entrada en CHANGELOG.md,
   pasa `php -l`, regenera el ZIP con ./build-zip.sh y haz commit de dist/llms-txt-generator.zip.
4. Resume al final qué debo probar en WordPress.

Tarea: <describe aquí lo que quieres hacer o el error que ves en Diagnóstico>
```
