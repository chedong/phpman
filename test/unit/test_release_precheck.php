<?php
/**
 * Unit tests: deploy/release-precheck.sh — 发布前的三条守卫
 * Requirement: docs/08-RELEASE-VERSION-INTEGRITY.md（issue #250）
 *
 * 三条守卫（顺序即脚本的检查顺序，第一个失败就退出）：
 *   1. origin/master 是 HEAD 的祖先 —— 挡住「从落后的 checkout 发布」
 *   2. HEAD 未被 origin 上的任何 tag 指着 —— 挡住重复发布同一提交
 *   3. 部署路径上工作树干净 —— 挡住「部署的字节 ≠ tag 指向的提交」
 *
 * 每条都造一个真实的临时 git 仓库来测，因为守卫的价值全在「真的能拦住」。
 */
declare(strict_types=1);
require_once __DIR__ . '/../test_helper.php';

echo "=== Unit: Release precheck guards ===\n\n";

/** 造一个临时仓库 + 一个 bare 的 origin，返回 [repoDir, cleanupCallable] */
function make_repo(): array {
    $base = sys_get_temp_dir() . '/precheck-' . bin2hex(random_bytes(6));
    $repo = "$base/repo";
    mkdir($repo, 0777, true);

    $run = function (string $cwd, string $cmd): void {
        $out = [];
        $rc = 0;
        exec('cd ' . escapeshellarg($cwd) . ' && ' . $cmd . ' 2>&1', $out, $rc);
    };

    $run($repo, 'git init -q -b master');
    $run($repo, 'git config user.email t@example.com && git config user.name tester');
    $run($repo, 'echo a > f.txt && git add f.txt && git commit -qm init');
    $run($base, 'git init -q --bare origin.git');
    $run($repo, 'git remote add origin ' . escapeshellarg("$base/origin.git") . ' && git push -q origin master');

    return [$repo, function () use ($base) {
        exec('rm -rf ' . escapeshellarg($base));
    }];
}

/** 在 $repo 里跑 release-precheck.sh，返回 [退出码, stdout+stderr] */
function precheck(string $repo, string $paths = ''): array {
    $script = escapeshellarg(__DIR__ . '/../../deploy/release-precheck.sh');
    $cmd = 'cd ' . escapeshellarg($repo) . " && $script" . ($paths === '' ? '' : ' ' . $paths);
    $out = [];
    $rc = 0;
    exec($cmd . ' 2>&1', $out, $rc);
    return [$rc, trim(implode("\n", $out))];
}

echo "--- 正例 ---\n";

[$repo, $cleanup] = make_repo();
[$rc, $out] = precheck($repo);
assert_equals(0, $rc, "干净且同步的仓库 → 通过");
assert_empty($out, "通过时不输出任何东西");
$cleanup();

// 本地领先（有未推送的提交）是正常发布状态：守卫只要求 origin/master 是祖先
[$repo, $cleanup] = make_repo();
exec('cd ' . escapeshellarg($repo) . ' && echo b >> f.txt && git commit -qam second');
[$rc, $out] = precheck($repo);
assert_equals(0, $rc, "本地领先（有未推送提交）→ 通过");
$cleanup();

// Review Focus 3：detached HEAD 要有确定结果
[$repo, $cleanup] = make_repo();
exec('cd ' . escapeshellarg($repo) . ' && git checkout -q --detach HEAD');
[$rc, $out] = precheck($repo);
assert_equals(0, $rc, "detached HEAD 且未落后 → 通过");
$cleanup();

echo "\n--- 守卫 1：origin/master 必须是 HEAD 的祖先 ---\n";

[$repo, $cleanup] = make_repo();
exec('cd ' . escapeshellarg($repo) . ' && echo b >> f.txt && git commit -qam second && git push -q origin master');
exec('cd ' . escapeshellarg($repo) . ' && git reset -q --hard HEAD~1');
[$rc, $out] = precheck($repo);
assert_not_equals(0, $rc, "本地落后 origin/master → 拒绝");
assert_contains("origin/master", $out, "拒绝时点名 origin/master");
$cleanup();

echo "\n--- 守卫 2：HEAD 未被 origin 上的 tag 指着 ---\n";

[$repo, $cleanup] = make_repo();
exec('cd ' . escapeshellarg($repo) . ' && git tag -a v9.9.9 -m v9.9.9 && git push -q origin v9.9.9');
[$rc, $out] = precheck($repo);
assert_not_equals(0, $rc, "HEAD 已作为 v9.9.9 发布过 → 拒绝");
assert_contains("v9.9.9", $out, "拒绝时点名已有的 tag");
$cleanup();

// lightweight tag 也要认（git ls-remote 对它与 annotated tag 的输出形态不同）
[$repo, $cleanup] = make_repo();
exec('cd ' . escapeshellarg($repo) . ' && git tag v8.8.8 && git push -q origin v8.8.8');
[$rc, $out] = precheck($repo);
assert_not_equals(0, $rc, "lightweight tag 指着 HEAD → 也拒绝");
assert_contains("v8.8.8", $out, "拒绝时点名 lightweight tag");
$cleanup();

// 远端有 tag 但不指着 HEAD → 不拦
[$repo, $cleanup] = make_repo();
exec('cd ' . escapeshellarg($repo) . ' && git tag -a v7.7.7 -m v7.7.7 && git push -q origin v7.7.7 && echo c >> f.txt && git commit -qam third');
[$rc, $out] = precheck($repo);
assert_equals(0, $rc, "远端有 tag 但不指着 HEAD → 通过");
$cleanup();

echo "\n--- 守卫 3：部署路径上工作树干净 ---\n";

[$repo, $cleanup] = make_repo();
exec('cd ' . escapeshellarg($repo) . ' && mkdir -p src && echo x > src/new.php');
[$rc, $out] = precheck($repo, 'src phpMan.php');
assert_not_equals(0, $rc, "src/ 下有未跟踪文件 → 拒绝（rsync -avz src/ 会把它传上去）");
assert_contains("src/new.php", $out, "拒绝时点名具体文件");
$cleanup();

[$repo, $cleanup] = make_repo();
exec('cd ' . escapeshellarg($repo) . ' && mkdir -p src && echo x > src/config.php && git add src/config.php && git commit -qm add && echo y >> src/config.php');
[$rc, $out] = precheck($repo, 'src phpMan.php');
assert_not_equals(0, $rc, "src/ 下被跟踪文件有改动 → 拒绝");
assert_contains("src/config.php", $out, "拒绝时点名具体文件");
$cleanup();

// 部署路径**之外**的脏文件不拦：根目录放个草稿笔记不该拦住发布
[$repo, $cleanup] = make_repo();
exec('cd ' . escapeshellarg($repo) . ' && echo x > scratch-note.txt');
[$rc, $out] = precheck($repo, 'src phpMan.php');
assert_equals(0, $rc, "部署路径之外的脏文件不拦");
$cleanup();

echo "\n--- Review Focus 1：取不到远端必须中止 ---\n";

[$repo, $cleanup] = make_repo();
exec('cd ' . escapeshellarg($repo) . ' && git remote set-url origin ' . escapeshellarg($repo . '/does-not-exist.git'));
[$rc, $out] = precheck($repo);
assert_not_equals(0, $rc, "origin 不可达 → 拒绝（绝不能当成「没落后」放过）");
assert_not_equals("", $out, "origin 不可达时说明原因");
$cleanup();

exit(test_summary());
