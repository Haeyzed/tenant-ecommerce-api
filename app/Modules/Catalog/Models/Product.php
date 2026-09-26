<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use OwenIt\Auditing\Auditable;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\Sluggable\HasSlug;
use Spatie\Sluggable\SlugOptions;

/**
 * A catalogue product (spec §27.3, authoritative). Stock is never a
 * column here: it lives per warehouse in inventory (§32).
 *
 * @property int $id
 * @property string $product_type simple | variable | bundle | digital | service
 * @property string $name
 * @property string $slug
 * @property string|null $sku
 * @property string|null $barcode
 * @property string|null $description
 * @property string $price
 * @property string|null $compare_at_price
 * @property string|null $cost_price
 * @property string $tax_class standard | reduced | exempt
 * @property int|null $brand_id
 * @property int|null $seller_id
 * @property string $moderation_status
 * @property string|null $moderation_note
 * @property int|null $unit_id
 * @property string|null $hsn_code
 * @property Carbon|null $expiry_date
 * @property bool $has_warehouse_pricing
 * @property bool $is_active
 * @property int $view_count
 * @property string $rating_average
 * @property int $rating_count
 * @property string|null $meta_title
 * @property string|null $meta_description
 * @property string|null $meta_keywords
 * @property bool $is_bookable
 * @property int|null $duration_minutes
 * @property bool $is_subscribable
 * @property string|null $subscription_discount_percent
 * @property list<string>|null $social_commerce_excluded_channels
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read Brand|null $brand
 * @property-read UnitOfMeasure|null $unit
 */
class Product extends Model implements AuditableContract, HasMedia
{
    use Auditable;
    use HasSlug;
    use InteractsWithMedia;
    use SoftDeletes;

    public const string SIMPLE = 'simple';

    public const string VARIABLE = 'variable';

    public const string BUNDLE = 'bundle';

    public const string DIGITAL = 'digital';

    public const string SERVICE = 'service';

    public const array TYPES = [self::SIMPLE, self::VARIABLE, self::BUNDLE, self::DIGITAL, self::SERVICE];

    /** Types that hold or consume stock and ship. */
    public const array PHYSICAL = [self::SIMPLE, self::VARIABLE, self::BUNDLE];

    public const array TAX_CLASSES = ['standard', 'reduced', 'exempt'];

    protected $connection = 'tenant';

    /**
     * The column defaults that code reads before a refresh.
     *
     * @var array<string, mixed>
     */
    protected $attributes = ['product_type' => self::SIMPLE, 'moderation_status' => 'not_required'];

    protected $fillable = [
        'product_type', 'name', 'slug', 'sku', 'barcode', 'description', 'price', 'compare_at_price', 'cost_price', 'tax_class',
        'brand_id', 'unit_id', 'hsn_code', 'expiry_date', 'has_warehouse_pricing', 'is_active', 'meta_title', 'meta_description', 'meta_keywords',
        'is_bookable', 'duration_minutes', 'is_subscribable', 'subscription_discount_percent', 'social_commerce_excluded_channels',
    ];

    /**
     * Price and cost history is the point of auditing products (§27.3).
     *
     * @var list<string>
     */
    protected array $auditExclude = ['view_count', 'rating_average', 'rating_count'];

    protected $casts = [
        'price' => 'decimal:4',
        'compare_at_price' => 'decimal:4',
        'cost_price' => 'decimal:4',
        'brand_id' => 'integer',
        'seller_id' => 'integer',
        'unit_id' => 'integer',
        'expiry_date' => 'date',
        'has_warehouse_pricing' => 'boolean',
        'is_active' => 'boolean',
        'view_count' => 'integer',
        'rating_average' => 'decimal:2',
        'rating_count' => 'integer',
        'is_bookable' => 'boolean',
        'duration_minutes' => 'integer',
        'is_subscribable' => 'boolean',
        'subscription_discount_percent' => 'decimal:4',
        'social_commerce_excluded_channels' => 'array',
    ];

    public function getSlugOptions(): SlugOptions
    {
        return SlugOptions::create()->generateSlugsFrom('name')->saveSlugsTo('slug')->preventOverwrite()->doNotGenerateSlugsOnUpdate();
    }

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('gallery')->useDisk('public');
        $this->addMediaCollection('featured')->singleFile()->useDisk('public');
        $this->addMediaCollection('og_image')->singleFile()->useDisk('public');
    }

    /**
     * @return BelongsTo<Brand, $this>
     */
    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    /**
     * @return BelongsTo<UnitOfMeasure, $this>
     */
    public function unit(): BelongsTo
    {
        return $this->belongsTo(UnitOfMeasure::class, 'unit_id');
    }

    /**
     * @return BelongsToMany<Category, $this>
     */
    public function categories(): BelongsToMany
    {
        return $this->belongsToMany(Category::class, 'product_categories')->withPivot('is_primary');
    }

    /**
     * @return BelongsToMany<Tag, $this>
     */
    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class, 'product_tag');
    }

    /**
     * @return HasMany<ProductVariant, $this>
     */
    public function variants(): HasMany
    {
        return $this->hasMany(ProductVariant::class)->orderBy('id');
    }

    /**
     * @return HasMany<ProductBundleItem, $this>
     */
    public function bundleItems(): HasMany
    {
        return $this->hasMany(ProductBundleItem::class, 'bundle_product_id');
    }

    /**
     * @return HasMany<DigitalProductFile, $this>
     */
    public function digitalFiles(): HasMany
    {
        return $this->hasMany(DigitalProductFile::class);
    }

    /**
     * @return HasMany<ProductSpecification, $this>
     */
    public function specifications(): HasMany
    {
        return $this->hasMany(ProductSpecification::class)->orderBy('sort_order')->orderBy('id');
    }

    /**
     * @return HasMany<ProductBadge, $this>
     */
    public function badges(): HasMany
    {
        return $this->hasMany(ProductBadge::class);
    }

    /**
     * @return HasMany<ProductRelation, $this>
     */
    public function relations(): HasMany
    {
        return $this->hasMany(ProductRelation::class)->orderBy('sort_order');
    }

    /**
     * Published and visible on the storefront (moderation must allow it).
     *
     * @param  Builder<self>  $query
     */
    public function scopeVisible(Builder $query): void
    {
        $query->where('products.is_active', true)->whereIn('products.moderation_status', ['not_required', 'approved']);
    }

    public function isPhysical(): bool
    {
        return in_array($this->product_type, self::PHYSICAL, true);
    }
}
