<?php

namespace App\Http\Controllers\Api\v1;

use App\Http\Controllers\Controller;
use App\Services\ProductService;
use Illuminate\Http\Request;
class ProductController extends Controller
{
    public function __construct(
        private ProductService $productService
    )
    {}

    public function bulkImport(Request $request) {
        try {
            if ($request->file('file')) {
                $filePath = $request->file('file')->storeAs('products', $request->file('file')->getClientOriginalName(), 'public');
                $result = $this->productService->bulkImport($filePath);
                if($result['success']) {
                    return response()->json($result['data']);
                }
                return response()->json($result['error'], 400);
            }
            return response()->json('Please upload the PDF file.', 400);
        } catch (\Exception $e) {
            return response()->json($e->getMessage(), 400);
        }
    }
}
