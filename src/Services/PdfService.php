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

        $equipe = [];
        try {
            $st = Database::getInstance()->prepare('SELECT * FROM documento_equipe WHERE documento_id = ? ORDER BY ordem, id');
            $st->execute([(int)$doc['id']]);
            $equipe = $st->fetchAll();
        } catch (\Throwable $e) {}

        $equipeHtml = '<em>Não informado</em>';
        if ($equipe) {
            $rowsEq = '';
            foreach ($equipe as $i => $m) {
                $rowsEq .= '<tr><td style="text-align:center;width:30px">' . str_pad((string)($i + 1), 2, '0', STR_PAD_LEFT) . '</td>'
                    . '<td>' . $e($m['nome'] ?? '') . '</td>'
                    . '<td>' . $e($m['funcao'] ?? '') . '</td>'
                    . '<td>' . $e($m['cpf'] ?? '') . '</td>'
                    . '<td>' . ($imgTag(['imagem_path' => $m['assinatura_path'] ?? null]) ?: '') . '</td></tr>';
            }
            $equipeHtml = '<table class="tbl"><thead><tr><th>Nº</th><th>Nome do Colaborador</th><th>Função / Cargo</th><th>CPF / Matrícula</th><th>Assinatura</th></tr></thead><tbody>' . $rowsEq . '</tbody></table>';
        }

        // Nomes da equipe para a linha "Equipe Envolvida" (usa o campo; senão, os integrantes)
        $equipeNomes = (string)($doc['equipe_envolvida'] ?? '');
        if ($equipeNomes === '' && $equipe) {
            $equipeNomes = implode(', ', array_filter(array_map(fn($m) => $m['nome'] ?? '', $equipe)));
        }

        $lista = function ($v) use ($e) {
            $arr = is_string($v) ? json_decode($v, true) : $v;
            if (!is_array($arr) || empty($arr)) return '<em>Não informado</em>';
            $out = '';
            foreach ($arr as $i) {
                $txt = is_array($i) ? ($i['descricao'] ?? $i['valor'] ?? json_encode($i, JSON_UNESCAPED_UNICODE)) : $i;
                $out .= '<li>' . $e($txt) . '</li>';
            }
            return '<ul class="chk">' . $out . '</ul>';
        };

        // Riscos agrupados por categoria
        $riscos = $j($doc['riscos'] ?? null);
        $grupos = [];
        foreach ($riscos as $r) {
            $cat = $r['categoria'] ?? 'RISCOS';
            $grupos[$cat][] = $r;
        }
        $riscosHtml = '<em>Não informado</em>';
        if ($grupos) {
            $riscosHtml = '';
            foreach ($grupos as $cat => $itens) {
                $linhas = '';
                foreach ($itens as $r) {
                    $sev = (int)($r['sev'] ?? 0);
                    $prob = (int)($r['prob'] ?? 0);
                    $nivel = $r['nivel'] ?? self::nivel($sev, $prob);
                    $linhas .= '<tr><td>' . $e($r['perigo'] ?? $r['descricao'] ?? '') . '</td>'
                        . '<td style="text-align:center;width:38px">' . ($sev ?: '-') . '</td>'
                        . '<td style="text-align:center;width:38px">' . ($prob ?: '-') . '</td>'
                        . '<td style="text-align:center;width:60px">' . $e($nivel) . '</td>'
                        . '<td>' . $e($r['medidas'] ?? '') . '</td></tr>';
                }
                $riscosHtml .= '<div class="grupo">' . $e($cat) . '</div>'
                    . '<table class="tbl"><thead><tr><th>Risco</th><th>Sev.</th><th>Prob.</th><th>Nível</th><th>Medidas de controle</th></tr></thead><tbody>' . $linhas . '</tbody></table>';
            }
        }

        $normas = $j($doc['normas'] ?? null);
        $normasTxt = $normas ? implode(' · ', array_map($e, $normas)) : '';

        $empresaRazao = self::texto('empresa_razao', '');
        $empresaCnpj = self::texto('empresa_cnpj', '');
        $empresaEnd = self::texto('empresa_endereco', '');
        $termoCiencia = self::texto('termo_ciencia', '');
        $textoLiberacao = self::texto('autorizacao_supervisor', '');
        $textoEncerramento = self::texto('declaracao_encerramento', '');

        $aut = strtoupper((string)($doc['pt_autorizacao'] ?? ''));
        $chk = fn(bool $on) => $on ? '&#9745;' : '&#9744;';
        $enc = strtoupper((string)($doc['pt_encerramento_status'] ?? ''));

        $sigLiber = $porTipo['supervisor'] ?? null;
        $sigEnc = $porTipo['supervisor_encerramento'] ?? null;
        $sigTec = $porTipo['tecnico'] ?? null;

        $chkNr = function ($v) { return $v ? '&#9745;' : '&#9744;'; };

        return '<html><head><meta charset="utf-8"><style>
            body { font-family: "DejaVu Sans", sans-serif; font-size: 11px; color: #1a1a1a; }
            h1 { font-size: 15px; margin: 0 0 2px; }
            h2 { font-size: 12px; background: #eef2f7; padding: 6px 8px; margin: 16px 0 8px; border-left: 3px solid #0d3b66; }
            .tbl { width: 100%; border-collapse: collapse; margin-bottom: 6px; }
            .tbl th, .tbl td { border: 1px solid #ccc; padding: 4px 6px; vertical-align: top; text-align: left; }
            .tbl th { background: #f3f4f6; font-size: 10px; }
            .dados td { padding: 3px 6px; vertical-align: top; }
            .label { color: #555; }
            .badge { display: inline-block; padding: 2px 8px; border: 1px solid #999; border-radius: 3px; font-size: 10px; }
            .box { border: 1px solid #94a3b8; border-radius: 6px; padding: 10px 12px; margin-top: 8px; }
            .grupo { font-weight: bold; background: #f3f4f6; padding: 3px 6px; margin-top: 6px; font-size: 10px; }
            .doth { border-bottom: 1px solid #333; display: inline-block; min-width: 240px; }
            ul.chk { margin: 3px 0 6px 16px; padding: 0; }
            ul.chk li { margin: 0; }
            small { color: #666; }
        </style></head><body>
            <h1>Análise Preliminar de Risco (APR) e Permissão de Trabalho</h1>
            <div>' . $e($empresaRazao) . '</div>
            <div style="font-size:10px;color:#666;">CNPJ: ' . $e($empresaCnpj) . ' — ' . $e($empresaEnd) . '</div>
            <div class="badge" style="margin-top:4px;">Status: ' . $status . '</div>
            <div style="font-size:10px;color:#777;">Documento: ' . $e($doc['uuid']) . '</div>

            <h2>1. Identificação</h2>
            <table class="dados">
                <tr><td class="label">Cliente</td><td>' . $e($os['cliente'] ?? '') . '</td>
                    <td class="label">OS / Protocolo</td><td>#' . $e($os['ixc_id'] ?? '') . ' ' . $e($os['protocolo'] ?? '') . '</td></tr>
                <tr><td class="label">Atividade</td><td>' . $e($os['assunto'] ?? '') . '</td>
                    <td class="label">Data</td><td>' . $data($doc['validade_inicio'] ?? null) . '</td></tr>
                <tr><td class="label">Local da Atividade</td><td colspan="3">' . $e($doc['local_atividade'] ?? trim(($os['endereco'] ?? '') . ' ' . ($os['bairro'] ?? '') . ' ' . ($os['cidade'] ?? ''))) . '</td></tr>
                <tr><td class="label">Responsável pela APR</td><td>' . $e($doc['responsavel_apr'] ?? '') . '</td>
                    <td class="label">Equipe Envolvida</td><td>' . $e($equipeNomes) . '</td></tr>
            </table>

            <h2>2. Análise Preliminar de Risco (APR)</h2>
            <div><span class="label">Normas aplicáveis:</span> ' . $e($normasTxt) . '</div>

            <div style="margin-top:8px;"><strong>Ferramentas Utilizadas</strong></div>' . $lista($doc['ferramentas'] ?? null) . '
            <div style="margin-top:4px;"><strong>EPIs e EPCs Obrigatórios</strong></div>' . $lista($doc['epis'] ?? null) . '

            <div style="margin-top:8px;"><strong>Riscos, impactos e medidas de controle</strong></div>' . $riscosHtml . '

            <div class="box" style="margin-top:10px;">
                <strong>Autorização formal de trabalho.</strong> O empregado está formalmente autorizado a trabalhar pela empresa
                em atividades envolvendo riscos com eletricidade, conforme exigido pelo item 10.8.4 da NR-10, bem como apto
                para trabalhos em altura nos termos da NR-35.
                <div style="margin-top:6px;">Validade dos treinamentos / habilitação: &nbsp; '
                    . $chkNr(!empty($doc['treinamento_nr10'])) . ' Treinamento NR-10 válido &nbsp;&nbsp; '
                    . $chkNr(!empty($doc['treinamento_nr35'])) . ' Treinamento NR-35 válido &nbsp;&nbsp; '
                    . $chkNr(!empty($doc['aso_apto'])) . ' ASO apto (altura/elétrica)</div>
            </div>

            <div style="margin-top:8px;"><strong>Outras observações / riscos adicionais não mapeados</strong><br>' . nl2br($e($doc['observacoes'] ?? '')) . '</div>
            <div style="margin-top:8px;"><strong>Etapas da atividade</strong><br>' . nl2br($e($doc['etapas'] ?? '')) . '</div>

            <div class="box" style="margin-top:10px;">
                <strong>Termo de Ciência e Compromisso.</strong> ' . $e($termoCiencia) . '
            </div>

            <div style="margin-top:10px;"><strong>Registro e Assinatura da Equipe Envolvida</strong></div>' . $equipeHtml . '

            <table style="width:100%;margin-top:14px"><tr>
                <td style="width:60%">Assinatura do Responsável pela APR:<br>' . ($imgTag($sigTec) ?: '____________________') .
                    ($sigTec ? ' <small>' . $e($sigTec['user_nome']) . '</small>' : '') . '</td>
                <td>Data:<br>' . $data($sigTec['assinado_em'] ?? null) . '</td>
            </tr></table>

            <h2>3. PT — PERMISSÃO PARA TRABALHO</h2>
            <div class="box">
                <div>' . $e($textoLiberacao) . '</div>
                <div style="margin-top:6px;">Status da Permissão: &nbsp; ' . $chk($aut === 'AUTORIZADA') . ' <strong>AUTORIZADA</strong> &nbsp;&nbsp; '
                    . $chk($aut === 'NAO_AUTORIZADA') . ' <strong style="color:#b91c1c">NÃO AUTORIZADA</strong></div>
                <table style="width:100%;margin-top:8px"><tr>
                    <td style="width:60%">Nome do Supervisor / Emissor:<br><span class="doth">' . $e($doc['pt_supervisor_nome'] ?? '') . '</span></td>
                    <td>Registro / Cargo:<br><span class="doth">' . $e($doc['pt_supervisor_registro'] ?? '') . '</span></td>
                </tr></table>
                <table style="width:100%;margin-top:8px"><tr>
                    <td style="width:60%">Assinatura do Supervisor:<br>' . ($imgTag($sigLiber) ?: '____________________') . '</td>
                    <td>Data:<br>' . $data($sigLiber['assinado_em'] ?? null) . '</td>
                </tr></table>
            </div>

            <h2>4. ENCERRAMENTO DA PT</h2>
            <div class="box">
                <div>' . $e($textoEncerramento) . '</div>
                <div style="margin-top:6px;">Status do Encerramento: &nbsp; ' . $chk($enc === 'CONCLUIDO') . ' <strong>Concluído com sucesso</strong> &nbsp;&nbsp; '
                    . $chk($enc === 'SUSPENSO') . ' <strong style="color:#b45309">Serviço suspenso / PT cancelada</strong></div>
                <div style="margin-top:6px;"><span class="label">Observações:</span> ' . nl2br($e($doc['encerramento_obs'] ?? '')) . '</div>
                <table style="width:100%;margin-top:8px"><tr>
                    <td style="width:60%">Assinatura do Supervisor no Encerramento:<br>' . ($imgTag($sigEnc) ?: '____________________') . '</td>
                    <td>Data:<br>' . $data($doc['encerrado_em'] ?? null) . '</td>
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
