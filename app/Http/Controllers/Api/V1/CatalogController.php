<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\ProductResource;
use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class CatalogController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = Product::query()
            ->active()
            ->with([
                'category',
                'brand',
                'unit',
                'salesChannel',
                'images',
                'prices.salesChannel',
                'variations.attributeValues.attribute',
            ]);

        if ($request->has('search')) {
            $search = $request->input('search');
            $query->where(function ($q) use ($search) {
                $q->where('title', 'ilike', "%{$search}%")
                    ->orWhere('short_description', 'ilike', "%{$search}%");
            });
        }

        if ($request->has('category_id')) {
            $query->where('category_id', $request->input('category_id'));
        }

        $products = $query->paginate(15);

        return ProductResource::collection($products);
    }

    public function show(Request $request, string $slug): ProductResource
    {
        $product = Product::with([
            'category',
            'brand',
            'unit',
            'salesChannel',
            'images',
            'prices.salesChannel',
            'variations.attributeValues.attribute',
        ])
            ->where('slug', $slug)
            ->firstOrFail();

        return new ProductResource($product);
    }
}
