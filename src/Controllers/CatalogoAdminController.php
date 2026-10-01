<?php

namespace App\Controllers;

use App\Database;
use App\Helpers\Response;
use App\Middleware;

class CatalogoAdminController
{
    /** Mapa tipo => tabela, campos editáveis e obrigatórios. */
    private const TIPOS = [
        'riscos' => [
            'tabela' => 'catalogo_riscos',
            'campos' => ['codigo', 'categoria', 'nr', 'descricao', 'fonte', 'consequencia', 'medidas', 'ordem', 'ativo'],
            'obrigatorios' => ['descricao'],
        ],
        'epis' => [
            'tabela' => 'catalogo_epis',
            'campos' => ['codigo', 'descricao', 'nr', 'ordem', 'ativo'],
            'obrigatorios' => ['descricao'],
        ],
        'epcs' => [
            'tabela' => 'catalogo_epc',
            'campos' => ['codigo', 'descricao', 'ordem', 'ativo'],
            'obrigatorios' => ['descricao'],
        ],
        'checklist' => [
            'tabela' => 'catalogo_checklist',
            'campos' => ['codigo', 'descricao', 'grupo', 'critico', 'ordem', 'ativo'],
            'obrigatorios' => ['descricao'],
        ],
    ];

    /** GET /api/modelo — tudo (inclusive inativos) + textos, para edição. */
    public static function index(): void
    {
        Middleware::requireSupervisor();
        $db = Database::getInstance();

        $listas = [];
        foreach (self::TIPOS as $slug => $cfg) {
            $listas[$slug] = $db->query("SELECT * FROM {$cfg['tabela']} ORDER BY ordem, id")->fetchAll();
        }

        $textos = [];
        foreach ($db->query('SELECT chave, valor, descricao FROM config_textos ORDER BY chave')->fetchAll() as $t) {
            $textos[] = $t;
        }

        Response::success(['listas' => $listas, 'textos' => $textos]);
    }

    /** POST /api/modelo/{tipo} */
    public static function create(string $tipo): void
    {
        Middleware::requireSupervisor();
        $cfg = self::cfg($tipo);
        $data = json_decode(file_get_contents('php://input'), true) ?? [];

        foreach ($cfg['obrigatorios'] as $campo) {
            if (empty($data[$campo])) {
                Response::error("O campo {$campo} é obrigatório.", 422);
            }
        }

        $campos = array_values(array_filter($cfg['campos'], fn($c) => array_key_exists($c, $data)));
        if (empty($campos)) {
            Response::error('Nenhum campo válido enviado.', 422);
        }

        $cols = implode(', ', $campos);
        $ph = implode(', ', array_map(fn($c) => ':' . $c, $campos));
        $stmt = Database::getInstance()->prepare("INSERT INTO {$cfg['tabela']} ({$cols}) VALUES ({$ph})");
        try {
            $stmt->execute(self::valores($campos, $data));
        } catch (\PDOException $e) {
            if ($e->getCode() === '23000') {
                Response::error('Já existe um item com este código.', 409);
            }
            throw $e;
        }

        Response::success(['id' => (int)Database::getInstance()->lastInsertId()], 'Item criado.', 201);
    }

    /** PUT /api/modelo/{tipo}/{id} */
    public static function update(string $tipo, string $id): void
    {
        Middleware::requireSupervisor();
        $cfg = self::cfg($tipo);
        $data = json_decode(file_get_contents('php://input'), true) ?? [];

        $campos = array_values(array_filter($cfg['campos'], fn($c) => array_key_exists($c, $data)));
        if (empty($campos)) {
            Response::error('Nenhum campo válido enviado.', 422);
        }

        $sets = implode(', ', array_map(fn($c) => "{$c} = :{$c}", $campos));
        $stmt = Database::getInstance()->prepare("UPDATE {$cfg['tabela']} SET {$sets} WHERE id = :id");
        $valores = self::valores($campos, $data);
        $valores[':id'] = (int)$id;
        try {
            $stmt->execute($valores);
        } catch (\PDOException $e) {
            if ($e->getCode() === '23000') {
                Response::error('Já existe um item com este código.', 409);
            }
            throw $e;
        }

        Response::success(null, 'Item atualizado.');
    }

    /** DELETE /api/modelo/{tipo}/{id} */
    public static function destroy(string $tipo, string $id): void
    {
        Middleware::requireSupervisor();
        $cfg = self::cfg($tipo);
        $stmt = Database::getInstance()->prepare("DELETE FROM {$cfg['tabela']} WHERE id = ?");
        $stmt->execute([(int)$id]);
        Response::success(null, 'Item removido.');
    }

    /** PUT /api/modelo/textos — { chave: valor, ... } */
    public static function saveTextos(): void
    {
        Middleware::requireSupervisor();
        $data = json_decode(file_get_contents('php://input'), true) ?? [];
        $db = Database::getInstance();
        $stmt = $db->prepare('UPDATE config_textos SET valor = ? WHERE chave = ?');

        $n = 0;
        foreach ($data as $chave => $valor) {
            $stmt->execute([(string)$valor, (string)$chave]);
            $n++;
        }
        Response::success(['atualizados' => $n], 'Textos atualizados.');
    }

    private static function cfg(string $tipo): array
    {
        if (!isset(self::TIPOS[$tipo])) {
            Response::error('Tipo inválido.', 404);
        }
        return self::TIPOS[$tipo];
    }

    private static function valores(array $campos, array $data): array
    {
        $out = [];
        foreach ($campos as $c) {
            $v = $data[$c];
            if (in_array($c, ['ativo', 'critico'], true)) {
                $v = (int)(bool)$v;
            } elseif ($c === 'ordem') {
                $v = (int)$v;
            } elseif ($v === '') {
                $v = null;
            }
            $out[':' . $c] = $v;
        }
        return $out;
    }
}
