<?php
declare(strict_types=1);

/**
 * REST-API: index.php?r=api&p=/pfad  (Methode = HTTP-Verb, Authorization: Bearer <Token>).
 * Antwort: {"ok": true, "data": …} bzw. {"ok": false, "error": "…"} mit passendem HTTP-Status.
 */
function ctrl_api(string $r, string $a): void
{
    define('API_REQUEST', true); // Fehler der Anwendung (abort) kommen als JSON
    header('Cache-Control: no-store');
    $method = (string)($_SERVER['REQUEST_METHOD'] ?? 'GET');
    $path = '/' . trim((string)($_GET['p'] ?? ''), '/');

    if ($path === '/openapi.json' && $method === 'GET') {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(ApiSpec::openapi(site_base_url() . 'index.php'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        return;
    }
    if (!ApiTokens::secureTransport()) {
        json_out(['ok' => false, 'error' => t('api.err_https')], 400);
    }
    $auth = ApiTokens::login();
    if (!$auth) {
        header('WWW-Authenticate: Bearer realm="funeralorg"');
        json_out(['ok' => false, 'error' => t('api.err_token')], 401);
    }
    $scope = (string)$auth['token']['scope'];
    ApiService::$scope = $scope;

    [$op, $params] = ApiSpec::match($method, $path);
    if (!$op) {
        if ($params) {
            header('Allow: ' . implode(', ', array_unique($params)));
            json_out(['ok' => false, 'error' => t('api.err_method')], 405);
        }
        json_out(['ok' => false, 'error' => t('error.404')], 404);
    }
    if ($op['write'] && $scope !== 'write') {
        json_out(['ok' => false, 'error' => t('api.err_scope')], 403);
    }
    if (!ApiSpec::available($op, $scope)) {
        json_out(['ok' => false, 'error' => t('perm.denied')], 403);
    }

    $body = [];
    if (in_array($method, ['POST', 'PUT', 'PATCH'], true)) {
        if (stripos((string)($_SERVER['CONTENT_TYPE'] ?? ''), 'json') !== false) {
            $raw = (string)file_get_contents('php://input', false, null, 0, 1048576);
            $body = $raw === '' ? [] : json_decode($raw, true);
            if (!is_array($body)) {
                json_out(['ok' => false, 'error' => t('api.err_json')], 400);
            }
        } else {
            $body = $_POST;
        }
    }
    $query = array_diff_key($_GET, ['r' => 1, 'p' => 1]);
    $args = array_merge($query, $body, $params);

    try {
        $data = ([ApiService::class, $op['fn']])($args);
    } catch (ApiError $e) {
        json_out(['ok' => false, 'error' => $e->getMessage()], $e->status);
    }
    json_out(['ok' => true, 'data' => $data]);
}
