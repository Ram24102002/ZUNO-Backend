<?php

namespace Zuno\Storefront\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Event;
use Webkul\Product\Repositories\ProductReviewRepository;
use Zuno\Storefront\Support\Catalog;

/**
 * Product reviews. New reviews wait for approval in the admin (Catalog > Reviews);
 * only approved ones are shown.
 */
class ReviewController extends Controller
{
    public function __construct(
        protected Catalog $catalog,
        protected ProductReviewRepository $productReviewRepository,
    ) {}

    public function index(string $slug): JsonResponse
    {
        $product = $this->catalog->findBySlug($slug) ?? abort(404);
        $censor = (bool) core()->getConfigData('catalog.products.review.censoring_reviewer_name');

        $reviews = $product->reviews()
            ->where('status', 'approved')
            ->latest()
            ->paginate(10);

        return response()->json([
            'rating' => $this->catalog->rating($product),
            'breakdown' => collect(range(5, 1))->mapWithKeys(fn ($stars) => [
                $stars => $product->reviews()->where('status', 'approved')->where('rating', $stars)->count(),
            ]),
            'reviews' => $reviews->getCollection()->map(fn ($review) => [
                'id' => $review->id,
                'name' => $censor ? $this->censor($review->name) : $review->name,
                'title' => $review->title,
                'comment' => $review->comment,
                'rating' => (int) $review->rating,
                'date' => $review->created_at->toDateString(),
                'verified' => $review->customer_id && $this->bought($review->customer_id, $product->id),
            ]),
            'page' => $reviews->currentPage(),
            'lastPage' => $reviews->lastPage(),
        ]);
    }

    public function store(Request $request, string $slug): JsonResponse
    {
        $product = $this->catalog->findBySlug($slug) ?? abort(404);
        $customer = auth()->guard('customer')->user();

        if (! $customer && ! core()->getConfigData('catalog.products.review.guest_review')) {
            return response()->json(['error' => 'Log in to write a review.'], 401);
        }

        $data = $request->validate([
            'rating' => 'required|integer|min:1|max:5',
            'title' => 'required|string|max:120',
            'comment' => 'required|string|max:2000',
            'name' => [$customer ? 'nullable' : 'required', 'string', 'max:80'],
        ]);

        Event::dispatch('customer.review.create.before', $product->id);

        $review = $this->productReviewRepository->createWithAttachments([
            'rating' => $data['rating'],
            'title' => $data['title'],
            'comment' => $data['comment'],
            'name' => $customer?->name ?? $data['name'],
            'customer_id' => $customer?->id,
            'product_id' => $product->id,
            'status' => 'pending',
            'attachments' => [],
        ]);

        Event::dispatch('customer.review.create.after', $review);

        return response()->json(['message' => 'Thanks! Your review will appear once it has been checked.'], 201);
    }

    /** Whether this customer has ordered this product (any colour or size). */
    protected function bought(int $customerId, int $productId): bool
    {
        return \Webkul\Sales\Models\OrderItem::query()
            ->where('product_id', $productId)
            ->whereHas('order', fn ($order) => $order->where('customer_id', $customerId)->where('status', '!=', 'canceled'))
            ->exists();
    }

    protected function censor(string $name): string
    {
        return collect(explode(' ', $name))
            ->map(fn ($part) => mb_substr($part, 0, 1).str_repeat('*', max(0, mb_strlen($part) - 1)))
            ->implode(' ');
    }
}
