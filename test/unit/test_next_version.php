<?php
/**
 * Unit tests: deploy/next-version.sh — 从 tag 列表算下一个发布版本号
 * Requirement: docs/08-RELEASE-VERSION-INTEGRITY.md（issue #250）
 *
 * 为什么版本号要从远端 tag 算：`git describe` 读的是**运行 make 那台机器的** tag
 * 集合，同一个提交在不同机器上会给出不同答案 —— 生产就是这样把已经越过 v4.12.2 的
 * 代码标成了 v4.11.2-59-gf06411a。
 *
 * 脚本不联网：调用方把 `git ls-remote --tags origin` 的输出喂进 stdin，测试喂 fixture。
 */
declare(strict_types=1);
require_once __DIR__ . '/../test_helper.php';

echo "=== Unit: Release version computation ===\n\n";

/**
 * 跑 next-version.sh，返回 [退出码, stdout, stderr]。
 * stdout 与 stderr 分开收：stdout 只允许出现版本号本身，警告必须走 stderr。
 */
function next_version(string $tags, string $explicit = ''): array {
    $script = escapeshellarg(__DIR__ . '/../../deploy/next-version.sh');
    $arg = $explicit === '' ? '' : ' ' . escapeshellarg($explicit);
    $errFile = tempnam(sys_get_temp_dir(), 'nv');
    $cmd = "printf '%s\\n' " . escapeshellarg($tags) . " | $script$arg 2>" . escapeshellarg($errFile);
    $out = [];
    $rc = 0;
    exec($cmd, $out, $rc);
    $err = trim((string)file_get_contents($errFile));
    unlink($errFile);
    return [$rc, trim(implode("\n", $out)), $err];
}

echo "--- 默认：取最高三段 tag，补丁 +1 ---\n";

[$rc, $out, $err] = next_version("refs/tags/v4.11.2\nrefs/tags/v4.12.0\nrefs/tags/v4.12.2\n");
assert_equals(0, $rc, "tag 列表正常时退出 0");
assert_equals("4.12.3", $out, "最高 tag v4.12.2 → 补丁 +1 = 4.12.3");
assert_empty($err, "正常路径不输出警告");

[$rc, $out, $err] = next_version("refs/tags/v4.12.2\nrefs/tags/v4.12.2^{}\n");
assert_equals("4.12.3", $out, "annotated tag 的 ^{} 行被忽略");

[$rc, $out, $err] = next_version("refs/tags/v4.12.2\nrefs/tags/v4.12.10\n");
assert_equals("4.12.11", $out, "版本比较是数值的（4.12.10 > 4.12.2），不是字典序");

echo "\n--- 非三段 tag 一律忽略 ---\n";

// 仓库里真实存在这些：两段的 v2.0..v4.5、带后缀的 v3.6.1-readme、非版本的 phpMan_2/time
[$rc, $out, $err] = next_version(
    "refs/tags/v2.0\nrefs/tags/v4.5\nrefs/tags/v3.6.1-readme\n"
    . "refs/tags/phpMan_2\nrefs/tags/time\nrefs/tags/v4.12.2\n"
);
assert_equals("4.12.3", $out, "两段 / 带后缀 / 非版本 tag 都不参与计算");

[$rc, $out, $err] = next_version("refs/tags/v4.12.2\n");
assert_equals("4.12.3", $out, "只有 v 前缀的写法也认");

[$rc, $out, $err] = next_version("refs/tags/4.12.2\n");
assert_equals("4.12.3", $out, "没有 v 前缀的写法也认");

echo "\n--- 远端一个三段 tag 都没有（Review Focus 2）---\n";

[$rc, $out, $err] = next_version("refs/tags/v2.0\nrefs/tags/time\n");
assert_equals(0, $rc, "无三段 tag 时退出 0（不是错误：首次发布就是这个状态）");
assert_equals("0.0.1", $out, "无三段 tag → 0.0.1");
assert_not_equals("", $err, "无三段 tag 时在 stderr 说明原因");

[$rc, $out, $err] = next_version("");
assert_equals("0.0.1", $out, "空输入 → 0.0.1");

echo "\n--- 显式 VERSION 归一化成三段 ---\n";

[$rc, $out, $err] = next_version("refs/tags/v4.12.2\n", "4.13");
assert_equals(0, $rc, "VERSION=4.13 接受");
assert_equals("4.13.0", $out, "VERSION=4.13 → 4.13.0（不归一化的话下次发布扫不到它）");

[$rc, $out, $err] = next_version("refs/tags/v4.12.2\n", "5.0");
assert_equals("5.0.0", $out, "VERSION=5.0 → 5.0.0");

[$rc, $out, $err] = next_version("refs/tags/v4.12.2\n", "4.13.1");
assert_equals("4.13.1", $out, "VERSION=4.13.1 原样保留");

[$rc, $out, $err] = next_version("refs/tags/v4.12.2\n", "v4.13");
assert_equals("4.13.0", $out, "VERSION 带 v 前缀也能归一化");

echo "\n--- 显式 VERSION 必须严格大于远端最新（Review Focus 4）---\n";

[$rc, $out, $err] = next_version("refs/tags/v4.12.2\n", "4.12.2");
assert_not_equals(0, $rc, "VERSION 恰好等于远端最新 → 拒绝（>= 会让同一版本号发两次）");
assert_contains("4.12.2", $err, "拒绝时点名冲突的版本号");

[$rc, $out, $err] = next_version("refs/tags/v4.12.2\n", "4.11");
assert_not_equals(0, $rc, "VERSION 小于远端最新 → 拒绝");
assert_contains("4.12.2", $err, "拒绝时给出远端最新是多少");

[$rc, $out, $err] = next_version("refs/tags/v4.12.2\n", "4.12.1.9");
assert_not_equals(0, $rc, "四段版本号 → 拒绝");

echo "\n--- 非法 VERSION ---\n";

foreach (["abc", "4.x", "4..1", ".4", "4.", "-1", "4.13-rc1"] as $bad) {
    [$rc, $out, $err] = next_version("refs/tags/v4.12.2\n", $bad);
    assert_not_equals(0, $rc, "VERSION='{$bad}' → 拒绝");
    assert_equals("", $out, "VERSION='{$bad}' 拒绝时不往 stdout 写东西");
}

exit(test_summary());
