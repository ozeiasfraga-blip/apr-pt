<?php

require_once __DIR__ . '/../../src/bootstrap.php';

use App\Middleware;
use App\Helpers\Response;

Middleware::cors();

$uri = $_SERVER['REQUEST_URI'] ?? '/';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

// Remove query string e a base "/api"
$uri = strtok($uri, '?');
$uri = rtrim($uri, '/') ?: '/';

global $routes;
$routes = ['GET' => [], 'POST' => [], 'PUT' => [], 'PATCH' => [], 'DELETE' => []];

function route(string $method, string $pattern, callable $handler): void
{
    global $routes;
    $routes[$method][$pattern] = $handler;
}

function matchRoute(string $method, string $uri): bool
{
    global $routes;
    if (!isset($routes[$method])) {
        return false;
    }
    foreach ($routes[$method] as $pattern => $handler) {
        $regex = preg_replace('/\{(\w+)\}/', '([^/]+)', $pattern);
        $regex = '#^' . $regex . '$#';
        if (preg_match($regex, $uri, $matches)) {
            array_shift($matches);
            $handler(...$matches);
            return true;
        }
    }
    return false;
}

// ── Auth ────────────────────────────────────────────────────
route('POST', '/api/auth/login', [\App\Controllers\AuthController::class, 'login']);
route('POST', '/api/auth/register', [\App\Controllers\AuthController::class, 'register']);
route('POST', '/api/auth/logout', [\App\Controllers\AuthController::class, 'logout']);
route('GET', '/api/auth/me', [\App\Controllers\AuthController::class, 'me']);
route('POST', '/api/auth/change-password', [\App\Controllers\AuthController::class, 'changePassword']);

// ── Ordens de serviço ───────────────────────────────────────
route('GET', '/api/os', [\App\Controllers\OrdemServicoController::class, 'index']);
route('GET', '/api/os/{id}', [\App\Controllers\OrdemServicoController::class, 'show']);

// ── Sincronização com o IXC ─────────────────────────────────
route('POST', '/api/sync/os', [\App\Controllers\SyncController::class, 'os']);
route('GET', '/api/ixc/funcionarios', [\App\Controllers\SyncController::class, 'funcionarios']);

// ── Usuários (supervisor/admin vê e gerencia) ───────────────
route('GET', '/api/users', [\App\Controllers\UserController::class, 'index']);
route('POST', '/api/users', [\App\Controllers\UserController::class, 'create']);
route('PUT', '/api/users/{id}', [\App\Controllers\UserController::class, 'update']);
route('POST', '/api/users/{id}/senha', [\App\Controllers\UserController::class, 'resetPassword']);
route('DELETE', '/api/users/{id}', [\App\Controllers\UserController::class, 'destroy']);

// ── Documentos APR/PT ───────────────────────────────────────
route('GET', '/api/catalogo', [\App\Controllers\CatalogoController::class, 'index']);
route('GET', '/api/documentos', [\App\Controllers\DocumentoSstController::class, 'index']);
route('POST', '/api/documentos', [\App\Controllers\DocumentoSstController::class, 'store']);
route('GET', '/api/documentos/{id}', [\App\Controllers\DocumentoSstController::class, 'show']);
route('POST', '/api/documentos/{id}/assinar', [\App\Controllers\DocumentoSstController::class, 'assinar']);
route('POST', '/api/documentos/{id}/aprovar', [\App\Controllers\DocumentoSstController::class, 'aprovar']);
route('POST', '/api/documentos/{id}/encerrar', [\App\Controllers\DocumentoSstController::class, 'encerrar']);
route('GET', '/api/documentos/{id}/pdf', [\App\Controllers\DocumentoSstController::class, 'pdf']);

// ── Healthcheck ─────────────────────────────────────────────
route('GET', '/api/arquivo', [\App\Controllers\ArquivoController::class, 'show']);

route('GET', '/api/health', function () {
    Response::success([
        'app' => $_ENV['APP_NAME'] ?? 'APR/PT',
        'time' => date('c'),
        'ixc_configurado' => (new \App\Services\IxcService())->isConfigured(),
    ], 'ok');
});

if (!matchRoute($method, $uri)) {
    Response::error('Rota não encontrada: ' . $method . ' ' . $uri, 404);
}
