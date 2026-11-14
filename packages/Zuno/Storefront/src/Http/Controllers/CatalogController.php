<?php

namespace Zuno\Storefront\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\Rule;
use Webkul\Core\Repositories\CountryStateRepository;
use Webkul\Razorpay\Payment\RazorpayPayment;
use Zuno\Storefront\Support\Catalog;

class CatalogController extends Controller
{
    public function __construct(
        protected Catalog $catalog,
        protected CountryStateRepository $countryStateRepository,
    ) {}

    /** The shop's products; every query parameter is optional. */
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'q' => 'nullable|string|max:100',
            'category' => 'nullable|string|max:120',
            'color' => 'nullable|string|max:40',
            'size' => 'nullable|string|max:5',
            'min_price' => 'nullable|numeric|min:0',
            'max_price' => 'nullable|numeric|min:0',
            'sort' => ['nullable', Rule::in(Catalog::SORTS)],
        ]);

        return response()->json($this->catalog->search(array_filter($filters, fn ($v) => $v !== null && $v !== '')));
    }

    /** The categories, colours, sizes and price range the shop can be filtered by. */
    public function filters(): JsonResponse
    {
        return response()->json($this->catalog->filters());
    }

    public function show(string $slug): JsonResponse
    {
        $product = $this->catalog->findBySlug($slug);

        if (! $product) {
            return response()->json(['error' => 'Product not found'], 404);
        }

        return response()->json(array_merge($this->catalog->toArray($product), [
            'related' => $this->catalog->related($product),
        ]));
    }

    /** What the storefront needs to know before checkout. */
    public function config(): JsonResponse
    {
        $tee = config('zuno.custom_tee');
        $product = $this->catalog->customTee();

        return response()->json([
            'customTee' => [
                'id' => $tee['id'],
                'name' => $product?->name ?? $tee['name'],
                'price' => $product ? $this->catalog->money((float) $product->getTypeInstance()->getMinimalPrice()) : $tee['price'],
                'sizes' => $tee['sizes'],
                'colors' => $tee['colors'],
                'available' => (bool) $product?->status,
            ],
            'razorpayKeyId' => $this->razorpayKeyId(),
            // Whether shoppers must log in to write a review (Configure > Catalog > Products in the admin)
            'guestReviews' => (bool) core()->getConfigData('catalog.products.review.guest_review'),
            'googleLogin' => GoogleLoginController::enabled(),
            // ZUNO delivers in India; the checkout's state list
            'states' => $this->countryStateRepository->findWhere(['country_code' => 'IN'])
                ->sortBy('default_name')
                ->map(fn ($state) => ['code' => $state->code, 'name' => $state->default_name])
                ->values(),
        ]);
    }

    /** The public key id when Razorpay is switched on; null means cash on delivery. */
    protected function razorpayKeyId(): ?string
    {
        $razorpay = app(RazorpayPayment::class);

        return $razorpay->isAvailable() ? $razorpay->getApiKey() : null;
    }
}
