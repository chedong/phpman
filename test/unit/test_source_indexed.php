<?php
/**
 * Unit tests: sourceIsIndexed() (#280)
 *
 * The search cascade shells out to `pydoc3 -k` (8-14s) and `ri` (~1s) when FTS
 * returns nothing. That is correct on a machine whose index was never built —
 * but once the source IS indexed, an FTS miss means "no such module", not "I
 * cannot answer", and the fallback buys nothing for its cost.
 *
 * The switch is the presence of rows for that source in search_index_meta, not
 * a hardcoded list, so a fresh install keeps its fallbacks. These tests pin
 * both directions: a source with rows answers true, one without answers false.
 *
 * Runs each case in a subprocess because sourceIsIndexed() memoises per source
 * in a static — the same process cannot observe a change of state.
 */
declare(strict_types=1);
require_once __DIR__ . '/../test_helper.php';

$tmp = sys_get_temp_dir() . '/phpman_srcidx_' . getmypid();
@mkdir($tmp . '/logs', 0755, true);
@mkdir($tmp . '/db', 0755, true);

/**
 * Run a probe inside the fixture home; returns decoded JSON or null.
 * $seed is SQL run before the probe (to populate search_index_meta).
 */
function probe(string $tmp, string $seed): ?array {
    $code = 'require ' . var_export(dirname(__DIR__, 2) . '/cli/_bootstrap.php', true) . ';'
          . ($seed !== '' ? '$db = cacheDb(); $db->exec(' . var_export($seed, true) . ');' : '')
          . 'echo json_encode(["pydoc" => sourceIsIndexed("pydoc"), "ri" => sourceIsIndexed("ri")]);';
    $out = [];
    $exit = 0;
    exec('PHPMAN_HOME=' . escapeshellarg($tmp) . ' php -r ' . escapeshellarg($code) . ' 2>&1', $out, $exit);
    $decoded = json_decode(implode("\n", $out), true);
    return is_array($decoded) ? $decoded : null;
}

echo "=== Unit: sourceIsIndexed() (#280) ===\n\n";

echo "--- an empty index ---\n";
$r = probe($tmp, '');
assert_equals(true, $r !== null, "the probe runs and returns JSON");
assert_equals(false, $r['pydoc'] ?? null, "pydoc has no rows → fallback stays enabled");
assert_equals(false, $r['ri'] ?? null, "ri has no rows → fallback stays enabled");

echo "\n--- pydoc indexed, ri not ---\n";
$r = probe($tmp,
    "INSERT OR IGNORE INTO search_index_meta (name, section, source, body_len) VALUES ('json','pydoc','pydoc',0)");
assert_equals(true,  $r['pydoc'] ?? null, "pydoc has a row → fallback is skipped");
assert_equals(false, $r['ri'] ?? null, "ri still has none → its fallback stays");

echo "\n--- both indexed ---\n";
$r = probe($tmp,
    "INSERT OR IGNORE INTO search_index_meta (name, section, source, body_len) VALUES ('Array','ri','ri',0)");
assert_equals(true, $r['pydoc'] ?? null, "pydoc still true");
assert_equals(true, $r['ri'] ?? null, "ri now true as well");

// cleanup
foreach (glob($tmp . '/db/*') ?: [] as $f) @unlink($f);
@unlink($tmp . '/logs/phpman_error.log');
@rmdir($tmp . '/db'); @rmdir($tmp . '/logs'); @rmdir($tmp);

exit(test_summary());
