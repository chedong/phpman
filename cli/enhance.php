#!/usr/bin/env php
<?php
/**
 * cli/enhance.php — LLM emoji enhancement for specific pages.
 *
 * Replaces: php phpMan.php --enhance=mode:name1,name2,...
 *
 * For full batch enhancement with rate limiting, resume, status, etc.,
 * use tools/batch_enhance.php instead.
 *
 * Usage:
 *   php cli/enhance.php man:ls
 *   php cli/enhance.php man:ls,tar,grep
 *   php cli/enhance.php perldoc:File::Basename,Getopt::Long
 *   php cli/enhance.php pydoc:os,json,re
 *   php cli/enhance.php ri:Array,String,File
 *   php cli/enhance.php info:coreutils,bash,make
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(400);
    die("CLI only\n");
}

$options = getopt('hr', ['help', 'rebuild']);
$rebuild = isset($options['r']) || isset($options['rebuild']);
if (isset($options['help']) || isset($options['h']) || $argc < 2) {
    echo "phpMan LLM Emoji Enhancement\n\n";
    echo "Usage: php cli/enhance.php [-r|--rebuild] <mode>:<name1>[,<name2>,...]\n\n";
    echo "Options:\n";
    echo "  -r, --rebuild   Force re-enhance even if cache exists\n\n";
    echo "Examples:\n";
    echo "  php cli/enhance.php man:ls\n";
    echo "  php cli/enhance.php -r man:ls                    # force redo\n";
    echo "  php cli/enhance.php man:ls,tar,grep\n";
    echo "  php cli/enhance.php perldoc:File::Basename,Getopt::Long,Digest::MD5\n";
    echo "  php cli/enhance.php info:coreutils,bash,make\n";
    echo "  php cli/enhance.php pydoc:os,json,re\n";
    echo "  php cli/enhance.php ri:Array,String,File\n\n";
    echo "For full batch enhancement (rate-limited, resumable, with status):\n";
    echo "  php tools/batch_enhance.php --help\n\n";
    echo "Docs: https://github.com/chedong/phpman\n";
    exit(0);
}

// Find the mode:name spec (first non-flag argument)
$spec = '';
for ($i = 1; $i < $argc; $i++) {
    if ($argv[$i][0] !== '-') {
        $spec = $argv[$i];
        break;
    }
}
if ($spec === '') {
    fwrite(STDERR, "ERROR: missing mode:name(s) argument. Use --help for examples.\n");
    exit(1);
}
if (!preg_match('/^([a-z]+):(.+)$/', $spec, $m)) {
    fwrite(STDERR, "ERROR: format is mode:name1,name2,... Use --help for examples.\n");
    exit(1);
}

// Resolve PHPMAN_HOME the same way phpMan.php does
$config_file = __DIR__ . '/../phpman.config.php';
if (file_exists($config_file)) {
    require $config_file;
}

if (!defined('PHPMAN_HOME') || PHPMAN_HOME === '') {
    $home = getenv('HOME') ?: ($_SERVER['HOME'] ?? '');
    if (!$home && function_exists('posix_getpwuid')) {
        $pw = posix_getpwuid(posix_getuid());
        $home = $pw['dir'] ?? '';
    }
    define('PHPMAN_HOME', getenv('PHPMAN_HOME') ?: $home . '/.phpman');
}

// Load phpMan core
define('PHPMAN_NO_CLI_DISPATCH', true);
require_once PHPMAN_HOME . '/phpMan.php';

if (!defined('LLM_API_KEY') || LLM_API_KEY === '') {
    fwrite(STDERR, "ERROR: LLM_API_KEY not configured in phpman.config.php\n");
    exit(1);
}

$mode = $m[1];
$names = explode(',', $m[2]);

$consecutiveFailures = 0;
$maxConsecutiveFailures = 3;
$enhanced = 0;
$failed = 0;

foreach ($names as $name) {
    $name = trim($name);
    if ($name === '') continue;

    // --rebuild: delete existing cache so enhanceManPage re-generates
    if ($rebuild) {
        $pcache = new PageCache();
        $pcache->delete($mode, $name, '');
        echo "  Purged cache for {$mode}:{$name}\n";
    }
    echo "Enhancing {$mode}:{$name}...\n";
    $result = enhanceManPage($mode, $name);
    if ($result !== '') {
        $enhanced++;
        $consecutiveFailures = 0;
    } else {
        $consecutiveFailures++;
        $failed++;
        echo "  FAILED ({$consecutiveFailures}/{$maxConsecutiveFailures} consecutive)\n";
        if ($consecutiveFailures >= $maxConsecutiveFailures) {
            fwrite(STDERR, "\nERROR: {$maxConsecutiveFailures} consecutive LLM failures — aborting.\n");
            fwrite(STDERR, "  Check phpman_error.log for details.\n");
            break;
        }
    }
}

echo "\nDone. Enhanced {$enhanced} doc(s), {$failed} failed.\n";
