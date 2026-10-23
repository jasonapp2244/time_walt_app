<?php

$routesJson = $argv[1];
$routes = json_decode(file_get_contents($routesJson), true);
if (! is_array($routes)) {
    fwrite(STDERR, "Could not parse route list\n");
    exit(1);
}

$norm = fn (string $k): string => preg_replace('#/\{[a-zA-Z_]+\??\}#', '/{p}', $k);

$api = [];
foreach ($routes as $r) {
    if (! str_starts_with($r['uri'], 'api/')) {
        continue;
    }
    foreach (explode('|', $r['method']) as $m) {
        if ($m === 'HEAD') {
            continue;
        }
        $api[$norm($m.' /'.$r['uri'])] = $r['uri'];
    }
}

$c = json_decode(file_get_contents('TimeVault_Complete_Postman_Collection.json'), true);
if (! is_array($c)) {
    fwrite(STDERR, "Collection is not valid JSON\n");
    exit(1);
}

$have = [];
$walk = function (array $items) use (&$walk, &$have, $norm) {
    foreach ($items as $i) {
        if (isset($i['item'])) {
            $walk($i['item']);

            continue;
        }
        $p = $i['request']['url']['raw'];
        $p = str_replace('{{base_url}}', 'api', $p);
        $p = str_replace('{{host}}', '', $p);
        $p = preg_replace('/\?.*$/', '', $p);
        $p = preg_replace('/\{\{[a-z_]+\}\}/', '{p}', $p);
        $have[$norm($i['request']['method'].' /'.ltrim($p, '/'))][] = $i['name'];
    }
};
$walk($c['item']);

echo 'API routes in code:     '.count($api)."\n";
echo 'Unique paths covered:   '.count($have)."\n";
echo 'Total requests:         '.array_sum(array_map('count', $have))."\n\n";

$missing = array_diff(array_keys($api), array_keys($have));
echo "MISSING from collection:\n";
echo $missing ? '  '.implode("\n  ", $missing)."\n" : "  (none)\n";

$extra = array_diff(array_keys($have), array_keys($api));
echo "\nIn collection but not an api/ route:\n";
foreach ($extra as $e) {
    echo '  '.$e.'  <- '.implode(', ', $have[$e])."\n";
}
if (! $extra) {
    echo "  (none)\n";
}

echo "\nDuplicated paths (intentional variants):\n";
$dupes = array_filter($have, fn ($n) => count($n) > 1);
foreach ($dupes as $k => $names) {
    echo '  '.$k.'  <- '.implode(' | ', $names)."\n";
}
if (! $dupes) {
    echo "  (none)\n";
}

foreach (['TimeVault_Postman_Environment.Production.json', 'TimeVault_Postman_Environment.Local.json'] as $env) {
    $e = json_decode(file_get_contents($env), true);
    echo "\n".$env.': '.(is_array($e) ? 'valid JSON, '.count($e['values']).' variables, base_url='.$e['values'][1]['value'] : 'INVALID')."\n";
}
