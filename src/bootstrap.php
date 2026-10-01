<?php

require_once __DIR__ . '/../vendor/autoload.php';

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->load();

date_default_timezone_set($_ENV['APP_TIMEZONE'] ?? 'America/Sao_Paulo');

require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/Helpers/JWT.php';
require_once __DIR__ . '/Helpers/Response.php';
require_once __DIR__ . '/Helpers/Validator.php';
require_once __DIR__ . '/Middleware.php';
require_once __DIR__ . '/Services/IxcService.php';
require_once __DIR__ . '/Services/SyncService.php';
require_once __DIR__ . '/Services/StorageService.php';
require_once __DIR__ . '/Services/PdfService.php';
require_once __DIR__ . '/Models/User.php';
require_once __DIR__ . '/Models/Log.php';
require_once __DIR__ . '/Models/OrdemServico.php';
require_once __DIR__ . '/Models/DocumentoSst.php';
require_once __DIR__ . '/Models/Assinatura.php';
require_once __DIR__ . '/Controllers/AuthController.php';
require_once __DIR__ . '/Controllers/OrdemServicoController.php';
require_once __DIR__ . '/Controllers/DocumentoSstController.php';
require_once __DIR__ . '/Controllers/SyncController.php';
require_once __DIR__ . '/Controllers/UserController.php';
require_once __DIR__ . '/Controllers/CatalogoController.php';
require_once __DIR__ . '/Controllers/ArquivoController.php';
