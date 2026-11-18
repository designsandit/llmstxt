# Changelog — LLMs.txt Generator

## 3.4.0

**Actualizaciones automáticas desde GitHub** (`includes/updater.php`)
- WordPress detecta las versiones nuevas publicadas en la rama `main` del repositorio (`designsandit/llmstxt`) y muestra "Hay una nueva versión", igual que con los plugins de wordpress.org. El paquete es `dist/llms-txt-generator.zip`.
- Compatible con el interruptor nativo **"Activar actualizaciones automáticas"** de la página de Plugins.
- "Ver detalles" muestra este CHANGELOG.
- Ajustes: repositorio, rama y **token de GitHub** (necesario si el repo es privado; también se puede definir `LLMSTXT_GITHUB_TOKEN` en `wp-config.php`).
- Diagnóstico: versión disponible, errores de conexión con GitHub (token caducado, sin acceso…) y estado de las actualizaciones automáticas. Botón **"Buscar actualizaciones"**.

**Leads: CSV y privacidad** (`includes/privacy.php`)
- **Exportar todos a CSV** desde *LLMs.txt Leads* (UTF-8 con BOM y separador `;`, para que abra bien en Excel en español; se protege contra inyección de fórmulas).
- **Borrar** un lead, o todos los de más de N días.
- **Borrado automático** de leads con más de N días (Ajustes → Privacidad de los leads; `0` = nunca). Se revisa una vez al día.
- Integración con **Herramientas → Exportar/Borrar datos personales** de WordPress (solicitudes RGPD por email) y texto sugerido para la política de privacidad.

**Otros**
- La página de leads muestra el total real (antes mostraba como máximo 500).
- Puntos de extensión internos: `llmstxt_settings_fields`, `llmstxt_diagnostics_rows`, `llmstxt_diagnostics_buttons`, `llmstxt_leads_toolbar` y `llmstxt_lead_row_actions`.

## 3.3.1
- Diagnóstico → **Email: último envío**: registra por dónde salió realmente el último correo (SMTP con host y puerto, o `mail()`/sendmail del servidor), a qué dirección se envió y si hubo autenticación.
- Diagnóstico → **Email: plugins que intervienen**: lista qué plugins o temas modifican el envío. En la 3.3.0 cualquier plugin enganchado contaba como "SMTP", lo que podía dar un ✓ engañoso.
- El aviso del email de prueba dice a qué dirección se envió y por qué vía.
- Si se envía con `mail()` del servidor, el Return-Path se alinea con el remitente para mejorar SPF/DMARC.

## 3.3.0
Para cuando un lead aparece como "Enviado" pero el correo no llega.

- El llms.txt generado se guarda en la base de datos (nueva columna `llms_content`) y se puede **descargar** desde *LLMs.txt Leads* con el botón "Descargar llms.txt", aunque el email no haya llegado.
- Diagnóstico → **Email**: muestra el remitente real. Avisa si es una cuenta gratuita (@gmail, @hotmail…), que Gmail rechaza por DMARC al enviarse desde otro servidor, o si su dominio no coincide con el del sitio. También dice si hay un plugin SMTP o si WordPress usa `mail()` del servidor.
- Botón **"Enviarme un email de prueba"**: envía un correo con un llms.txt de prueba adjunto al email del administrador conectado.
- El aviso de "Reprocesar" deja claro que "Enviado" significa que WordPress entregó el correo al servidor, no que haya llegado a la bandeja.

## 3.2.0
Revisión completa del plugin en una sola pasada.

**Correcciones**
- Modelo restaurado a `gpt-5-mini` (en la v3.1.0 se había cambiado por error a `gpt-4o-mini`). Ahora se configura en Ajustes.
- Herramienta de búsqueda actualizada a `web_search` (antes `web_search_preview`), con `reasoning.effort = low` para modelos GPT-5/o-series. Así es más rápido y hay menos riesgo de timeout.
- Migración de base de datos reescrita sin `dbDelta`: crea la tabla y añade **todas** las columnas que falten a partir de una sola definición del esquema.
- Si el guardado falla por una columna que falta, se repara la tabla y se reintenta automáticamente.
- El límite de intentos solo cuenta los envíos guardados. Los errores ya no gastan cupo y los administradores no tienen límite.
- Un nonce caducado (por caché de página) muestra "recarga la página" en lugar de "error de conexión".
- El adjunto se llama `llms.txt` (antes `llms-XXXX.txt`).
- Se comprueba si `wp_mail()` falló y se guarda el motivo.
- WP-Cron se dispara con `spawn_cron()`, el mecanismo oficial, en lugar de una llamada manual a `wp-cron.php`.
- El campo "nombre" se valida en el servidor.

**Nuevo**
- Estados de lead: Pendiente / Procesando / Enviado / **Error**, con el motivo visible y un contador de intentos.
- Botón **Reprocesar** por lead: lo procesa al momento, sin depender de WP-Cron.
- Diagnóstico ampliado: columnas faltantes, prueba de conexión con OpenAI, aviso de `DISABLE_WP_CRON`, trabajos atrasados y leads con error.
- Acciones de administración protegidas con nonce (antes eran vulnerables a CSRF).
- Ajustes nuevos: modelo de OpenAI y enlace del botón CTA.
- Filtros `llmstxt_openai_model` y `llmstxt_openai_payload`.
- Los datos del lead (NAP incluido) se guardan en la base de datos, lo que permite reprocesar.

**Compatibilidad**
- Los trabajos que quedaron en cola con versiones ≤ 3.1 se siguen procesando.

## 3.1.0 y anteriores
Correcciones hechas durante la depuración: tabla no creada, ZIP mal empaquetado, columnas `ip_address`/`status` faltantes, panel de diagnóstico y botón para limpiar el límite de intentos. Detalle en `HANDOFF-llms-txt-generator.md`.
