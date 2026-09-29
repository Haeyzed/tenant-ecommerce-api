<?php

declare(strict_types=1);

namespace App\Modules\Repair\Http\Controllers\Tenant\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Repair\Http\RepairPresenter;
use App\Modules\Repair\Models\RepairJob;
use App\Modules\Repair\Models\RepairJobPart;
use App\Modules\Repair\Services\RepairJobService;
use App\Modules\Users\Models\User;
use App\Shared\Http\APIResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Parts fitted to a repair (spec §67.3).
 */
final class RepairJobPartController extends Controller
{
    public function __construct(
        private readonly RepairJobService $jobs,
        private readonly RepairPresenter $presenter,
    ) {}

    /**
     * Body: product_id, product_variant_id?, quantity
     */
    public function store(Request $request, RepairJob $job): JsonResponse
    {
        $data = $request->validate([
            'product_id' => ['required', 'integer'],
            'product_variant_id' => ['sometimes', 'nullable', 'integer'],
            'quantity' => ['required', 'numeric'],
        ]);
        $product = Product::query()->findOrFail($data['product_id']);
        $variant = isset($data['product_variant_id']) ? ProductVariant::query()->where('product_id', $product->id)->findOrFail($data['product_variant_id']) : null;
        $part = $this->jobs->addPart($this->jobs->getJob($job, $this->user($request)), $product, $variant, (string) $data['quantity']);

        return APIResponse::created($this->presenter->part($part, true), 'Part fitted');
    }

    public function destroy(Request $request, RepairJob $job, RepairJobPart $part): JsonResponse
    {
        if ($part->repair_job_id !== $job->id) {
            throw new NotFoundHttpException('Not found.');
        }

        $this->jobs->removePart($this->jobs->getJob($job, $this->user($request)), $part);

        return APIResponse::success(null, 'Part removed and restocked');
    }

    private function user(Request $request): User
    {
        /** @var User */
        return $request->user();
    }
}
