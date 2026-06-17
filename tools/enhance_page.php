#!/usr/bin/env php
<?php
/**
 * enhance_page.php — Single-page LLM emoji enhancement CLI tool.
 *
 * Fetches a page's Markdown from a running phpMan instance, sends it to the
 * LLM for emoji enhancement, and writes the result directly into the phpMan
 * SQLite cache (emoji_md format).  Useful when the server cannot fork man(1)
 * (e.g. shared hosting under load) but the web frontend is still alive.
 *
 * Usage:
 *   php tools/enhance_page.php man ls           # man section 1 (default)
 *   php tools/enhance_page.php man crontab 5    # man section 5
 *   php tools/enhance_page.php perldoc File::Basename
 *   php tools/enhance_page.php pydoc os
 *
 * Requires phpman.config.php with LLM_API_URL, LLM_API_KEY, LLM_MODEL,
 * LLM_MAX_TOKENS, and PHPMAN_HOME (for the cache DB path).
 *
 * The target phpMan instance URL defaults to the environment variable
 * PHPMAN_BASE_URL, falling back to 'http://localhost:8080/phpMan.php'.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(400);
    die("CLI only\n");
}

if ($argc < 3) {
    fwrite(STDERR, "Usage: php tools/enhance_page.php <mode> <name> [section]\n");
    fwrite(STDERR, "  mode: man | perldoc | info | pydoc | ri\n");
    fwrite(STDERR, "  name: command or module name\n");
    fwrite(STDERR, "  section: numeric (default: 1 for man, empty for others)\n");
    exit(1);
}

$mode = $argv[1];
$name = $argv[2];
$section = $argv[3] ?? ($mode === 'man' ? '1' : '');

// Load config
$configFile = __DIR__ . '/../phpman.config.php';
if (!file_exists($configFile)) {
    fwrite(STDERR, "ERROR: phpman.config.php not found at $configFile\n");
    exit(1);
}
require $configFile;

// Validate required constants
foreach (['LLM_API_URL', 'LLM_API_KEY', 'LLM_MODEL', 'LLM_MAX_TOKENS', 'PHPMAN_HOME'] as $c) {
    if (!defined($c) || constant($c) === '') {
        fwrite(STDERR, "ERROR: $c not configured in phpman.config.php\n");
        exit(1);
    }
}

$baseUrl = getenv('PHPMAN_BASE_URL') ?: 'http://localhost:8080/phpMan.php';
$sectionPath = ($section !== '') ? '/' . $section : '';
$url = rtrim($baseUrl, '/') . '/' . $mode . '/' . urlencode($name) . $sectionPath . '/markdown';

echo "Fetching: $url\n";
$plainMd = @file_get_contents($url);
if ($plainMd === false || trim($plainMd) === '') {
    fwrite(STDERR, "ERROR: could not fetch markdown from $url\n");
    exit(1);
}
echo "Markdown: " . strlen($plainMd) . " chars\n";

// Truncate very long documents to fit LLM context
$maxLen = 12000;
if (strlen($plainMd) > $maxLen) {
    $plainMd = substr($plainMd, 0, $maxLen) . "\n\n...(truncated)";
    echo "Truncated to: " . strlen($plainMd) . " chars\n";
}

$systemPrompt = "You are a Linux documentation emoji-enhancement assistant. Transform plain man page Markdown into an emoji-rich, visually scannable version optimized for both human developers and AI agents.\n\n" .
    "Output rules:\n" .
    "1. Output ONLY valid Markdown — no HTML tags, no code fences, no JSON wrapper, no preamble\n" .
    "2. Preserve ALL original technical information (options, flags, syntax, descriptions)\n" .
    "3. Do NOT invent new content — only reorganize and decorate existing content\n" .
    "4. Use ONLY Markdown formatting: `backticks` for code, **double stars** for bold, [text](url) for links. NEVER use <code>, <b>, <i>, <a> or any HTML tags.\n" .
    "5. Options and examples MUST use standard Markdown list syntax: start each item with \"- \" (dash+space) followed by content. NEVER use emoji as list markers.\n\n" .
    "Style rules:\n" .
    "- Every ## section heading gets ONE relevant emoji prefix\n" .
    "- ## NAME section: add emoji tagline below heading\n" .
    "- Add a Quick Reference table after SYNOPSIS with common use cases\n" .
    "- Group related options into ### subsections with emoji titles\n" .
    "- Each option row: \"- 📁 `-f`, `--flag`\" — dash+space then descriptive emoji matching the option's purpose. Do NOT use bullet-like emoji (🔹🔸▪️▫️➡️). Use meaningful ones: 📁 for files, 📋 for format, ⏱️ for time/sort, 🎨 for color, 🔗 for links, 🛡️ for security, etc.\n" .
    "- Usage examples: each line annotated with emoji comments after #\n" .
    "- Exit codes section: emoji table\n" .
    "- SEE ALSO section: each reference gets relevant emoji\n" .
    "- Keep all original command syntax and flags exactly as-is\n" .
    "- Emoji should be standard Unicode, widely supported";

$payload = json_encode([
    'model' => LLM_MODEL,
    'messages' => [
        ['role' => 'system', 'content' => $systemPrompt],
        ['role' => 'user', 'content' => "Transform this man page Markdown into an emoji-enhanced version:\n\n{$plainMd}"],
    ],
    'max_tokens' => min((int)LLM_MAX_TOKENS, 16384),
    'temperature' => 0.3,
], JSON_UNESCAPED_UNICODE);

echo "Payload: " . strlen($payload) . " bytes\n";

$start = microtime(true);
$ch = curl_init(LLM_API_URL);
curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => $payload,
    CURLOPT_HTTPHEADER => [
        'Content-Type: application/json',
        'Authorization: Bearer ' . LLM_API_KEY,
        'User-Agent: phpMan/enhance-cli',
    ],
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 300,
]);
$response = curl_exec($ch);
$error = curl_error($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$totalTime = curl_getinfo($ch, CURLINFO_TOTAL_TIME);
curl_close($ch);

printf("HTTP: %d | Time: %.1fs\n", $httpCode, $totalTime);

if ($response === false || $response === '') {
    fwrite(STDERR, "ERROR: LLM call failed: " . ($error ?: 'empty response') . "\n");
    exit(1);
}

$data = json_decode($response, true);
if ($data === null) {
    fwrite(STDERR, "ERROR: JSON decode failed\n");
    exit(1);
}
if (!empty($data['error'])) {
    fwrite(STDERR, "ERROR: LLM API error: " . ($data['error']['message'] ?? 'unknown') . "\n");
    exit(1);
}

$content = $data['choices'][0]['message']['content'] ?? '';
if ($content === '') {
    $content = $data['choices'][0]['message']['reasoning_content'] ?? '';
}

// Clean up LLM output
$content = trim($content);
$content = preg_replace('/^```(?:markdown|md)?\s*\n?/m', '', $content);
$content = preg_replace('/\n?```\s*$/m', '', $content);
$content = preg_replace('/^\[?[\w.-]+\]?\(\d+\w*\)\s+.*\s+\[?[\w.-]+\]?\(\d+\w*\)\s*\n/', '', $content);

echo "Enhanced: " . strlen($content) . " chars\n";

// Write to cache DB
$dbPath = rtrim(PHPMAN_HOME, '/') . '/db/phpman_cache.db';
$db = new SQLite3($dbPath);
$compressed = gzcompress($content);
$stmt = $db->prepare(
    "INSERT OR REPLACE INTO cache (mode, name, section, format, content, content_len, status, ttl, created_at, updated_at)
     VALUES (:mode, :name, :section, :format, :content, :len, :status, 0, strftime('%s','now'), strftime('%s','now'))"
);
$stmt->bindValue(':mode', $mode, SQLITE3_TEXT);
$stmt->bindValue(':name', $name, SQLITE3_TEXT);
$stmt->bindValue(':section', $section, SQLITE3_TEXT);
$stmt->bindValue(':format', 'emoji_md', SQLITE3_TEXT);
$stmt->bindValue(':content', $compressed, SQLITE3_BLOB);
$stmt->bindValue(':len', strlen($content), SQLITE3_INTEGER);
$stmt->bindValue(':status', 'found', SQLITE3_TEXT);
$stmt->execute();

echo "OK: cached {$mode}/{$name} (" . strlen($content) . " chars)\n";
echo "First 200 chars: " . substr($content, 0, 200) . "\n";
