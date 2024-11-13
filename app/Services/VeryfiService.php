<?php

namespace App\Services;

use App\Models\Order;
use App\Models\Product;
use ConvertApi\ConvertApi;
use veryfi\Client;

class VeryfiService
{
    public function __construct(
        private Order          $orderModel,
        private Product        $productModel,
        private ChatGPTService $chatGPTService
    )
    {
    }

    public function run(string $filePath)
    {
        try {
//            $lineItemGroupsString = <<<EOD
//                [
//                    {
//                      "groupTitle": "P-1",
//                      "lineItems": [0, 1, 2, 3, 4]
//                    },
//                    {
//                      "groupTitle": "P-2",
//                      "lineItems": [5]
//                    },
//                    {
//                      "groupTitle": "EWH-1",
//                      "lineItems": [6, 7, 8, 9]
//                    },
//                    {
//                      "groupTitle": "TMV-1",
//                      "lineItems": [10]
//                    }
//                  ]
//            EOD;
//            $lineItemGroupsData = json_decode($lineItemGroupsString, true);
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
            $processResult = $veryfiClient->process_document($file, $categories, $deleteAfterProcessing);
//            file_put_contents(storage_path('line-items-3774.json'), $processResult);
//            $processResult = file_get_contents(storage_path('line-items.json'));
            $result = json_decode($processResult, $returnAssociative);
            $orderNumber = $result['invoice_number'];
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
            $lineItems = array_values(array_filter($result['line_items'], fn($item)=>(float) $item['price'] > 0));
//
            $lineItemGroups = $this->getLineItemGroup($result['ocr_text']);

            if(!$lineItemGroups['success']) return $lineItemGroups;

            $lineItemGroupsData = $lineItemGroups['data']['lineItemGroups'];
            $pdfUrls = [];
            foreach ($lineItemGroupsData as $lineItemGroup) {
                $lineItemGroupTitle = $lineItemGroup['groupTitle'];
                $tempFilePath = storage_path('tmp', 'public') . '/' . rand() . '.html';
                file_put_contents($tempFilePath, "<h1>$lineItemGroupTitle</h1>");
                $result = ConvertApi::convert('pdf', [
                    'File' => $tempFilePath,
                ], 'html'
                );
                $pdfUrls[] = $result->getFile()->getUrl();
                unlink($tempFilePath);

                foreach ($lineItemGroup['lineItems'] as $lineItemIndex) {
                    if(isset($lineItems[$lineItemIndex])) continue;
                    $lineItem = $lineItems[$lineItemIndex];
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
                            $fileFullPath = storage_path('app/public/line-items/' . $lineItemParams['filename']);
                            if (file_exists($fileFullPath)) {
                                $pdfUrls[] = $fileFullPath;
                            } else {
                                $text = 'Product info found in database but specification sheet does not found: brand ' . $product->brand . ', filename: ' . $lineItemParams['filename'];
                                $tempFilePath = storage_path('tmp', 'public') . '/' . rand() . '.html';
                                file_put_contents($tempFilePath, "<h1>$text</h1>");
                                $result = ConvertApi::convert('pdf', [
                                    'File' => $tempFilePath,
                                ], 'html'
                                );
                                $pdfUrls[] = $result->getFile()->getUrl();
                            }
                        } else {
                            $text = 'Product info not found in database: code: ' . $code . ' order no ' . $order->order_no . ', filename: ' . $lineItemParams['filename'];
                            $tempFilePath = storage_path('tmp', 'public') . '/' . rand() . '.html';
                            file_put_contents($tempFilePath, "<h1>$text</h1>");
                            $result = ConvertApi::convert('pdf', [
                                'File' => $tempFilePath,
                            ], 'html'
                            );
                            $pdfUrls[] = $result->getFile()->getUrl();
                        }
                    }else{
                        $text = 'Can not extract the product info from this description: '. $lineItem['description'];
                        $tempFilePath = storage_path('tmp', 'public') . '/' . rand() . '.html';
                        file_put_contents($tempFilePath, "<h1>$text</h1>");
                        $result = ConvertApi::convert('pdf', [
                            'File' => $tempFilePath,
                        ], 'html'
                        );
                        $pdfUrls[] = $result->getFile()->getUrl();
                    }

                    $order->lineItems()->create($lineItemParams);
                }

            }

            $order->load('lineItems');

            if (count($pdfUrls) > 0) {

                $result = ConvertApi::convert('merge', ['Files' => $pdfUrls], 'pdf');
                $result->saveFiles(storage_path('app/public/results/' . $order->brand . '/' . $orderNumber . '.pdf'));

                return [
                    'success' => true,
                    'data' => storage_path('app/public/results/' . $order->brand . '/' . $orderNumber . '.pdf')
                ];
            }
            return [
                'success' => false,
                'error' => 'No line items found'
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getLine() . ' ' . $e->getMessage()
            ];
        }
    }
    public function getLineItemGroup(string $ocrText): array
    {
        try {
            $messageText =
                <<<EOD
            please extract the line item group title from below string and group the line items. include only index number of line item into line item group and build JSON data and return json data so I can directly parse in my code.
            each line item group is divided into a line Subtotal -------:
            each line item has quantity and price.
            expected json format:
            {
              "lineItemGroups": [
                {
                  "groupTitle": "TITLE-1",
                  "lineItems": [0, 1, 2]
                }
              ]
            }

            If no line item group does not exist, then the line item group title is `all` and its data is expected json format should be
            {
              "lineItemGroups": [
                {
                  "groupTitle": "all",
                  "lineItems": <array_of_all_indexes>
                }
              ]
            }
            given string:
            $ocrText
            EOD;
            $messages = [
                ['role' => 'user', 'content' => $messageText],
            ];
            $chatResponse = $this->chatGPTService->create($messages, 'gpt-4');
            $jsonContent = $chatResponse['data']->choices[0]->message->content;
            preg_match('/\{[\s\S]*\}/', $jsonContent, $matches);
            // Check if JSON content was found
            if (isset($matches[0])) {
                $jsonDataString = trim($matches[0]);
                // Decode JSON data
                $parsedData = json_decode($jsonDataString, true);

                // Check for JSON parsing errors
                if (json_last_error() === JSON_ERROR_NONE) {
                    // Successfully parsed JSON data
                    return ['success' => true, 'data' => $parsedData];
                } else {
                    return ['success' => false, 'error' => 'Invalid JSON data: ' . json_last_error_msg()];
                }
            } else {
                return ['success' => false, 'error' => 'No JSON data found in the input string.'];
            }

        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }
}
