<?php
/**
 * stale-asset-audit - find pages serving a cached asset under an OLD version key.
 *
 * Multi-page sites version their CSS/JS with ?v=N cache busters. Bump passes tend to
 * be page-local: one page gets ?v=9, the rest keep ?v=7. Every stale page then serves
 * the CURRENT asset file, but returning browsers keep their cached copy under the old
 * key - so "deployed" code never actually runs there. This scanner catches it.
 *
 * Usage:
 *   php audit.php /var/www/site            # scan a directory tree
 *   php audit.php . --exclude=fixt,backups # skip directories (comma separated)
 *   php audit.php . --json                 # machine-readable report
 *
 * Exit codes: 0 = no drift, 1 = drift found (CI friendly), 2 = usage error.
 *
 * Zero dependencies. PHP 7.2+. Extracted from production (84 pages, one site, Nov 2026).
 */

if ($argc < 2) {
    fwrite(STDERR, "usage: php audit.php <dir> [--exclude=a,b] [--json]\n");
    exit(2);
}

$root  = rtrim(realpath($argv[1]), '/');
if ($root === '' || !is_dir($root)) {
    fwrite(STDERR, "error: {$argv[1]} is not a directory\n");
    exit(2);
}

$exclude = array();
$json    = false;
for ($i = 2; $i < $argc; $i++) {
    if (preg_match('/^--exclude=(.+)$/', $argv[$i], $m)) {
        foreach (explode(',', $m[1]) as $x) { $x = trim($x); if ($x !== '') $exclude[$x] = true; }
    } elseif ($argv[$i] === '--json') {
        $json = true;
    } else {
        fwrite(STDERR, "error: unknown option {$argv[$i]}\n");
        exit(2);
    }
}

/* Collect every script/stylesheet reference to a LOCAL asset, with its version key. */
$refs = array();   // asset path => [ page => version-or-'' ]
$it   = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));

foreach ($it as $f) {
    if (!$f->isFile()) continue;
    $ext = strtolower($f->getExtension());
    if ($ext !== 'html' && $ext !== 'htm' && $ext !== 'php') continue;

    $path = $f->getPathname();
    $rel  = ltrim(str_replace($root, '', $path), '/');

    foreach ($exclude as $x => $_) {
        if (strpos($rel, $x . '/') === 0 || $rel === $x) continue 2;
    }
    if ($f->getSize() > 1000000) continue;

    $src = file_get_contents($path);
    if ($src === false) continue;

    /* <script src="..."> and <link ... href="..."> */
    if (!preg_match_all('#(?:<script[^>]*?src=|<link[^>]*?href=)["\']([^"\']+)["\']#i', $src, $m)) continue;

    foreach ($m[1] as $url) {
        if (preg_match('#^[a-z]+://#i', $url)) continue;                 // skip remote
        if (preg_match('#^//#', $url)) continue;                          // protocol-relative
        $ver = (preg_match('#[?&]v=([^&"\']*)#', $url, $vm)) ? $vm[1] : '';
        $url = preg_replace('/^\/+/', '', parse_url($url, PHP_URL_PATH) ?: $url);
        if ($url === '' || !preg_match('#\.(css|js)(\?|$)#i', $url)) continue;
        $asset = $url;

        if (!isset($refs[$asset])) $refs[$asset] = array();
        $refs[$asset][$rel] = $ver;
    }
}

/* Flag any asset referenced under more than one version key (or mixed keyed/bare). */
$report = array();
foreach ($refs as $asset => $pages) {
    $versions = array_unique(array_values($pages));
    if (count($versions) < 2) continue;
    $max = '';
    foreach ($versions as $v) { if (strcmp($v, $max) > 0) $max = $v; }
    $stale = array();
    foreach ($pages as $page => $v) {
        if ($v !== $max) $stale[] = array('page' => $page, 'version' => $v, 'latest' => $max);
    }
    $report[] = array('asset' => $asset, 'versions' => array_values($versions),
                      'latest' => $max, 'stale_pages' => $stale);
}

usort($report, function ($a, $b) { return strcmp($a['asset'], $b['asset']); });

if ($json) {
    echo json_encode(array('root' => $root, 'assets' => count($refs),
                           'drifted' => count($report), 'findings' => $report),
                     JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), "\n";
} else {
    if (!$report) {
        echo "stale-asset-audit: " . count($refs) . " local assets checked - no version drift.\n";
        exit(0);
    }
    echo "stale-asset-audit: " . count($refs) . " local assets checked, " . count($report) . " with drift:\n\n";
    foreach ($report as $r) {
        echo "  {$r['asset']}\n";
        echo "    versions seen: " . implode(', ', $r['versions']) . " (latest: {$r['latest']})\n";
        foreach ($r['stale_pages'] as $s) {
            echo "    STALE {$s['version']}: {$s['page']}\n";
        }
        echo "\n";
    }
    echo count($report) . " asset(s) served under stale cache keys. Bump every ref, not just the page you edited.\n";
}

exit(1);
