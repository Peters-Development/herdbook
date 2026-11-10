<?php

namespace App\Http\Middleware;

use App\Models\AgentToken;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

/**
 * The MCP server for agents (routes/ai.php): a Bearer token per agent instead
 * of a session, like the status screen's API. The token acts for its account
 * without the second factor, so it only works while the account has one.
 * While HERDBOOK_MCP_ENABLED is off, /mcp answers 404. Wrong tokens have a
 * limit of their own, which never blocks a good one; behind `tailscale serve`
 * every request comes from one address, so nothing is keyed on the IP.
 */
class AuthenticateAgent
{
    /**
     * Every connection also counts its handshake and its list of tools.
     */
    private const int PER_AGENT_PER_MINUTE = 120;

    private const int WRONG_TOKENS_PER_MINUTE = 30;

    private const string WRONG_TOKENS_KEY = 'agent-token:wrong-token';

    public function handle(Request $request, Closure $next): Response
    {
        if (! config('herdbook.mcp.enabled')) {
            abort(404);
        }

        // Agents send no Origin; a browser does, and has no business here
        // (the MCP specification asks servers to refuse other origins).
        if ($request->headers->has('Origin')) {
            return $this->refuse(__('Requests from a browser are refused.'), 403);
        }

        $token = $request->bearerToken();
        $agent = filled($token) ? AgentToken::findByToken($token) : null;

        // Without its account (deleted a moment ago) a token is as good as unknown.
        if ($agent?->user === null) {
            if (RateLimiter::tooManyAttempts(self::WRONG_TOKENS_KEY, self::WRONG_TOKENS_PER_MINUTE)) {
                return $this->refuse(__('Too many attempts. Try again in a minute.'), 429, ['Retry-After' => (string) RateLimiter::availableIn(self::WRONG_TOKENS_KEY)]);
            }

            if (RateLimiter::hit(self::WRONG_TOKENS_KEY, 60) === self::WRONG_TOKENS_PER_MINUTE) {
                Log::warning('Too many unknown agent tokens within a minute: the MCP server refuses unknown tokens until it has passed.');
            }

            return $this->refuse(__('This agent token is unknown or has been revoked.'), 401);
        }

        // Counted before the other refusals, so an agent that keeps trying an
        // expired token is slowed down too.
        $key = "agent-token:{$agent->id}";

        if (RateLimiter::tooManyAttempts($key, self::PER_AGENT_PER_MINUTE)) {
            return $this->refuse(__('Too many requests from this agent. Try again shortly.'), 429, ['Retry-After' => (string) RateLimiter::availableIn($key)]);
        }

        RateLimiter::hit($key, 60);

        if ($agent->isExpired()) {
            return $this->refuse(__('This agent token has expired. Create a new one with agents:create.'), 401);
        }

        if (! $agent->user->hasTwoFactor()) {
            return $this->refuse(__('The account of this agent has no two-factor authentication. The token works again once it is set up.'), 403);
        }

        // Only for this request: no session, nothing is kept. On the default
        // guard: Request::user(), the activity log and the package all read that one.
        Auth::setUser($agent->user->withAgentToken($agent));
        $agent->markUsed();

        $response = $next($request);
        $response->headers->set('Cache-Control', 'no-store');

        return $response;
    }

    /**
     * @param  array<string, string>  $headers
     */
    private function refuse(string $message, int $status, array $headers = []): JsonResponse
    {
        return response()->json(['message' => $message], $status, ['Cache-Control' => 'no-store', ...$headers]);
    }
}
