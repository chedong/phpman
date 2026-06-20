<?php
function getMcpToolDefinitions (): array {
    return [
        [
            "name" => "cli_help",
            "description" => "Get structured man / perldoc / info / pydoc3 / ri page for a command or module. Returns sections with sub-sections, synopsis, and full content. Supports all Unix/Linux commands, Perl modules (e.g. File::Basename), and GNU info pages.",
            "inputSchema" => [
                "type" => "object",
                "properties" => [
                    "command" => [
                        "type" => "string",
                        "description" => "Command or module name (e.g. 'ls', 'git', 'File::Basename', 'bash', 'json', 'Array#map')"
                    ],
                    "section" => [
                        "type" => "string",
                        "description" => "Optional manual section number (e.g. '1' for user commands, '3pm' for Perl modules). Omit for best-match behavior."
                    ]
                ],
                "required" => ["command"]
            ]
        ],
        [
            "name" => "cli_search",
            "description" => "Search Unix/Linux man pages by keyword using apropos. Also searches Python 3 modules via pydoc3. Returns matching command names with sections and detail links.",
            "inputSchema" => [
                "type" => "object",
                "properties" => [
                    "query" => [
                        "type" => "string",
                        "description" => "Search keyword (e.g. 'recursive delete', 'network', 'cron')"
                    ],
                    "section" => [
                        "type" => "string",
                        "description" => "Optional: restrict to a specific manual section (e.g. '1', '8')"
                    ]
                ],
                "required" => ["query"]
            ]
        ]
    ];
}

/**
 * Clean terminal overstrike and ANSI escape sequences from man/perldoc output.
 * Shared by formatToJSON() and formatManPerlDocToMarkdown().
 * Returns array of cleaned lines with placeholder markers for bold/underline.
 *   \x01..\x02 = bold boundary,  \x03..\x04 = underline boundary
 */

function handleWellKnown (): void {
    // Only allow GET requests for well-known discovery
    if (serverValue("REQUEST_METHOD") !== "GET") {
        http_response_code(405);
        header("Content-Type: application/json; charset=UTF-8");
        header("X-Content-Type-Options: nosniff");
        header("Allow: GET");
        echo json_encode(["error" => "Method not allowed. Use GET."], JSON_UNESCAPED_SLASHES);
        return;
    }

    header("Content-Type: application/json; charset=UTF-8");
    header("X-Content-Type-Options: nosniff");
    header("Cache-Control: public, max-age=3600");
    header("Last-Modified: " . gmdate("D, d M Y H:i:s") . " GMT");

    $base = baseUrl();
    $mcpEndpoint = $base . "/mcp";

    $discovery = [
        "name" => "phpMan",
        "version" => PHPMAN_VERSION,
        "description" => "Unix/Linux man page, Perldoc, and Info page web interface with MCP support",
        "url" => $base,
        "mcp" => [
            "endpoint" => $mcpEndpoint,
            "protocolVersion" => "2024-11-05",
            "transport" => "streamable-http",
            "capabilities" => [
                "tools" => ["listChanged" => false]
            ]
        ],
        "tools" => getMcpToolDefinitions(),  // #48: shared definition
        "endpoints" => [
            "man" => $base . "/man/{command}/{section?}/json",
            "perldoc" => $base . "/perldoc/{module}/json",
            "info" => $base . "/info/{page}/json",
            "search" => $base . "/search/{query}/{section?}/json",
            "markdown" => $base . "/{mode}/{command}/{section?}/markdown"
        ]
    ];

    echo json_encode($discovery, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
}

//handle Mcp protocol request
function handleMcp (): void {
    header("Content-Type: application/json; charset=UTF-8");
    header("X-Content-Type-Options: nosniff");
    // MCP uses text/event-stream for SSE transport, but StreamableHTTP uses plain POST
    // Allow both plain and SSE content types
    header("Cache-Control: no-store, no-cache, must-revalidate");
    header("Pragma: no-cache");

    // Only accept POST requests (#42)
    if (serverValue("REQUEST_METHOD") !== "POST") {
        http_response_code(405);
        header("Allow: POST");
        sendMcpError(null, -32600, "Method not allowed. Use POST.");
        return;
    }

    // API key authentication (#97)
    if (MCP_API_KEY !== '') {
        $apiKey = serverValue("HTTP_X_API_KEY", "");
        if ($apiKey !== MCP_API_KEY) {
            http_response_code(401);
            sendMcpError(null, -32001, "Unauthorized: invalid or missing API key");
            return;
        }
    }

    // Limit request body size to 64KB (#31, #42)
    $contentLength = (int)(serverValue("CONTENT_LENGTH", "0"));
    if ($contentLength > 65536) {
        sendMcpError(null, -32700, "Payload too large (max 64KB)");
        return;
    }

    // Read JSON-RPC body
    $rawBody = file_get_contents("php://input");
    if ($rawBody === false || trim($rawBody) === "") {
        sendMcpError(null, -32700, "Parse error: empty body");
        return;
    }

    $request = json_decode($rawBody, true);
    if ($request === null) {
        sendMcpError(null, -32700, "Parse error: invalid JSON");
        return;
    }

    $method = $request["method"] ?? "";
    $id = $request["id"] ?? null;

    // Also accept "notifications/initialized" as no-op
    if ($method === "notifications/initialized") {
        // MCP spec: client sends this after initialize, server can ignore
        http_response_code(202);
        echo json_encode(["jsonrpc" => "2.0"]);
        return;
    }

    if ($method === "") {
        sendMcpError($id, -32600, "Invalid Request: missing method");
        return;
    }

    switch ($method) {
        case "initialize":
            handleMcpInitialize($id);
            break;
        case "tools/list":
            handleMcpToolsList($id);
            break;
        case "tools/call":
            $params = $request["params"] ?? [];
            if (!is_array($params)) {
                sendMcpError($id, -32602, "Invalid params: params must be an object");
                break;
            }
            handleMcpToolsCall($id, $params);
            break;
        default:
            sendMcpError($id, -32601, "Method not found");
    }
}

function sendMcpError ($id, int $code, string $message): void {
    echo json_encode([
        "jsonrpc" => "2.0",
        "id" => $id,
        "error" => ["code" => $code, "message" => $message]
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}

function sendMcpResult ($id, array $result): void {
    echo json_encode([
        "jsonrpc" => "2.0",
        "id" => $id,
        "result" => $result
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}

function handleMcpInitialize ($id): void {
    $base = baseUrl();
    sendMcpResult($id, [
        "protocolVersion" => "2024-11-05",
        "serverInfo" => [
            "name" => "phpMan",
            "version" => PHPMAN_VERSION
        ],
        "capabilities" => [
            "tools" => ["listChanged" => false]
        ],
        "instructions" => "phpMan provides structured access to Unix/Linux man pages, Perl perldoc modules, and GNU info pages. "
            . "Use cli_help to retrieve the full manual for a command or module (e.g. command='ls', command='git', or command='File::Basename' for Perl; "
            . "optionally pass section='3pm' for Perl modules or another manual section). "
            . "Use cli_search to find commands by keyword via apropos (e.g. query='recursive delete', query='network'). "
            . "Responses include a section outline, synopsis, flag/option table, examples, and see-also references — prefer the section outline to locate "
            . "specific content before reading full sections. "
            . "Web endpoint: {$base}/mcp"
    ]);
}

function handleMcpToolsList ($id): void {
    sendMcpResult($id, [
        "tools" => getMcpToolDefinitions()  // #48: shared definition
    ]);
}

function handleMcpToolsCall ($id, array $params): void {
    $name = $params["name"] ?? "";
    $args = $params["arguments"] ?? [];

    if ($name === "") {
        sendMcpError($id, -32602, "Invalid params: missing tool name");
        return;
    }
    if (!is_array($args)) {
        sendMcpError($id, -32602, "Invalid params: arguments must be an object");
        return;
    }

    try {
        $content = executeMcpTool($name, $args);
        // Content is already MCP-wrapped (format="mcp" produces {"content":[...]})
        // Send as raw result — the wrapper IS the result
        $result = json_decode($content, true);
        if ($result === null) {
            sendMcpError($id, -32603, "Internal error: invalid MCP output");
            return;
        }
        sendMcpResult($id, $result);
    } catch (Throwable $e) {
        phpManLog("MCP internal error: " . $e->getMessage() . " in " . $e->getFile() . ":" . $e->getLine());
        sendMcpError($id, -32603, "Internal error");
    }
}

function executeMcpTool (string $name, array $args): string {
    switch ($name) {
        case "cli_help":
            return executeCliHelp($args);
        case "cli_search":
            return executeCliSearch($args);
        default:
            throw new Exception("Unknown tool: {$name}");
    }
}

function executeCliHelp (array $args): string {
    $command = trim($args["command"] ?? "");
    $section = trim($args["section"] ?? "");

    if ($command === "") {
        throw new Exception("Missing required parameter: command");
    }

    // Auto-detect documentation source
    $is_perl = (strpos($command, "::") !== false || $section === "3pm" || $section === "3perl");
    // Ruby instance methods use # (e.g. Array#map)
    $is_ruby = (strpos($command, "#") !== false);
    // Python qualified names use dotted notation without :: (e.g. os.path, json.loads)
    $is_python = (!$is_perl && !$is_ruby && strpos($command, ".") !== false);
    
    if ($is_perl) {
        return getPerldocPage($command, "mcp");
    }
    if ($is_ruby) {
        $content = getRiPage($command, "mcp");
        if ($content !== "") return $content;
    }
    if ($is_python) {
        $content = getPydocPage($command, "mcp");
        if ($content !== "") return $content;
    }
    
    // Try man first (default)
    $content = getManPage($command, $section, "mcp");
    if ($content !== "") return $content;
    
    // Fallback cascade: try pydoc, then ri
    $content = getPydocPage($command, "mcp");
    if ($content !== "") return $content;
    
    $content = getRiPage($command, "mcp");
    if ($content !== "") return $content;
    
    return $content;
}

function executeCliSearch (array $args): string {
    $query = trim($args["query"] ?? "");
    $section = trim($args["section"] ?? "");

    if ($query === "") {
        throw new Exception("Missing required parameter: query");
    }

    return getSearchPage($query, $section, "mcp");
}

//get specified command's man page and convert to html format
