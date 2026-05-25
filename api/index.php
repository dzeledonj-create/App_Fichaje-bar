<?php
// ============================================================
//  index.php — Router principal (API REST + servir vistas)
//  Todos los requests pasan por aquí gracias a .htaccess
// ============================================================

declare(strict_types=1);

// ── Autoloader simple ─────────────────────────────────────────
spl_autoload_register(function (string $class): void {
    $map = [
        'Config\\'      => __DIR__ . '/config/',
        'Models\\'      => __DIR__ . '/models/',
        'Controllers\\' => __DIR__ . '/controllers/',
    ];
    foreach ($map as $ns => $dir) {
        if (str_starts_with($class, $ns)) {
            $file = $dir . str_replace('\\', '/', substr($class, strlen($ns))) . '.php';
            if (file_exists($file)) {
                require_once $file;
                return;
            }
        }
    }
});

// ── Zona horaria ──────────────────────────────────────────────
date_default_timezone_set('Europe/Madrid');

// ── CORS (ajustar en producción) ──────────────────────────────
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Token');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// ── Parsear ruta ──────────────────────────────────────────────
$uri    = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$script = $_SERVER['SCRIPT_NAME'] ?? '';
$base   = rtrim(dirname($script), '/\\');
if ($base !== '' && str_starts_with($uri, $base)) {
    $uri = substr($uri, strlen($base));
}
$uri = preg_replace('#^/index\.php#', '', $uri);
$uri = rtrim($uri, '/');
$uri = normalizeApiRoute($uri ?: '/');
$method = $_SERVER['REQUEST_METHOD'];

// Obtener token de cabecera
function getBearerToken(): ?string
{
    $headers = '';

    if (!empty($_SERVER['HTTP_AUTHORIZATION'])) {
        $headers = trim($_SERVER['HTTP_AUTHORIZATION']);
    } elseif (!empty($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
        $headers = trim($_SERVER['REDIRECT_HTTP_AUTHORIZATION']);
    } elseif (function_exists('apache_request_headers')) {
        $requestHeaders = apache_request_headers();
        if (!empty($requestHeaders['Authorization'])) {
            $headers = trim($requestHeaders['Authorization']);
        } elseif (!empty($requestHeaders['authorization'])) {
            $headers = trim($requestHeaders['authorization']);
        }
    } elseif (!empty($_SERVER['HTTP_X_TOKEN'])) {
        $headers = trim($_SERVER['HTTP_X_TOKEN']);
    }

    if (str_starts_with($headers, 'Bearer ')) {
        return substr($headers, 7);
    }

    if (!empty($_GET['token'])) {
        return (string) $_GET['token'];
    }

    return $headers ?: null;
}

// Middleware de autenticación
function requireAuth(string $requiredRole = 'empleado'): array
{
    $token = getBearerToken();
    if (!$token) {
        jsonResponse(['success' => false, 'message' => 'No autenticado.'], 401);
    }

    $auth = new Controllers\AuthController();
    $user = $auth->validateToken($token);

    if (!$user) {
        jsonResponse(['success' => false, 'message' => 'Sesión expirada o inválida.'], 401);
    }

    if ($requiredRole === 'admin' && $user['rol'] !== 'admin') {
        jsonResponse(['success' => false, 'message' => 'Acceso denegado.'], 403);
    }

    return $user;
}

// Helper de respuesta JSON
function jsonResponse(array $data, int $code = 200): never
{
    header('Content-Type: application/json; charset=utf-8');
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

// Leer cuerpo JSON
function getJsonBody(): array
{
    $raw = file_get_contents('php://input');
    return json_decode($raw, true) ?? [];
}

function normalizeApiRoute(string $uri): string
{
    $uri = '/' . trim($uri, '/');
    if ($uri === '/admin') {
        return $uri;
    }
    foreach (['auth', 'time', 'admin'] as $prefix) {
        if ($uri === '/' . $prefix || str_starts_with($uri, '/' . $prefix . '/')) {
            return '/api' . $uri;
        }
    }
    return $uri;
}

// ── RUTAS ─────────────────────────────────────────────────────

// -- Vistas HTML --
if ($uri === '' || $uri === '/') {
    include __DIR__ . '/views/login.html';
    exit;
}
if ($uri === '/empleado') {
    include __DIR__ . '/views/employee.html';
    exit;
}
if ($uri === '/admin') {
    include __DIR__ . '/views/admin.html';
    exit;
}

// -- API --

// POST /api/auth/login
if ($uri === '/api/auth/login' && $method === 'POST') {
    $body = getJsonBody();
    $auth = new Controllers\AuthController();
    jsonResponse($auth->login($body['email'] ?? '', $body['password'] ?? ''));
}

// POST /api/auth/logout
if ($uri === '/api/auth/logout' && $method === 'POST') {
    $token = getBearerToken();
    if ($token) {
        (new Controllers\AuthController())->logout($token);
    }
    jsonResponse(['success' => true, 'message' => 'Sesión cerrada.']);
}

// GET /api/auth/me
if ($uri === '/api/auth/me' && $method === 'GET') {
    $user = requireAuth();
    jsonResponse(['success' => true, 'user' => $user]);
}

// GET /api/time/status
if ($uri === '/api/time/status' && $method === 'GET') {
    $user   = requireAuth();
    $ctrl   = new Controllers\TimeController();
    jsonResponse(['success' => true, 'data' => $ctrl->getStatus((int)$user['id'])]);
}

// POST /api/time/clock-in
if ($uri === '/api/time/clock-in' && $method === 'POST') {
    $user  = requireAuth();
    $body  = getJsonBody();
    $ctrl  = new Controllers\TimeController();
    $firma = [
        'nombre'    => $body['nombre']    ?? '',
        'apellidos' => $body['apellidos'] ?? '',
        'dni'       => $body['dni']       ?? '',
    ];
    jsonResponse($ctrl->clockIn((int)$user['id'], $firma));
}

// POST /api/time/clock-out
if ($uri === '/api/time/clock-out' && $method === 'POST') {
    $user  = requireAuth();
    $body  = getJsonBody();
    $ctrl  = new Controllers\TimeController();
    $firma = [
        'nombre'    => $body['nombre']    ?? '',
        'apellidos' => $body['apellidos'] ?? '',
        'dni'       => $body['dni']       ?? '',
    ];
    jsonResponse($ctrl->clockOut((int)$user['id'], $firma));
}

// GET /api/time/history?page=1
if ($uri === '/api/time/history' && $method === 'GET') {
    $user = requireAuth();
    $ctrl = new Controllers\TimeController();
    $page = max(1, (int)($_GET['page'] ?? 1));
    jsonResponse(['success' => true, 'data' => $ctrl->getHistory((int)$user['id'], $page)]);
}

// GET /api/time/records?year=&month=
if ($uri === '/api/time/records' && $method === 'GET') {
    $user = requireAuth('empleado');
    $year = !empty($_GET['year'])  ? (int) $_GET['year']  : null;
    $month= !empty($_GET['month']) ? (int) $_GET['month'] : null;
    $ctrl = new Controllers\TimeController();
    jsonResponse(['success' => true, 'data' => $ctrl->getHistoryRecords((int)$user['id'], $year, $month)]);
}

// GET /api/admin/dashboard
if ($uri === '/api/admin/dashboard' && $method === 'GET') {
    requireAuth('admin');
    $ctrl = new Controllers\AdminController();
    jsonResponse(['success' => true, 'data' => $ctrl->getDashboard()]);
}

// GET /api/admin/records?user_id=&year=&month=
if ($uri === '/api/admin/records' && $method === 'GET') {
    requireAuth('admin');
    $ctrl   = new Controllers\AdminController();
    $userId = !empty($_GET['user_id']) ? (int)$_GET['user_id'] : null;
    $year   = !empty($_GET['year'])    ? (int)$_GET['year']    : null;
    $month  = !empty($_GET['month'])   ? (int)$_GET['month']   : null;
    jsonResponse(['success' => true, 'data' => $ctrl->getRecords($userId, $year, $month)]);
}

// POST /api/admin/employees
if ($uri === '/api/admin/employees' && $method === 'POST') {
    requireAuth('admin');
    $body = getJsonBody();
    $ctrl = new Controllers\AdminController();
    jsonResponse($ctrl->createEmployee($body));
}

// PUT /api/admin/employees/{id}/toggle
if (preg_match('#^/api/admin/employees/(\d+)/toggle$#', $uri, $m) && $method === 'PUT') {
    requireAuth('admin');
    $body = getJsonBody();
    $ctrl = new Controllers\AdminController();
    jsonResponse($ctrl->toggleEmployee((int)$m[1], (bool)($body['active'] ?? false)));
}

// PUT /api/admin/employees/{id}
if (preg_match('#^/api/admin/employees/(\d+)$#', $uri, $m) && $method === 'PUT') {
    requireAuth('admin');
    $body = getJsonBody();
    $ctrl = new Controllers\AdminController();
    jsonResponse($ctrl->updateEmployee((int)$m[1], $body));
}

// ── GET /api/admin/export/pdf — Generar PDF (usando TCPDF) ───
if ($uri === '/api/admin/export/pdf' && $method === 'GET') {
    requireAuth('admin');
    include __DIR__ . '/api/export_pdf.php';
    exit;
}

// ── GET /api/time/export/pdf — Exportar PDF de horas del empleado ───
if ($uri === '/api/time/export/pdf' && $method === 'GET') {
    $user = requireAuth('empleado');
    // For employee export, force the current user id even if no query param is provided.
    if (empty($_GET['user_id'])) {
        $_GET['user_id'] = (string)$user['id'];
    }
    include __DIR__ . '/api/export_pdf.php';
    exit;
}

// ── 404 ───────────────────────────────────────────────────────
jsonResponse(['success' => false, 'message' => 'Ruta no encontrada.'], 404);