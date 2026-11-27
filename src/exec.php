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
 *
 * $seconds 省略时用 PHPMAN_CMD_TIMEOUT。传别的值只为一件事：`pydoc3 -k` 实测
 * 10–14s，正好压在全局 15s 上，需要一个更宽的上界（PHPMAN_PYDOC_K_TIMEOUT）——
 * 而放宽全局会削弱所有命令的槽保证，所以做成按调用点覆盖。结果按秒数缓存，
 * 每条命令仍然只探测一次文件系统。
 *
 * #285: SIGTERM 只是"请求退出"，不响应它的子进程会把 timeout(1) 自己拖住。
 * 生产实测 `timeout 1 sh -c 'trap "" TERM; sleep 5'` 跑了 5.01s，而
 * `timeout -k 1 1 …` 在 2.00s 就结束。所以真实上界是 N + PHPMAN_CMD_KILL_AFTER
 * （默认 5 秒）—— 后者为 0 时退回只有 SIGTERM 的旧行为。
 */
function phpManTimeoutPrefix(?int $seconds = null): string {
    static $cache = [];
    $seconds = $seconds ?? (defined('PHPMAN_CMD_TIMEOUT') ? (int)PHPMAN_CMD_TIMEOUT : 0);
    if ($seconds <= 0) return '';
    $killAfter = defined('PHPMAN_CMD_KILL_AFTER') ? (int)PHPMAN_CMD_KILL_AFTER : 0;
    if ($killAfter < 0) $killAfter = 0;
    $key = $seconds . '|' . $killAfter;
    if (array_key_exists($key, $cache)) return $cache[$key];
    $prefix = '';
    foreach (['/usr/bin/timeout', '/bin/timeout'] as $p) {
        if (is_executable($p)) {
            $prefix = $p . ($killAfter > 0 ? ' -k ' . $killAfter : '') . ' ' . $seconds . ' ';
            break;
        }
    }
    return $cache[$key] = $prefix;
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
 * 上界由两段组成：N 秒后 SIGTERM，再 PHPMAN_CMD_KILL_AFTER 秒后 SIGKILL。
 */
function phpManWrapCommand(string $cmd, ?int $seconds = null): string {
    $prefix = phpManTimeoutPrefix($seconds);
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
 * 返回 true 表示命令成功（退出码 0）。超时类退出码会被记一行日志 —— 它是「这条
 * 路径病态」的直接证据，而 mf 遥测只在渲染跑完时才写行，看不到它。日志会区分
 * 「真超时」和「命令自己返回 124」：124 不是 timeout(1) 独占的，xargs 在子进程
 * 以 255 退出时也返回它（#283），只看退出码会把 0.1 秒的失败记成 15 秒超时。
 *
 * 加了 -k 之后还有一个坑：timeout 用 SIGKILL 收尾时，**PHP 的 exec() 报的是
 * 信号号 9**，不是 shell 里看到的 137（128+9）。生产 PHP 8.3 实测：
 *   timeout -k 1 1 sh -c 'trap "" TERM; sleep 5'  → rc=9
 *   timeout 1     sh -c 'sleep 5'                 → rc=124
 * 所以超时类要同时认 9 / 124 / 137，再用「是否跑到上界」区分真超时。
 *
 * 边界说明：这个上界保证**命令本身**在 N + killAfter 内被杀，不保证调用方同样
 * 早返回。若命令 fork 出的孙进程继承了 stdout 并活过被杀的父进程，exec() 仍要
 * 等到管道 EOF —— CI 上实测过这一形态：timeout 2s 就返回 rc=137，而调用方
 * 5.05s 才回来。产品的命令（man/perldoc/pydoc3/ri/info）都不这样做，但如果将来
 * 接一个会 fork 常驻子进程的命令，这里需要改 proc_open + 截止时间。
 */
function phpManExec(string $cmd, array &$lines, ?int &$rc = null, ?int $timeout = null): bool {
    $lines = [];
    $rc = 0;
    $start = microtime(true);
    exec(phpManWrapCommand($cmd, $timeout), $lines, $rc);
    $elapsed = microtime(true) - $start;
    if (in_array($rc, [9, 124, 137], true) && function_exists('phpManLog')) {
        $secs = $timeout ?? (defined('PHPMAN_CMD_TIMEOUT') ? (int)PHPMAN_CMD_TIMEOUT : 0);
        // 124 不是 timeout(1) 独占的退出码。GNU xargs 的约定是：123 = 子进程以
        // 1–125 退出，**124 = 子进程以 255 退出**，125 = 子进程被信号杀死。所以
        // `perldoc -l X | head -1 | tr | xargs -0 pod2text` 里只要 pod2text 以
        // 255 失败（POD 语法错），整条管道就返回 124 —— 和生产上「15 秒超时」
        // 长得一模一样，而它其实 0.1 秒就结束了，日志里 35 条假「超时」全是这个
        // 来路（#283）。判据因此必须带耗时：只有真的跑到了上界才算超时。
        if (phpManTimeoutPrefix($timeout) !== '' && $elapsed >= $secs - 0.5) {
            phpManLog(sprintf('exec timed out after %ds (elapsed %.1fs, rc %d): %s', $secs, $elapsed, $rc, $cmd));
        } else {
            // 没有 timeout(1) 的机器（macOS 默认）根本没有上界，这时说
            // "(bound 15s)" 会把人引到错误的方向 —— 124 只可能来自命令自己。
            // $secs <= 0 是"显式关掉了上界"，与"这台机器没有 timeout(1)"是两件事
            // —— 前者在 macOS 上也会出现（PHPMAN_CMD_TIMEOUT=0）。
            $where = $secs <= 0
                ? ' (no bound configured)'
                : (phpManTimeoutPrefix($timeout) !== ''
                    ? sprintf(' (bound %ds)', $secs)
                    : ' (no timeout(1) on this host)');
            phpManLog(sprintf('exec exited %d after %dms — not a timeout%s: %s',
                $rc, (int)round($elapsed * 1000), $where, $cmd));
        }
    }
    return $rc === 0;
}

/** shell_exec() 的替代品，同样加上界。返回 trim 过的输出（失败时为空串）。 */
function phpManExecString(string $cmd, ?int $timeout = null): string {
    return trim((string)@shell_exec(phpManWrapCommand($cmd, $timeout)));
}
