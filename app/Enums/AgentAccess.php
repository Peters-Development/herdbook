<?php

namespace App\Enums;

/**
 * What an agent token may do on the MCP server. Write includes read; no
 * token can delete anything, as there is no tool for it. Only the command
 * line shows it, so it has no translated label.
 */
enum AgentAccess: string
{
    case Read = 'read';
    case Write = 'write';
}
