<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Modules\Catalog\Models\DigitalDownloadGrant;
use App\Modules\Catalog\Services\DigitalDownloadService;
use App\Modules\Customers\Models\Customer;
use App\Shared\Http\APIResponse;
use App\Shared\Http\Middleware\Tenant\ResolveGuestToken;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The shopper's digital downloads (spec §28.4): a customer's own grants, or
 * a guest order's by X-Guest-Token.
 */
final class DownloadController extends Controller
{
    public function __construct(private readonly DigitalDownloadService $downloads) {}

    public function index(Request $request): JsonResponse
    {
        $grants = $this->downloads->listFor($this->customer($request), $this->guestToken($request));

        return APIResponse::success($grants->map(static fn (DigitalDownloadGrant $g): array => [
            'id' => $g->id,
            'order_number' => $g->orderItem->order->order_number,
            'item_name' => $g->orderItem->name_snapshot,
            'file_name' => $g->file->getFirstMedia('file')?->file_name,
            'download_count' => $g->download_count,
            'download_limit' => $g->download_limit,
            'expires_at' => $g->expires_at?->toIso8601String(),
            'available' => $g->unusableReason() === null,
            'status' => $g->unusableReason() ?? 'available',
        ])->values());
    }

    public function show(Request $request, DigitalDownloadGrant $grant): Response
    {
        $grant->load('orderItem.order', 'file.media');
        $this->downloads->assertOwnedBy($grant, $this->customer($request), $this->guestToken($request));

        return $this->downloads->download($grant);
    }

    private function customer(Request $request): ?Customer
    {
        $user = $request->user();

        return $user instanceof Customer ? $user : null;
    }

    private function guestToken(Request $request): ?string
    {
        return $this->customer($request) === null ? ResolveGuestToken::from($request) : null;
    }
}
