<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Request;
use App\Http\Response;
use App\Instagram\Exception\InstagramException;

/**
 * صفحات الواجهة: الرئيسية، التحقق من الاسم، تحليل الحساب، وقياس السلوك.
 */
final class PageController extends Controller
{
    public function home(Request $request): Response
    {
        return Response::html($this->view->render('pages/home', $this->pageContext('home')));
    }

    public function username(Request $request): Response
    {
        $data = $this->pageContext('username') + [
            'username' => $request->input('username', ''),
            'result' => null,
            'error' => null,
        ];

        if ($data['username'] !== '' && $data['username'] !== null) {
            try {
                $this->throttle($request, 'username');
                $data['result'] = $this->container->usernameChecker()->check((string) $data['username']);
            } catch (InstagramException $e) {
                $data['error'] = $e->getMessage();
            }
        }

        return Response::html($this->view->render('pages/username', $data));
    }

    public function analyze(Request $request): Response
    {
        $limit = $request->intInput('limit', $this->container->config()->int('analysis.default_media_limit', 25));

        $data = $this->pageContext('analyze') + [
            'username' => $request->input('username', ''),
            'limit' => max(1, min(50, $limit)),
            'report' => null,
            'error' => null,
        ];

        if ($data['username'] !== '' && $data['username'] !== null) {
            try {
                $this->throttle($request, 'analyze');
                $data['report'] = $this->container->accountAnalyzer()->analyze((string) $data['username'], $data['limit']);
            } catch (InstagramException $e) {
                $data['error'] = $e->getMessage();
            }
        }

        return Response::html($this->view->render('pages/analyze', $data));
    }

    public function behavior(Request $request): Response
    {
        $limit = $request->intInput('limit', $this->container->config()->int('analysis.default_media_limit', 25));

        $data = $this->pageContext('behavior') + [
            'username' => $request->input('username', ''),
            'limit' => max(1, min(50, $limit)),
            'report' => null,
            'error' => null,
        ];

        if ($data['username'] !== '' && $data['username'] !== null) {
            try {
                $this->throttle($request, 'behavior');
                $data['report'] = $this->container->behaviorAnalyzer()->analyze((string) $data['username'], $data['limit']);
            } catch (InstagramException $e) {
                $data['error'] = $e->getMessage();
            }
        }

        return Response::html($this->view->render('pages/behavior', $data));
    }

    public function notFound(Request $request): Response
    {
        return Response::html(
            $this->view->render('pages/error', $this->pageContext('') + [
                'title' => '404',
                'message' => 'الصفحة المطلوبة غير موجودة.',
            ]),
            404
        );
    }
}
