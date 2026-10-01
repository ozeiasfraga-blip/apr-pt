<?php

namespace App\Services;

use App\Models\OrdemServico;
use App\Models\Assinatura;
use Dompdf\Dompdf;
use Dompdf\Options;

class PdfService
{
    /**
     * Gera o PDF do documento APR/PT e devolve o caminho absoluto gravado.
     */
    public static function gerarDocumento(array $doc): string
    {
        $os = OrdemServico::getById((int)$doc['os_id']);
        $assinaturas = Assinatura::listByDocumento((int)$doc['id']);

        $html = self::render($doc, $os ?: [], $assinaturas);

        $options = new Options();
        $options->set('isRemoteEnabled', true);
        $options->set('defaultFont', 'DejaVu Sans');

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        $dir = StorageService::basePath() . '/pdfs';
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException('Não foi possível criar o diretório de PDFs.');
        }

        $relative = 'pdfs/apr_pt_' . $doc['uuid'] . '.pdf';
        file_put_contents(StorageService::basePath() . '/' . $relative, $dompdf->output());

        return $relative;
    }

    private static function render(array $doc, array $os, array $assinaturas): string
    {
        $e = fn($v) => htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8');
        $json = function ($v) use ($e) {
            $arr = is_string($v) ? json_decode($v, true) : $v;
            if (!is_array($arr) || empty($arr)) {
                return '<em>Não informado</em>';
            }
            $items = array_map(fn($i) => '<li>' . $e(is_array($i) ? json_encode($i, JSON_UNESCAPED_UNICODE) : $i) . '</li>', $arr);
            return '<ul>' . implode('', $items) . '</ul>';
        };

        $assinaturaBlock = '';
        foreach ($assinaturas as $a) {
            $img = '';
            if (!empty($a['imagem_path'])) {
                $path = StorageService::absolute($a['imagem_path']);
                if (is_file($path)) {
                    $img = '<img src="data:image/png;base64,' . base64_encode(file_get_contents($path)) . '" style="height:70px;">';
                }
            }
            $assinaturaBlock .= '<div style="margin-top:14px;">' . $img
                . '<div style="border-top:1px solid #333;width:280px;margin-top:6px;"></div>'
                . '<div><strong>' . $e($a['user_nome']) . '</strong> — '
                . ($a['tipo'] === 'tecnico' ? 'Técnico' : 'Supervisor')
                . '<br><small>Assinado em ' . $e($a['assinado_em']) . '</small></div></div>';
        }

        $status = strtoupper($e($doc['status']));

        return '<html><head><meta charset="utf-8"><style>
            body { font-family: "DejaVu Sans", sans-serif; font-size: 12px; color: #222; }
            h1 { font-size: 18px; margin: 0 0 4px; }
            h2 { font-size: 13px; background: #f0f0f0; padding: 6px; margin: 18px 0 8px; }
            table { width: 100%; border-collapse: collapse; }
            td { padding: 4px 6px; vertical-align: top; }
            .label { color: #666; width: 120px; }
            .badge { display: inline-block; padding: 2px 8px; border: 1px solid #999; border-radius: 3px; font-size: 11px; }
        </style></head><body>
            <h1>APR / PT — Análise Preliminar de Risco e Permissão de Trabalho</h1>
            <div class="badge">Status: ' . $status . '</div>
            <div style="margin-top:4px;font-size:11px;color:#777;">Documento: ' . $e($doc['uuid']) . '</div>

            <h2>Dados da Ordem de Serviço</h2>
            <table>
                <tr><td class="label">OS (IXC)</td><td>' . $e($os['ixc_id'] ?? '') . '</td>
                    <td class="label">Protocolo</td><td>' . $e($os['protocolo'] ?? '') . '</td></tr>
                <tr><td class="label">Cliente</td><td>' . $e($os['cliente'] ?? '') . '</td>
                    <td class="label">Data agenda</td><td>' . $e($os['data_agenda'] ?? '') . '</td></tr>
                <tr><td class="label">Endereço</td><td colspan="3">' . $e(trim(($os['endereco'] ?? '') . ' ' . ($os['bairro'] ?? ''))) . '</td></tr>
            </table>

            <h2>Normas aplicáveis</h2>
            <div>NR-10 (Elétrica): <strong>' . (!empty($doc['nr10']) ? 'SIM' : 'NÃO') . '</strong>
                 &nbsp;&nbsp; NR-35 (Altura): <strong>' . (!empty($doc['nr35']) ? 'SIM' : 'NÃO') . '</strong></div>

            <h2>Riscos identificados</h2>' . $json($doc['riscos'] ?? null) . '
            <h2>Medidas de controle</h2>' . $json($doc['medidas'] ?? null) . '
            <h2>EPIs</h2>' . $json($doc['epis'] ?? null) . '
            <h2>Atividades / Observações</h2>
            <div>' . nl2br($e($doc['atividades'] ?? '')) . '</div>
            <div style="margin-top:6px;">' . nl2br($e($doc['observacoes'] ?? '')) . '</div>

            <h2>Assinaturas</h2>' . ($assinaturaBlock ?: '<em>Sem assinaturas registradas.</em>') . '
        </body></html>';
    }
}
