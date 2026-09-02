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

$request = Request::fromGlobals();

$router = (new Router())
    ->get('/', [$pages, 'home'])
    ->any('/username', [$pages, 'username'])
    ->any('/analyze', [$pages, 'analyze'])
    ->any('/behavior', [$pages, 'behavior'])
    ->get('/api/health', [$api, 'health'])
    ->any('/api/username/check', [$api, 'checkUsername'])
    ->any('/api/account/analyze', [$api, 'analyzeAccount'])
    ->any('/api/account/behavior', [$api, 'accountBehavior'])
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
