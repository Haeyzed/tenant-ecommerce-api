<?php

declare(strict_types=1);

namespace App\Shared\Http\Middleware\Tenant;

use App\Modules\ModuleNotices\Services\ModuleNoticeService;
use App\Modules\Tenancy\Models\Tenant;
use App\Shared\Exceptions\ApiException;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * module.notice:{key} (spec §18.2): maintenance notices block the module
 * (all requests, or writes only); other notices are announced in the
 * X-Module-Notice header.
 */
final readonly class CheckModuleNotice
{
    public function __construct(private ModuleNoticeService $notices) {}

    public function handle(Request $request, Closure $next, string $key): Response
    {
        $tenant = tenant();
        $notices = $this->notices->getActiveNoticesForModule($key, $tenant instanceof Tenant ? $tenant : null);

        foreach ($notices as $notice) {
            if ($notice->type !== 'maintenance') {
                continue;
            }

            $behavior = $this->notices->effectiveBehavior($notice);

            if ($behavior === 'hard_block' || ! $request->isMethodSafe()) {
                throw new ApiException('maintenance', $notice->title, 503, [
                    'module' => $key,
                    'notice_id' => $notice->id,
                    'message' => $notice->message,
                    'ends_at' => $notice->ends_at?->toIso8601String(),
                ]);
            }
        }

        $response = $next($request);

        $first = $notices->first();

        if ($first !== null) {
            $response->headers->set('X-Module-Notice', (string) $first->id);
        }

        return $response;
    }
}
