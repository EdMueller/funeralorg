<?php
declare(strict_types=1);

/**
 * MCP-Endpunkt (Streamable HTTP, zustandslos): index.php?r=mcp, POST mit JSON-RPC, Authorization: Bearer <Token>.
 */
function ctrl_mcp(string $r, string $a): void
{
    define('API_REQUEST', true);
    header('Cache-Control: no-store');
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        header('Allow: POST');
        json_out(['ok' => false, 'error' => t('api.err_method')], 405);
    }
    // Schutz vor DNS-Rebinding: ein Origin-Header muss zum eigenen Host passen
    $origin = (string)($_SERVER['HTTP_ORIGIN'] ?? '');
    if ($origin !== '') {
        $oh = (string)parse_url($origin, PHP_URL_HOST);
        $hh = (string)preg_replace('/:\d+$/', '', (string)($_SERVER['HTTP_HOST'] ?? ''));
        if ($oh === '' || strcasecmp($oh, $hh) !== 0) {
            json_out(['ok' => false, 'error' => 'Origin'], 403);
        }
    }
    if (!ApiTokens::secureTransport()) {
        json_out(['ok' => false, 'error' => t('api.err_https')], 400);
    }
    $auth = ApiTokens::login();
    if (!$auth) {
        header('WWW-Authenticate: Bearer realm="funeralorg"');
        json_out(['jsonrpc' => '2.0', 'id' => null, 'error' => ['code' => -32001, 'message' => t('api.err_token')]], 401);
    }
    $scope = (string)$auth['token']['scope'];
    ApiService::$scope = $scope;

    $msg = json_decode((string)file_get_contents('php://input', false, null, 0, 1048576), true);
    if ($msg === null || !is_array($msg)) {
        json_out(['jsonrpc' => '2.0', 'id' => null, 'error' => ['code' => -32700, 'message' => 'Parse error']], 400);
    }
    $batch = array_is_list($msg) && $msg !== [];
    $out = [];
    foreach ($batch ? $msg : [$msg] as $m) {
        $res = Mcp::handle($m, $scope);
        if ($res !== null) {
            $out[] = $res;
        }
    }
    if (!$out) {
        http_response_code(202);
        return;
    }
    http_response_code(200);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($batch ? $out : $out[0], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
