<?php
declare(strict_types=1);

/** Zugriffsprotokoll der API (nur Administratoren). */
function ctrl_apilog(string $r, string $a): void
{
    Perm::needAdmin();
    $days = in_array((int)get('days', 30), [1, 7, 30, 90, 365], true) ? (int)get('days', 30) : 30;
    $filter = [
        'token' => preg_replace('/[^a-f0-9-]/', '', (string)get('token')),
        'proto' => in_array(get('proto'), ['rest', 'mcp'], true) ? (string)get('proto') : '',
        'only'  => in_array(get('only'), ['write', 'error'], true) ? (string)get('only') : '',
    ];
    $stats = ApiLog::stats(ApiLog::read($days));
    $rows = ApiLog::read($days, $filter);
    $per = 100;
    $pages = max(1, (int)ceil(count($rows) / $per));
    $page = max(1, min($pages, (int)get('page', 1)));
    view('@api/log', [
        'rows' => array_slice($rows, ($page - 1) * $per, $per), 'total' => count($rows),
        'stats' => $stats, 'days' => $days, 'filter' => $filter, 'page' => $page, 'pages' => $pages,
    ]);
}
