#!/usr/bin/env bash
# Genera dist/llms-txt-generator.zip con UNA sola carpeta raíz "llms-txt-generator/",
# que es la estructura que WordPress espera al subir un plugin.
set -euo pipefail
cd "$(dirname "$0")"

php -l llms-txt-generator/llms-txt-generator.php >/dev/null

mkdir -p dist
rm -f dist/llms-txt-generator.zip
zip -rqX dist/llms-txt-generator.zip llms-txt-generator -x '*.DS_Store'

echo "Creado dist/llms-txt-generator.zip:"
unzip -l dist/llms-txt-generator.zip
