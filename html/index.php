<?php
require_once __DIR__ . '/../src/bootstrap.php';
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="theme-color" content="#0d3b66">
    <title><?= htmlspecialchars($_ENV['APP_NAME'] ?? 'APR/PT') ?></title>
    <link rel="manifest" href="/manifest.webmanifest">
    <link rel="stylesheet" href="/assets/css/style.css">
</head>
<body>
    <!-- ── Login ─────────────────────────────────────────── -->
    <div id="view-login" class="login-wrap">
        <section class="card">
            <h1><?= htmlspecialchars($_ENV['APP_NAME'] ?? 'APR/PT') ?></h1>
            <p class="muted">Análise Preliminar de Risco e Permissão de Trabalho</p>
            <form id="form-login">
                <label>E-mail<input type="email" name="email" required autocomplete="username"></label>
                <label>Senha<input type="password" name="password" required autocomplete="current-password"></label>
                <button type="submit">Entrar</button>
            </form>
            <p id="login-error" class="error"></p>
        </section>
    </div>

    <!-- ── Aplicação ─────────────────────────────────────── -->
    <div id="view-app" class="hidden">
        <header class="topbar">
            <div class="brand"><?= htmlspecialchars($_ENV['APP_NAME'] ?? 'APR/PT') ?></div>
            <nav id="nav" class="nav">
                <a href="#os" data-view="os">Ordens de Serviço</a>
                <a href="#documentos" data-view="documentos">Documentos</a>
                <a href="#usuarios" data-view="usuarios" data-roles="supervisor,admin">Usuários</a>
            </nav>
            <div class="user-box">
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
                <button class="icon-btn" id="modal-close" type="button">&times;</button>
            </header>
            <div id="modal-body"></div>
        </div>
    </div>

    <div id="toast" class="toast hidden"></div>

    <script src="/assets/js/api.js"></script>
    <script src="/assets/js/app.js"></script>
    <script>
        if ('serviceWorker' in navigator) {
            navigator.serviceWorker.register('/service-worker.js').catch(console.error);
        }
    </script>
</body>
</html>
