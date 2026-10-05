<?php

namespace App\Controllers;

use App\Database;
use App\Helpers\Response;
use App\Middleware;

class CatalogoController
{
    /**
     * GET /api/catalogo — devolve todos os catálogos + textos do modelo APR/PT.
     * Qualquer usuário autenticado (o técnico precisa para preencher).
     */
    public static function index(): void
    {
        Middleware::requireAuth();
        $db = Database::getInstance();

        $riscos = $db->query(
            'SELECT codigo, categoria, nr, descricao, fonte, consequencia, medidas, severidade, probabilidade
             FROM catalogo_riscos WHERE ativo = 1
             ORDER BY categoria, ordem, id'
        )->fetchAll();

        $epis = $db->query(
            'SELECT codigo, descricao, nr, ordem FROM catalogo_epis WHERE ativo = 1 ORDER BY ordem, id'
        )->fetchAll();

        $epcs = $db->query(
            'SELECT codigo, descricao, nr, ordem FROM catalogo_epc WHERE ativo = 1 ORDER BY ordem, id'
        )->fetchAll();

        $ferramentas = $db->query(
            'SELECT codigo, descricao, ordem FROM catalogo_ferramentas WHERE ativo = 1 ORDER BY ordem, id'
        )->fetchAll();

        $checklist = $db->query(
            'SELECT codigo, descricao, grupo, critico, nr, ordem FROM catalogo_checklist
             WHERE ativo = 1 AND grupo = \'PT\' ORDER BY ordem, id'
        )->fetchAll();

        $textos = [];
        foreach ($db->query('SELECT chave, valor FROM config_textos')->fetchAll() as $t) {
            $textos[$t['chave']] = $t['valor'];
        }

        Response::success([
            'riscos' => $riscos,
            'epis' => $epis,
            'epcs' => $epcs,
            'ferramentas' => $ferramentas,
            'checklist' => $checklist,
            'textos' => $textos,
        ]);
    }
}
