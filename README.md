# llmstxt

Plugin de WordPress **LLMs.txt Generator**: lead magnet que genera archivos `llms.txt` con OpenAI (`gpt-5-mini` + búsqueda web) y los envía por email.

## Descargar el plugin para instalarlo

El ZIP listo para subir a WordPress está en **[`dist/llms-txt-generator.zip`](dist/llms-txt-generator.zip)**. En GitHub, abre el archivo y pulsa **Download raw file**.

Luego, en WordPress: Plugins → Añadir nuevo → Subir plugin → selecciona el ZIP → *Reemplazar el actual por el subido* → Activar.

## Estructura del repositorio

```
llms-txt-generator/        ← código fuente del plugin (lo que se edita)
  llms-txt-generator.php
  assets/app.js
  assets/style.css
  README.md                ← documentación del plugin
dist/llms-txt-generator.zip ← ZIP generado, listo para WordPress
build-zip.sh               ← regenera el ZIP con la estructura correcta
CHANGELOG.md               ← cambios por versión
HANDOFF-llms-txt-generator.md ← contexto para continuar en una charla nueva de Claude Code
```

## Hacer cambios

1. Edita los archivos dentro de `llms-txt-generator/`.
2. Sube la versión en la cabecera del plugin **y** en la constante `LLMSTXT_VERSION`. Con eso se ejecuta la migración de la base de datos.
3. Añade una entrada en `CHANGELOG.md`.
4. Ejecuta `./build-zip.sh` y haz commit también de `dist/llms-txt-generator.zip`.
