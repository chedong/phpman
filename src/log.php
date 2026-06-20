<?php
function phpManLog (string $message): void {
    error_log("phpMan: " . $message);
}

/**
 * Get MCP tool definitions (shared by .well-known and tools/list).  (#48)
 */
