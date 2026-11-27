<?php

namespace App\Http\Controllers;

use App\Http\Responses\Api;
use App\Models\PluginApp;
use App\Plugins\PluginGateway;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/** The one endpoint Hermes calls (server to server: no cookies, no CSRF). Only the gateway key opens it. */
class PluginGatewayController extends Controller
{
    public function handle(Request $request, PluginGateway $gateway): JsonResponse|Response
    {
        $hash = PluginApp::gateway()->gateway_key_hash;
        $token = (string) $request->bearerToken();
        if (! $hash || $token === '' || ! hash_equals($hash, hash('sha256', $token))) {
            return Api::error('unauthenticated', 'Unauthenticated.', 401);
        }

        $reply = $gateway->handle((array) $request->json()->all());

        return $reply === null ? response('', 202) : response()->json($reply);
    }
}
