<?php
/**
 * Unit tests: expandNameForFts(), buildFtsQuery(), mergeSearchResults(), formatSearchResults()
 * Requirement: SEARCH_FTS5_DESIGN.md — FTS5 full-text search with unicode61 tokenchars '-:'
 *
 * Design notes:
 * - expandNameForFts expands names so hyphenated and double-colon entries match both
 *   exact tokens and the component words (e.g., "git-commit" → "git-commit git commit")
 * - buildFtsQuery uses prefix matching by default: "git" → '"git"*' in FTS5 query syntax
 * - mergeSearchResults merges results with same (name, section) key across sources
 * - formatSearchResults renders to HTML, Markdown, or JSON
 */
declare(strict_types=1);
define('PHPMAN_TEST_MODE', true);
require_once __DIR__ . '/../test_helper.php';
require_once __DIR__ . '/../../phpMan.php';

echo "=== Unit: FTS5 Search Functions ===\n\n";

echo "--- expandNameForFts() ---\n";
// Hyphenated command names
assert_equals("git-commit git commit", expandNameForFts("git-commit"),
    "git-commit → expanded with space-replaced version");
assert_equals("git-upload-pack git upload pack", expandNameForFts("git-upload-pack"),
    "git-upload-pack → multi-hyphen expanded");
assert_equals("run-parts run parts", expandNameForFts("run-parts"),
    "run-parts → expanded");

// Double-colon Perl modules
assert_equals("File::Find File Find", expandNameForFts("File::Find"),
    "File::Find → expanded with space-replaced version");
assert_equals("File::Path::Tiny File Path Tiny", expandNameForFts("File::Path::Tiny"),
    "File::Path::Tiny → multi-colon expanded");

// No special separators
assert_equals("ls", expandNameForFts("ls"),
    "simple command → unchanged");
assert_equals("grep", expandNameForFts("grep"),
    "grep → unchanged");
assert_equals("bash", expandNameForFts("bash"),
    "bash → unchanged");

// Empty / edge cases
assert_equals("", expandNameForFts(""),
    "empty string → empty");
assert_equals("a", expandNameForFts("a"),
    "single char → unchanged");

// Combination: both hyphen and colon
assert_equals("App-Cmd::Command App Cmd::Command App-Cmd Command", expandNameForFts("App-Cmd::Command"),
    "App-Cmd::Command → both hyphen and colon expanded");

echo "\n--- buildFtsQuery() ---\n";
// Single word → prefix match
$q1 = buildFtsQuery("git");
assert_contains('"git"*', $q1, "single word → FTS5 prefix query");
assert_not_contains('"git" AND ', $q1, "single word → no AND");

// Multi-word query
$q2 = buildFtsQuery("git commit");
assert_contains('"git"*', $q2, "multi-word first term → prefix");
assert_contains('"commit"*', $q2, "multi-word second term → prefix");
assert_contains(' AND ', $q2, "multi-word → joined by AND");

// Hyphenated query (preserved for tokenchars tokenizer)
$q3 = buildFtsQuery("git-commit");
assert_contains('"git-commit"*', $q3, "hyphenated → preserved as single token");
// Note: with tokenchars '-:', the tokenizer keeps hyphens, so FTS5 can match "git-commit"

// Double-colon query (preserved as single token with tokenchars '-:')
$q4 = buildFtsQuery("File::Find");
assert_contains('"File::Find"*', $q4, "File::Find → preserved as single token for tokenchars");
assert_not_contains('AND', $q4, "File::Find → single term, no AND");

// Query with special chars stripped
$q5 = buildFtsQuery("git;rm -rf /");
assert_contains('"gitrm"*', $q5, "semicolon stripped, git and rm merge: gitrm");
assert_contains('"-rf"*', $q5, "hyphen-preserved term -rf kept");
assert_not_contains(";", $q5, "semicolon stripped");
assert_not_contains("/", $q5, "slash stripped");

// Exact phrase (quoted) pass-through
$q6 = buildFtsQuery('"recursive delete"');
assert_not_contains(' AND ', $q6, "exact phrase → no AND joined");
assert_contains('"recursive delete"', $q6, "exact phrase → preserved as-is");

// Empty query
$q7 = buildFtsQuery("");
assert_equals("", $q7, "empty query → empty");

echo "\n--- mergeSearchResults() ---\n";
// Single result
$single = [
    ["name" => "ls", "section" => "1", "description" => "list directory contents", "hits" => 10, "source" => "man"]
];
$merged1 = mergeSearchResults($single);
assert_equals(1, count($merged1), "single result stays single");
assert_equals("ls", $merged1[0]["name"], "single result name preserved");
assert_equals(["man"], $merged1[0]["sources"], "single source → array with one item");
assert_equals(10, $merged1[0]["hits"], "single result hits preserved");

// Two distinct results
$distinct = [
    ["name" => "ls", "section" => "1", "description" => "list directory", "hits" => 10, "source" => "man"],
    ["name" => "cp", "section" => "1", "description" => "copy files", "hits" => 5, "source" => "man"],
];
$merged2 = mergeSearchResults($distinct);
assert_equals(2, count($merged2), "distinct results — both kept");
assert_equals("ls", $merged2[0]["name"], "first result name");

// Same name/section from multiple sources → merged
$duplicate = [
    ["name" => "git-commit", "section" => "1", "description" => "Record changes", "hits" => 3, "source" => "man"],
    ["name" => "git-commit", "section" => "1", "description" => "Record changes to the repository", "hits" => 0, "source" => "pydoc"],
];
$merged3 = mergeSearchResults($duplicate);
assert_equals(1, count($merged3), "duplicate (name,section) → merged into one");
assert_equals("git-commit", $merged3[0]["name"], "merged name preserved");
assert_equals(true, in_array("man", $merged3[0]["sources"]), "merged sources includes man");
assert_equals(true, in_array("pydoc", $merged3[0]["sources"]), "merged sources includes pydoc");
// hits should be summed
$expectedHits = ($duplicate[0]["hits"] ?? 0) + ($duplicate[1]["hits"] ?? 0);
assert_equals($expectedHits, $merged3[0]["hits"], "merged hits = sum of individual hits");

// Three-way merge
$triple = [
    ["name" => "git", "section" => "1", "description" => "the stupid content tracker", "hits" => 7, "source" => "man"],
    ["name" => "git", "section" => "1", "description" => "Git version control", "hits" => 0, "source" => "pydoc"],
    ["name" => "git", "section" => "1", "description" => "Git SCM", "hits" => 4, "source" => "ri"],
];
$merged4 = mergeSearchResults($triple);
assert_equals(1, count($merged4), "triple duplicate → one merged result");
assert_equals(3, count($merged4[0]["sources"]), "triple → 3 sources merged");
assert_equals(11, $merged4[0]["hits"], "triple → hits summed (7+0+4)");

// Empty input
$merged5 = mergeSearchResults([]);
assert_equals([], $merged5, "empty input → empty output");

echo "\n--- formatSearchResults() HTML ---\n";
$testResults = [
    ["name" => "ls", "section" => "1", "description" => "list directory contents", "sources" => ["man"], "hits" => 10],
    ["name" => "cp", "section" => "1", "description" => "copy files and directories", "sources" => ["man"], "hits" => 5],
    ["name" => "File::Find", "section" => "3pm", "description" => "Traverse directory tree", "sources" => ["perldoc"], "hits" => 8],
];

$html = formatSearchResults($testResults, "files", "", "html");
assert_contains("<ul>", $html, "HTML output contains <ul>");
assert_contains("list directory contents", $html, "HTML output contains description");
assert_contains("ls", $html, "HTML output contains name 'ls'");
assert_contains("File::Find", $html, "HTML output contains 'File::Find'");
assert_contains("perldoc", $html, "HTML output source annotation 'perldoc'");
// Correct link format
assert_contains('/man/ls/1', $html, "HTML link to /man/ls/1");
assert_contains('/man/cp', $html, "HTML link to /man/cp");
assert_contains('/perldoc/File%3A%3AFind/3pm', $html, "HTML link to /perldoc/File::Find (URL-encoded)");

echo "\n--- formatSearchResults() Markdown ---\n";
$md = formatSearchResults($testResults, "files", "", "markdown");
assert_contains("[ls", $md, "Markdown output contains link start [");
assert_contains("/man/ls/1/markdown", $md, "Markdown link to /man/ls/1/markdown");
assert_contains("list directory contents", $md, "Markdown output contains description");
assert_contains("[File::Find(3pm)]", $md, "Markdown output contains 'File::Find' link with section");

echo "\n--- formatSearchResults() JSON ---\n";
$json = formatSearchResults($testResults, "files", "", "json");
$data = json_decode($json, true);
assert_equals(true, $data !== null, "JSON output is valid");
assert_equals("search", $data["mode"], "JSON mode is 'search'");
assert_equals("files", $data["query"], "JSON query preserved");
assert_equals(3, $data["count"], "JSON count matches");
assert_equals("ls", $data["results"][0]["name"], "JSON first result name");
assert_equals("fts5", $data["engine"], "JSON engine is 'fts5'");

echo "\n--- formatSearchResults() Section filter ---\n";
$sectionResults = [
    ["name" => "ls", "section" => "1", "description" => "list directory", "sources" => ["man"], "hits" => 10],
];
$htmlSection = formatSearchResults($sectionResults, "ls", "1", "html");
assert_contains("(1)", $htmlSection, "section number appears in parentheses in HTML");

echo "\n--- formatSearchResults() Edge cases ---\n";
$empty = formatSearchResults([], "test", "", "html");
assert_equals("<ul>\n</ul>\n", $empty, "empty results → empty <ul>");

exit(test_summary());
