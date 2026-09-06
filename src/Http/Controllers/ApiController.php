<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Request;
use App\Http\Response;
use App\Instagram\Exception\NotFoundException;
use App\Instagram\Exception\ProviderUnavailableException;
use App\Instagram\Exception\RateLimitException;
use App\Instagram\Exception\ValidationException;
use Throwable;

/**
 * واجهة JSON للمنظومة — نفس منطق الصفحات لكن بمخرجات قابلة للاستهلاك برمجيًا.
 */
final class ApiController extends Controller
{
    public function checkUsername(Request $request): Response
    {
        return $this->handle($request, 'username', function (Request $request): array {
            $username = (string) $request->input('username', '');
            $withSuggestions = $request->input('suggestions', '1') !== '0';

            return $this->container->usernameChecker()->check($username, $withSuggestions);
        });
    }

    public function analyzeAccount(Request $request): Response
    {
        return $this->handle($request, 'analyze', function (Request $request): array {
            $limit = $request->intInput('limit', $this->container->config()->int('analysis.default_media_limit', 25));

            return $this->container->accountAnalyzer()->analyze((string) $request->input('username', ''), $limit);
        });
    }

    public function accountBehavior(Request $request): Response
    {
        return $this->handle($request, 'behavior', function (Request $request): array {
            $limit = $request->intInput('limit', $this->container->config()->int('analysis.default_media_limit', 25));

            return $this->container->behaviorAnalyzer()->analyze((string) $request->input('username', ''), $limit);
        });
    }

    /** فحص جاهزية المنظومة والمزوّدين المفعّلين. */
    public function health(Request $request): Response
    {
        return Response::json([
            'ok' => true,
            'app' => $this->container->config()->string('app.name'),
            'time' => date(DATE_ATOM),
            'php' => PHP_VERSION,
            'providers' => $this->container->providers()->activeNames(),
            'demo_only' => $this->container->isDemoOnly(),
            'endpoints' => [
                'GET /api/username/check?username=',
                'GET /api/account/analyze?username=&limit=',
                'GET /api/account/behavior?username=&limit=',
                'GET /api/health',
            ],
        ]);
    }

    public function notFound(Request $request): Response
    {
        return Response::jsonError('المسار غير موجود.', 404, [
            'hint' => 'راجع /api/health لقائمة المسارات المتاحة.',
        ]);
    }

    /**
     * تنفيذ موحّد مع حدّ الطلبات وتحويل الاستثناءات إلى رموز HTTP مناسبة.
     *
     * @param callable(Request):array<string,mixed> $action
     */
    private function handle(Request $request, string $bucket, callable $action): Response
    {
        try {
            $this->throttle($request, $bucket);

            if ($request->input('username', '') === '') {
                throw new ValidationException('المعامل "username" مطلوب.');
            }

            return Response::json(['ok' => true, 'data' => $action($request)]);
        } catch (ValidationException $e) {
            return Response::jsonError($e->getMessage(), 422);
        } catch (NotFoundException $e) {
            return Response::jsonError($e->getMessage(), 404);
        } catch (RateLimitException $e) {
            return Response::jsonError($e->getMessage(), 429);
        } catch (ProviderUnavailableException $e) {
            return Response::jsonError($e->getMessage(), 503);
        } catch (Throwable $e) {
            $debug = $this->container->config()->bool('app.debug', false);

            return Response::jsonError(
                $debug ? $e->getMessage() : 'حدث خطأ غير متوقع أثناء المعالجة.',
                500,
                $debug ? ['trace' => explode("\n", $e->getTraceAsString())] : []
            );
        }
    }
}
