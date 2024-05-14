<?php
use App\Http\Controllers\SyncLogsOrderController;
use App\Http\Controllers\SyncLogsController;

use App\Http\Controllers\UserIntegrationSettingsController;

use App\Http\Controllers\TrelloIntegrationSettingController;


use App\Exceptions\ShopifyProductCreatorException;
use App\Lib\AuthRedirection;
use App\Lib\EnsureBilling;
use App\Lib\ProductCreator;
use App\Models\Session;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Shopify\Auth\OAuth;
use Shopify\Auth\Session as AuthSession;
use Shopify\Clients\HttpHeaders;
use Shopify\Clients\Rest;
use Shopify\Context;
use Shopify\Exception\InvalidWebhookException;
use Shopify\Utils;
use Shopify\Webhooks\Registry;
use Shopify\Webhooks\Topics;
//use App\Http\Controllers\UserIntegrationSettingsController; // Assuming you have a model named UserIntegrationSetting

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| contains the "web" middleware group. Now create something great!
|
| If you are adding routes outside of the /api path, remember to also add a
| proxy rule for them in web/frontend/vite.config.js
|
*/
function getShopifyWebhooks($shopifyStoreDomain, $accessToken) {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, "https://{$shopifyStoreDomain}/admin/api/2023-01/webhooks.json");
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "X-Shopify-Access-Token: {$accessToken}",
        "Content-Type: application/json"
    ]);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "GET");

    $response = curl_exec($ch);
    $error = curl_error($ch);
    curl_close($ch);

    if ($error) {
        return ['error' => 'Curl error: ' . $error];
    }

    // Decode the JSON response and return it
    return json_decode($response, true);
}

 function getAccessToken($shop)
{
    // Fetch the session record where 'shop' equals the specified value
    $session = Session::where('shop', $shop)->first();

    // Check if the session record was found
    if ($session) {
        // Return the access_token
        return $session->access_token; // assuming 'access_token' is the column name
    } else {
        // Handle the case where no matching session is found
        return null; // or handle as appropriate
    }
}

Route::fallback(function (Request $request) {
    Log::error(
        "fallback for shop  with response body: " .
        print_r($_SERVER['REQUEST_URI'] ,true)
    );
    if (Context::$IS_EMBEDDED_APP &&  $request->query("embedded", false) === "1") {
        if (env('APP_ENV') === 'production') {
            return file_get_contents(public_path('index.html'));
        } else {


//    $shop = Utils::sanitizeShopDomain($request->query('shop'));
//
//    // Delete any previously created OAuth sessions that were not completed (don't have an access token)
//       Session::where('shop', $shop);
//
//            $shop = Utils::sanitizeShopDomain($request->query('shop'));
//            $accessToken = getAccessToken($shop);
//            $shopifyStoreDomain = $shop;
//            $webhooksInfo = getShopifyWebhooks($shopifyStoreDomain, $accessToken);

            if (isset($webhooksInfo['error'])) {
                //echo $webhooksInfo['error'];
            } else {
                //echo "List of current webhooks:\n";
               // print_r($webhooksInfo);
            }
            return file_get_contents(public_path('index.html'));

           // return file_get_contents(base_path('frontend/dist/index.html'));
        }
    } else {
     // return file_get_contents(base_path('frontend/dist/index.html'));

      return redirect(Utils::getEmbeddedAppUrl($request->query("host", null)) . "/" . $request->path());
    }
})->middleware('shopify.installed');

Route::get('/api/auth', function (Request $request) {
    $shop = Utils::sanitizeShopDomain($request->query('shop'));
    Log::error(
        "api/auth for shop $shop with response body: " .
        print_r($_SERVER['REQUEST_URI'] ,true)
    );
    // Delete any previously created OAuth sessions that were not completed (don't have an access token)
    Session::where('shop', $shop)->where('access_token', null)->delete();

    return AuthRedirection::redirect($request);
});

Route::get('/api/auth/callback', function (Request $request) {

    // De
    $session = OAuth::callback(
        $request->cookie(),
        $request->query(),
        ['App\Lib\CookieHandler', 'saveShopifyCookie'],
    );

    $host = $request->query('host');
    $shop = Utils::sanitizeShopDomain($request->query('shop'));

    $response = Registry::register('/api/webhooks', Topics::APP_UNINSTALLED, $shop, $session->getAccessToken());
    if ($response->isSuccess()) {
        Log::debug("Registered APP_UNINSTALLED webhook for shop $shop");
    } else {
        Log::error(
            "Failed to register APP_UNINSTALLED webhook for shop $shop with response body: " .
                print_r($response->getBody(), true)
        );
    }

    $response = Registry::register('/api/webhooks', Topics::ORDERS_CREATE, $shop, $session->getAccessToken());
    if ($response->isSuccess()) {
        Log::debug("Registered ORDERS_CREATE webhook for shop $shop");
    } else {
        Log::error(
            "Failed to register ORDERS_CREATE webhook for shop $shop with response body: " .
            print_r($response->getBody(), true)
        );
    }


//    $response = Registry::register('/api/webhooks', Topics::ORDERS_UPDATED, $shop, $session->getAccessToken());
//    if ($response->isSuccess()) {
//        Log::debug("Registered ORDERS_CREATE webhook for shop $shop");
//    } else {
//        Log::error(
//            "Failed to register ORDERS_CREATE webhook for shop $shop with response body: " .
//            print_r($response->getBody(), true)
//        );
//    }

    $redirectUrl = Utils::getEmbeddedAppUrl($host);
    if (Config::get('shopify.billing.required')) {
        list($hasPayment, $confirmationUrl) = EnsureBilling::check($session, Config::get('shopify.billing'));

        if (!$hasPayment) {
            $redirectUrl = $confirmationUrl;
        }
    }
   // Log::debug("Redirect URL $redirectUrl");
    // Define the substring to find in the URL
$targetSubstring = '/auth/callback?';

// Find the position of the target substring
$position = strpos($redirectUrl, $targetSubstring);

if ($position !== false) {
    // Extract the query string part after '/auth/callback?'
    $queryString = substr($redirectUrl, $position + strlen($targetSubstring));

    // Construct the new URL with 'index.php'
    $newUrl = 'index.php?' . $queryString;
    Log::debug("Redirect to  $newUrl");

} else {
    echo "The string '/auth/callback?' was not found in the URL!";
    Log::debug("Redirect to  $redirectUrl");

    $newUrl = $redirectUrl;
}
    return redirect($newUrl);
});

Route::get('/api/products/count', function (Request $request) {
    /** @var AuthSession */
    $session = $request->get('shopifySession'); // Provided by the shopify.auth middleware, guaranteed to be active

    $client = new Rest($session->getShop(), $session->getAccessToken());
    $result = $client->get('products/count');

    return response($result->getDecodedBody());
})->middleware('shopify.auth');

Route::get('/api/products/create', function (Request $request) {
    /** @var AuthSession */
    $session = $request->get('shopifySession'); // Provided by the shopify.auth middleware, guaranteed to be active

    $success = $code = $error = null;
    try {
        ProductCreator::call($session, 5);
        $success = true;
        $code = 200;
        $error = null;
    } catch (\Exception $e) {
        $success = false;

        if ($e instanceof ShopifyProductCreatorException) {
            $code = $e->response->getStatusCode();
            $error = $e->response->getDecodedBody();
            if (array_key_exists("errors", $error)) {
                $error = $error["errors"];
            }
        } else {
            $code = 500;
            $error = $e->getMessage();
        }

        Log::error("Failed to create products: $error");
    } finally {
        return response()->json(["success" => $success, "error" => $error], $code);
    }
})->middleware('shopify.auth');

Route::post('/api/webhooks', function (Request $request) {
    try {
        Log::error("Got a webhook request: {$request->getContent()}");

        $topic = $request->header(HttpHeaders::X_SHOPIFY_TOPIC, '');

        $response = Registry::process($request->header(), $request->getContent());
        if (!$response->isSuccess()) {
            Log::error("Failed to process '$topic' webhook: {$response->getErrorMessage()}");
            return response()->json(['message' => "Failed to process '$topic' webhook"], 500);
        }
    } catch (InvalidWebhookException $e) {
        Log::error("Got invalid webhook request for topic '$topic': {$e->getMessage()}");
        return response()->json(['message' => "Got invalid webhook request for topic '$topic'"], 401);
    } catch (\Exception $e) {
        Log::error("Got an exception when handling '$topic' webhook: {$e->getMessage()}");
        return response()->json(['message' => "Got an exception when handling '$topic' webhook"], 500);
    }
});
Route::post('/api/savesettings', [UserIntegrationSettingsController::class, 'saveSettings']);

Route::post('/api/user_integration_settings/save', [UserIntegrationSettingsController::class, 'save']);
Route::get('/api/user_integration_settings/fetch', [UserIntegrationSettingsController::class, 'fetch']);
Route::post('/api/user_integration_settings/fetchList', [UserIntegrationSettingsController::class, 'fetchList']);
Route::post('/api/user_integration_settings/delete', [UserIntegrationSettingsController::class, 'delete']);

Route::post('/api/sync_logs/save', [SyncLogsController::class, 'save']);
Route::get('/api/sync_logs/fetch', [SyncLogsController::class, 'fetch']);
Route::post('/api/sync_logs/fetchList', [SyncLogsController::class, 'fetchList']);
Route::post('/api/sync_logs/delete', [SyncLogsController::class, 'delete']);
Route::get('/api/sync_logs_order/fetchList', [SyncLogsOrderController::class, 'fetchList']);
