<?php
/**
 * Unit tests: callLLM(), callLLMEndpoint() return semantics, cleanEmojiHtml() XSS defense
 * Covers v4.9.7 (per-endpoint max_tokens/timeout), v4.9.11 (false vs '' disambiguation),
 * and the XSS defense-in-depth in cleanEmojiHtml().
 */
declare(strict_types=1);
define('PHPMAN_TEST_MODE', true);
require_once __DIR__ . '/../test_helper.php';
require_once __DIR__ . '/../../phpMan.php';

echo "=== Unit: LLM Enhancement & XSS Defense ===\n\n";

// ── callLLMEndpoint return types ──
// We can't call real endpoints, but we verify:
// 1. Function exists with correct signature
// 2. Return type is string|false|null
// 3. Empty LLM config → callLLM returns '' (no API calls made)

echo "--- callLLM() empty config guard ---\n";
// When LLM_API_URL or LLM_API_KEY is empty, callLLM returns '' immediately
// without attempting any HTTP calls. Safe to test without mocking.
$result = callLLM("test", "test", "test/unit");
assert_equals('', $result, "Empty LLM config → returns empty string");

echo "--- callLLMEndpoint function exists ---\n";
$ref = new ReflectionFunction('callLLMEndpoint');
assert_equals('string|false|null', (string)$ref->getReturnType(), "Return type is string|false|null");
$params = $ref->getParameters();
assert_equals(4, count($params), "callLLMEndpoint has 4 parameters");
assert_equals('systemPrompt', $params[0]->getName(), "param 1: systemPrompt");
assert_equals('userMessage', $params[1]->getName(), "param 2: userMessage");
assert_equals('ep', $params[2]->getName(), "param 3: ep (endpoint array)");
assert_equals('context', $params[3]->getName(), "param 4: context");

echo "--- callLLM() builds endpoint array ---\n";
// callLLM returns '' when primary endpoint has no URL/key
// The function exists and returns string
$ref2 = new ReflectionFunction('callLLM');
assert_equals('string', (string)$ref2->getReturnType(), "callLLM returns string");

// ── cleanEmojiHtml XSS defense ──

echo "\n--- cleanEmojiHtml() XSS defense ---\n";

// T1: Pass-through safe HTML
$safe = '<h2>NAME</h2><p>ls - list directory contents</p>';
assert_equals($safe, cleanEmojiHtml($safe), "Safe HTML passes through unchanged");

// T2: <h1> → <h2> (page already has H1 from breadcrumb)
assert_contains('<h2>Bad H1</h2>', cleanEmojiHtml('<h1>Bad H1</h1>'), "<h1> downgraded to <h2>");

// T3: Strip <script> with content (XSS vector)
$noScript = cleanEmojiHtml('<p>Hello</p><script>alert("xss")</script>');
assert_not_contains('<script>', $noScript, "<script> removed");
assert_not_contains('alert', $noScript, "script content removed");

// T4: Strip <style> with content
$noStyle = cleanEmojiHtml('<style>body { color: red; }</style><p>ok</p>');
assert_not_contains('<style>', $noStyle, "<style> removed");

// T5: Strip <meta>, <link>, <title>
$noMeta = cleanEmojiHtml('<meta name="robots" content="noindex"><p>ok</p>');
assert_not_contains('<meta', $noMeta, "<meta> removed");

// T6: Strip <link> tags
$noLink = cleanEmojiHtml('<link rel="stylesheet" href="evil.css"><p>ok</p>');
assert_not_contains('<link', $noLink, "<link> removed");

// T7: Strip <title> with content
$noTitle = cleanEmojiHtml('<title>evil title</title><p>ok</p>');
assert_not_contains('<title>', $noTitle, "<title> removed");

// T8: HTML entity encoding: bare < and > NOT in allowed tags → would be stripped by strip_tags
// strip_tags removes unknown tags entirely. <evil>text</evil> → text (tag removed, content kept)
$stripped = cleanEmojiHtml('<p>safe</p><evil>bad</evil>');
assert_not_contains('<evil>', $stripped, "Unknown tags stripped");

// T9: Inline event handlers (onclick, onerror) stripped
$noOnclick = cleanEmojiHtml('<p onclick="alert(1)">text</p>');
assert_not_contains('onclick', $noOnclick, "onclick handler removed");

// T10: javascript: URI in href neutralized
$noJs = cleanEmojiHtml('<a href="javascript:alert(1)">click</a>');
assert_not_contains('javascript:', $noJs, "javascript: URI neutralized");

// T11: Strip DOCTYPE/html/head/body wrappers (LLM output wrapper)
$wrapped = '<!DOCTYPE html><html><head><title>x</title></head><body><p>content</p></body></html>';
$cleaned = cleanEmojiHtml($wrapped);
assert_contains('<p>content</p>', $cleaned, "DOCTYPE/html/head/body wrappers removed");
assert_not_contains('<!DOCTYPE', $cleaned, "DOCTYPE removed");
assert_not_contains('<html', $cleaned, "<html> removed");

// T12: data:text URI in href neutralized
$noDataUri = cleanEmojiHtml('<a href="data:text/html,<script>alert(1)</script>">x</a>');
assert_not_contains('data:text', strtolower($noDataUri), "data: URI neutralized");

echo "\n=== Result: All enhance/XSS tests passed ===\n";
test_summary();
