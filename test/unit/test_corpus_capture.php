<?php
/**
 * Unit tests: golden-corpus capture (#255, Task 3)
 *   corpusBuildCommand() — 每个 manifest 条目对应的 shell 命令
 *   corpusVersionsText() — VERSIONS.md 正文，缺失工具写 "(unavailable)"
 *
 * 同时验证：require cli/corpus.php 不会执行主流程
 * （主流程由 realpath($argv[0]) === __FILE__ 守卫），
 * 且该文件不 require _bootstrap.php——单文件可拷贝到任意主机运行。
 */
declare(strict_types=1);
define('PHPMAN_TEST_MODE', true);
require_once __DIR__ . '/../test_helper.php';
require_once __DIR__ . '/../../cli/corpus.php';

echo "=== Unit: corpus capture (#255 Task 3) ===\n\n";

echo "--- corpusBuildCommand() ---\n";
$man = ['id'=>'man-ls','tool'=>'man','name'=>'ls','section'=>'1'];
assert_equals(
    "MANROFFOPT=-rLL=100n man -Tutf8 1 'ls'",
    corpusBuildCommand($man),
    "man 命令与 source_man.php 一致"
);

$pod = ['id'=>'perldoc-DBI','tool'=>'perldoc','name'=>'DBI','section'=>''];
assert_contains("pod2text -w 100", corpusBuildCommand($pod), "perldoc 走 pod2text 管线");
assert_contains("perldoc -l 'DBI'", corpusBuildCommand($pod), "先定位 POD 源文件");
assert_not_contains("MANROFFOPT", corpusBuildCommand($pod), "perldoc 不带 man 环境变量");

$podSpaces = ['id'=>'perldoc-x','tool'=>'perldoc','name'=>'Space Doc','section'=>''];
assert_contains("'Space Doc'", corpusBuildCommand($podSpaces), "含空格 name 被 escapeshellarg 包裹");

$manSpaces = ['id'=>'man-x','tool'=>'man','name'=>'term cap','section'=>'5'];
assert_match("/-Tutf8 5 'term cap'/", corpusBuildCommand($manSpaces), "man 的 section/name 分别转义");

// 恶意 section 走转义，不允许裸拼进命令行
$evil = ['id'=>'man-evil','tool'=>'man','name'=>'ls','section'=>'1; rm -rf /'];
assert_equals(
    "MANROFFOPT=-rLL=100n man -Tutf8 '1; rm -rf /' 'ls'",
    corpusBuildCommand($evil),
    "非法 section 被 escapeshellarg 包裹"
);

echo "\n--- corpusVersionsText() ---\n";
$md = corpusVersionsText(['man'=>'man 2.12.0','groff'=>'GNU groff version 1.23.0']);
assert_contains('groff', $md, "版本写入 VERSIONS.md");
assert_contains('man 2.12.0', $md, "man 版本原文保留");
assert_contains('GNU groff version 1.23.0', $md, "groff 版本原文保留");
assert_contains('(unavailable)', corpusVersionsText(['pod2text'=>false]), "命令不存在（false）写 (unavailable)");
assert_contains('(unavailable)', corpusVersionsText(['pod2text'=>'']), "空输出也写 (unavailable)");

exit(test_summary());
