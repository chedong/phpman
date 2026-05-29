<?php
/**
 * E2E tests: AI Agent scenarios (MCP/JSON client)
 * Persona: 🤖 Agent uses MCP tools or JSON API to look up commands
 *
 * Use cases:
 *   A01: GET /man/ls/1/json → parse flags table
 *   A02: POST /mcp initialize → get server info
 *   A03: POST /mcp tools/list → discover cli_help, cli_search
 *   A04: POST /mcp tools/call cli_help → get structured markdown
 *   A05: POST /mcp tools/call cli_search → search results
 *   A06: Follow see_also links → /man/dir/1/json
 *   A07: Perldoc auto-detect via MCP → File::Path
 *   A08: MCP error handling → invalid tool name
 *   A09: ETag caching → 304 on repeat request
 *   A10: Gzip compression → smaller payload
 */
require_once __DIR__ . '/../test_helper.php';

$BASE = "https://www.chedong.com/phpMan.php";

function fetchJson(string $url): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_HTTPHEADER => ["Accept: application/json"],
        CURLOPT_ENCODING => "gzip",
    ]);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ["code" => $code, "body" => $body, "data" => json_decode($body, true)];
}

function mcpCall(string $method, array $params = [], int $id = 1): array {
    $url = $GLOBALS["BASE"] . "/mcp";
    $payload = json_encode(["jsonrpc" => "2.0", "method" => $method, "params" => $params, "id" => $id]);
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_HTTPHEADER => ["Content-Type: application/json"],
        CURLOPT_TIMEOUT => 30,
    ]);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ["code" => $code, "body" => $body, "data" => json_decode($body, true)];
}

echo "=== E2E: AI Agent Scenarios ===\n\n";

// A01: JSON API
echo "A01: GET /man/ls/1/json\n";
$r = fetchJson("{$BASE}/man/ls/1/json");
assert_equals(200, $r["code"], "HTTP 200");
assert_equals("ls", $r["data"]["parameter"], "parameter = ls");
assert_equals(true, count($r["data"]["flags"]) > 0, "has flags");

// A02: MCP initialize
echo "\nA02: POST /mcp initialize\n";
$r = mcpCall("initialize", ["protocolVersion" => "2025-03-26", "clientInfo" => ["name" => "test"]]);
assert_equals(200, $r["code"], "HTTP 200");
assert_equals("phpMan", $r["data"]["result"]["serverInfo"]["name"], "server name = phpMan");

// A03: MCP tools/list
echo "\nA03: POST /mcp tools/list\n";
$r = mcpCall("tools/list");
$toolNames = array_map(fn($t) => $t["name"], $r["data"]["result"]["tools"]);
assert_equals(true, in_array("cli_help", $toolNames), "has cli_help tool");
assert_equals(true, in_array("cli_search", $toolNames), "has cli_search tool");

// A04: MCP cli_help
echo "\nA04: POST /mcp cli_help ls\n";
$r = mcpCall("tools/call", ["name" => "cli_help", "arguments" => ["command" => "ls"]]);
assert_equals(true, isset($r["data"]["result"]["content"]), "has content");
$text = $r["data"]["result"]["content"][0]["text"] ?? "";
assert_contains("ls", $text, "response mentions ls");

// A05: MCP cli_search
echo "\nA05: POST /mcp cli_search file\n";
$r = mcpCall("tools/call", ["name" => "cli_search", "arguments" => ["query" => "file"]]);
$text = $r["data"]["result"]["content"][0]["text"] ?? "";
assert_equals(true, strlen($text) > 10, "search returns results");

// A06: Follow see_also (JSON)
echo "\nA06: GET /man/dir/1/json (see_also follow)\n";
$r = fetchJson("{$BASE}/man/dir/1/json");
assert_equals(200, $r["code"], "HTTP 200");
assert_equals("dir", $r["data"]["parameter"], "parameter = dir");

// A07: Perldoc auto-detect via MCP
echo "\nA07: POST /mcp cli_help File::Path (perldoc auto-detect)\n";
$r = mcpCall("tools/call", ["name" => "cli_help", "arguments" => ["command" => "File::Path"]]);
// May fail if perldoc not installed, but should not 500
assert_equals(true, $r["code"] === 200, "HTTP 200 (graceful)");

// A08: MCP error handling
echo "\nA08: POST /mcp unknown_tool\n";
$r = mcpCall("tools/call", ["name" => "unknown_tool", "arguments" => []]);
assert_equals(true, isset($r["data"]["error"]), "returns JSON-RPC error");

// A09: ETag caching
echo "\nA09: ETag 304 caching\n";
$ch = curl_init("{$BASE}/man/ls/1/json");
curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_TIMEOUT => 30]);
$resp = curl_exec($ch);
$headers = substr($resp, 0, curl_getinfo($ch, CURLINFO_HEADER_SIZE));
curl_close($ch);
preg_match('/ETag: (.+)/i', $headers, $m);
$etag = trim($m[1] ?? "");
if ($etag) {
    $ch = curl_init("{$BASE}/man/ls/1/json");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ["If-None-Match: {$etag}"],
        CURLOPT_TIMEOUT => 10,
    ]);
    curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    assert_equals(304, $code, "ETag → 304 Not Modified");
} else {
    echo "  ⚠️  No ETag header found, skipping\n";
}

// A10: Gzip compression
echo "\nA10: Gzip compression\n";
$ch = curl_init("{$BASE}/man/ls/1/json");
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HEADER => true,
    CURLOPT_HTTPHEADER => ["Accept-Encoding: gzip"],
    CURLOPT_TIMEOUT => 30,
]);
$resp = curl_exec($ch);
$headers = substr($resp, 0, curl_getinfo($ch, CURLINFO_HEADER_SIZE));
curl_close($ch);
assert_contains("gzip", strtolower($headers), "Content-Encoding: gzip");

exit(test_summary());
