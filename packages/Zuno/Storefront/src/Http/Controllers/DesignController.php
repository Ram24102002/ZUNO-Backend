<?php

namespace Zuno\Storefront\Http\Controllers;

use Illuminate\Routing\Controller;
use Webkul\Sales\Repositories\OrderItemRepository;
use Zuno\Storefront\Support\Designs;

/** Serves a custom-tee design file to a logged-in admin (route is behind Bagisto's admin middleware). */
class DesignController extends Controller
{
    public function __construct(protected OrderItemRepository $orderItemRepository, protected Designs $designs) {}

    public function show(int $item, string $file)
    {
        $orderItem = $this->orderItemRepository->findOrFail($item);
        $path = $this->designs->pathFor($orderItem->additional['zuno_design'] ?? [], $file);

        abort_unless($path && $this->designs->disk()->exists($path), 404);

        $name = 'order-'.$orderItem->order->increment_id.'-item-'.$orderItem->id.'-'.basename($path);

        return $this->designs->disk()->response($path, $name, [], request()->boolean('download') ? 'attachment' : 'inline');
    }
}
