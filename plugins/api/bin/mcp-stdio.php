#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * stdio-Brücke für MCP-Clients, die nur lokale Server starten (z. B. Claude Desktop).
 * Liest JSON-RPC-Nachrichten (eine pro Zeile) von stdin, schickt sie an den MCP-Endpunkt der Anwendung und schreibt
 * die Antworten nach stdout.
 *
 *   FUNERALORG_URL    Adresse des Endpunkts, z. B. https://example.org/index.php?r=mcp
 *   FUNERALORG_TOKEN  Zugriffstoken (Verwaltung unter „API-Zugang“)
 *
 * Läuft auf dem Rechner des Clients (PHP ≥ 8.2 mit allow_url_fopen), nicht auf dem Server der Anwendung.
 */
$url = (string)getenv('FUNERALORG_URL');
$token = (string)getenv('FUNERALORG_TOKEN');
if ($url === '' || $token === '') {
    fwrite(STDERR, "FUNERALORG_URL und FUNERALORG_TOKEN müssen gesetzt sein.\n");
    exit(1);
}

while (($line = fgets(STDIN)) !== false) {
    $line = trim($line);
    if ($line === '') {
        continue;
    }
    $id = null;
    $msg = json_decode($line, true);
    if (is_array($msg) && !array_is_list($msg)) {
        $id = $msg['id'] ?? null;
    }
    $ctx = stream_context_create(['http' => [
        'method' => 'POST',
        'header' => "Content-Type: application/json\r\nAccept: application/json\r\nAuthorization: Bearer $token\r\n",
        'content' => $line,
        'ignore_errors' => true,
        'timeout' => 60,
    ]]);
    $body = @file_get_contents($url, false, $ctx);
    $status = 0;
    foreach ($http_response_header ?? [] as $h) {
        if (preg_match('#^HTTP/\S+\s+(\d{3})#', $h, $m)) {
            $status = (int)$m[1];
        }
    }
    if ($status === 202 || ($body === '' && $status < 400 && $status !== 0)) {
        continue; // Notification, keine Antwort
    }
    $decoded = $body === false ? null : json_decode($body, true);
    if (is_array($decoded) && (isset($decoded['jsonrpc']) || array_is_list($decoded))) {
        fwrite(STDOUT, json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");
    } elseif ($id !== null) {
        $text = is_array($decoded) ? (string)($decoded['error'] ?? 'Fehler') : 'Keine Verbindung zum Server';
        fwrite(STDOUT, json_encode(['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => -32000, 'message' => $text . ($status ? " (HTTP $status)" : '')]], JSON_UNESCAPED_UNICODE) . "\n");
    }
}
