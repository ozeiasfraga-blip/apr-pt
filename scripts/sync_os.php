<?php

/**
 * Sincroniza as Ordens de Serviço do IXC.
 *
 * Uso:
 *   php scripts/sync_os.php                 # OS de hoje
 *   php scripts/sync_os.php 2026-10-01      # data específica
 *   php scripts/sync_os.php 2026-10-01 12   # data + id do técnico (funcionarios.id)
 *
 * Sugestão de cron (a cada 15 minutos):
 *   0,15,30,45 * * * * /usr/bin/php /var/www/apr-pt/scripts/sync_os.php >> /var/log/aprpt-sync.log 2>&1
 */

require_once __DIR__ . '/../src/bootstrap.php';

use App\Services\SyncService;

$date = $argv[1] ?? date('Y-m-d');
$tecnico = isset($argv[2]) ? (int)$argv[2] : null;

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
    fwrite(STDERR, "Data inválida: {$date} (use YYYY-MM-DD)\n");
    exit(1);
}

echo "== Sincronização APR/PT — IXC ==\n";
echo "Data: {$date}" . ($tecnico ? " | Técnico (IXC id): {$tecnico}" : '') . "\n";

try {
    $result = SyncService::ordensServico($date, $tecnico);
    echo "Total retornado: {$result['total']}\n";
    echo "OS sincronizadas: {$result['sincronizadas']}\n";
    if (!empty($result['erros'])) {
        echo "Erros (" . count($result['erros']) . "):\n";
        foreach (array_slice($result['erros'], 0, 20) as $e) {
            echo "  - {$e}\n";
        }
        exit(2);
    }
    echo "Concluído com sucesso.\n";
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, "Falha na sincronização: " . $e->getMessage() . "\n");
    exit(1);
}
