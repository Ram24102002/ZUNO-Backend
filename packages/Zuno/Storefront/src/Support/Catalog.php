<?php

namespace Zuno\Storefront\Support;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Webkul\Category\Repositories\CategoryRepository;
use Webkul\Product\Contracts\Product;
use Webkul\Product\Repositories\ProductRepository;

/**
 * Reads ZUNO's catalogue out of Bagisto. Each tee is a configurable product whose
 * variants are its colour × size combinations; the storefront sees one plain object per tee.
 */
class Catalog
{
    public const SORTS = ['featured', 'newest', 'price-asc', 'price-desc', 'rating'];

    public function __construct(
        protected ProductRepository $productRepository,
        protected CategoryRepository $categoryRepository,
    ) {}

    /** Every tee shown in the shop, oldest first. */
    public function all(): Collection
    {
        return $this->productRepository
            // Loading the variants' stock and attributes up front saves a few queries per variant
            ->with([
                'images', 'categories', 'super_attributes.options',
                'variants.inventory_indices', 'variants.attribute_values', 'variants.attribute_family',
            ])
            ->findWhere(['type' => 'configurable', 'parent_id' => null])
            ->filter(fn (Product $product) => $this->isListed($product))
            ->sortBy('id')
            ->values();
    }

    /**
     * The shop's product list as the React site shows it, narrowed by
     * q (search words), category (slug), color, size, min_price, max_price, and sorted by sort.
     * The range is small, so this filters in memory rather than through Bagisto's search index.
     */
    public function search(array $filters): Collection
    {
        $products = $this->all()->map(fn (Product $product) => $this->toArray($product));
        $words = Str::of($filters['q'] ?? '')->lower()->squish()->explode(' ')->filter();

        $products = $products->filter(function (array $p) use ($filters, $words) {
            $text = Str::lower($p['name'].' '.$p['description'].' '.implode(' ', $p['colors']).' '.implode(' ', array_column($p['categories'], 'name')));

            return $words->every(fn ($word) => str_contains($text, $word))
                && (empty($filters['category']) || in_array($filters['category'], array_column($p['categories'], 'slug'), true))
                && (empty($filters['color']) || $this->hasStock($p, color: $filters['color']))
                && (empty($filters['size']) || $this->hasStock($p, size: $filters['size']))
                && (! isset($filters['min_price']) || $p['price'] >= (float) $filters['min_price'])
                && (! isset($filters['max_price']) || $p['price'] <= (float) $filters['max_price']);
        });

        $sorted = match ($filters['sort'] ?? 'featured') {
            'newest' => $products->sortByDesc('productId'),
            'price-asc' => $products->sortBy('price'),
            'price-desc' => $products->sortByDesc('price'),
            'rating' => $products->sortByDesc(fn ($p) => [$p['rating']['average'], $p['rating']['count']]),
            default => $products, // catalogue order
        };

        return $sorted->values();
    }

    /** Categories that hold at least one listed product, in admin order, with what can be filtered on. */
    public function filters(): array
    {
        $products = $this->all()->map(fn (Product $product) => $this->toArray($product));
        $inCategory = fn (string $slug) => $products->filter(fn ($p) => in_array($slug, array_column($p['categories'], 'slug'), true))->count();

        return [
            'categories' => $products->pluck('categories')->flatten(1)
                ->unique('slug')
                ->sortBy('position')
                ->map(fn ($c) => ['slug' => $c['slug'], 'name' => $c['name'], 'count' => $inCategory($c['slug'])])
                ->values(),
            // Both in the option order set in the admin
            'colors' => $products->pluck('colors')->flatten()->unique()->values(),
            'sizes' => $products->pluck('sizes')->flatten()->unique()->values(),
            'price' => ['min' => (int) floor($products->min('price') ?? 0), 'max' => (int) ceil($products->max('price') ?? 0)],
        ];
    }

    public function findBySlug(string $slug): ?Product
    {
        $product = $this->productRepository->findByAttributeCode('url_key', $slug);

        return $product && $product->type === 'configurable' && $this->isListed($product) ? $product : null;
    }

    /** "You may also like": related products chosen in the admin that are on sale. */
    public function related(Product $product): array
    {
        return $product->related_products()
            ->get()
            ->filter(fn (Product $related) => $related->type === 'configurable' && $this->isListed($related))
            ->map(fn (Product $related) => $this->toArray($related))
            ->values()
            ->all();
    }

    /** The custom-tee product that design-your-own orders are booked against. */
    public function customTee(): ?Product
    {
        return $this->productRepository->findOneByField('sku', config('zuno.custom_tee.sku'));
    }

    /** The variant for a colour and size, by their labels (e.g. "Black", "XL"). */
    public function variant(Product $product, string $color, string $size): ?Product
    {
        $options = $this->optionIds($product);
        $colorId = $options['color'][$color] ?? null;
        $sizeId = $options['size'][$size] ?? null;

        if (! $colorId || ! $sizeId) {
            return null;
        }

        return $product->variants->first(
            fn (Product $variant) => (int) $variant->color === $colorId && (int) $variant->size === $sizeId
        );
    }

    /** The shape the React site works with. */
    public function toArray(Product $product): array
    {
        $labels = $this->optionLabels($product);
        $variants = $product->variants
            ->filter(fn (Product $variant) => $variant->status)
            ->map(fn (Product $variant) => [
                'color' => $labels['color'][(int) $variant->color] ?? null,
                'size' => $labels['size'][(int) $variant->size] ?? null,
                'inStock' => $variant->haveSufficientQuantity(1),
            ])
            ->filter(fn (array $variant) => $variant['color'] && $variant['size'])
            ->values();

        // Colours and sizes follow the option order set in the admin, limited to ones that exist
        $colors = array_values(array_filter($labels['color'], fn ($c) => $variants->contains('color', $c)));
        $sizes = array_values(array_filter($labels['size'], fn ($s) => $variants->contains('size', $s)));

        $price = (float) $product->getTypeInstance()->getMinimalPrice();
        $rootId = core()->getCurrentChannel()->root_category_id;

        return [
            'id' => $product->url_key,
            'productId' => $product->id,
            'name' => $product->name,
            'price' => $this->money($price),
            'tag' => $product->new ? 'New' : '',
            'description' => trim(html_entity_decode(strip_tags((string) $product->description))),
            'colors' => $colors,
            'sizes' => $sizes,
            'variants' => $variants->all(),
            'images' => $product->images->sortBy('position')->map(fn ($image) => $this->relativeUrl($image->url))->values()->all(),
            'soldOut' => ! $variants->contains('inStock', true),
            'categories' => $product->categories
                ->filter(fn ($category) => $category->id !== $rootId && $category->status)
                ->map(fn ($category) => ['slug' => $category->slug, 'name' => $category->name, 'position' => $category->position])
                ->values()
                ->all(),
            'rating' => $this->rating($product),
        ];
    }

    /** Average of approved reviews, e.g. ['average' => 4.5, 'count' => 12]. */
    public function rating(Product $product): array
    {
        $stats = DB::table('product_reviews')
            ->where('product_id', $product->id)
            ->where('status', 'approved')
            ->selectRaw('COUNT(*) as count, AVG(rating) as average')
            ->first();

        return ['average' => round((float) $stats->average, 1), 'count' => (int) $stats->count];
    }

    public function money(float $amount): int|float
    {
        $amount = round($amount, 2);

        return fmod($amount, 1.0) === 0.0 ? (int) $amount : $amount;
    }

    protected function hasStock(array $product, ?string $color = null, ?string $size = null): bool
    {
        return collect($product['variants'])->contains(
            fn ($v) => $v['inStock'] && (! $color || strcasecmp($v['color'], $color) === 0) && (! $size || strcasecmp($v['size'], $size) === 0)
        );
    }

    /** ['color' => [optionId => label], 'size' => [...]] in admin sort order. */
    protected function optionLabels(Product $product): array
    {
        $labels = ['color' => [], 'size' => []];

        foreach ($product->super_attributes as $attribute) {
            foreach ($attribute->options->sortBy('sort_order') as $option) {
                $labels[$attribute->code][$option->id] = $option->label ?: $option->admin_name;
            }
        }

        return $labels;
    }

    /** ['color' => [label => optionId], 'size' => [...]] */
    protected function optionIds(Product $product): array
    {
        return array_map(fn ($labels) => array_flip($labels), $this->optionLabels($product));
    }

    protected function isListed(Product $product): bool
    {
        return (bool) $product->status && (bool) $product->visible_individually && $product->url_key;
    }

    /**
     * Images are served by Bagisto under /storage. A path relative to the site lets the
     * React app load them through its own origin (the dev proxy or the production web server).
     */
    protected function relativeUrl(string $url): string
    {
        return parse_url($url, PHP_URL_PATH) ?: $url;
    }
}
