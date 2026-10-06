#!/bin/sh
# Copia o "casco" do sistema (HTML/CSS/JS) para dentro do APK.
#
# É isso que permite abrir o app SEM INTERNET: quando o aparelho está sem rede,
# o app carrega esta cópia do pacote em vez de buscar as telas no servidor.
# Quando há rede, o app usa normalmente a versão do servidor (sempre atualizada).
#
# Uso: sh android/tools/sync-web.sh   (o build do APK já chama este script)
set -e

TOOLS="$(cd "$(dirname "$0")" && pwd)"
RAIZ="$(cd "$TOOLS/../.." && pwd)"
DEST="$RAIZ/android/app/src/main/assets/site"
FONTE="$RAIZ/html"

[ -d "$FONTE" ] || { echo "ERRO: não encontrei $FONTE" >&2; exit 1; }

rm -rf "$DEST"
mkdir -p "$DEST/assets/css" "$DEST/assets/js" "$DEST/assets/img"

cp "$FONTE/assets/css/style.css" "$DEST/assets/css/"
cp "$FONTE/assets/js/offline.js" "$FONTE/assets/js/api.js" "$FONTE/assets/js/app.js" "$DEST/assets/js/"
cp "$FONTE/assets/img/"*.png "$DEST/assets/img/"
cp "$FONTE/manifest.webmanifest" "$DEST/"

# Página inicial já renderizada (com os ?v=… de cada arquivo)
if curl -fsS --max-time 15 "http://127.0.0.1:8089/" -o "$DEST/index.html.tmp"; then
    mv "$DEST/index.html.tmp" "$DEST/index.html"
else
    rm -f "$DEST/index.html.tmp"
    echo "ERRO: o servidor local (127.0.0.1:8089) não respondeu — sem ele o app não abre offline." >&2
    exit 1
fi

echo "Casco do app atualizado em $DEST:"
find "$DEST" -type f | sed "s#$DEST/##" | sort
