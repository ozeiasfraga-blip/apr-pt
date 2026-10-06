<?php
/**
 * Página de download do APK — APR/PT
 * http://177.125.60.123:8089/apk/
 */
$v = json_decode(@file_get_contents(__DIR__ . '/versao.json'), true) ?: [];
$versao = htmlspecialchars($v['versao'] ?? '—');
$tamanho = htmlspecialchars($v['tamanho'] ?? '—');
$publicado = htmlspecialchars($v['publicado_em'] ?? '—');
$novidades = htmlspecialchars($v['novidades'] ?? '');
$apk = htmlspecialchars($v['apk'] ?? 'app.apk');

$svgMarca = '<svg viewBox="0 0 24 24" width="44" height="44" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 3l7 3v5.5c0 4.2-2.9 7.1-7 8.5-4.1-1.4-7-4.3-7-8.5V6z"/><path d="M8.8 12.2l2.3 2.3 4.1-4.4"/></svg>';
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#0d3b66">
    <title>APR/PT — Baixar aplicativo</title>
    <link rel="icon" type="image/png" href="/assets/img/favicon.png">
    <link rel="apple-touch-icon" href="/assets/img/apple-touch-icon.png">
    <style>
        :root {
            --brand: #0d3b66; --brand-2: #17548f; --brand-3: #2a72c4;
            --bg: #eef1f6; --card: #fff; --text: #0f172a; --muted: #4a5768;
            --line: #dde5ee; --ok: #166534;
            --radius: 14px;
            --shadow: 0 4px 24px rgba(13, 59, 102, .10);
        }
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: system-ui, -apple-system, "Segoe UI", Roboto, sans-serif;
            background: linear-gradient(160deg, var(--brand) 0%, var(--brand-2) 55%, var(--brand-3) 100%);
            color: var(--text); min-height: 100vh; display: flex; align-items: center; justify-content: center;
            padding: 24px 16px;
        }
        .card {
            background: var(--card); border-radius: var(--radius); box-shadow: var(--shadow);
            max-width: 420px; width: 100%; padding: 32px 28px 28px; text-align: center;
        }
        .marca {
            display: inline-flex; align-items: center; justify-content: center;
            width: 72px; height: 72px; border-radius: 20px;
            background: linear-gradient(140deg, var(--brand-2), var(--brand));
            color: #fff; margin-bottom: 16px; box-shadow: 0 6px 18px rgba(13, 59, 102, .30);
        }
        .empresa { display: block; height: 34px; width: auto; margin: 0 auto 18px; }
        h1 { font-size: 22px; color: var(--brand); margin-bottom: 4px; }
        .sub { color: var(--muted); font-size: 14px; margin-bottom: 22px; }
        .info {
            display: grid; grid-template-columns: 1fr 1fr; gap: 8px;
            margin-bottom: 22px;
        }
        .info div {
            background: var(--bg); border: 1px solid var(--line); border-radius: 10px; padding: 10px 8px;
        }
        .info dt { font-size: 11.5px; color: var(--muted); text-transform: uppercase; letter-spacing: .4px; }
        .info dd { font-size: 15px; font-weight: 600; color: var(--text); margin-top: 2px; }
        .btn {
            display: inline-flex; align-items: center; justify-content: center; gap: 8px;
            width: 100%; height: 50px; border-radius: 12px;
            background: var(--brand); color: #fff; text-decoration: none;
            font-size: 16px; font-weight: 600;
            transition: background .15s ease, transform .1s ease;
        }
        .btn:hover { background: var(--brand-2); }
        .btn:active { transform: scale(.98); }
        .btn svg { flex-shrink: 0; }
        .obs { margin-top: 16px; font-size: 12.5px; color: var(--muted); line-height: 1.55; }
        .obs code {
            background: var(--bg); border: 1px solid var(--line); border-radius: 6px;
            padding: 1px 5px; font-size: 12px;
        }
        .rodape { margin-top: 22px; font-size: 12px; color: var(--muted); }
    </style>
</head>
<body>
    <main class="card">
        <img class="empresa" src="/assets/img/nwt-logo.png" alt="NWT Telecom">
        <div class="marca"><?= $svgMarca ?></div>
        <h1>APR/PT</h1>
        <p class="sub">Análise Preliminar de Risco e Permissão de Trabalho</p>

        <dl class="info">
            <div><dt>Versão</dt><dd><?= $versao ?></dd></div>
            <div><dt>Tamanho</dt><dd><?= $tamanho ?></dd></div>
            <div><dt>Publicado em</dt><dd><?= $publicado ?></dd></div>
            <div><dt>Plataforma</dt><dd>Android</dd></div>
        </dl>

        <a class="btn" href="<?= $apk ?>" download="aprpt-<?= $versao ?>.apk">
            <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3v12"/><path d="M7 10l5 5 5-5"/><path d="M4 19h16"/></svg>
            Baixar aplicativo
        </a>

        <?php if ($novidades): ?>
            <p class="obs"><?= $novidades ?></p>
        <?php endif; ?>
        <p class="obs">Instale o APK e abra o aplicativo. As atualizações da interface chegam automaticamente; quando publicarmos uma nova versão do app, um aviso aparece na tela.</p>

        <p class="rodape">NWT Telecom · Segurança do Trabalho · NR-10 e NR-35</p>
    </main>
</body>
</html>
