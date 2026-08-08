<?php

/**
 * VCMS — Village Cooperative Management System
 * app/routes/api.php — Route table and dispatcher
 *
 * This file is required (not namespaced) directly by public/index.php.
 *
 * Route entry format:
 *   [ METHOD, '/path/pattern/{param}', 'ControllerClass@method', [ roles ] ]
 *
 * roles:
 *   []               — public (no authentication required)
 *   ['auth']         — any authenticated admin
 *   ['Super_Admin']  — Super Admin only
 *
 * Middleware execution order: AuthMiddleware → CsrfMiddleware → RbacMiddleware
 */

declare(strict_types=1);

use App\Helpers\Response;
use App\Middleware\AuthMiddleware;
use App\Middleware\CsrfMiddleware;
use App\Middleware\RbacMiddleware;

// ─── Route table ─────────────────────────────────────────────────────────────

$routes = [

    // ── Auth ─────────────────────────────────────────────────────────────────
    ['POST', '/auth/login',           'AuthController@login',          []],
    ['POST', '/auth/logout',          'AuthController@logout',         ['auth']],
    ['GET',  '/auth/csrf-token',      'AuthController@csrfToken',      []],
    ['GET',  '/auth/me',              'AuthController@me',             ['auth']],
    ['PUT',  '/auth/change-password', 'AuthController@changePassword', ['auth']],

    // ── Admins (Super_Admin only) ─────────────────────────────────────────────
    ['GET',   '/admins',              'AdminController@index',        ['Super_Admin']],
    ['POST',  '/admins',              'AdminController@store',        ['Super_Admin']],
    ['GET',   '/admins/{id}',         'AdminController@show',         ['Super_Admin']],
    ['PUT',   '/admins/{id}',         'AdminController@update',       ['Super_Admin']],
    ['PATCH', '/admins/{id}/status',  'AdminController@setStatus',    ['Super_Admin']],

    // ── Members ───────────────────────────────────────────────────────────────
    ['GET',    '/members',                    'MemberController@index',     ['auth']],
    ['POST',   '/members',                    'MemberController@store',     ['auth']],
    ['GET',    '/members/{id}',               'MemberController@show',      ['auth']],
    ['PUT',    '/members/{id}',               'MemberController@update',    ['auth']],
    ['DELETE', '/members/{id}',               'MemberController@destroy',   ['auth']],
    ['GET',    '/members/{id}/statement',     'MemberController@statement', ['auth']],

    // ── Accounting Periods ────────────────────────────────────────────────────
    // NOTE: '/accounting-periods/current' must be listed BEFORE '/accounting-periods/{id}/reopen'
    // so the literal segment "current" is matched first.
    ['GET',  '/accounting-periods',              'AccountingPeriodController@index',      ['auth']],
    ['GET',  '/accounting-periods/current',      'AccountingPeriodController@current',    ['auth']],
    ['POST', '/accounting-periods/month-close',  'AccountingPeriodController@monthClose', ['auth']],
    ['POST', '/accounting-periods/{id}/reopen',  'AccountingPeriodController@reopen',     ['Super_Admin']],

    // ── Savings ───────────────────────────────────────────────────────────────
    ['GET',  '/savings/bulk-screen',   'SavingsController@bulkScreen',   ['auth']],
    ['POST', '/savings/bulk-collect',  'SavingsController@bulkCollect',  ['auth']],
    ['GET',  '/savings',               'SavingsController@index',        ['auth']],

    // ── Loans ─────────────────────────────────────────────────────────────────
    ['GET',   '/loans',                    'LoanController@index',         ['auth']],
    ['POST',  '/loans',                    'LoanController@store',         ['auth']],
    ['GET',   '/loans/{id}',               'LoanController@show',          ['auth']],
    ['PUT',   '/loans/{id}',               'LoanController@update',        ['auth']],
    ['PATCH', '/loans/{id}/cancel',        'LoanController@cancel',        ['auth']],
    ['POST',  '/loans/{id}/repayments',    'LoanController@addRepayment',  ['auth']],
    ['GET',   '/loans/{id}/repayments',    'LoanController@repayments',    ['auth']],

    // ── Cash & Bank ───────────────────────────────────────────────────────────
    ['GET',  '/cash-bank/balances',      'CashBankController@balances',     ['auth']],
    ['POST', '/cash-bank/transfer',      'CashBankController@transfer',     ['auth']],
    ['GET',  '/cash-bank/transactions',  'CashBankController@transactions', ['auth']],

    // ── Dashboard ─────────────────────────────────────────────────────────────
    ['GET', '/dashboard', 'DashboardController@index', ['auth']],

    // ── Distribution ─────────────────────────────────────────────────────────
    // NOTE: Literals like '/distribution/current', '/distribution/generate-pdf',
    // '/distribution/confirm', '/distribution/history' must precede the
    // parameterised '/distribution/pdf/{cycle_id}' and '/distribution/history/{cycle_id}'.
    ['GET',  '/distribution/current',              'DistributionController@current',     ['auth']],
    ['POST', '/distribution/generate-pdf',         'DistributionController@generatePdf', ['auth']],
    ['GET',  '/distribution/pdf/{cycle_id}',       'DistributionController@downloadPdf', ['auth']],
    ['POST', '/distribution/confirm',              'DistributionController@confirm',     ['auth']],
    ['GET',  '/distribution/history',              'DistributionController@history',     ['auth']],
    ['GET',  '/distribution/history/{cycle_id}',   'DistributionController@historyDetail', ['auth']],

    // ── Reports ───────────────────────────────────────────────────────────────
    // NOTE: The export route '/reports/{type}/export' must come AFTER the
    // specific named report routes so that e.g. /reports/monthly is not
    // accidentally captured by {type}.
    ['GET', '/reports/monthly',      'ReportController@monthly',      ['auth']],
    ['GET', '/reports/loans',        'ReportController@loans',        ['auth']],
    ['GET', '/reports/cash-book',    'ReportController@cashBook',     ['auth']],
    ['GET', '/reports/bank-book',    'ReportController@bankBook',     ['auth']],
    ['GET', '/reports/savings',      'ReportController@savings',      ['auth']],
    ['GET', '/reports/interest',     'ReportController@interest',     ['auth']],
    ['GET', '/reports/distribution', 'ReportController@distribution', ['auth']],
    ['GET', '/reports/audit',        'ReportController@audit',        ['auth']],
    ['GET', '/reports/{type}/export','ReportController@export',       ['auth']],

    // ── Backup & Restore ──────────────────────────────────────────────────────
    ['GET',  '/backup/list',    'BackupController@list',    ['auth']],
    ['POST', '/backup/create',  'BackupController@create',  ['auth']],
    ['POST', '/backup/restore', 'BackupController@restore', ['auth']],

    // ── Settings ─────────────────────────────────────────────────────────────
    ['GET', '/settings', 'SettingsController@index',  ['auth']],
    ['PUT', '/settings', 'SettingsController@update', ['Super_Admin']],

    // ── Language ─────────────────────────────────────────────────────────────
    // NOTE: '/lang/{locale}' must come BEFORE any future parameterised lang routes.
    ['GET',  '/lang/{locale}',    'LangController@getLocale',     []],
    ['POST', '/lang/preference',  'LangController@setPreference', ['auth']],

];

// ─── URI parsing ─────────────────────────────────────────────────────────────

/**
 * Parse the incoming request URI, strip the query string, and normalise the
 * path so it starts with a single slash and has no trailing slash (unless root).
 *
 * Also strips the base path prefixes that Laragon/Apache may add when the app
 * lives in a subdirectory, e.g.:
 *   /Bachat/backend/public/api/v1/members  →  /members
 *   /api/v1/members                        →  /members
 */
$requestMethod = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
$rawUri        = $_SERVER['REQUEST_URI'] ?? '/';

// Remove query string
$uriPath = strtok($rawUri, '?');
if ($uriPath === false || $uriPath === '') {
    $uriPath = '/';
}

// Decode percent-encoding (e.g. %20 → space), but keep slashes as-is
$uriPath = rawurldecode($uriPath);

// Strip known base-path prefixes (longest first to avoid partial matches)
$basePrefixes = [
    '/Bachat/backend/public/api/v1',
    '/backend/public/api/v1',
    '/api/v1',
];

foreach ($basePrefixes as $prefix) {
    if (str_starts_with($uriPath, $prefix)) {
        $uriPath = substr($uriPath, strlen($prefix));
        break;
    }
}

// Ensure path starts with /
if ($uriPath === '' || $uriPath[0] !== '/') {
    $uriPath = '/' . $uriPath;
}

// Remove trailing slash (unless the path is just "/")
if (strlen($uriPath) > 1) {
    $uriPath = rtrim($uriPath, '/');
}

// ─── Route matching ───────────────────────────────────────────────────────────

/**
 * Match the parsed URI path + HTTP method against the route table.
 * Returns ['handler' => 'Controller@action', 'roles' => [], 'params' => []]
 * or null when no route matches.
 *
 * Path parameter segments have the form {name}; they match any non-empty,
 * non-slash sequence and are captured into $params[$name].
 *
 * Routes with no parameters (literals) are compared first via a fast exact
 * check; parameterised routes are then tried in declaration order.
 */
function matchRoute(string $method, string $path, array $routes): ?array
{
    // First pass: exact match (no params) — O(n) but cheap strcmp
    foreach ($routes as [$routeMethod, $routePath, $handler, $roles]) {
        if ($routeMethod !== $method) {
            continue;
        }
        if (strpos($routePath, '{') === false && $routePath === $path) {
            return ['handler' => $handler, 'roles' => $roles, 'params' => []];
        }
    }

    // Second pass: pattern match (routes with path parameters)
    foreach ($routes as [$routeMethod, $routePath, $handler, $roles]) {
        if ($routeMethod !== $method) {
            continue;
        }
        if (strpos($routePath, '{') === false) {
            continue; // already tried in pass 1
        }

        // Build a regex from the route pattern
        // {name} captures one or more non-slash characters
        $paramNames = [];
        $pattern = preg_replace_callback(
            '/\{(\w+)\}/',
            static function (array $m) use (&$paramNames): string {
                $paramNames[] = $m[1];
                return '([^/]+)';
            },
            $routePath
        );

        if ($pattern === null) {
            continue;
        }

        $regex = '#^' . $pattern . '$#u';

        if (preg_match($regex, $path, $matches) === 1) {
            array_shift($matches); // remove full-match entry
            $params = array_combine($paramNames, $matches);
            return [
                'handler' => $handler,
                'roles'   => $roles,
                'params'  => $params ?: [],
            ];
        }
    }

    return null;
}

$match = matchRoute($requestMethod, $uriPath, $routes);

if ($match === null) {
    Response::error('NOT_FOUND', 'Endpoint not found.', [], 404);
    exit;
}

['handler' => $handler, 'roles' => $roles, 'params' => $params] = $match;

// ─── Parse request body ───────────────────────────────────────────────────────

$body = [];
$contentType = strtolower(trim($_SERVER['CONTENT_TYPE'] ?? ''));

if (str_contains($contentType, 'application/json')) {
    $raw = file_get_contents('php://input');
    if ($raw !== '' && $raw !== false) {
        $decoded = json_decode($raw, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
            $body = $decoded;
        }
    }
} elseif (str_contains($contentType, 'multipart/form-data')
       || str_contains($contentType, 'application/x-www-form-urlencoded')) {
    // PHP already populates $_POST for these content types
    $body = $_POST;
}

// Merge query-string params into $params for GET convenience
// (controllers may also read $_GET directly)
if (!empty($_GET)) {
    // Avoid overwriting path params
    $params = array_merge($_GET, $params);
}

// ─── Middleware stack ─────────────────────────────────────────────────────────

// 1. AuthMiddleware — session validation + timeout
//    Skipped for public routes (empty $roles array)
if (!empty($roles)) {
    AuthMiddleware::handle();
}

// 2. CsrfMiddleware — token validation for state-changing requests
//    Applied to all routes (the middleware itself skips GET/HEAD/OPTIONS and
//    the public csrf-token endpoint)
CsrfMiddleware::handle();

// 3. RbacMiddleware — role-based access control
//    Only enforced when roles are specified
if (!empty($roles)) {
    RbacMiddleware::handle($roles);
}

// ─── Controller dispatch ──────────────────────────────────────────────────────

[$controllerClass, $actionMethod] = explode('@', $handler, 2);

// Build the fully-qualified class name (all controllers are in App\Controllers)
$fqcn = 'App\\Controllers\\' . $controllerClass;

// Resolve the controller file path
$controllerFile = APP_PATH . '/controllers/' . $controllerClass . '.php';

// Require the controller file if the class is not yet loaded
if (!class_exists($fqcn, false) && file_exists($controllerFile)) {
    require $controllerFile;
}

if (!class_exists($fqcn)) {
    Response::error('INTERNAL_ERROR', "Controller {$controllerClass} not found.", [], 500);
    exit;
}

if (!method_exists($fqcn, $actionMethod)) {
    Response::error('INTERNAL_ERROR', "Action {$controllerClass}::{$actionMethod} not found.", [], 500);
    exit;
}

// Call the controller action: Controller::action($params, $body)
$fqcn::$actionMethod($params, $body);
