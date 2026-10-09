<?php

namespace App\Services;

/**
 * Envio do PDF à nuvem via rclone (ex.: Dropbox).
 *
 * Configuração (.env):
 *   RCLONE_BIN=rclone
 *   RCLONE_CONFIG=storage/credentials/rclone.conf
 *   RCLONE_REMOTE=nwt        (nome do remote dentro do rclone.conf)
 *   RCLONE_DIR=APR-PT         (pasta de destino na nuvem)
 *
 * O token do remote vem de `rclone authorize dropbox` rodado uma única
 * vez no PC (1 min). O rclone renova o acesso sozinho depois disso.
 * O mesmo nome de arquivo sobrescreve — reencerrar atualiza, não duplica.
 */
class RcloneService
{
    public static function bin(): string
    {
        return trim((string)($_ENV['RCLONE_BIN'] ?? 'rclone')) ?: 'rclone';
    }

    public static function configPath(): ?string
    {
        $rel = trim((string)($_ENV['RCLONE_CONFIG'] ?? 'storage/credentials/rclone.conf'));
        if ($rel === '') {
            return null;
        }
        // Aceita caminho relativo à raiz do projeto ou absoluto
        if ($rel[0] !== '/') {
            $rel = dirname(__DIR__, 2) . '/' . $rel;
        }
        return $rel;
    }

    public static function remote(): string
    {
        return trim((string)($_ENV['RCLONE_REMOTE'] ?? 'nwt')) ?: 'nwt';
    }

    public static function dir(): string
    {
        return trim((string)($_ENV['RCLONE_DIR'] ?? 'APR-PT'), '/') ?: 'APR-PT';
    }

    public static function configurado(): bool
    {
        $conf = self::configPath();
        if ($conf === null || !is_file($conf) || !is_readable($conf)) {
            return false;
        }
        $bin = trim((string)shell_exec('command -v ' . escapeshellarg(self::bin()) . ' 2>/dev/null'));
        if ($bin === '') {
            return false;
        }
        $conteudo = @file_get_contents($conf);
        if (!is_string($conteudo)) {
            return false;
        }
        return (bool)preg_match('/^\[' . preg_quote(self::remote(), '/') . '\]/m', $conteudo);
    }

    /**
     * Envia (ou atualiza) o PDF na nuvem e devolve um identificador.
     * Devolve null quando não está configurado.
     */
    public static function enviarPdf(string $caminhoAbsoluto, string $nome, string $uuid): ?string
    {
        if (!self::configurado()) {
            return null;
        }
        if (!is_file($caminhoAbsoluto)) {
            throw new \RuntimeException('PDF não encontrado para envio.');
        }

        $bin = self::bin();
        $conf = self::configPath();
        $dest = self::remote() . ':' . self::dir() . '/' . $nome;

        $cmd = sprintf(
            'timeout 120 %s copyto %s %s --config %s --timeout 60s --retries 1 --low-level-retries 2 2>&1',
            escapeshellcmd($bin),
            escapeshellarg($caminhoAbsoluto),
            escapeshellarg($dest),
            escapeshellarg($conf)
        );
        exec($cmd, $saida, $codigo);
        if ($codigo !== 0) {
            throw new \RuntimeException('Falha no envio (rclone): ' . trim(implode("\n", $saida)));
        }

        // Identificador do arquivo (para registrar e não reenviar à toa)
        $cmdId = sprintf(
            'timeout 60 %s lsjson %s --files-only --config %s 2>/dev/null',
            escapeshellcmd($bin),
            escapeshellarg(self::remote() . ':' . self::dir()),
            escapeshellarg($conf)
        );
        exec($cmdId, $outId, $codeId);
        if ($codeId === 0) {
            $lista = json_decode(implode("\n", $outId), true);
            if (is_array($lista)) {
                foreach ($lista as $f) {
                    if (($f['Name'] ?? '') === $nome && !empty($f['ID'])) {
                        return (string)$f['ID'];
                    }
                }
            }
        }

        return 'rclone:' . $nome; // subiu, mas sem ID — marca como enviado
    }
}
