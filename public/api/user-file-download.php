<?php

declare(strict_types=1);

$config = require dirname(__DIR__, 2) . '/bootstrap_web.php';

use App\Database\Connection;
use App\Repositories\UserFileRepository;
use App\Repositories\UserRepository;
use App\Services\AuthService;

function colmena_user_uploads_dir_dl(string $projectRoot): string
{
    return $projectRoot . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'user_uploads';
}

function colmena_disposition_filename(string $original): string
{
    $ascii = preg_replace('/[^\x20-\x7E]+/', '_', $original) ?? 'archivo';
    if ($ascii === '') {
        $ascii = 'archivo';
    }

    return str_replace(['"', '\\'], ['_', '_'], $ascii);
}

try {
    if (!file_exists($config['db']['path'])) {
        throw new RuntimeException('Base de datos no inicializada.');
    }
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
        http_response_code(405);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Método no permitido';
        exit;
    }

    $pdo = Connection::get($config);
    $auth = new AuthService(new UserRepository($pdo));
    $userId = $auth->userId();
    if ($userId === null) {
        http_response_code(401);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'No autenticado';
        exit;
    }

    $fileId = isset($_GET['id']) ? (int) $_GET['id'] : 0;
    if ($fileId < 1) {
        http_response_code(422);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Parámetro id inválido';
        exit;
    }

    $repo = new UserFileRepository($pdo);
    $row = $repo->findByIdForUser($fileId, $userId);
    if ($row === null) {
        http_response_code(404);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'No encontrado';
        exit;
    }

    $projectRoot = dirname(__DIR__, 2);
    $path = colmena_user_uploads_dir_dl($projectRoot) . DIRECTORY_SEPARATOR . $row['stored_name'];
    if (!is_file($path)) {
        http_response_code(404);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Fichero no disponible';
        exit;
    }

    $mime = $row['mime_type'] ?? '';
    if ($mime === '') {
        $mime = 'application/octet-stream';
    }
    $disp = colmena_disposition_filename($row['original_name']);
    $utf8Star = "filename*=UTF-8''" . rawurlencode($row['original_name']);

    header('Content-Type: ' . $mime);
    header('Content-Length: ' . (string) filesize($path));
    header('Content-Disposition: attachment; filename="' . $disp . '"; ' . $utf8Star);
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: private, max-age=0, must-revalidate');

    $fh = fopen($path, 'rb');
    if ($fh === false) {
        http_response_code(500);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Error al leer';
        exit;
    }
    fpassthru($fh);
    fclose($fh);
} catch (Throwable $e) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo $e->getMessage();
}
