<?php

namespace App\Services;

use App\Imports\ProductsImport;
use Maatwebsite\Excel\Facades\Excel;

class ProductService
{
    public function __construct(){}

    public function bulkImport(string $filePath)
    {
        $filePath = storage_path('app/public/'.$filePath);
        try {

            $data = Excel::import(new ProductsImport, $filePath);
            unlink($filePath);

            return [
                'success' => true,
                'data' => $data
            ];
        } catch (\Exception $exception) {

            unlink($filePath);
            return [
                'success' => false,
                'error' => $exception->getMessage()
            ];
        }
    }
}
