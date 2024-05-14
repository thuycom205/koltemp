<?php

namespace App\Http\Middlewarex;

use App\Lib\AuthRedirection;
use App\Models\Session;
use Closure;
use Illuminate\Http\Request;
use Shopify\Utils;
use Illuminate\Support\Facades\Log;

class EnsureShopifyInstalled
{
    /**
     * Checks if the shop in the query arguments is currently installed.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure  $next
     * @return mixed
     */
    public function handle(Request $request, Closure $next)
    {
        $REQUEST_URI = $_SERVER['REQUEST_URI'];
        Log::info('EnsureShopifyInstalled: REQUEST_URI: ' . $REQUEST_URI);
//        return $next($request);

        // Check if the request is for a .js or .css file
        if ($request->is('*.js') || $request->is('*.png') || $request->is('*.ico') || $request->is('*.css')) {
            return $next($request);
        }

        $shop = $request->query('shop') ? Utils::sanitizeShopDomain($request->query('shop')) : null;

        $appInstalled = $shop && Session::where('shop', $shop)->where('access_token', '<>', null)->exists();
        Log::info('EnsureShopifyInstalled: REQUEST_URI: ' . $REQUEST_URI);
        Log::info('EnsureShopifyInstalled: REQUEST_URI: ' . $appInstalled);
        $isExitingIframe = preg_match("/^ExitIframe/i", $request->path());
        if ($appInstalled || $isExitingIframe) {
            return $next($request);
        } else {
            Log::info('EnsureShopifyInstalled: Redirect ' . $REQUEST_URI);

            return AuthRedirection::redirect($request);
        }


       // return ($appInstalled || $isExitingIframe) ? $next($request) : AuthRedirection::redirect($request);
    }
    protected $except = [
        'api/*',

        'api/graphql',
        'api/webhooks',
    ];
}
