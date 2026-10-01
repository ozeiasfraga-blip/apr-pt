<?php

namespace App\Services;

use App\Models\OrdemServico;
use App\Models\Assinatura;
use Dompdf\Dompdf;
use Dompdf\Options;

class PdfService
{
    /**
     * Gera o PDF do documento APR/PT e devolve o caminho relativo (a partir de storage/).
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
        $j = fn($v) => is_string($v) ? (json_decode($v, true) ?: []) : (is_array($v) ? $v : []);

        $status = strtoupper($e($doc['status']));

        // Riscos
        $riscos = $j($doc['riscos'] ?? null);
        $riscosHtml = '';
        if ($riscos) {
            $linhas = '';
            foreach ($riscos as $r) {
                $sev = (int)($r['sev'] ?? 0);
                $prob = (int)($r['prob'] ?? 0);
                $nivel = $r['nivel'] ?? self::nivel($sev, $prob);
                $linhas .= '<tr>'
                    . '<td>' . $e($r['perigo'] ?? $r['descricao'] ?? '') . '</td>'
                    . '<td>' . $e($r['nr'] ?? '') . '</td>'
                    . '<td style="text-align:center">' . ($sev ?: '-') . '</td>'
                    . '<td style="text-align:center">' . ($prob ?: '-') . '</td>'
                    . '<td style="text-align:center">' . $e($nivel) . '</td>'
                    . '<td>' . $e($r['medidas'] ?? '') . '</td>'
                    . '</tr>';
            }
            $riscosHtml = '<table class="tbl"><thead><tr><th>Perigo</th><th>NR</th><th>Sev.</th><th>Prob.</th><th>Nível</th><th>Medidas de controle</th></tr></thead><tbody>' . $linhas . '</tbody></table>';
        } else {
            $riscosHtml = '<em>Não informado</em>';
        }

        $lista = function ($v) use ($e) {
            $arr = is_string($v) ? json_decode($v, true) : $v;
            if (!is_array($arr) || empty($arr)) return '<em>Não informado</em>';
            $out = '';
            foreach ($arr as $i) {
                $txt = is_array($i) ? ($i['descricao'] ?? $i['valor'] ?? json_encode($i, JSON_UNESCAPED_UNICODE)) : $i;
                $out .= '<li>' . $e($txt) . '</li>';
            }
            return '<ul>' . $out . '</ul>';
        };

        // Checklist PT
        $checklist = is_string($doc['checklist'] ?? null) ? (json_decode($doc['checklist'], true) ?: []) : ($doc['checklist'] ?? []);
        $checkHtml = '';
        if (is_array($checklist) && $checklist) {
            $checkHtml = '<table class="tbl"><thead><tr><th>Item</th><th style="width:80px">Resposta</th></tr></thead><tbody>';
            foreach ($checklist as $c) {
                $resp = strtoupper((string)($c['resposta'] ?? ($c['valor'] ?? '')));
                $checkHtml .= '<tr><td>' . $e($c['descricao'] ?? $c['codigo'] ?? '') . '</td><td style="text-align:center">' . $e($resp) . '</td></tr>';
            }
            $checkHtml .= '</tbody></table>';
        } else {
            $checkHtml = '<em>Não informado</em>';
        }

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
                . ($a['tipo'] === 'tecnico' ? 'Técnico executante' : 'Supervisor')
                . '<br><small>Assinado em ' . $e($a['assinado_em']) . '</small></div></div>';
        }

        $normas = $j($doc['normas'] ?? null);
        $normasTxt = $normas ? implode(' · ', array_map($e, $normas)) : ($doc['nr35'] ? 'NR-35' : '') . ($doc['nr10'] ? ' · NR-10' : '');

        return '<html><head><meta charset="utf-8"><style>
            body { font-family: "DejaVu Sans", sans-serif; font-size: 11px; color: #222; }
            h1 { font-size: 16px; margin: 0 0 2px; }
            h2 { font-size: 12px; background: #eef2f7; padding: 6px 8px; margin: 16px 0 8px; border-left: 3px solid #0d3b66; }
            .tbl { width: 100%; border-collapse: collapse; }
            .tbl th, .tbl td { border: 1px solid #ccc; padding: 4px 6px; vertical-align: top; text-align: left; }
            .tbl th { background: #f3f4f6; font-size: 10px; }
            .dados td { padding: 3px 6px; vertical-align: top; }
            .label { color: #666; width: 110px; }
            .badge { display: inline-block; padding: 2px 8px; border: 1px solid #999; border-radius: 3px; font-size: 10px; }
            small { color: #666; }
        </style></head><body>
            <h1>APR / PT — Análise Preliminar de Risco e Permissão de Trabalho</h1>
            <div class="badge">Status: ' . $status . '</div>
            <div style="font-size:10px;color:#777;">Documento: ' . $e($doc['uuid']) . '</div>

            <h2>1. Identificação</h2>
            <table class="dados">
                <tr><td class="label">OS (IXC)</td><td>' . $e($os['ixc_id'] ?? '') . '</td>
                    <td class="label">Protocolo</td><td>' . $e($os['protocolo'] ?? '') . '</td></tr>
                <tr><td class="label">Cliente</td><td>' . $e($os['cliente'] ?? '') . '</td>
                    <td class="label">Data agenda</td><td>' . $e($os['data_agenda'] ?? '') . '</td></tr>
                <tr><td class="label">Endereço</td><td colspan="3">' . $e(trim(($os['endereco'] ?? '') . ' ' . ($os['bairro'] ?? '') . ' ' . ($os['cidade'] ?? ''))) . '</td></tr>
                <tr><td class="label">Atividade</td><td colspan="3">' . $e($os['assunto'] ?? '') . '</td></tr>
            </table>

            <h2>2. APR — Análise Preliminar de Risco</h2>
            <div><span class="label">Normas:</span> ' . $e($normasTxt) . '</div>
            <div style="margin-top:6px;"><span class="label">Descrição da atividade:</span><br>' . nl2br($e($doc['atividades'] ?? '')) . '</div>
            <div style="margin-top:8px;"><strong>Riscos identificados</strong></div>' . $riscosHtml . '
            <div style="margin-top:8px;"><strong>EPIs</strong></div>' . $lista($doc['epis'] ?? null) . '
            <div style="margin-top:8px;"><strong>EPC / Ferramentas</strong></div>' . $lista($doc['epcs'] ?? null) . '
            <div style="margin-top:8px;"><span class="label">Observações:</span> ' . nl2br($e($doc['observacoes'] ?? '')) . '</div>

            <h2>3. PT — Permissão de Trabalho</h2>
            <div><strong>Checklist de liberação</strong></div>' . $checkHtml . '
            <div style="margin-top:8px;"><span class="label">Restrições:</span> ' . nl2br($e($doc['restricoes'] ?? '')) . '</div>
            <div style="margin-top:4px;"><span class="label">Validade:</span> ' . $e($doc['validade_inicio'] ?? '') . ' até ' . $e($doc['validade_fim'] ?? '') . '</div>

            <h2>4. Assinaturas</h2>' . ($assinaturaBlock ?: '<em>Sem assinaturas registradas.</em>') . '

            <h2>5. Encerramento</h2>
            <div><span class="label">Concluído em:</span> ' . $e($doc['encerrado_em'] ?? '') . '</div>
            <div><span class="label">Serviço conforme:</span> ' . (($doc['servico_conforme'] ?? null) === null ? '-' : ((int)$doc['servico_conforme'] ? 'Sim' : 'Não')) . '</div>
            <div><span class="label">Observações:</span> ' . nl2br($e($doc['encerramento_obs'] ?? '')) . '</div>
        </body></html>';
    }

    private static function nivel(int $sev, int $prob): string
    {
        $n = $sev * $prob;
        if ($n <= 0) return '-';
        if ($n <= 4) return 'Baixo';
        if ($n <= 9) return 'Médio';
        if ($n <= 16) return 'Alto';
        return 'Crítico';
    }
}
