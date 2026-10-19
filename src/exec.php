<?php
// exec.php — 所有文档渲染子进程的唯一出口。
//
// 为什么需要它
// ------------
// phpMan 每次渲染都要 fork 一个外部命令（man / perldoc / pydoc3 / ri / info）。
// 这些调用原来全是裸 exec()，**没有任何时间上界**。实测生产上：
//
//   /man/DCB-DCBX/markdown     36.4s   响应体 34 字节
//   /pydoc/local/markdown      29.6s   响应体 415 字节
//   /pydoc/FTP/markdown        30.5s   响应体 756 字节
//
// 在 mod_fcgid 的固定进程池下，一次这样的请求就把一个槽占住半分钟；几个并发
// 就能让池饱和，其余所有人一起吃 503（phpman#248）。上界不改变「这些命令有多慢」，
// 它改变的是「最坏情况下一个请求能占用多久」。
//
// 为什么用 timeout(1) 而不是 proc_open
// -----------------------------------
// proc_open + 非阻塞读 + 截止时间可以做到纯 PHP、不依赖外部命令，但那要自己处理
// 管道死锁、部分读、信号传播，几十行且容易写错。GNU coreutils 的 timeout(1) 在
// 目标主机（DreamHost）上必然存在；macOS 默认没有 —— 所以探测一次并缓存，探测
// 不到就退回裸 exec()，本地开发不会因此挂掉。
//
// 超时的退出码是 124（timeout(1) 自己的约定），调用方一律按「命令失败」处理，
// 与原来 `$return_code !== 0` 的路径完全一致，所以调用点只需换函数名。

/**
 * 探测一次 timeout(1)，返回可直接前置到命令的字符串（含尾随空格）；没有则空串。
 *
 * 只看固定路径、不搜 PATH：这个函数在请求路径上被调用，不该为了它再 fork 一次
 * `command -v`。两个路径覆盖了常见发行版布局。
 */
function phpManTimeoutPrefix(): string {
    static $prefix = null;
    if ($prefix !== null) return $prefix;
    $prefix = '';
    if (defined('PHPMAN_CMD_TIMEOUT') && PHPMAN_CMD_TIMEOUT > 0) {
        foreach (['/usr/bin/timeout', '/bin/timeout'] as $p) {
            if (is_executable($p)) {
                $prefix = $p . ' ' . (int)PHPMAN_CMD_TIMEOUT . ' ';
                break;
            }
        }
    }
    return $prefix;
}

/**
 * 把一条命令包成「整条命令行都在上界之内」的形式。
 *
 * 为什么必须经 `sh -c`：`timeout N cmd1 | cmd2` 由 shell 解析成 `(timeout N cmd1) | cmd2`
 * —— 上界只盖住第一段。实测 `timeout 1 sleep 30; echo never` 会在 1 秒后杀掉 sleep，
 * 然后**照常执行 `echo never`**。而 source_perldoc.php 的命令本身就是管道
 * （`perldoc -l … | head -1 | tr … | xargs …`），不包起来等于没上界。
 *
 * `/bin/sh -c` 也正是 PHP 的 exec() 在 Unix 上用的形式，所以语义与替换前一致。
 * escapeshellarg 负责把命令里的引号（`tr '\n' '\0'`）安全地交给内层 shell。
 */
function phpManWrapCommand(string $cmd): string {
    $prefix = phpManTimeoutPrefix();
    if ($prefix === '') return $cmd;
    return $prefix . '/bin/sh -c ' . escapeshellarg($cmd);
}

/**
 * exec() 的替代品：同样的签名与语义，但命令有 PHPMAN_CMD_TIMEOUT 秒的上界。
 *
 * `?int &$rc = null` 不是可有可无的修饰。exec() 的第三个参数无类型、可选、可空，
 * 所以调用点**不初始化** `$return_code` 一直是合法的，12 个调用点就是这么写的。
 * 这个函数一开始把 `$rc` 收成必填的 `int`，于是那些调用点全部抛
 * `TypeError: Argument #3 ($rc) must be of type int, null given`（生产表现：
 * 每次页面缓存未命中都 500，缓存命中则正常，因为命中根本不走渲染）。收窄成
 * `?int` 是把 exec() 的行为原样接过来 —— 函数内部第 73 行立刻就赋 0，
 * 所以调用点拿到的永远是 int，`$rc === 0` 之类的比较不受影响。
 *
 * 返回 true 表示命令成功（退出码 0）。超时会被记一行日志 —— 它是「这条路径
 * 病态」的直接证据，而 mf 遥测只在渲染跑完时才写行，看不到它。
 */
function phpManExec(string $cmd, array &$lines, ?int &$rc = null): bool {
    $lines = [];
    $rc = 0;
    exec(phpManWrapCommand($cmd), $lines, $rc);
    if ($rc === 124 && function_exists('phpManLog')) {
        phpManLog("exec timed out after " . (int)PHPMAN_CMD_TIMEOUT . "s: " . $cmd);
    }
    return $rc === 0;
}

/** shell_exec() 的替代品，同样加上界。返回 trim 过的输出（失败时为空串）。 */
function phpManExecString(string $cmd): string {
    return trim((string)@shell_exec(phpManWrapCommand($cmd)));
}
