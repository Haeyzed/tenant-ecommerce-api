<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Modules\Catalog\Http\CatalogPresenter;
use App\Modules\Catalog\Models\Brand;
use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductQuestion;
use App\Modules\Catalog\Models\ProductRelation;
use App\Modules\Catalog\Models\Tag;
use App\Modules\Catalog\Services\CategoryService;
use App\Modules\Catalog\Services\ProductContentService;
use App\Modules\Catalog\Services\ProductQuestionService;
use App\Modules\Catalog\Services\ProductService;
use App\Modules\Catalog\Services\ProductViewService;
use App\Modules\Catalog\Support\ProductSorts;
use App\Modules\Customers\Models\Customer;
use App\Shared\Http\APIResponse;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The storefront catalogue (spec §31.1): only visible products and active
 * categories; in_stock and the effective price, never warehouse detail.
 */
final class StorefrontCatalogController extends Controller
{
    public function __construct(
        private readonly ProductService $products,
        private readonly CatalogPresenter $presenter,
        private readonly ProductSorts $sorts,
    ) {}

    public function products(Request $request): JsonResponse
    {
        return $this->listing($request, []);
    }

    /**
     * {product} is an id or a slug; ?slug= also resolves a URL slug.
     */
    public function show(Request $request, string $product, ProductViewService $views): JsonResponse
    {
        $model = $this->visibleProduct($request->query('slug') !== null ? (string) $request->query('slug') : $product);

        $customer = Auth::guard('customer')->user();
        $views->recordView($model, $customer instanceof Customer ? $customer : null);

        return APIResponse::success($this->presenter->storefrontProduct($model));
    }

    public function related(Request $request, string $product, ProductContentService $content): JsonResponse
    {
        $type = $request->validate(['type' => ['sometimes', Rule::in(ProductRelation::TYPES)]])['type'] ?? 'related';

        return APIResponse::success($this->presenter->storefrontCards($content->getRelated($this->visibleProduct($product), $type)));
    }

    public function questions(string $product, ProductQuestionService $questions): JsonResponse
    {
        return APIResponse::success($questions->listForProduct($this->visibleProduct($product))
            ->through(fn (ProductQuestion $q): array => $this->presenter->question($q, true)));
    }

    public function askQuestion(Request $request, string $product, ProductQuestionService $questions): JsonResponse
    {
        /** @var Customer $customer */
        $customer = $request->user();
        $question = $questions->askQuestion($customer, $this->visibleProduct($product), (string) $request->input('question', ''));

        return APIResponse::created(
            ['id' => $question->id, 'is_approved' => $question->is_approved],
            $question->is_approved ? 'Question posted' : 'Thank you. Your question will appear once approved.',
        );
    }

    public function categories(CategoryService $categories): JsonResponse
    {
        return APIResponse::success($this->presenter->categoryTree($categories->listCategories(['active_only' => true, 'tree' => true]), true));
    }

    public function category(string $category): JsonResponse
    {
        return APIResponse::success($this->presenter->category($this->activeCategory($category), true));
    }

    public function categoryProducts(Request $request, string $category): JsonResponse
    {
        return $this->listing($request, ['category_id' => $this->activeCategory($category)->id]);
    }

    public function brands(): JsonResponse
    {
        return APIResponse::success(Brand::query()->orderBy('name')->get()->map(fn (Brand $b): array => $this->presenter->brand($b))->values());
    }

    public function brand(string $brand): JsonResponse
    {
        return APIResponse::success($this->presenter->brand($this->findBySlugOrId(Brand::query(), $brand)));
    }

    public function brandProducts(Request $request, string $brand): JsonResponse
    {
        return $this->listing($request, ['brand_id' => $this->findBySlugOrId(Brand::query(), $brand)->id]);
    }

    public function tags(): JsonResponse
    {
        return APIResponse::success(Tag::query()->orderBy('name')->get(['name', 'slug']));
    }

    /**
     * @param  array<string, mixed>  $base
     */
    private function listing(Request $request, array $base): JsonResponse
    {
        $validated = $request->validate([
            'search' => ['sometimes', 'string', 'max:100'],
            'category_id' => ['sometimes', 'string', 'max:500', 'regex:/^\d+(,\d+)*$/'],
            'brand_id' => ['sometimes', 'string', 'max:500', 'regex:/^\d+(,\d+)*$/'],
            'tag' => ['sometimes', 'string', 'max:500'],
            'min_price' => ['sometimes', 'numeric', 'min:0'],
            'max_price' => ['sometimes', 'numeric', 'min:0'],
            'option' => ['sometimes', 'array', 'max:10'],
            'option.*' => ['string', 'max:64'],
            'in_stock' => ['sometimes', 'boolean'],
            'badge' => ['sometimes', Rule::in(['new', 'bestseller', 'on_sale', 'limited', 'custom'])],
            'sort' => ['sometimes', Rule::in($this->sorts->names())],
            'currency' => ['sometimes', 'string', 'size:3'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        if (array_key_exists('in_stock', $validated)) {
            $validated['in_stock'] = $request->boolean('in_stock');
        }

        /** @var LengthAwarePaginator<int, Product> $page */
        $page = $this->products->searchAndFilter($validated, $validated['sort'] ?? null, (int) ($validated['per_page'] ?? 24), $base);
        $cards = $this->presenter->storefrontCards($page->getCollection());

        return APIResponse::success($page->setCollection(collect($cards)));
    }

    private function visibleProduct(string $idOrSlug): Product
    {
        /** @var Product */
        return $this->findBySlugOrId(Product::query()->visible(), $idOrSlug);
    }

    private function activeCategory(string $idOrSlug): Category
    {
        /** @var Category */
        return $this->findBySlugOrId(Category::query()->where('is_active', true), $idOrSlug);
    }

    /**
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @return TModel
     */
    private function findBySlugOrId($query, string $idOrSlug)
    {
        $model = ctype_digit($idOrSlug) ? (clone $query)->whereKey((int) $idOrSlug)->first() : null;

        return $model ?? (clone $query)->where('slug', $idOrSlug)->first() ?? throw new NotFoundHttpException('Not found.');
    }
}
