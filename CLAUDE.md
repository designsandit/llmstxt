# Convenciones del proyecto (léelas antes de cambiar nada)

Plugin de WordPress **LLMs.txt Generator** de Designs and IT. El contexto completo está en `HANDOFF-llms-txt-generator.md` y los cambios por versión en `CHANGELOG.md`.

## Autoría (obligatorio en todos los plugins de Designs and IT)

La cabecera de cada plugin debe decir:

```
 * Author:      Designs and IT
 * Author URI:  https://www.designsandit.com/?utm_source=media-seo&utm_medium=plugin&utm_campaign=lista-plugins
```

No uses nunca "Tu Agencia SEO" ni otros textos de ejemplo. En este plugin, el nombre y la URL están en las constantes `LLMSTXT_AUTHOR` y `LLMSTXT_AUTHOR_URI`. Úsalas siempre que el autor aparezca en otro sitio (ventana "Ver detalles", remitente por defecto…).

## Cómo se trabaja

- Antes de proponer cambios, revisa el plugin completo (`llms-txt-generator/`). Haz la revisión entera de una vez, no por partes.
- El modelo de OpenAI es `gpt-5-mini`, que existe. No lo cambies sin que te lo pidan.
- En cada entrega:
  - Sube la versión en la cabecera **y** en `LLMSTXT_VERSION`.
  - Añade la entrada en `CHANGELOG.md`.
  - Pasa `php -l` a todos los `.php`.
  - Regenera el ZIP con `./build-zip.sh` y haz commit de `dist/llms-txt-generator.zip`.
- Las actualizaciones llegan a los sitios cuando el cambio entra en `main`: el actualizador de `includes/updater.php` lee la versión de `main`. El repo es público y no usa token.
- Diseño del formulario: sigue el estilo de designsandit.com.
  - Exo 2 cursiva en títulos, Montserrat en texto y DM Sans en botones.
  - Naranja `#FF6B35`, verde `#43A047`, azul `#0077B6`/`#023E8A`, pizarra `#2C3E50` y fondo crema.
  - Tarjetas y botones con sombra sólida desplazada.
  - Las variables CSS están en `assets/style.css`.
