<?php

use App\Http\Middleware\AuthenticateAgent;
use App\Http\Middleware\SetUserLocale;
use App\Mcp\Servers\HerdbookServer;
use Illuminate\Support\Facades\Route;
use Laravel\Mcp\Facades\Mcp;

/*
| The MCP server for agents (docs/agents.md). laravel/mcp loads this file
| itself, outside the `web` group: no session, no CSRF, no cookies. The group
| puts AuthenticateAgent in front of the package's own middleware, so only a
| request with a good token reaches the JSON-RPC handling. Off unless
| HERDBOOK_MCP_ENABLED (AuthenticateAgent answers 404).
*/

Route::middleware([AuthenticateAgent::class, SetUserLocale::class])->group(function (): void {
    Mcp::web('/mcp', HerdbookServer::class);

    // Laravel would answer OPTIONS itself, without this middleware: also
    // while the server is off.
    Route::options('/mcp', fn () => response('', 405)->header('Allow', 'POST'));
});
