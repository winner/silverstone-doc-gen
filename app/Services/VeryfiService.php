<?php

namespace App\Services;

use App\Models\LineItem;
use App\Models\Order;
use veryfi\Client;

class VeryfiService
{
    public function __construct(
        private LineItem $lineItemModel,
        private Order $orderModel,
    )
    {
    }

    public function run(string $filePath)
    {
        try {
            $client_id = config('services.veryfi.client_id');
            $client_secret = config('services.veryfi.client_secret');
            $username = config('services.veryfi.username');
            $apiKey = config('services.veryfi.api_key');

            $veryfiClient = new Client($client_id, $client_secret, $username, $apiKey);
            $categories = ['Advertising & Marketing', 'Automotive', 'Supplies'];
            $file = storage_path('app/public/'. $filePath);
            $returnAssociative = true;
            $deleteAfterProcessing = false;
            $result = json_decode($veryfiClient->process_document($file, $categories, $deleteAfterProcessing), $returnAssociative);
            $orderNumber = $result['invoice_number'];
            file_put_contents(storage_path("app/public/orders/$orderNumber.json"), json_encode($result, JSON_PRETTY_PRINT));
            $order = $this->orderModel->where('order_no', $orderNumber)->first();
            if(is_null($order)){
                $orderParams = [
                    'order_no' => $orderNumber,
                    'total' => $result['total'],
                    'subtotal' => $result['subtotal'],
                    'filename' => basename($filePath),
                ];
                $order = $this->orderModel->create($orderParams);
            }

            $order->lineItems()->delete();

            $lineItems = $result['line_items'];

            for ($i = 0; $i < count($lineItems); $i++) {
                $lineItem = $lineItems[$i];
                $lineItemParams = [
                    'order_id' => $order->id,
                    'sku' => $lineItem['sku'],
                    'price' => $lineItem['price'],
                    'quantity' => $lineItem['quantity'],
                    'total' => $lineItem['total'],
                    'description' => $lineItem['description'],
                ];
                $keywords = explode(" ", $lineItem['description']);
                $initName = $keywords[1] . ' ' . $keywords[0] . '.pdf';
                $firstFromFilePath = storage_path('app/public/line-items/' . $initName);
                if (file_exists($firstFromFilePath)) {
                    $lineItemParams['filename'] = $initName;
                    $order->lineItems()->create($lineItemParams);
                } else {
                    $pdfFiles = scandir(storage_path('app/public/line-items/'));
                    foreach ($pdfFiles as $pdfFile) {
                        $pdfFileName = basename($pdfFile);
                        if (strpos($pdfFileName, $keywords[0]) > -1) {
                            $lineItemParams['filename'] = $pdfFileName;
                            $order->lineItems()->create($lineItemParams);
                        };
                    }
                }
            }

            $order->load('lineItems');

            return [
                'success' => true,
                'data' => $order->toArray()
            ];
        }catch (\Exception $e){
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }
}
