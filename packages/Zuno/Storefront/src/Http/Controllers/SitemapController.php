<?php

namespace Zuno\Storefront\Http\Controllers;

use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Zuno\Storefront\Support\Catalog;

/**
 * sitemap.xml for search engines: the shop's public pages plus every listed product.
 * The React site on Vercel serves it at /sitemap.xml (see vercel.json in zuno-frontend).
 */
class SitemapController extends Controller
{
    /** Public pages of the React shop that are worth indexing. */
    protected const PAGES = ['/', '/shop-men', '/shop-women', '/about', '/contact'];

    public function __construct(protected Catalog $catalog) {}

    public function __invoke(): Response
    {
        $base = config('zuno.frontend_url');

        $urls = array_map(fn ($path) => ['loc' => $base.$path, 'lastmod' => null], self::PAGES);

        foreach ($this->catalog->all() as $product) {
            $urls[] = [
                'loc' => $base.'/product/'.rawurlencode($product->url_key),
                'lastmod' => $product->updated_at?->toDateString(),
            ];
        }

        $xml = '<?xml version="1.0" encoding="UTF-8"?>'."\n"
            .'<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'."\n";

        foreach ($urls as $url) {
            $xml .= '  <url><loc>'.e($url['loc']).'</loc>'
                .($url['lastmod'] ? '<lastmod>'.$url['lastmod'].'</lastmod>' : '')
                ."</url>\n";
        }

        $xml .= "</urlset>\n";

        return response($xml, 200, [
            'Content-Type' => 'application/xml; charset=UTF-8',
            'Cache-Control' => 'public, max-age=3600',
        ]);
    }
}
