<?php
declare(strict_types=1);

/**
 * Lokaler Ersatz für die GitHub-API und die Release-Downloads, nur für bin/smoke-test.sh (Plugin-Marktplatz).
 * Aufruf als Router des eingebauten Servers:
 *   MOCK_DIR=<verzeichnis> MOCK_PORT=<port> php -S 127.0.0.1:<port> bin/github-mock.php
 * In configs/config.php der getesteten Anwendung: 'market_api' => 'http://127.0.0.1:<port>'.
 * Repositories: me/funeral-org-demo-plug (Releases 1.0.0, 1.1.0 mit neuer Berechtigung, unvollständige, Draft, Pre-Release,
 * inkompatible 9.0.0), me/funeral-org-bad (falsche Prüfsumme, Asset auf fremdem Host), me/funeral-org-redir (Weiterleitung auf
 * fremden Host), me/funeral-org-limited (API-Kontingent erschöpft). Alle Anfragen stehen in $MOCK_DIR/log.txt.
 */
$dir = getenv('MOCK_DIR'); $port = getenv('MOCK_PORT'); $base = "http://127.0.0.1:$port";
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
file_put_contents("$dir/log.txt", $path . ' ' . ($_SERVER['HTTP_AUTHORIZATION'] ?? '-') . ' ' . ($_SERVER['HTTP_IF_NONE_MATCH'] ?? '') . "\n", FILE_APPEND);
$A = str_repeat('a', 40); $B = str_repeat('b', 40);
$repos = [
  'me/funeral-org-demo-plug' => ['slug' => 'demo-plug', 'rel' => [
     '1.0.0' => ['audit' => str_repeat('c', 40)], '1.1.0' => ['perm' => ['mail'], 'audit' => $A],
     '1.2.0' => ['nosha' => 1], '1.3.0' => ['pre' => 1], '2.0.0' => ['draft' => 1], '9.0.0' => ['reqapp' => '99.0.0']]],
  'me/funeral-org-bad' => ['slug' => 'bad-plug', 'rel' => ['1.0.0' => ['badsha' => 1], '1.0.1' => ['evil' => 1]]],
  'me/funeral-org-redir' => ['slug' => 'redir-plug', 'rel' => ['1.0.0' => ['redir' => 1]]],
];
function manifest($slug, $ver, $o) { return ['slug' => $slug, 'name' => 'Test ' . $slug, 'version' => $ver, 'stores' => ['tp_' . str_replace('-', '_', $slug) => ['x' => 1]]]
   + (isset($o['reqapp']) ? ['requires_app' => $o['reqapp']] : []) + (isset($o['perm']) ? ['permissions' => $o['perm']] : [])
   + (isset($o['audit']) ? ['audit' => ['commit' => $o['audit']]] : []); }
function zipbytes($slug, $ver, $o) {
  $f = tempnam(sys_get_temp_dir(), 'z'); $z = new ZipArchive(); $z->open($f, ZipArchive::OVERWRITE);
  $z->addFromString("$slug/plugin.json", json_encode(manifest($slug, $ver, $o))); $z->addFromString("$slug/plugin.php", "<?php\nreturn function (PluginApi \$p): void { \$p->onUpdate(function (string \$from): void { file_put_contents(DATA_DIR . '/update-hook.txt', \$from); }); };\n"); $z->close();
  $b = file_get_contents($f); unlink($f); return $b;
}
function out($d, $code = 200, $extra = []) { http_response_code($code); header('Content-Type: application/json');
  header('X-RateLimit-Limit: 60'); header('X-RateLimit-Remaining: 59'); header('X-RateLimit-Reset: ' . (time() + 3600)); foreach ($extra as $h) header($h); echo json_encode($d); exit; }

if (str_starts_with($path, '/repos/me/funeral-org-limited')) { http_response_code(403); header('X-RateLimit-Remaining: 0'); header('X-RateLimit-Reset: ' . (time() + 600)); echo '{}'; exit; }
if ($path === '/search/repositories') {
  $items = []; foreach ($repos as $r => $d) $items[] = ['full_name' => $r, 'name' => explode('/', $r)[1], 'description' => 'Beschreibung <b>x</b>', 'stargazers_count' => 5, 'pushed_at' => '2026-10-01T10:00:00Z', 'archived' => false];
  out(['items' => $items]);
}
if (preg_match('~^/repos/(me/[^/]+)/releases$~', $path, $m) && isset($repos[$m[1]])) {
  if (($_SERVER['HTTP_IF_NONE_MATCH'] ?? '') === '"rel-1"') { http_response_code(304); exit; }
  $list = [];
  foreach ($repos[$m[1]]['rel'] as $ver => $o) {
    $slug = $repos[$m[1]]['slug']; $zn = "$slug-$ver.zip";
    $assets = [['name' => $zn, 'size' => 1000, 'state' => 'uploaded', 'browser_download_url' => "$base/dl/$zn"],
               ['name' => 'plugin.json', 'size' => 100, 'state' => 'uploaded', 'browser_download_url' => "$base/dl/$slug-$ver-plugin.json"]];
    if (empty($o['nosha'])) $assets[] = ['name' => "$zn.sha256", 'size' => 80, 'state' => 'uploaded', 'browser_download_url' => "$base/dl/$zn.sha256"];
    if (!empty($o['evil'])) $assets[0]['browser_download_url'] = 'https://evil.example/' . $zn;
    $list[] = ['tag_name' => "v$ver", 'draft' => !empty($o['draft']), 'prerelease' => !empty($o['pre']), 'published_at' => '2026-10-02T00:00:00Z', 'body' => "Notes <script>$ver</script>", 'assets' => $assets];
  }
  out($list, 200, ['ETag: "rel-1"']);
}
if (preg_match('~^/repos/me/[^/]+/git/ref/tags/v(.+)$~', $path, $m)) out(['object' => $m[1] === '1.0.0' ? ['type' => 'tag', 'sha' => $B] : ['type' => 'commit', 'sha' => $A]]);
if (preg_match('~^/repos/me/[^/]+/git/tags/(.+)$~', $path, $m)) out(['object' => ['sha' => $A]]);
if (preg_match('~^/dl/(.+)$~', $path, $m)) {
  if (str_starts_with($m[1], 'redir-plug-1.0.0.zip') && !str_ends_with($m[1], '.sha256')) { header('Location: https://evil.example/a.zip', true, 302); exit; }
  header('Location: /cdn/' . $m[1], true, 302); exit; }
if (preg_match('~^/cdn/([a-z-]+)-(\d+\.\d+\.\d+)(\.zip|\.zip\.sha256|-plugin\.json)$~', $path, $m)) {
  [$all, $slug, $ver, $kind] = $m; $o = null;
  foreach ($repos as $d) if ($d['slug'] === $slug) $o = $d['rel'][$ver] ?? null;
  if ($o === null) { http_response_code(404); exit; }
  if ($kind === '-plugin.json') { echo json_encode(manifest($slug, $ver, $o)); exit; }
  $zip = zipbytes($slug, $ver, $o);
  if ($kind === '.zip') { header('Content-Type: application/zip'); echo $zip; exit; }
  echo (!empty($o['badsha']) ? str_repeat('0', 64) : hash('sha256', $zip)) . "  $slug-$ver.zip\n"; exit;
}
http_response_code(404); echo '{}';
