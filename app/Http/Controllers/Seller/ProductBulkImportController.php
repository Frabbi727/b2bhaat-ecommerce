<?php

namespace App\Http\Controllers\Seller;

use App\Http\Controllers\Controller;
use App\Services\Inventory\ProductBulkImportService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class ProductBulkImportController extends Controller
{
    /**
     * The product import template, with worked example rows that are skipped on import.
     */
    public function template(Request $request, ProductBulkImportService $service): Response
    {
        if (! $request->user()->sellerProfile) {
            abort(403, 'Only sellers can import products.');
        }

        return response($service->templateCsv(), 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="b2bhaat-product-import-template.csv"',
            'Cache-Control' => 'no-store',
        ]);
    }
}
