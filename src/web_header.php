<?php
function showHeader (string $title = "", string $parameter = "", string $section = "", string $mode = "", bool $hasRealContent = true, bool $showNav = false, string $etag = ""): void {
    header("Content-Type: text/html; charset=UTF-8");
    // Security response headers (#40, #36, #29)
    header("X-Content-Type-Options: nosniff");
    header("X-Frame-Options: DENY");
    header("Referrer-Policy: strict-origin-when-cross-origin");
    // CSP: allow GA domains only when GA is enabled (#158)
    $csp = "default-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' https://www.w3.org https://jigsaw.w3.org data:; script-src 'self' 'unsafe-inline'";
    if (defined('PHPMAN_GA_ID') && PHPMAN_GA_ID !== '') {
        $csp .= " https://www.googletagmanager.com https://*.google-analytics.com https://*.analytics.google.com";
        $csp .= "; connect-src 'self' https://www.google-analytics.com https://*.google-analytics.com https://analytics.google.com https://*.google.com";
    }
    $csp .= "; frame-ancestors 'none';";
    header("Content-Security-Policy: " . $csp);
    if (!isLocalRequest()) {
        header("Strict-Transport-Security: max-age=31536000; includeSubDomains; preload");
    }
    // MCP service discovery — uses scriptName() for correct URL in CLI/reverse-proxy
    $script_path = scriptName();
    $script_path = preg_replace('/[\r\n].*/', '', $script_path);
    header('Link: <' . $script_path . '/mcp>; rel="mcp-server"');
    // ETag + caching (#60)
    if ($etag !== "") {
        header("ETag: {$etag}");
        header("Cache-Control: public, max-age=86400, stale-while-revalidate=604800");
    }
    header("Last-Modified: " . gmdate("D, d M Y H:i:s") . " GMT");
    // Expires one month later
    header("Expires: " .gmdate ("D, d M Y H:i:s", time() + 3600 * 24 * 7). " GMT");
    // Gzip handled by Nginx/Cloudflare (#84)

    // Build SEO meta values — use baseUrl() for correct URL in CLI/reverse-proxy
    $base_url = baseUrl();
    $canonical_url = $base_url;
    $meta_description = "phpman: Open-source Linux command reference with JSON API and MCP Server for AI agents. Browse man pages, perldoc, and GNU info.";
    $meta_keywords = "man page, unix manual, linux command, perldoc, info page, phpman, json api, mcp server, ai agent";

    if ($parameter !== "") {
        $section_suffix = $section !== "" ? "({$section})" : "";
        $canonical_url = $base_url . "/" . urlencode($mode ?: "man") . "/" . urlencode($parameter);
        if ($section !== "") {
            $canonical_url .= "/" . urlencode($section);
        }

        if ($mode === "man") {
            $meta_description = "{$parameter}{$section_suffix} man page — Linux command reference with options, examples, and JSON API/MCP access via phpman";
            $meta_keywords = "{$parameter} man page, {$parameter} linux, {$parameter} unix, man {$parameter}, {$parameter} command, json api, mcp";
        } elseif ($mode === "perldoc") {
            $meta_description = "{$parameter} perldoc — Perl documentation with JSON API and MCP access via phpman";
            $meta_keywords = "{$parameter} perldoc, {$parameter} perl, perl {$parameter}, {$parameter} documentation, json api, mcp";
        } elseif ($mode === "info") {
            $meta_description = "{$parameter} info page — GNU documentation with JSON API and MCP access via phpman";
            $meta_keywords = "{$parameter} info page, {$parameter} gnu, info {$parameter}, {$parameter} documentation, json api, mcp";
        } elseif ($mode === "search") {
            $meta_description = "Search results for '{$parameter}' in Unix/Linux man pages, perldoc, and info pages via phpman";
            $meta_keywords = "{$parameter}, man page search, {$parameter} command, search manual, json api, mcp";
        } elseif ($mode === "pydoc") {
            $meta_description = "{$parameter} pydoc — Python 3 documentation with JSON API and MCP access via phpman";
            $meta_keywords = "{$parameter} pydoc, {$parameter} python, python {$parameter}, {$parameter} documentation, json api, mcp";
        } elseif ($mode === "ri") {
            $meta_description = "{$parameter} ri — Ruby documentation with JSON API and MCP access via phpman";
            $meta_keywords = "{$parameter} ri, {$parameter} ruby, ruby {$parameter}, {$parameter} documentation, json api, mcp";
        }
    }

    // XHTML 1.0 Transitional
    echo "<!DOCTYPE html PUBLIC \"-//W3C//DTD XHTML 1.0 Transitional//EN\" ".
        "\"http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd\">".
        "<html xmlns=\"http://www.w3.org/1999/xhtml\" xml:lang=\"en\" lang=\"en\">\n".
        "<head>\n".
        "<title>".h($title)."</title>\n".
        "<meta http-equiv=\"Content-Type\" content=\"text/html; charset=UTF-8\"/>\n".
        "<meta name=\"description\" content=\"".h($meta_description)."\"/>\n".
        "<meta name=\"keywords\" content=\"".h($meta_keywords)."\"/>\n".
        "<link rel=\"canonical\" href=\"".h($canonical_url)."\"/>\n".
        "<meta name=\"robots\" content=\"".($hasRealContent ? "index, follow" : "noindex, follow")."\"/>\n".
        // GEO: citation for AI/LLM attribution
        "<meta name=\"citation_title\" content=\"".h($title)."\"/>\n".
        "<meta name=\"citation_online_date\" content=\"".gmdate("Y/m/d")."\"/>\n".
        "<meta name=\"citation_author\" content=\"Che Dong\"/>\n".
        "<meta name=\"viewport\" content=\"width=device-width, initial-scale=1\"/>\n".
        "<link rel=\"icon\" type=\"image/x-icon\" href=\"/favicon.ico\"/>\n";

    $css_path = str_replace('phpMan.php', 'phpman.css', scriptName());
    echo "<link rel=\"stylesheet\" type=\"text/css\" href=\"".h($css_path)."\"/>\n";

    // JSON-LD structured data for SEO/GEO (#64)
    if ($parameter !== "" && in_array($mode, PHPMAN_CONTENT_MODES)) {
        $section_label = $section !== "" ? " (section {$section})" : "";
        $schema_json = json_encode([
            "@context" => "https://schema.org",
            "@type" => "TechArticle",
            "name" => $parameter . $section_label,
            "description" => $meta_description,
            "url" => $canonical_url,
            "author" => [
                "@type" => "Person",
                "name" => "Che Dong",
                "url" => $base_url
            ],
            "publisher" => [
                "@type" => "Organization",
                "name" => PHPMAN_PROJECT_NAME,
                "url" => $base_url
            ],
            "about" => [
                "@type" => "SoftwareApplication",
                "name" => $parameter,
                "applicationCategory" => "DeveloperApplication",
                "operatingSystem" => "Linux, Unix"
            ],
            "datePublished" => gmdate("Y-m-d"),
            "inLanguage" => "en"
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        // #37: escape </ to prevent breaking out of <script> context
        $schema_json = str_replace('</', '<\/', $schema_json);
        echo "<script type=\"application/ld+json\">\n{$schema_json}\n</script>\n";
    } else {
        // Homepage/index: WebApplication schema
        $schema_json = json_encode([
            "@context" => "https://schema.org",
            "@type" => "WebApplication",
            "name" => PHPMAN_PROJECT_NAME,
            "description" => $meta_description,
            "url" => $canonical_url,
            "author" => [
                "@type" => "Person",
                "name" => "Che Dong",
                "url" => $base_url
            ],
            "applicationCategory" => "DeveloperApplication",
            "operatingSystem" => "Linux, Unix"
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $schema_json = str_replace('</', '<\/', $schema_json);
        echo "<script type=\"application/ld+json\">\n{$schema_json}\n</script>\n";
    }

    $bodyClass = $showNav ? ' class="ext-nav"' : '';
    echo "</head>\n<body{$bodyClass}>\n<div id=\"top\"></div>\n";
}

//promter and recursive call
