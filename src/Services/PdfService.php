<?php

namespace App\Services;

use App\Database;
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
        $data = fn($v) => $v ? date('d/m/Y', strtotime((string)$v)) : '';

        $status = strtoupper($e($doc['status']));

        // Assinaturas por tipo
        $porTipo = [];
        foreach ($assinaturas as $a) {
            $porTipo[$a['tipo']] = $a;
        }
        $imgTag = function (?array $a) {
            if (!$a || empty($a['imagem_path'])) return '';
            $p = StorageService::absolute($a['imagem_path']);
            if (!is_file($p)) return '';
            return '<img src="data:image/png;base64,' . base64_encode(file_get_contents($p)) . '" style="height:58px;">';
        };

        // Riscos
        $riscos = $j($doc['riscos'] ?? null);
        $riscosHtml = '<em>Não informado</em>';
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
                    . '<td>' . $e($r['medidas'] ?? '') . '</td></tr>';
            }
            $riscosHtml = '<table class="tbl"><thead><tr><th>Perigo</th><th>NR</th><th>Sev.</th><th>Prob.</th><th>Nível</th><th>Medidas de controle</th></tr></thead><tbody>' . $linhas . '</tbody></table>';
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
        $checklist = $j($doc['checklist'] ?? null);
        $checkHtml = '<em>Não informado</em>';
        if ($checklist) {
            $checkHtml = '<table class="tbl"><thead><tr><th>Item</th><th style="width:80px">Resposta</th></tr></thead><tbody>';
            foreach ($checklist as $c) {
                $checkHtml .= '<tr><td>' . $e($c['descricao'] ?? $c['codigo'] ?? '') . '</td><td style="text-align:center">' . strtoupper($e($c['resposta'] ?? '')) . '</td></tr>';
            }
            $checkHtml .= '</tbody></table>';
        }

        $normas = $j($doc['normas'] ?? null);
        $normasTxt = $normas ? implode(' · ', array_map($e, $normas)) : '' ;

        $textoLiberacao = self::texto('autorizacao_supervisor',
            'Eu, na condição de Supervisor / Emissor Autorizado da PT, declaro que analisei a APR anexada, conferi as condições de segurança elétrica do circuito e autorizo o início dos trabalhos.');
        $textoEncerramento = self::texto('declaracao_encerramento',
            'Declaro que os trabalhos foram concluídos ou paralisados e a equipe e ferramentas foram retiradas da zona de risco.');

        $aut = strtoupper((string)($doc['pt_autorizacao'] ?? ''));
        $chk = fn(bool $on) => $on ? '&#9745;' : '&#9744;';
        $autAutorizada = $aut === 'AUTORIZADA';
        $autNegada = $aut === 'NAO_AUTORIZADA';

        $enc = strtoupper((string)($doc['pt_encerramento_status'] ?? ''));
        $encConcluido = $enc === 'CONCLUIDO';
        $encSuspenso = $enc === 'SUSPENSO';

        $sigLiber = $porTipo['supervisor'] ?? null;
        $sigEnc = $porTipo['supervisor_encerramento'] ?? null;
        $sigTec = $porTipo['tecnico'] ?? null;

        return '<html><head><meta charset="utf-8"><style>
            body { font-family: "DejaVu Sans", sans-serif; font-size: 11px; color: #1a1a1a; }
            h1 { font-size: 16px; margin: 0 0 2px; }
            h2 { font-size: 12px; background: #eef2f7; padding: 6px 8px; margin: 16px 0 8px; border-left: 3px solid #0d3b66; }
            .tbl { width: 100%; border-collapse: collapse; }
            .tbl th, .tbl td { border: 1px solid #ccc; padding: 4px 6px; vertical-align: top; text-align: left; }
            .tbl th { background: #f3f4f6; font-size: 10px; }
            .dados td { padding: 3px 6px; vertical-align: top; }
            .label { color: #555; }
            .badge { display: inline-block; padding: 2px 8px; border: 1px solid #999; border-radius: 3px; font-size: 10px; }
            .box { border: 1px solid #94a3b8; border-radius: 6px; padding: 10px 12px; margin-top: 8px; }
            .termo { margin: 0 0 10px; }
            .doth { border-bottom: 1px solid #333; display: inline-block; min-width: 240px; }
            .linha { margin: 8px 0; }
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
            <div class="linha"><span class="label">Descrição da atividade:</span><br>' . nl2br($e($doc['atividades'] ?? '')) . '</div>
            <div><strong>Riscos identificados</strong></div>' . $riscosHtml . '
            <div class="linha"><strong>EPIs</strong></div>' . $lista($doc['epis'] ?? null) . '
            <div class="linha"><strong>EPC / Ferramentas</strong></div>' . $lista($doc['epcs'] ?? null) . '
            <div class="linha"><span class="label">Observações:</span> ' . nl2br($e($doc['observacoes'] ?? '')) . '</div>
            <div class="linha"><strong>Assinatura do executante (APR):</strong> ' . ($imgTag($sigTec) ?: '-') .
                ($sigTec ? ' <small>' . $e($sigTec['user_nome']) . ' — ' . $data($sigTec['assinado_em']) . '</small>' : '') . '</div>

            <h2>3. PT — PERMISSÃO PARA TRABALHO</h2>
            <div class="box">
                <div class="termo">' . $e($textoLiberacao) . '</div>
                <div class="linha">Status da Permissão: &nbsp;&nbsp; ' . $chk($autAutorizada) . ' <strong>AUTORIZADA</strong>
                    &nbsp;&nbsp;&nbsp; ' . $chk($autNegada) . ' <strong style="color:#b91c1c">NÃO AUTORIZADA</strong></div>
                <table style="width:100%"><tr>
                    <td style="width:60%">Nome do Supervisor / Emissor:<br><span class="doth">' . $e($doc['pt_supervisor_nome'] ?? '') . '</span></td>
                    <td>Registro / Cargo:<br><span class="doth">' . $e($doc['pt_supervisor_registro'] ?? '') . '</span></td>
                </tr></table>
                <table style="width:100%;margin-top:10px"><tr>
                    <td style="width:60%">Assinatura do Supervisor:<br>' . ($imgTag($sigLiber) ?: '____________________') . '</td>
                    <td>Data da Assinatura:<br>' . ($sigLiber ? $data($sigLiber['assinado_em']) : '____/____/______') . '</td>
                </tr></table>
            </div>

            <h2>4. ENCERRAMENTO DA PT</h2>
            <div class="box">
                <div class="termo">' . $e($textoEncerramento) . '</div>
                <div class="linha">Status do Encerramento: &nbsp;&nbsp; ' . $chk($encConcluido) . ' <strong>Concluído com sucesso</strong>
                    &nbsp;&nbsp;&nbsp; ' . $chk($encSuspenso) . ' <strong style="color:#b45309">Serviço suspenso / PT cancelada</strong></div>
                <div class="linha"><span class="label">Observações:</span> ' . nl2br($e($doc['encerramento_obs'] ?? '')) . '</div>
                <table style="width:100%"><tr>
                    <td style="width:60%">Assinatura do Supervisor no Encerramento:<br>' . ($imgTag($sigEnc) ?: '____________________') . '</td>
                    <td>Data do Encerramento:<br>' . ($doc['encerrado_em'] ? $data($doc['encerrado_em']) : '____/____/______') . '</td>
                </tr></table>
            </div>
        </body></html>';
    }

    private static function texto(string $chave, string $padrao): string
    {
        try {
            $stmt = Database::getInstance()->prepare('SELECT valor FROM config_textos WHERE chave = ?');
            $stmt->execute([$chave]);
            $v = $stmt->fetchColumn();
            return $v !== false && $v !== null && $v !== '' ? (string)$v : $padrao;
        } catch (\Throwable $e) {
            return $padrao;
        }
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
