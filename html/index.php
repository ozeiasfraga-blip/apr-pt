<?php
require_once __DIR__ . '/../src/bootstrap.php';

$marca = htmlspecialchars($_ENV['APP_NAME'] ?? 'APR/PT');
$svgMarca = '<svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 3l7 3v5.5c0 4.2-2.9 7.1-7 8.5-4.1-1.4-7-4.3-7-8.5V6z"/><path d="M8.8 12.2l2.3 2.3 4.1-4.4"/></svg>';
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="theme-color" content="#0d3b66">
    <meta name="color-scheme" content="light">
    <title><?= $marca ?></title>
    <link rel="manifest" href="/manifest.webmanifest">
    <link rel="icon" type="image/png" href="/assets/img/favicon.png">
    <link rel="apple-touch-icon" href="/assets/img/apple-touch-icon.png">
    <link rel="stylesheet" href="/assets/css/style.css">
</head>
<body>
    <!-- ── Login ─────────────────────────────────────────── -->
    <div id="view-login" class="login-wrap">
        <section class="card">
            <div class="login-empresa">
                <img src="/assets/img/nwt-logo.png" alt="NWT Telecom">
            </div>
            <div class="login-head">
                <span class="brand-mark"><?= $svgMarca ?></span>
                <div>
                    <h1><?= $marca ?></h1>
                    <p>Análise Preliminar de Risco e Permissão de Trabalho</p>
                </div>
            </div>
            <p class="login-sub">Entre com o seu e-mail corporativo para acessar as ordens de serviço e emitir documentos.</p>
            <form id="form-login">
                <label>E-mail<input type="email" name="email" required autocomplete="username" placeholder="voce@nwt.net.br"></label>
                <label>Senha<input type="password" name="password" required autocomplete="current-password" placeholder="••••••••"></label>
                <button type="submit">Entrar</button>
            </form>
            <p id="login-error" class="error"></p>
            <p class="login-foot">NWT Telecom · Segurança do Trabalho · NR-10 e NR-35</p>
        </section>
    </div>

    <!-- ── Aplicação ─────────────────────────────────────── -->
    <div id="view-app" class="hidden">
        <header class="topbar">
            <a class="brand" href="#os">
                <span class="brand-mark"><?= $svgMarca ?></span>
                <span><?= $marca ?></span>
                <img class="brand-nwt" src="/assets/img/nwt-logo.png" alt="NWT Telecom">
            </a>
            <nav id="nav" class="nav">
                <a href="#os" data-view="os">Ordens de Serviço</a>
                <a href="#documentos" data-view="documentos">APR/PT</a>
                <a href="#usuarios" data-view="usuarios" data-roles="supervisor,admin">Usuários</a>
                <a href="#modelo" data-view="modelo" data-roles="supervisor,admin">Modelo APR/PT</a>
            </nav>
            <div class="user-box">
                <span class="avatar" id="user-avatar" aria-hidden="true"></span>
                <span id="user-info"></span>
                <button id="btn-logout" class="secondary small">Sair</button>
            </div>
        </header>

        <main id="content" class="content"></main>
    </div>

    <!-- ── Modal genérico ────────────────────────────────── -->
    <div id="modal" class="modal hidden">
        <div class="modal-box">
            <header>
                <h2 id="modal-title">Título</h2>
                <button class="icon-btn" id="modal-close" type="button" aria-label="Fechar">&times;</button>
            </header>
            <div id="modal-body"></div>
        </div>
    </div>

    <div id="toast" class="toast hidden"></div>

    <script src="/assets/js/api.js"></script>
    <script src="/assets/js/app.js"></script>
    <script>
        if ('serviceWorker' in navigator) {
            // updateViaCache: 'none' garante que o próprio SW nunca venha do cache HTTP
            navigator.serviceWorker.register('/service-worker.js', { updateViaCache: 'none' })
                .then((reg) => reg.update().catch(() => {}))
                .catch(console.error);

            // Quando um novo SW assume, recarrega para aplicar a versão nova
            let recarregando = false;
            navigator.serviceWorker.addEventListener('controllerchange', () => {
                if (recarregando) return;
                recarregando = true;
                location.reload();
            });
        }
    </script>
</body>
</html>
