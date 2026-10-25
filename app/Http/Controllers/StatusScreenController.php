<?php

namespace App\Http\Controllers;

use App\Services\Screen\StatusScreenPayload;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

/**
 * The status screen for a wall display (routes/screen.php). The page holds
 * no data and needs no login; its script reads the token from the link's
 * fragment and asks status() for the rest.
 */
class StatusScreenController extends Controller
{
    public function show(): Response
    {
        return response()
            ->view('screen', ['version' => StatusScreenPayload::version()])
            ->header('Cache-Control', 'no-store');
    }

    public function status(StatusScreenPayload $payload): JsonResponse
    {
        // Outside the cache: the clock follows "now", and a page loaded just
        // after a deploy must not see the old version and reload for nothing.
        return response()
            ->json([...$payload->cached(), 'version' => StatusScreenPayload::version(), 'now' => now()->utc()->toIso8601ZuluString()])
            ->header('Cache-Control', 'no-store');
    }
}
