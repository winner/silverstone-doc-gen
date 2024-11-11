<?php

namespace App\Services;

use App\Models\Order;
use App\Models\Product;
use ConvertApi\ConvertApi;
use veryfi\Client;

class VeryfiService
{
    public function __construct(
        private Order    $orderModel,
        private Product  $productModel
    )
    {
    }

    public function run(string $filePath)
    {
        try {
            ConvertApi::setApiCredentials(config('services.convert_api.secret'));

            $client_id = config('services.veryfi.client_id');
            $client_secret = config('services.veryfi.client_secret');
            $username = config('services.veryfi.username');
            $apiKey = config('services.veryfi.api_key');
            $veryfiClient = new Client($client_id, $client_secret, $username, $apiKey);
            $categories = ['Advertising & Marketing', 'Automotive', 'Supplies'];
            $file = storage_path('app/public/' . $filePath);
            $returnAssociative = true;
            $deleteAfterProcessing = false;
            $result = json_decode($veryfiClient->process_document($file, $categories, $deleteAfterProcessing), $returnAssociative);
            $orderNumber = $result['invoice_number'];
            file_put_contents(storage_path("app/public/orders/$orderNumber.json"), json_encode($result, JSON_PRETTY_PRINT));
            $order = $this->orderModel->where('order_no', $orderNumber)->first();
            if (is_null($order)) {
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
            $pdfArray = [
                'existing' => [],
                'nonFileExisting' => [],
                'nonNameExisting' => []
            ];

            $subTitles = $this->getInvoiceSubtitle($result['ocr_text']);
            foreach ($subTitles as $subTitleKey => $subTitleValue) {
                $tempFilePath = storage_path('tmp', 'public') . '/' . rand().'.html';
                file_put_contents($tempFilePath, "<h1>$subTitleKey</h1>");
                $result = ConvertApi::convert('pdf', [
                    'File' => $tempFilePath,
                ], 'html'
                );
                $pdfArray['existing'][] = $result->getFile()->getUrl();
                unlink($tempFilePath);
            }

            for ($i = 0; $i < count($lineItems); $i++) {
                $lineItem = $lineItems[$i];
                $lineItemParams = [
                    'order_id' => $order->id,
                    'sku' => $lineItem['sku'],
                    'price' => $lineItem['price'],
                    'quantity' => $lineItem['quantity'],
                    'total' => $lineItem['total'],
                    'description' => $lineItem['description'],
                    'filename' => '',
                ];
                $description = $lineItem['description'];
                // Define the pattern to extract Model, Code, and Brand
                $pattern = '/^(?<model>[A-Z0-9-]+)-(?<code>[A-Z0-9]+) (?<brand>[A-Z]+)/';

                // Use preg_match to find matches based on the pattern
                if (preg_match($pattern, $description, $matches)) {
                    $model = $matches['model'];
                    $code = $matches['code'];
                    $brand = $matches['brand'];
                    $product = $this->productModel
                        ->where('code', $code)
                        ->where('model', 'like', '%' . $model)
                        ->where('brand', 'like', '%' . $brand . '%')
                        ->first();
                    if (!is_null($product)) {
                        $lineItemParams['filename'] = $product->doc_name . '.pdf';
                        $fileFullPath = storage_path('app/public/line-items/' . strtolower($product->brand) . '/' . $lineItemParams['filename']);
                        if (file_exists($fileFullPath)) {
                            $pdfArray['existing'][] = $fileFullPath;
                        } else {
                            $pdfArray['nonFileExisting'][] = ['brand' => $product->brand, 'filename' => $lineItemParams['filename']];
                        }
                    } else {
                        $pdfArray['nonNameExisting'][] = ['code' => $code, 'model' => $model, 'brand' => $brand];
                    }
                }

                $order->lineItems()->create($lineItemParams);
            }

            $order->load('lineItems');
            if (
                count($pdfArray['existing']) > 0 ||
                count($pdfArray['nonFileExisting']) > 0 ||
                count($pdfArray['nonNameExisting']) > 0
            ) {
                if(count($pdfArray['existing']) > 0) {
                    $result = ConvertApi::convert('merge', [ 'Files' => $pdfArray['existing'], ], 'pdf');
                    $result->saveFiles(storage_path('app/public/results/' . $order->brand . '/' . $orderNumber . '.pdf'));
                }
            }
            return [
                'success' => true,
                'data' => [
                    'mergedSheetsPdf' => storage_path('app/public/results/' . $order->brand . '/' . $orderNumber . '.pdf'),
                    'nonFileExisting' => $pdfArray['nonFileExisting'],
                    'nonNameExisting' => $pdfArray['nonNameExisting'],
                ]
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getLine() . ' ' . $e->getMessage()
            ];
        }
    }

    public function getInvoiceSubtitle($text) {
        try {
            // Split the text into lines
            $lines = explode("\n", $text);
            $groupedItems = [];
            $currentTitle = null;

            foreach ($lines as $line) {
                // Trim whitespace from each line
                $line = trim($line);

                // Check if the line matches a title pattern (e.g., alphanumeric with optional hyphens)
                if (preg_match('/^[A-Z0-9-]+$/', $line)) {
                    // Set the current title
                    $currentTitle = $line;
                    // Initialize the group for this title if it doesn't exist
                    if (!isset($groupedItems[$currentTitle])) {
                        $groupedItems[$currentTitle] = [];
                    }
                } elseif ($currentTitle) {
                    // If the line is not a title, add it as a line item under the current title
                    $groupedItems[$currentTitle][] = $line;
                }
            }
            return $groupedItems;
        }catch (\Exception $e){
            echo $e->getLine();
            throw new \Exception($e->getMessage());
        }
    }
}
