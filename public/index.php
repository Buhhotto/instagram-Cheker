<?php

declare(strict_types=1);

/**
 * نقطة الدخول الوحيدة للمنظومة (Front Controller).
 *
 * التشغيل محليًا:  php -S localhost:8000 -t public
 */

use App\Container;
use App\Http\Controllers\ApiController;
use App\Http\Controllers\PageController;
use App\Http\Controllers\PropertyAlertsController;
use App\Http\Controllers\WatchlistController;
use App\Http\Request;
use App\Http\Response;
use App\Http\Router;
use App\View\View;

$root = dirname(__DIR__);

/** @var Container $container */
$container = require $root . '/bootstrap.php';

$debug = $container->config()->bool('app.debug', false);
ini_set('display_errors', $debug ? '1' : '0');
error_reporting($debug ? E_ALL : E_ALL & ~E_DEPRECATED & ~E_NOTICE);

$view = new View($root . '/views', [
    'appName' => $container->config()->string('app.name', 'منظومة تحليل إنستاجرام'),
]);

$pages = new PageController($container, $view);
$api = new ApiController($container, $view);
$watchlist = new WatchlistController($container, $view);
$propertyAlerts = new PropertyAlertsController($container, $view);

$request = Request::fromGlobals();

$router = (new Router())
    ->get('/', [$pages, 'home'])
    ->any('/username', [$pages, 'username'])
    ->any('/analyze', [$pages, 'analyze'])
    ->any('/behavior', [$pages, 'behavior'])
    ->get('/watchlist', [$watchlist, 'page'])
    ->post('/watchlist/add', [$watchlist, 'add'])
    ->post('/watchlist/remove', [$watchlist, 'remove'])
    ->post('/watchlist/check', [$watchlist, 'checkOneWeb'])
    ->post('/watchlist/check-all', [$watchlist, 'checkAllWeb'])
    ->get('/property-alerts', [$propertyAlerts, 'page'])
    ->post('/property-alerts/add', [$propertyAlerts, 'add'])
    ->post('/property-alerts/remove', [$propertyAlerts, 'remove'])
    ->post('/property-alerts/check', [$propertyAlerts, 'checkOneWeb'])
    ->post('/property-alerts/check-all', [$propertyAlerts, 'checkAllWeb'])
    ->get('/api/health', [$api, 'health'])
    ->any('/api/username/check', [$api, 'checkUsername'])
    ->any('/api/account/analyze', [$api, 'analyzeAccount'])
    ->any('/api/account/behavior', [$api, 'accountBehavior'])
    ->get('/api/watchlist', [$watchlist, 'apiList'])
    ->post('/api/watchlist/add', [$watchlist, 'apiAdd'])
    ->post('/api/watchlist/remove', [$watchlist, 'apiRemove'])
    ->post('/api/watchlist/check', [$watchlist, 'apiCheck'])
    ->get('/api/property-alerts', [$propertyAlerts, 'apiList'])
    ->post('/api/property-alerts/add', [$propertyAlerts, 'apiAdd'])
    ->post('/api/property-alerts/remove', [$propertyAlerts, 'apiRemove'])
    ->post('/api/property-alerts/check', [$propertyAlerts, 'apiCheck'])
    ->fallback(fn (Request $request): Response => $request->wantsJson()
        ? $api->notFound($request)
        : $pages->notFound($request));

try {
    $response = $router->dispatch($request);
} catch (Throwable $e) {
    $message = $debug ? $e->getMessage() : 'حدث خطأ غير متوقع.';

    $response = $request->wantsJson()
        ? Response::jsonError($message, 500)
        : Response::html(
            $view->render('pages/error', [
                'active' => '',
                'title' => 'خطأ',
                'message' => $message,
                'demoOnly' => false,
                'sources' => [],
            ]),
            500
        );
}

$response->send();
