# App Android — APR/PT

App Android **nativo** (WebView em tela cheia) que abre `http://177.125.60.123:8089/`.
As telas continuam vindo do servidor — mudanças na web chegam automaticamente, sem
reinstalar o app. Diferente do TWA que existia antes, **não mostra a barra do navegador
nem depende do Chrome**.

## Gerar o APK

Pré-requisitos: JDK 17 e Android SDK em `/opt/android-sdk` (veja `local.properties`).

```bash
export ANDROID_HOME=/opt/android-sdk
./gradlew assembleRelease
```

Resultado:

```
app/build/outputs/apk/release/app-release.apk
```

## Publicar

```bash
cp app/build/outputs/apk/release/app-release.apk ../html/apk/app.apk
```

Depois atualize `../html/apk/versao.json` (versão, `versionCode` e tamanho). O app
compara esse arquivo ao abrir e mostra o aviso de atualização quando a versão muda.
A página de download fica em `http://177.125.60.123:8089/apk/`.

Ao publicar uma versão nova, incremente **os dois** em `app/build.gradle`:
`versionCode` (inteiro, sempre maior) e `versionName` (ex.: `1.2.0`).

## Assinatura

- Keystore: `android.keystore` — alias `android`, senha `AprPT@2026` (**não versionada**).
- As senhas estão em `app/build.gradle` (signingConfigs).
- **Sem essa chave o Android recusa atualizar o app instalado** (seria preciso
  desinstalar antes). Mantenha um backup da keystore junto com o backup do servidor.

## Estrutura

| Arquivo | O que faz |
|---|---|
| `app/src/main/java/br/net/nwt/aprpt/MainActivity.java` | WebView e recursos: botão voltar, download de PDF/APK (com cookie de sessão), links externos, tela sem conexão, ponte `window.AprptApp` |
| `app/src/main/AndroidManifest.xml` | Permissões (`INTERNET`, instalar APK) e activity principal |
| `app/build.gradle` | Versão, chave de assinatura e configuração do build |
| `app/src/main/res/` | Ícone do app, cores e tema (abertura azul da marca) |

## Observações

- O servidor é HTTP (IP:porta): por isso `usesCleartextTraffic="true"`. O service
  worker do PWA não roda dentro do app (HTTP não é contexto seguro), então o app
  precisa de rede para carregar as telas; a tela "Sem conexão" orienta o usuário.
- O app baixa PDFs em `Download/aprpt-documento-<id>.pdf` e as atualizações em
  `Download/aprpt-<versão>.apk`, abrindo em seguida o app correspondente
  (visualizador de PDF ou instalador).
