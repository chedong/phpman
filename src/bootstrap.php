<?php
// bootstrap.php — load all src/ files in dependency order
// Loaded by phpMan.php after phpman.config.php

$srcDir = __DIR__;

require $srcDir . '/config.php';         // 0: constants (defined() guards)
require $srcDir . '/util.php';           // 1: h(), baseUrl(), scriptName(), etc.
require $srcDir . '/log.php';            // 1: phpManLog()
require $srcDir . '/cache.php';          // 1: cacheDb(), PageCache, Profiler (init called here)
require $srcDir . '/search_index.php';   // 2: buildFtsQuery(), rebuildSearchIndex(), etc.
require $srcDir . '/format_common.php';  // 2: cleanTerminalOutput(), detectHeadingType()
require $srcDir . '/format_html.php';    // 3: formatManPerlDoc(), renderTocSidebar()
require $srcDir . '/format_markdown.php';// 3: formatManPerlDocToMarkdown(), showCopyright()
require $srcDir . '/format_json.php';    // 3: formatToJSON(), parseFlagJSON()
require $srcDir . '/format_mcp.php';     // 3: formatForOutput(), formatMcpMarkdown()
require $srcDir . '/source_man.php';     // 4: getManPage(), getManIndex()
require $srcDir . '/source_perldoc.php'; // 4: getPerldocPage()
require $srcDir . '/source_info.php';    // 4: getInfoPage()
require $srcDir . '/source_pydoc.php';   // 4: getPydocPage()
require $srcDir . '/source_ri.php';      // 4: getRiPage()
require $srcDir . '/source_search.php';  // 4: getSearchPage()
require $srcDir . '/enhance.php';        // 5: enhanceManPage(), callLLM(), cleanEmojiHtml()
require $srcDir . '/tldr.php';           // 5: fetchOfficialTldr()
require $srcDir . '/mcp_server.php';     // 6: handleMcp(), handleWellKnown()
require $srcDir . '/web_header.php';     // 7: showHeader()
require $srcDir . '/web_footer.php';     // 7: showFooter(), showForm()

// Global: default page title (overridden by web router for specific pages)
$PHPMAN_TITLE = PHPMAN_HOME_TITLE;
$TOC_ITEMS = array();
