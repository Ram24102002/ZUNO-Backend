<?php

namespace Zuno\Storefront\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Webkul\Attribute\Models\AttributeOption;
use Webkul\Attribute\Repositories\AttributeRepository;
use Webkul\Category\Repositories\CategoryRepository;
use Webkul\Core\Repositories\CoreConfigRepository;
use Webkul\Inventory\Repositories\InventorySourceRepository;
use Webkul\Product\Repositories\ProductRepository;

/**
 * Prepares a fresh Bagisto install for ZUNO. Safe to run on every start: settings are re-applied,
 * but products that already exist are left alone so edits made in the admin are kept.
 *
 *   php artisan zuno:setup            store settings + any missing products
 *   php artisan zuno:setup --fresh    also delete and re-create the ZUNO products from products.json
 */
class Setup extends Command
{
    protected $signature = 'zuno:setup {--fresh : Re-create the ZUNO products from products.json}';

    protected $description = 'Apply ZUNO store settings and create its catalogue in Bagisto';

    private const CHANNEL = 'default';

    private const LOCALE = 'en';

    public function __construct(
        protected ProductRepository $productRepository,
        protected AttributeRepository $attributeRepository,
        protected CoreConfigRepository $coreConfigRepository,
        protected InventorySourceRepository $inventorySourceRepository,
        protected CategoryRepository $categoryRepository,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $this->applySettings();

        $products = json_decode(file_get_contents(dirname(__DIR__, 3).'/data/products.json'), true);

        $created = DB::transaction(function () use ($products) {
            $created = 0;

            foreach ($products as $position => $product) {
                $created += (int) $this->createProduct($product, $position);
            }

            $created += (int) $this->createCustomTee();

            // Fill in what the admin has left empty, without undoing an admin's choices
            foreach ($products as $product) {
                $this->assignCategory($product);
            }
            $this->linkRelatedProducts($products);

            return $created;
        });

        if ($created) {
            $this->call('indexer:index', ['--mode' => ['full']]);
        }

        $this->components->info($created ? "ZUNO catalogue ready ($created products created)." : 'ZUNO catalogue already in place.');

        return self::SUCCESS;
    }

    /** Free delivery only, guest checkout, and the shop's name. */
    protected function applySettings(): void
    {
        $this->coreConfigRepository->create([
            'channel' => self::CHANNEL,
            'locale' => self::LOCALE,
            'sales' => [
                'carriers' => [
                    'free' => ['active' => 1, 'title' => 'Free delivery'],
                    'flatrate' => ['active' => 0],
                ],
                'checkout' => [
                    'shopping_cart' => ['allow_guest_checkout' => 1],
                ],
                'payment_methods' => [
                    'moneytransfer' => ['active' => 0],
                    'cashondelivery' => ['active' => 1, 'title' => 'Pay on delivery'],
                ],
            ],
        ]);

        DB::table('channel_translations')->update(['name' => 'ZUNO']);
    }

    protected function createProduct(array $data, int $position): bool
    {
        $sku = 'zuno-'.$data['id'];
        $existing = $this->productRepository->findOneByField('sku', $sku);

        if ($existing && ! $this->option('fresh')) {
            return false;
        }

        if ($existing) {
            $this->productRepository->delete($existing->id);
        }

        $colors = $this->optionIds('color', $data['colors']);
        $sizes = $this->optionIds('size', $data['sizes']);

        $product = $this->productRepository->create([
            'type' => 'configurable',
            'attribute_family_id' => 1,
            'sku' => $sku,
            'super_attributes' => ['color' => array_values($colors), 'size' => array_values($sizes)],
            'channel' => self::CHANNEL,
            'locale' => self::LOCALE,
        ]);

        $stock = $data['soldOut'] ?? false ? 0 : config('zuno.initial_stock');
        $source = $this->inventorySourceRepository->first()->id;
        $variants = [];

        foreach ($product->refresh()->variants as $variant) {
            $color = array_search((int) $variant->color, $colors, true);
            $size = array_search((int) $variant->size, $sizes, true);

            $variants[$variant->id] = [
                'sku' => "$sku-".strtolower(str_replace(' ', '-', "$color-$size")),
                'name' => "{$data['name']} - $color / $size",
                'price' => $data['price'],
                'weight' => 0.3,
                'status' => 1,
                'color' => $colors[$color],
                'size' => $sizes[$size],
                'inventories' => [$source => $stock],
            ];
        }

        $this->productRepository->update(array_merge($this->commonAttributes($data, $sku), [
            'variants' => $variants,
            'images' => ['files' => $this->uploads($data['images'] ?? [])],
        ]), $product->id);

        $this->line("  created {$data['name']}");

        return true;
    }

    /** The "design your own" tee: one simple product; colour and size travel with each order line. */
    protected function createCustomTee(): bool
    {
        $tee = config('zuno.custom_tee');

        if ($this->productRepository->findOneByField('sku', $tee['sku'])) {
            return false;
        }

        $product = $this->productRepository->create([
            'type' => 'simple',
            'attribute_family_id' => 1,
            'sku' => $tee['sku'],
        ]);

        $this->productRepository->update(array_merge($this->commonAttributes([
            'id' => 'custom-tee',
            'name' => $tee['name'],
            'description' => 'Your own artwork printed on a ZUNO oversized tee.',
            'tag' => '',
        ], $tee['sku']), [
            'price' => $tee['price'],
            'weight' => 0.3,
            // Not listed in the shop; it is ordered from the design studio
            'visible_individually' => 0,
            'manage_stock' => 0,
            'inventories' => [$this->inventorySourceRepository->first()->id => 10000],
        ]), $product->id);

        $this->line('  created custom tee');

        return true;
    }

    protected function commonAttributes(array $data, string $sku): array
    {
        return [
            'channel' => self::CHANNEL,
            'locale' => self::LOCALE,
            'channels' => [core()->getDefaultChannel()->id],
            'categories' => [core()->getDefaultChannel()->root_category_id],
            'sku' => $sku,
            'product_number' => '',
            'name' => $data['name'],
            'url_key' => $data['id'],
            'short_description' => '<p>'.e($data['description']).'</p>',
            'description' => '<p>'.e($data['description']).'</p>',
            'meta_title' => $data['name'].' | ZUNO',
            'meta_keywords' => '',
            'meta_description' => $data['description'],
            'new' => ($data['tag'] ?? '') === 'New' ? 1 : 0,
            'featured' => 0,
            'visible_individually' => 1,
            'status' => 1,
            'guest_checkout' => 1,
            'manage_stock' => 1,
            'allow_rma' => 0,
            'tax_category_id' => '',
        ];
    }

    /** Puts a product in its category from products.json, if it is not in any category of its own yet. */
    protected function assignCategory(array $data): void
    {
        $product = $this->productRepository->findOneByField('sku', 'zuno-'.$data['id']);
        $rootId = core()->getDefaultChannel()->root_category_id;

        if (! $product || empty($data['category']) || $product->categories()->where('categories.id', '!=', $rootId)->exists()) {
            return;
        }

        $product->categories()->syncWithoutDetaching([$this->categoryId($data['category'])]);
    }

    /** The id of the category with this name under the store's root category, created if missing. */
    protected function categoryId(string $name): int
    {
        $slug = \Illuminate\Support\Str::slug($name);
        $existing = DB::table('category_translations')->where('slug', $slug)->value('category_id');

        if ($existing) {
            return $existing;
        }

        $category = $this->categoryRepository->create([
            'locale' => 'all',
            'name' => $name,
            'slug' => $slug,
            'description' => $name,
            'meta_title' => $name.' | ZUNO',
            'meta_description' => '',
            'meta_keywords' => '',
            'status' => 1,
            'position' => DB::table('categories')->max('position') + 1,
            'display_mode' => 'products_only',
            'parent_id' => core()->getDefaultChannel()->root_category_id,
        ]);

        $this->line("  created category $name");

        return $category->id;
    }

    /**
     * Starting "you may also like" links: each product gets the rest of the ZUNO range. Only done while
     * no ZUNO product has any related products, so links edited in the admin are never redone.
     */
    protected function linkRelatedProducts(array $products): void
    {
        $ids = collect($products)
            ->map(fn ($data) => $this->productRepository->findOneByField('sku', 'zuno-'.$data['id'])?->id)
            ->filter();

        if (DB::table('product_relations')->whereIn('parent_id', $ids)->exists()) {
            return;
        }

        foreach ($ids as $id) {
            DB::table('product_relations')->insert(
                $ids->reject(fn ($other) => $other === $id)->map(fn ($other) => ['parent_id' => $id, 'child_id' => $other])->values()->all()
            );
        }
    }

    /**
     * ['Black' => optionId, ...] for a select attribute, adding any option that does not exist yet.
     */
    protected function optionIds(string $attributeCode, array $labels): array
    {
        $attribute = $this->attributeRepository->findOneByField('code', $attributeCode);
        $ids = [];

        foreach ($labels as $label) {
            $option = $attribute->options()->get()->first(
                fn ($option) => strcasecmp($option->admin_name, $label) === 0
            );

            $option ??= AttributeOption::create([
                'attribute_id' => $attribute->id,
                'admin_name' => $label,
                'sort_order' => ($attribute->options()->max('sort_order') ?? 0) + 1,
                self::LOCALE => ['label' => $label],
            ]);

            $ids[$label] = $option->id;
        }

        return $ids;
    }

    /** Product photos from the React app's public folder, handed to Bagisto as uploads. */
    protected function uploads(array $paths): array
    {
        $files = [];

        foreach ($paths as $path) {
            $file = rtrim(config('zuno.images_path'), '/').'/'.ltrim($path, '/');

            if (! is_file($file)) {
                $this->warn("  missing image $file");

                continue;
            }

            $files[] = new UploadedFile($file, basename($file), mime_content_type($file), null, true);
        }

        return $files;
    }
}
