<?php
/**
 * Unit tests: golden-corpus check (#255, Task 5)
 *
 * check.php 是 CI 入口（黄金/文本完整性/结构/JSON 契约四条检查），
 * 这里以子进程方式驱动它：
 *   - 干净语料 → exit 0，无 [FAIL]；
 *   - golden md 被改坏 → exit 1，输出含 [FAIL] <id> golden-md 与行号差异；
 *   - fingerprint 被改坏 → exit 1，输出含 [FAIL] <id> fingerprint；
 *   - 修好 → exit 0；
 *   - 空 raw/ → [SKIP] no corpus，exit 0（controller ruling：CI 先接线后抓语料）。
 *
 * Task 6 增加 xhtml 检查（整页 showHeader + formatManPerlDoc + showFooter，
 * 用本机 xmllint --noout --nonet --dtdvalid 校验随仓库离线解析的 XHTML 1.0
 * Transitional DTD）：
 *   - 干净语料 → 输出含 [PASS] xhtml；
 *   - 清空 PATH（找不到 xmllint）→ [SKIP] xhtml，exit 0，无 [FAIL]；
 *   - 进程内直接调 corpusCheckRenderFullPage()/corpusCheckXhtmlValidate()：
 *     完整页面通过；篡改渲染出的页面（去掉 </body></html>）→ 拒绝；
 *   - 本机没有 xmllint → 打印 [SKIP] xhtml 跳过本节，仍算通过。
 *
 * 语料用合成 raw（不跑 man/perldoc），黄金由 cli/corpus.php render 子进程生成，
 * 保证 check 与 render 走同一条渲染路径。fixture 带真实 man 形状的页眉/页脚
 * （首末相同的行），验证 tokens 检查对页眉噪声的行为。
 */
declare(strict_types=1);
define('PHPMAN_TEST_MODE', true);
require_once __DIR__ . '/../test_helper.php';

echo "=== Unit: corpus check (#255 Task 5) ===\n\n";

$root = dirname(__DIR__, 2);
$check = $root . '/test/corpus/check.php';
$render = $root . '/cli/corpus.php';

// 真实 man 输出形状：首末是同一行页眉，7 空格缩进正文
const RAW_PAGE = "DEMO(1)                    User Commands                   DEMO(1)\n"
    . "\n"
    . "NAME\n"
    . "       demo - a demo tool\n"
    . "\n"
    . "DESCRIPTION\n"
    . "       This tool does one thing well.\n"
    . "\n"
    . "OPTIONS\n"
    . "       -x  enable the feature\n"
    . "\n"
    . "SEE ALSO\n"
    . "       other(1)\n"
    . "\n"
    . "DEMO(1)                    User Commands                   DEMO(1)\n";

/**
 * 运行一个 php 脚本子进程，返回 [exitCode, stdout]。
 *
 * @return array{0: int, 1: string}
 */
function run_php(string $script, array $args): array {
    $cmd = "php " . escapeshellarg($script);
    foreach ($args as $a) {
        $cmd .= " " . escapeshellarg($a);
    }
    $out = [];
    $rc = 0;
    exec($cmd . " 2>&1", $out, $rc);
    return [$rc, implode("\n", $out)];
}

$tmp = sys_get_temp_dir() . '/phpman-check-test-' . getmypid();
if (is_dir($tmp)) {
    foreach (glob($tmp . '/*/*') ?: [] as $f) { unlink($f); }
}
mkdir($tmp . '/raw', 0777, true);
file_put_contents($tmp . '/pages.txt', "# id\ttool\tname\tsection\ncheck-demo\tman\tdemo\t1\n");
file_put_contents($tmp . '/raw/check-demo.txt', RAW_PAGE);

echo "--- 前置：render 生成黄金 ---\n";
[$rc, $out] = run_php($render, ['render', '--out=' . $tmp]);
assert_equals(0, $rc, "render 子进程退出码 0");
assert_equals(true, is_file($tmp . '/golden/check-demo.md'), "render 生成 golden/check-demo.md");
assert_equals(true, is_file($tmp . '/golden/check-demo.fingerprint'), "render 生成 golden/check-demo.fingerprint");

echo "\n--- 干净语料：exit 0 ---\n";
[$rc, $out] = run_php($check, ['--corpus=' . $tmp]);
assert_equals(0, $rc, "check 子进程退出码 0（干净语料）");
assert_not_contains('[FAIL]', $out, "干净语料无 [FAIL]");
assert_contains('check-demo', $out, "输出提到被检查的页 id");

echo "\n--- golden md 改坏：exit 1，[FAIL] <id> golden-md ---\n";
$mdPath = $tmp . '/golden/check-demo.md';
$mdOriginal = (string)file_get_contents($mdPath);
file_put_contents($mdPath, preg_replace('/a demo tool/', 'a broken tool', $mdOriginal));
[$rc, $out] = run_php($check, ['--corpus=' . $tmp]);
assert_equals(1, $rc, "golden md 改坏后退出码 1");
assert_contains('[FAIL] check-demo golden-md', $out, "输出含 [FAIL] check-demo golden-md");
assert_match('/line \d+/', $out, "输出含首个不同的行号");
assert_contains('golden/check-demo.md', $out, "输出指出被改坏的黄金文件");

echo "\n--- fingerprint 改坏：exit 1，[FAIL] <id> fingerprint ---\n";
$fpPath = $tmp . '/golden/check-demo.fingerprint';
$fpOriginal = (string)file_get_contents($fpPath);
file_put_contents($fpPath, $fpOriginal . 'JUNK(9lines)');
[$rc, $out] = run_php($check, ['--corpus=' . $tmp]);
assert_equals(1, $rc, "fingerprint 改坏后退出码 1");
assert_contains('[FAIL] check-demo fingerprint', $out, "输出含 [FAIL] check-demo fingerprint");
file_put_contents($fpPath, $fpOriginal);

echo "\n--- 修好 golden md：exit 0 ---\n";
file_put_contents($mdPath, $mdOriginal);
[$rc, $out] = run_php($check, ['--corpus=' . $tmp]);
assert_equals(0, $rc, "修好后退出码 0");
assert_not_contains('[FAIL]', $out, "修好后无 [FAIL]");

// 本机没有 xmllint：xhtml 检查在生产里会记 SKIP，这里的 xhtml 断言同样整体
// 跳过、打印 [SKIP] xhtml——下面的 [PASS] 断言依赖 xmllint 真实存在，不能在
// 没有它的机器上把套件打红（keep-the-suite-green）。清空 PATH 那节放在守卫
// 内也一样成立：有 xmllint 时它模拟缺失；真缺失时输出同样是 [SKIP]。
if (trim((string)shell_exec('command -v xmllint 2>/dev/null')) === '') {
    echo "[SKIP] xhtml\n";
} else {
    echo "\n--- xhtml（Task 6）：干净语料输出 [PASS] xhtml ---\n";
    [$rc, $out] = run_php($check, ['--corpus=' . $tmp]);
    assert_equals(0, $rc, "xhtml 干净语料退出码 0");
    assert_contains('[PASS] xhtml', $out, "干净语料输出 [PASS] xhtml");
    assert_contains('[OK] check-demo', $out, "整页有效时该页仍报 [OK]");

    echo "\n--- xhtml：清空 PATH（无 xmllint）→ [SKIP] xhtml，exit 0 ---\n";
    $out2 = [];
    $rc2 = 0;
    exec("env PATH=/nonexistent " . escapeshellarg(PHP_BINARY) . " "
        . escapeshellarg($check) . " --corpus=" . escapeshellarg($tmp) . " 2>&1", $out2, $rc2);
    $out2s = implode("\n", $out2);
    assert_equals(0, $rc2, "无 xmllint 时退出码 0（不 fail）");
    assert_contains('[SKIP] xhtml', $out2s, "无 xmllint 输出 [SKIP] xhtml");
    assert_not_contains('[FAIL]', $out2s, "无 xmllint 不产生 [FAIL]");

    echo "\n--- xhtml：进程内整页渲染 + 篡改拒绝 ---\n";
    // 整页组装需要 src/ 的 showHeader/showFooter；PHPMAN_HOME 指向临时目录，
    // 避免碰本机 ~/.phpman（与 check.php 的环境隔离同理）。
    define('PHPMAN_HOME', sys_get_temp_dir() . '/phpman-xhtml-test-home-' . getmypid());
    @mkdir(PHPMAN_HOME, 0777, true);
    define('PHPMAN_BASE_URL', 'https://www.chedong.com/phpMan.php');
    define('PHPMAN_TLDR_DISABLE', true);
    require_once $root . '/src/bootstrap.php';
    require_once $check;

    $page = corpusCheckRenderFullPage(corpusCheckLines(RAW_PAGE), 'demo', '1', 'man');
    assert_contains('<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN"', $page, "整页含 XHTML DOCTYPE");
    assert_contains('</body></html>', $page, "整页含闭合的 body/html");

    $res = corpusCheckXhtmlValidate($page, 'in-process-valid');
    assert_equals(true, $res['pass'], "完整页面通过 xmllint --dtdvalid");
    if (!$res['pass']) {
        echo "  xmllint diagnostics:\n" . $res['output'] . "\n";
    }

    $broken = str_replace('</body></html>', '', $page);
    $res2 = corpusCheckXhtmlValidate($broken, 'in-process-broken');
    assert_equals(false, $res2['pass'], "未闭合 body 的渲染页面被 xmllint 拒绝");
    assert_not_equals('', trim($res2['output']), "失败时给出 xmllint 诊断（前 5 行）");
}

echo "\n--- 空 raw/：[SKIP] no corpus，exit 0 ---\n";
$tmp2 = sys_get_temp_dir() . '/phpman-check-empty-' . getmypid();
if (is_dir($tmp2)) {
    foreach (glob($tmp2 . '/*/*') ?: [] as $f) { unlink($f); }
    @rmdir($tmp2 . '/raw');
    @rmdir($tmp2 . '/golden');
    @rmdir($tmp2);
}
mkdir($tmp2 . '/raw', 0777, true);
file_put_contents($tmp2 . '/pages.txt', "# id\ttool\tname\tsection\nnothing\tman\tnope\t1\n");
[$rc, $out] = run_php($check, ['--corpus=' . $tmp2]);
assert_equals(0, $rc, "空语料退出码 0（controller ruling）");
assert_contains('[SKIP] no corpus', $out, "空语料输出 [SKIP] no corpus");

echo "\n--- manifest 条目缺 raw：exit 1 并报 FAIL ---\n";
// 注意不能删掉唯一的 raw 文件——那会让语料变空、触发 [SKIP] no corpus（上节）。
// 追加一个没有 raw 的条目，让语料保持非空，才轮到逐页检查。
file_put_contents($tmp . '/pages.txt',
    "# id\ttool\tname\tsection\ncheck-demo\tman\tdemo\t1\ncheck-noraw\tman\tgone\t1\n");
[$rc, $out] = run_php($check, ['--corpus=' . $tmp]);
assert_equals(1, $rc, "条目缺 raw 退出码 1");
assert_contains('[FAIL] check-noraw golden-md', $out, "缺 raw 的条目报 [FAIL] <id> golden-md");
assert_contains('raw output missing', $out, "FAIL 原因指出 raw 缺失");
assert_contains('[OK] check-demo', $out, "其余页仍报 [OK]");

// --- 清理 ---
foreach (glob($tmp . '/*/*') ?: [] as $f) { unlink($f); }
@rmdir($tmp . '/raw');
@rmdir($tmp . '/golden');
@rmdir($tmp);
foreach (glob($tmp2 . '/*/*') ?: [] as $f) { unlink($f); }
@rmdir($tmp2 . '/raw');
@rmdir($tmp2 . '/golden');
@rmdir($tmp2);
if (defined('PHPMAN_HOME') && is_dir(PHPMAN_HOME)) {
    foreach (glob(PHPMAN_HOME . '/*/*') ?: [] as $f) { @unlink($f); }
    @rmdir(PHPMAN_HOME . '/db');
    @rmdir(PHPMAN_HOME . '/logs');
    @rmdir(PHPMAN_HOME . '/backups');
    @rmdir(PHPMAN_HOME);
}

exit(test_summary());
