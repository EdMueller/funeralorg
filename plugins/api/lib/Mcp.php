<?php
declare(strict_types=1);

/**
 * MCP-Server (Model Context Protocol), Transport „Streamable HTTP“ ohne Sitzung: JSON-RPC 2.0 per POST,
 * Antworten als application/json. Bereitgestellt werden Tools, abgeleitet aus ApiSpec; sichtbar und aufrufbar ist,
 * was Token-Bereich und Rechte des Benutzers erlauben.
 */
final class Mcp
{
    public const PROTOCOLS = ['2025-06-18', '2025-03-26', '2024-11-05'];

    public static function toolName(array $op): string
    {
        return str_replace('.', '_', $op['id']);
    }

    /** @return list<array> Tool-Beschreibungen für `tools/list`. */
    public static function tools(string $scope): array
    {
        $tools = [];
        foreach (ApiSpec::ops() as $op) {
            if ($op['raw'] || !ApiSpec::available($op, $scope)) {
                continue;
            }
            $tools[] = [
                'name' => self::toolName($op),
                'description' => $op['doc'] . '.',
                'inputSchema' => ApiSpec::schema($op['in']),
                'annotations' => [
                    'title' => $op['doc'],
                    'readOnlyHint' => !$op['write'],
                    'destructiveHint' => $op['destructive'],
                    'openWorldHint' => false,
                ],
            ];
        }
        if (Perm::gallery()) {
            $tools[] = [
                'name' => 'gallery_image_view',
                'description' => 'Ein Bild ansehen (Vorschau oder Großansicht als Bildinhalt).',
                'inputSchema' => ApiSpec::schema(['id!' => ['string', 'Bild-ID'], 'size' => ['string', 'thumb oder medium (Standard)', ['thumb', 'medium']]]),
                'annotations' => ['title' => 'Bild ansehen', 'readOnlyHint' => true, 'destructiveHint' => false, 'openWorldHint' => false],
            ];
        }
        return $tools;
    }

    private static function result(mixed $id, array $result): array
    {
        return ['jsonrpc' => '2.0', 'id' => $id, 'result' => $result];
    }

    private static function error(mixed $id, int $code, string $msg): array
    {
        return ['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => $code, 'message' => $msg]];
    }

    private static function text(string $text, bool $isError = false): array
    {
        return ['content' => [['type' => 'text', 'text' => $text]], 'isError' => $isError];
    }

    /** Verarbeitet eine JSON-RPC-Nachricht; null = keine Antwort (Notification oder Client-Antwort). */
    public static function handle(mixed $m, string $scope): ?array
    {
        if (!is_array($m) || ($m['jsonrpc'] ?? '') !== '2.0') {
            return self::error(null, -32600, 'Invalid Request');
        }
        $method = $m['method'] ?? null;
        $hasId = array_key_exists('id', $m) && $m['id'] !== null;
        if (!is_string($method)) {
            return null; // Antwort des Clients
        }
        if (!$hasId) {
            return null; // Notification (z. B. notifications/initialized)
        }
        $id = $m['id'];
        $p = is_array($m['params'] ?? null) ? $m['params'] : [];
        switch ($method) {
            case 'initialize':
                $want = (string)($p['protocolVersion'] ?? '');
                return self::result($id, [
                    'protocolVersion' => in_array($want, self::PROTOCOLS, true) ? $want : self::PROTOCOLS[0],
                    'capabilities' => ['tools' => ['listChanged' => false]],
                    'serverInfo' => ['name' => 'funeralorg', 'title' => 'FuneralOrg – Gedenkseite', 'version' => app_version()],
                    'instructions' => 'Zugriff auf Gedenkseite, Kontakte (Zu-/Absagen, Kondolenzen), Checkliste und Galerie einer Trauerfall-Organisation. '
                        . 'Die Daten sind persönlich; Rechte und Feldrechte des Token-Besitzers gelten. Ändere Daten nur auf ausdrückliche Anweisung.',
                ]);
            case 'ping':
                return self::result($id, []);
            case 'tools/list':
                return self::result($id, ['tools' => self::tools($scope)]);
            case 'tools/call':
                return self::result($id, self::call((string)($p['name'] ?? ''), is_array($p['arguments'] ?? null) ? $p['arguments'] : [], $scope));
        }
        return self::error($id, -32601, 'Method not found');
    }

    private static function call(string $name, array $args, string $scope): array
    {
        try {
            if ($name === 'gallery_image_view') {
                $img = ApiService::imageBinary((string)($args['id'] ?? ''), (string)($args['size'] ?? 'medium'));
                return ['content' => [['type' => 'image', 'data' => $img[1], 'mimeType' => $img[0]]], 'isError' => false];
            }
            foreach (ApiSpec::ops() as $op) {
                if ($op['raw'] || self::toolName($op) !== $name) {
                    continue;
                }
                if ($op['write'] && $scope !== 'write') {
                    return self::text(t('api.err_scope'), true);
                }
                if (!ApiSpec::available($op, $scope)) {
                    return self::text(t('perm.denied'), true);
                }
                foreach (array_keys($op['in']) as $k) {
                    if (str_ends_with($k, '!') && !isset($args[substr($k, 0, -1)])) {
                        return self::text('Pflichtparameter fehlt: ' . substr($k, 0, -1), true);
                    }
                }
                $data = ([ApiService::class, $op['fn']])($args);
                return self::text((string)json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
            }
        } catch (ApiError $e) {
            return self::text($e->getMessage(), true);
        } catch (Throwable $e) {
            return self::text(t('api.err_internal'), true);
        }
        return self::text('Unbekanntes Tool: ' . $name, true);
    }
}
