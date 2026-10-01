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
    <div id="app">
        <header class="topbar">
            <strong><?= htmlspecialchars($_ENV['APP_NAME'] ?? 'APR/PT') ?></strong>
            <span id="user-info"></span>
        </header>

        <!-- Tela de login -->
        <section id="view-login" class="card">
            <h1>Entrar</h1>
            <form id="form-login">
                <label>E-mail<input type="email" name="email" required autocomplete="username"></label>
                <label>Senha<input type="password" name="password" required autocomplete="current-password"></label>
                <button type="submit">Entrar</button>
            </form>
            <p id="login-error" class="error"></p>
        </section>

        <!-- Painel -->
        <section id="view-app" class="hidden">
            <div class="toolbar">
                <button id="btn-sync">Sincronizar OS do dia</button>
                <button id="btn-logout" class="secondary">Sair</button>
            </div>
            <p id="app-msg"></p>
            <div id="os-list" class="list"></div>
        </section>
    </div>

    <script src="/assets/js/api.js"></script>
    <script src="/assets/js/app.js"></script>
    <script>
        if ('serviceWorker' in navigator) {
            navigator.serviceWorker.register('/service-worker.js').catch(console.error);
        }
    </script>
</body>
</html>
