<?php
/**
 * Unit tests: golden-corpus render (#255, Task 4)
 *
 * 用合成语料（不跑 man/perldoc）验证 render：
 *   - 由 raw/<id>.txt 生成 golden/<id>.md（man 渲染）与 golden/<id>.fingerprint；
 *   - 黄金 md 含正文与固定基准 URL（PHPMAN_BASE_URL 钉死，环境不同黄金不变样）；
 *   - fingerprint 非空（jsonStructureFingerprint 输出）。
 *
 * 同时验证：require cli/corpus.php 不执行主流程（realpath 守卫），
 * corpusRenderMain() 在进程内可直接调用（bootstrap 的 require 在函数内）。
 */
declare(strict_types=1);
define('PHPMAN_TEST_MODE', true);
require_once __DIR__ . '/../test_helper.php';

echo "=== Unit: corpus render (#255 Task 4) ===\n\n";

// --- 合成语料 ---
// 页 1：brief 的最小 fixture 原样（注意：其中 4 空格缩进的 "    other(1)" 会被
// 渲染器的 pydoc 函数定义启发式认成 L2 小节（format_common.php /^ {4}([a-z]\w*)\(/），
// 交叉引用链接不会出现——src/ 本任务不可改，见 task-4-report）。
// 页 2：真实 man 输出的缩进形状（7 空格），用来钉死固定基准 URL 的断言。
$tmp = sys_get_temp_dir() . '/phpman-render-test-' . getmypid();
if (is_dir($tmp)) {
    foreach (glob($tmp . '/raw/*') ?: [] as $f) { unlink($f); }
    foreach (glob($tmp . '/golden/*') ?: [] as $f) { unlink($f); }
}
mkdir($tmp . '/raw', 0777, true);
file_put_contents($tmp . '/pages.txt',
    "# id\ttool\tname\tsection\n"
    . "test-page\tman\tdemo\t1\n"
    . "test-page-seealso\tman\tdemo2\t1\n");
file_put_contents($tmp . '/raw/test-page.txt', "NAME\n    demo - a demo\n\nSEE ALSO\n    other(1)\n");
file_put_contents($tmp . '/raw/test-page-seealso.txt',
    "NAME\n    demo2 - another demo\n\nSEE ALSO\n       other(1)\n");

require_once __DIR__ . '/../../cli/corpus.php';

// --- render 进程内运行，捕获 stdout ---
ob_start();
$rc = corpusRenderMain(['corpus.php', 'render', '--out=' . $tmp]);
$stdout = ob_get_clean();

assert_equals(0, $rc, "render 退出码 0");

// --- golden/<id>.md ---
$mdPath = $tmp . '/golden/test-page.md';
$md = is_file($mdPath) ? (string)file_get_contents($mdPath) : '';
assert_not_equals('', $md, "golden/test-page.md 存在且非空");
assert_contains('demo - a demo', $md, "黄金 md 包含正文 demo - a demo");

// --- 真实 man 形状的页：交叉引用链接指向固定基准 URL（环境隔离，不随本机漂移） ---
$md2 = is_file($tmp . '/golden/test-page-seealso.md')
    ? (string)file_get_contents($tmp . '/golden/test-page-seealso.md') : '';
assert_contains('https://www.chedong.com/phpMan.php/man/other/1/markdown', $md2,
    "交叉引用链接指向固定基准 URL（PHPMAN_BASE_URL 钉死）");

// --- golden/<id>.fingerprint ---
$fpPath = $tmp . '/golden/test-page.fingerprint';
assert_not_equals(false, is_file($fpPath), "golden/test-page.fingerprint 存在");
$fp = is_file($fpPath) ? (string)file_get_contents($fpPath) : '';
assert_not_equals('', trim($fp), "fingerprint 非空");
assert_match('/\(\d+lines\)/', $fp, "fingerprint 是结构指纹格式");

// --- stdout：进度行也应携带固定基准 URL（BASE_URL 钉死的第二道断言） ---
assert_contains('https://www.chedong.com/phpMan.php/man/demo/1/markdown', $stdout,
    "render 输出包含固定基准 URL");
assert_contains('golden/test-page.md', $stdout, "render 输出提到黄金文件");

// --- 清理 ---
foreach (glob($tmp . '/raw/*') ?: [] as $f) { unlink($f); }
foreach (glob($tmp . '/golden/*') ?: [] as $f) { unlink($f); }
@rmdir($tmp . '/raw');
@rmdir($tmp . '/golden');
@rmdir($tmp);

exit(test_summary());
