<?php

namespace App\Imports;

use App\Models\Product;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Maatwebsite\Excel\Concerns\ToCollection;

class ProductsImport implements ToCollection
{
    public function collection(Collection $rows)
    {
        try {
            $rowsArray = $rows->splice(1, count($rows))->toArray();
            $products = array_filter($rowsArray, fn($row) => !is_null($row[6]) && trim($row[6]) != '');
            foreach ($products as $product) {
                Product::firstOrCreate(
                    [
                        'brand' => $product[0],
                        'model' => $product[3],
                        'code' => $product[4],
                    ],
                    [
                        'brand' => $product[0],
                        'line' => $product[1],
                        'type' => $product[2],
                        'model' => $product[3],
                        'code' => $product[4],
                        'description' => $product[5],
                        'doc_name' => $product[6],
                        'is_marked' => $product[7] === 'Yes',
                    ]
                );
            };
            info('The products data has successfully been imported.');
            return [
                'success' => true,
                'data' => 'Successfully imported.'
            ];
        } catch (\Exception $e) {
            Log::error('Failed to import the products data from uploaded excel file.');
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }
}
