<?php

namespace App\Services;

use Exception;

/**
 * Cliente HTTP para a API WebService v1 do IXC Soft (somente leitura nesta fase).
 *
 * Documentação: https://wikiapiprovedor.ixcsoft.com.br/
 * - Base: https://<host>/webservice/v1/<recurso>
 * - Auth: Authorization: Basic base64(<IXC_USER>:<IXC_TOKEN>)
 * - Listagem: header "ixcsoft: listar" + body {qtype, query, oper, page, rp, sortname, sortorder}
 */
class IxcService
{
    private string $baseUrl;
    private string $user;
    private string $token;
    private bool $verifySsl;
    private int $pageSize;

    public function __construct()
    {
        $this->baseUrl = rtrim($_ENV['IXC_BASE_URL'] ?? '', '/');
        $this->user = $_ENV['IXC_USER'] ?? '';
        $this->token = $_ENV['IXC_TOKEN'] ?? '';
        $this->verifySsl = filter_var($_ENV['IXC_VERIFY_SSL'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $this->pageSize = (int)($_ENV['IXC_PAGE_SIZE'] ?? 100);
    }

    public function isConfigured(): bool
    {
        return $this->baseUrl !== '' && $this->user !== '' && $this->token !== '';
    }

    /**
     * Lista registros de um recurso.
     *
     * @param string $resource  Ex.: "su_oss_chamado", "funcionarios"
     * @param array  $filters   [['field' => 'su_oss_chamado.id_tecnico', 'value' => 5, 'oper' => '='], ...]
     * @param int    $page
     * @param int    $rp       Registros por página
     * @param string|null $sortname
     * @param string $sortorder asc|desc
     * @return array{total:int, registros:array}
     */
    public function list(
        string $resource,
        array $filters = [],
        int $page = 1,
        int $rp = 0,
        ?string $sortname = null,
        string $sortorder = 'asc'
    ): array {
        $body = [
            'page' => (string)$page,
            'rp' => (string)($rp ?: $this->pageSize),
            'sortorder' => $sortorder,
        ];

        $sortname = $sortname ?? $resource . '.id';
        $body['sortname'] = $sortname;

        if (!empty($filters)) {
            $qtype = [];
            $query = [];
            $oper = [];
            foreach ($filters as $f) {
                $qtype[] = $f['field'];
                $query[] = (string)$f['value'];
                $oper[] = $f['oper'] ?? '=';
            }
            // IXC aceita escalar (1 filtro) ou arrays (N filtros)
            $body['qtype'] = count($qtype) === 1 ? $qtype[0] : $qtype;
            $body['query'] = count($query) === 1 ? $query[0] : $query;
            $body['oper'] = count($oper) === 1 ? $oper[0] : $oper;
        }

        $response = $this->request('POST', $resource, $body, ['ixcsoft: listar']);

        return [
            'total' => (int)($response['total'] ?? 0),
            'registros' => $response['registros'] ?? [],
            'raw' => $response,
        ];
    }

    /**
     * Percorre todas as páginas de um recurso respeitando os filtros.
     */
    public function listAll(string $resource, array $filters = [], ?string $sortname = null, int $maxPages = 200): array
    {
        $all = [];
        $page = 1;
        do {
            $result = $this->list($resource, $filters, $page, $this->pageSize, $sortname);
            $rows = $result['registros'];
            $all = array_merge($all, $rows);
            $total = $result['total'];
            $page++;
        } while (count($all) < $total && !empty($rows) && $page <= $maxPages);

        return $all;
    }

    public function get(string $resource, int|string $id): array
    {
        return $this->request('GET', $resource . '/' . $id, null);
    }

    public function post(string $resource, array $data): array
    {
        return $this->request('POST', $resource, $data);
    }

    public function put(string $resource, int|string $id, array $data): array
    {
        return $this->request('PUT', $resource . '/' . $id, $data);
    }

    public function delete(string $resource, int|string $id): array
    {
        return $this->request('DELETE', $resource . '/' . $id, null);
    }

    /**
     * Executa a requisição HTTP e devolve o JSON decodificado.
     *
     * @throws Exception
     */
    private function request(string $method, string $path, ?array $body, array $extraHeaders = []): array
    {
        if (!$this->isConfigured()) {
            throw new Exception('Integração IXC não configurada (IXC_BASE_URL/IXC_USER/IXC_TOKEN).');
        }

        $url = $this->baseUrl . '/' . ltrim($path, '/');
        $ch = curl_init($url);

        $headers = array_merge([
            'Content-Type: application/json',
            'Authorization: Basic ' . base64_encode($this->user . ':' . $this->token),
        ], $extraHeaders);

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_SSL_VERIFYPEER => $this->verifySsl,
            CURLOPT_SSL_VERIFYHOST => $this->verifySsl ? 2 : 0,
            CURLOPT_TIMEOUT => 60,
            CURLOPT_CONNECTTIMEOUT => 15,
        ]);

        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body, JSON_UNESCAPED_UNICODE));
        }

        $raw = curl_exec($ch);
        $errno = curl_errno($ch);
        $error = curl_error($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($errno !== 0) {
            throw new Exception("Falha de conexão com o IXC: {$error}");
        }

        if ($httpCode === 401) {
            throw new Exception('IXC retornou 401 (não autorizado). Verifique IXC_USER e IXC_TOKEN.');
        }

        $decoded = json_decode((string)$raw, true);

        if (!is_array($decoded)) {
            throw new Exception("Resposta inválida do IXC (HTTP {$httpCode}): " . substr((string)$raw, 0, 300));
        }

        // Erros de negócio do IXC costumam vir como {"type":"error","message":"..."}
        if (isset($decoded['type']) && $decoded['type'] === 'error') {
            throw new Exception('IXC: ' . ($decoded['message'] ?? 'erro desconhecido'));
        }

        return $decoded;
    }
}
