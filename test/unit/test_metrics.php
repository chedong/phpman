<?php
/**
 * Unit tests: cli/metrics.php (#256)
 *
 * The tool exists so that "what is p95 / how many 5xx / did we lose slots" is a
 * command instead of a grep. These tests pin the arithmetic, because every one
 * of them can be wrong in a way that still prints a plausible number:
 *
 *   - percentile(): an off-by-one gives a p95 that is really p94
 *   - the quantile loop: PHP casts float array keys to int, so
 *     [0.5 => .., 0.95 => ..] collapses to key 0 and every quantile becomes p0.
 *     That bug shipped in the first version and printed the *minimum* as p95.
 *   - timestamp parsing: an unparsed stamp silently drops the line, so a whole
 *     log reads as "no data" instead of raising
 *
 * Fixtures are written to a temp PHPMAN_HOME and the tool is run as a
 * subprocess, so this exercises the real CLI entry point.
 */
declare(strict_types=1);
require_once __DIR__ . '/../test_helper.php';

$repo = dirname(__DIR__, 2);
$tool = $repo . '/cli/metrics.php';

$tmp = sys_get_temp_dir() . '/phpman_metrics_' . getmypid();
@mkdir($tmp . '/logs', 0755, true);
@mkdir($tmp . '/db', 0755, true);
@mkdir($tmp . '/weblog', 0755, true);

$now = date('d-M-Y H:i:s T');
$nap = date('d/M/Y:H:i:s O');
$errStamp = date('D M j H:i:s Y');   // Apache error log: Wed Nov 25 09:40:50 2026

// ── fixture: 100 sampled machine renders 10..1000ms + 10 unsampled 20s lines,
//    5 sampled HTML, 1000 requests
//    (4 of them 500, 2 of those with a zero-byte body), 2 slot-exhaustion lines
$mf = [];
for ($i = 0; $i < 100; $i++) {
    $ms = 10 * ($i + 1);
    $mf[] = "[{$now}] phpMan: [WEB: https://x/phpMan.php/man/n{$i}/1/markdown] "
          . "mf mode=man name=n{$i} sec=1 fmt=markdown elapsed={$ms}ms peak=2.0MB bytes=100 sampled=1";
}
// #284: threshold lines are the slow tail, not traffic. They must not move the
// percentiles — these 10 lines are 20s each and none carries sampled=1.
for ($i = 0; $i < 10; $i++) {
    $mf[] = "[{$now}] phpMan: [WEB: https://x/phpMan.php/man/slow{$i}/1/markdown] "
          . "mf mode=man name=slow{$i} sec=1 fmt=markdown elapsed=20000ms peak=2.0MB bytes=900000";
}
for ($i = 0; $i < 5; $i++) {
    $mf[] = "[{$now}] phpMan: [WEB: https://x/phpMan.php/man/h{$i}/1] "
          . "mf mode=man name=h{$i} sec=1 fmt=html elapsed=90ms peak=2.0MB bytes=9000 sampled=1";
}
file_put_contents($tmp . '/logs/phpman_mf.log', implode("\n", $mf) . "\n");

$acc = [];
for ($i = 0; $i < 1000; $i++) {
    $code = $i < 4 ? 500 : 200;
    $size = $i < 2 ? 0 : 1234;
    $acc[] = "1.2.3." . ($i % 250) . " - - [{$nap}] \"GET /phpMan.php/man/x/1 HTTP/1.1\" {$code} {$size} \"-\" \"c\"";
}
file_put_contents($tmp . '/weblog/access.log', implode("\n", $acc) . "\n");

$err = [];
for ($i = 0; $i < 2; $i++) {
    $err[] = "[{$errStamp}] [chedong.com] [warn] [client 1.2.3.4] fcgid_bridge.c(481): mod_fcgid: can't apply process slot for /x";
}
file_put_contents($tmp . '/weblog/error.log', implode("\n", $err) . "\n");

/** Run the tool with the fixture environment; returns [stdout, exitCode]. */
function runTool(string $tool, string $tmp, string $args, string $weblogRel = 'weblog'): array {
    $cmd = 'PHPMAN_HOME=' . escapeshellarg($tmp)
         . ' PHPMAN_WEBLOG_DIR=' . escapeshellarg($tmp . '/' . $weblogRel)
         . ' php ' . escapeshellarg($tool) . ' ' . $args . ' 2>/dev/null';
    $out = [];
    $code = 0;
    exec($cmd, $out, $code);
    return [implode("\n", $out), $code];
}

echo "=== Unit: cli/metrics.php (#256) ===\n\n";

// ── JSON snapshot ──
echo "--- --json ---\n";
[$out, $code] = runTool($tool, $tmp, '--since=1h --json');
$snap = json_decode($out, true);
assert_equals(0, $code, "the tool exits 0 on a healthy fixture");
assert_equals(true, is_array($snap), "output is valid JSON");

$byKey = [];
foreach ($snap['render'] ?? [] as $r) {
    $byKey[$r['mode'] . '|' . $r['format']] = $r;
}
assert_equals(110, $byKey['man|markdown']['count'] ?? null, "110 markdown lines counted (100 sampled + 10 threshold)");
assert_equals(100, $byKey['man|markdown']['sampled'] ?? null, "100 of them are sampled");
assert_equals(5,   $byKey['man|html']['count'] ?? null, "5 sampled HTML renders counted");
assert_equals(5,   $byKey['man|html']['sampled'] ?? null, "…and all 5 are flagged sampled");

// Nearest-rank over the 100 sampled values 10..1000 → p50 = 500, p95 = 950.
// The 10 unsampled 20s lines exist to prove they are excluded: if they leaked
// in, p95 would jump to 20000.
assert_equals(500.0, (float) ($byKey['man|markdown']['elapsed_p50'] ?? 0), "p50 is the 50th value");
assert_equals(950.0, (float) ($byKey['man|markdown']['elapsed_p95'] ?? 0),
    "p95 is the 95th sampled value — the 10 unsampled 20s lines do not move it (#284)");

assert_equals(1000, $snap['http']['requests'] ?? null, "1000 requests counted");
assert_equals(4,    $snap['http']['5xx'] ?? null, "4 of them 5xx");
assert_equals(2,    $snap['http']['5xx_zero_byte'] ?? null, "2 of the 5xx have a zero-byte body");
assert_equals(2,    $snap['slot_exhausted'] ?? null, "2 slot-exhaustion lines counted");

// ── SLO evaluation ──
echo "\n--- --check-slo ---\n";
[$out, $code] = runTool($tool, $tmp, '--check-slo');
assert_equals(1, $code, "a violated SLO exits non-zero (slot exhaustion is 2, not 0)");
assert_contains('slot exhausted', $out, "the violated SLO is named");

// Drop the slot lines: everything else passes, so the exit code flips to 0.
file_put_contents($tmp . '/weblog/error.log', '');
[$out, $code] = runTool($tool, $tmp, '--check-slo');
assert_equals(0, $code, "with no slot exhaustion the SLO check passes");

// ── a missing error.log must not read as "0 slots" ──
echo "\n--- unreadable error log ---\n";
@mkdir($tmp . '/weblog-noerr', 0755, true);
copy($tmp . '/weblog/access.log', $tmp . '/weblog-noerr/access.log');
[$out, $code] = runTool($tool, $tmp, '--json', 'weblog-noerr');
$snapNoErr = json_decode($out, true);
assert_equals(true,
    array_key_exists('slot_exhausted', $snapNoErr) && $snapNoErr['slot_exhausted'] === null,
    "no error.log → slot_exhausted is null, not 0");
[$out, $code] = runTool($tool, $tmp, '--check-slo', 'weblog-noerr');
assert_equals(0, $code, "an unknown slot count is not a violation");
assert_contains('· slot exhausted', $out, "…and the SLO renders as · (n/a), not ✓");

// ── Apache's weekday-prefixed, space-padded error-log stamp ──
echo "\n--- Apache error-log stamps ---\n";
// Pick a day-of-month ≤ 9 in the last 40 days so the padding branch is
// deterministic; the weekday is irrelevant (the parser strips it).
$padded = null;
for ($back = 1; $back <= 40; $back++) {
    $t = time() - $back * 86400;
    if ((int) date('j', $t) <= 9) {
        $padded = date('D M ', $t) . str_pad(date('j', $t), 2, ' ', STR_PAD_LEFT) . date(' H:i:s Y', $t);
        break;
    }
}
@mkdir($tmp . '/weblog-apache', 0755, true);
file_put_contents($tmp . '/weblog-apache/error.log',
    "[{$padded}] [chedong.com] [warn] [client 1.2.3.4] fcgid_bridge.c(481): mod_fcgid: can't apply process slot for /x\n");
copy($tmp . '/weblog/access.log', $tmp . '/weblog-apache/access.log');
[$out, $code] = runTool($tool, $tmp, '--since=45d --json', 'weblog-apache');
$snapApache = json_decode($out, true);
assert_equals(1, $snapApache['slot_exhausted'] ?? null,
    "weekday-prefixed, space-padded Apache stamp is counted ({$padded})");

// ── Prometheus textfile ──
echo "\n--- --out ---\n";
$prom = $tmp . '/metrics/phpman.prom';
[$out, $code] = runTool($tool, $tmp, '--since=1h --out=' . escapeshellarg($prom));
assert_equals(0, $code, "writing the textfile exits 0");
assert_equals(true, is_file($prom), "the textfile exists");
$body = (string) @file_get_contents($prom);
assert_contains('phpman_render_count{mode="man",format="markdown"} 110', $body, "render count exported (sampled + threshold)");
assert_contains('quantile="0.50"} 500', $body, "p50 exported with the right value");
assert_contains('quantile="0.95"} 950', $body, "p95 exported with the right value — not p0");
assert_contains('phpman_http_5xx_ratio 0.004000', $body, "5xx ratio exported");
assert_equals(false, is_file($prom . '.tmp'), "no .tmp is left behind (atomic rename)");

// ── an empty window must not read as a pass ──
echo "\n--- empty window ---\n";
@unlink($tmp . '/logs/phpman_mf.log');
[$out, $code] = runTool($tool, $tmp, '--check-slo');
assert_equals(0, $code, "no telemetry means 'n/a', not a violation");
assert_contains('n/a', $out, "…and the missing metric says so");

// ── cleanup ──
foreach (glob($tmp . '/metrics/*') ?: [] as $f) @unlink($f);
@rmdir($tmp . '/metrics');
@unlink($tmp . '/logs/phpman_mf.log');
@unlink($tmp . '/weblog/access.log');
@unlink($tmp . '/weblog/error.log');
@unlink($tmp . '/weblog-noerr/access.log'); @rmdir($tmp . '/weblog-noerr');
@unlink($tmp . '/weblog-apache/access.log'); @unlink($tmp . '/weblog-apache/error.log'); @rmdir($tmp . '/weblog-apache');
@rmdir($tmp . '/logs'); @rmdir($tmp . '/db'); @rmdir($tmp . '/weblog'); @rmdir($tmp);

exit(test_summary());
