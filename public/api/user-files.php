<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

$config = require dirname(__DIR__, 2) . '/bootstrap_web.php';

use App\Database\Connection;
use App\Repositories\UserFileRepository;
use App\Repositories\UserRepository;
use App\Services\AuthService;

const MAX_UPLOAD_BYTES = 100 * 1024 * 1024; // 100 MiB

/** @return array<int, string> */
function colmena_user_file_allowed_extensions(): array
{
    return [
        'pdf', 'txt', 'md', 'csv', 'json',
        'png', 'jpg', 'jpeg', 'gif', 'webp',
        'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx',
        'odt', 'ods', 'zip',
    ];
}

function colmena_user_uploads_dir(string $projectRoot): string
{
    return $projectRoot . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'user_uploads';
}

function colmena_sanitize_original_filename(string $name): string
{
    $base = basename(str_replace(["\0", '/', '\\'], '', $name));
    $base = preg_replace('/[\x00-\x1F\x7F]/u', '', $base) ?? '';
    if ($base === '') {
        return 'archivo';
    }
    if (strlen($base) > 200) {
        $base = substr($base, 0, 200);
    }

    return $base;
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

try {
    if (!file_exists($config['db']['path'])) {
        throw new RuntimeException('Base de datos no inicializada.');
    }
    $projectRoot = dirname(__DIR__, 2);
    $uploadDir = colmena_user_uploads_dir($projectRoot);
    if (!is_dir($uploadDir)) {
        if (!@mkdir($uploadDir, 0755, true) && !is_dir($uploadDir)) {
            throw new RuntimeException('No se pudo crear el directorio de subidas.');
        }
    }

    $pdo = Connection::get($config);
    $auth = new AuthService(new UserRepository($pdo));
    $userId = $auth->userId();
    if ($userId === null) {
        http_response_code(401);
        echo json_encode(['ok' => false, 'error' => 'Debes iniciar sesión'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $repo = new UserFileRepository($pdo);

    if ($method === 'GET') {
        $list = $repo->listForUser($userId);
        echo json_encode(['ok' => true, 'files' => $list], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($method === 'POST') {
        if (empty($_FILES['file']) || !is_array($_FILES['file'])) {
            http_response_code(422);
            echo json_encode(['ok' => false, 'error' => 'Falta el archivo (campo file).'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        $err = (int) ($_FILES['file']['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($err !== UPLOAD_ERR_OK) {
            http_response_code(422);
            echo json_encode(['ok' => false, 'error' => 'Error al subir el archivo (código ' . $err . ').'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        $size = (int) ($_FILES['file']['size'] ?? 0);
        if ($size < 1 || $size > MAX_UPLOAD_BYTES) {
            http_response_code(422);
            echo json_encode(['ok' => false, 'error' => 'Tamaño no permitido (máx. 100 MB).'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        $tmp = (string) ($_FILES['file']['tmp_name'] ?? '');
        if ($tmp === '' || !is_uploaded_file($tmp)) {
            http_response_code(422);
            echo json_encode(['ok' => false, 'error' => 'Archivo temporal no válido.'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        $orig = colmena_sanitize_original_filename((string) ($_FILES['file']['name'] ?? 'archivo'));
        $ext = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
        $allowed = colmena_user_file_allowed_extensions();
        if ($ext === '' || !in_array($ext, $allowed, true)) {
            http_response_code(422);
            echo json_encode(
                ['ok' => false, 'error' => 'Tipo de archivo no permitido. Extensiones: ' . implode(', ', $allowed) . '.'],
                JSON_UNESCAPED_UNICODE
            );
            exit;
        }
        $mime = isset($_FILES['file']['type']) ? trim((string) $_FILES['file']['type']) : '';
        if ($mime === '') {
            $mime = null;
        } elseif (strlen($mime) > 120) {
            $mime = substr($mime, 0, 120);
        }

        $stored = bin2hex(random_bytes(24));
        $dest = $uploadDir . DIRECTORY_SEPARATOR . $stored;
        if (!@move_uploaded_file($tmp, $dest)) {
            http_response_code(500);
            echo json_encode(['ok' => false, 'error' => 'No se pudo guardar el archivo.'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        try {
            $id = $repo->create($userId, $orig, $stored, $mime, $size);
        } catch (Throwable $e) {
            @unlink($dest);
            throw $e;
        }

        echo json_encode(['ok' => true, 'file' => ['id' => $id, 'original_name' => $orig, 'size_bytes' => $size]], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($method === 'DELETE') {
        $raw = file_get_contents('php://input');
        $data = json_decode($raw ?: '{}', true);
        if (!is_array($data)) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'JSON inválido'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        $fileId = isset($data['file_id']) ? (int) $data['file_id'] : 0;
        if ($fileId < 1) {
            http_response_code(422);
            echo json_encode(['ok' => false, 'error' => 'file_id es obligatorio'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        $storedName = $repo->deleteByIdForUser($fileId, $userId);
        if ($storedName === null) {
            http_response_code(404);
            echo json_encode(['ok' => false, 'error' => 'Archivo no encontrado'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        $path = $uploadDir . DIRECTORY_SEPARATOR . $storedName;
        if (is_file($path)) {
            @unlink($path);
        }
        echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
        exit;
    }

    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Método no permitido'], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
