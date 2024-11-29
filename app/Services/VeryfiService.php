<?php

namespace App\Services;

use App\Models\Order;
use App\Models\Product;
use ConvertApi\ConvertApi;
use veryfi\Client;

class VeryfiService
{
    public function __construct(
        private readonly Order          $orderModel,
        private readonly Product        $productModel,
        private readonly ChatGPTService $chatGPTService
    )
    {
    }

    public function run(string $orderFilePath)
    {
        try {
            /**************************/
//             Initiated ConvertAPI
            ConvertApi::setApiCredentials(config('services.convert_api.secret'));

            $client_id = config('services.veryfi.client_id');
            $client_secret = config('services.veryfi.client_secret');
            $username = config('services.veryfi.username');
            $apiKey = config('services.veryfi.api_key');
            // Initiated Veryfi client
            $veryfiClient = new Client($client_id, $client_secret, $username, $apiKey);

            $categories = ['Advertising & Marketing', 'Automotive', 'Supplies'];
            $orderFile = storage_path('app/public/' . $orderFilePath);

            $returnAssociative = true;
            $deleteAfterProcessing = false;
            $processResult = $veryfiClient->process_document($orderFile, $categories, $deleteAfterProcessing);
            /**************************/
//            file_put_contents(storage_path('app/public/order.json'), $processResult);
            /**************************/

            $result = json_decode($processResult, $returnAssociative);
            /**************************/

//            $result = json_decode(file_get_contents(storage_path('app/public/order.json')), true);
            $orderNumber = $result['invoice_number'];
            $order = $this->orderModel->where('order_no', $orderNumber)->first();
            if (is_null($order)) {
                $orderParams = [
                    'order_no' => $orderNumber,
                    'total' => $result['total'],
                    'subtotal' => $result['subtotal'],
                    'filename' => basename($orderFilePath),
                ];
                $order = $this->orderModel->create($orderParams);
            }
            $order->lineItems()->delete();

            $lineItems = array_values(array_filter($result['line_items'], fn($item) => (float)$item['price'] > 0));
            $lineItemGroups = $this->getLineItemGroup($order, $result['ocr_text']);
            if (!$lineItemGroups['success']) return $lineItemGroups;
            $lineItemGroupsData = $lineItemGroups['data']['lineItemGroups'];
            $pdfUrls = [];
            $sections = [];
            foreach ($lineItemGroupsData as $lineItemGroup) {
                $section = [];
                $section['id'] = rand();
                $section['title'] = $lineItemGroup['groupTitle'];
                $section['pageNum'] = 1;
                $lineItemGroupTitle = $lineItemGroup['groupTitle'];

                $tempFilePath = storage_path('tmp', 'public') . '/' . rand() . '.html';
                file_put_contents($tempFilePath, "<h1 style='font-family: \"Courier New\"'>$lineItemGroupTitle</h1>");
                $result = ConvertApi::convert('pdf', [
                    'File' => $tempFilePath,
                ], 'html'
                );
                $pdfUrls[] = $result->getFile()->getUrl();
                unlink($tempFilePath);
                foreach ($lineItemGroup['lineItems'] as $lineItemIndex) {
                    if (!isset($lineItems[$lineItemIndex])) continue;
                    $lineItem = $lineItems[$lineItemIndex];
                    $children = ['id' => $lineItem['sku'], 'title' => $lineItem['description'], 'pageNum' => 1];
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
                                $children['pageLength'] = $this->getPDFPages($fileFullPath);
                            } else {
                                $text = 'Product info found in database but specification sheet does not found: brand ' . $product->brand . ', filename: ' . $lineItemParams['filename'];
                                $tempFilePath = storage_path('tmp', 'public') . '/' . rand() . '.html';
                                file_put_contents($tempFilePath, "<h1 style='font-family: \"Courier New\"'>$text</h1>");
                                $result = ConvertApi::convert('pdf', [
                                    'File' => $tempFilePath,
                                ], 'html'
                                );
                                $pdfUrls[] = $result->getFile()->getUrl();
                                $children['pageLength'] = 1;
                            }
                        } else {
                            $text = 'Product info not found in database: code: ' . $code . ' order no: ' . $order->order_no;
                            $tempFilePath = storage_path('tmp', 'public') . '/' . rand() . '.html';
                            file_put_contents($tempFilePath, "<h1 style='font-family: \"Courier New\"'>$text</h1>");
                            $result = ConvertApi::convert('pdf', [
                                'File' => $tempFilePath,
                            ], 'html'
                            );
                            $pdfUrls[] = $result->getFile()->getUrl();
                            $children['pageLength'] = 1;
                        }
                    } else {
                        $text = 'Can not extract the product info from this description: ' . $lineItem['description'];
                        $tempFilePath = storage_path('tmp', 'public') . '/' . rand() . '.html';
                        file_put_contents($tempFilePath, "<h1 style='font-family: \"Courier New\"'>$text</h1>");
                        $result = ConvertApi::convert('pdf', [
                            'File' => $tempFilePath,
                        ], 'html'
                        );
                        $pdfUrls[] = $result->getFile()->getUrl();
                        $children['pageLength'] = 1;
                    }

                    $order->lineItems()->create($lineItemParams);
                    $section['children'][] = $children;
                }
                $sections[] = $section;
            }
            $totalPage = 1;

            foreach ($sections as &$section) {
                $totalPage += 1;
                $section['pageNum'] = $totalPage;

                foreach ($section['children'] as &$sectionChild) { // Use reference to modify original array
                    $sectionChild['pageNum'] = $totalPage + 1; // Set pageNum to the current totalPage
                    $totalPage += $sectionChild['pageLength'];
                }
            }

            unset($section);
            unset($sectionChild);
            $tableOfContentsHtml = storage_path('tmp', 'public') . '/table-of-contents-' . $orderNumber . '-' . rand() . '.html';

            file_put_contents($tableOfContentsHtml, $this->renderBladeTemplate('table-of-contents', ['sections' => $sections]));
            $result = ConvertApi::convert('pdf',
                [
                    'File' => $tableOfContentsHtml,
                ], 'html'
            );
            unlink($tableOfContentsHtml);
            $tableOfContentsPdf = storage_path('tmp', 'public') . '/table-of-contents-' . $orderNumber . '-' . rand() . '.pdf';
            $result->getFile()->save($tableOfContentsPdf);
            $order->load('lineItems');

            if (count($pdfUrls) > 0) {
                array_unshift($pdfUrls, $tableOfContentsPdf);
                $result = ConvertApi::convert('merge', ['Files' => $pdfUrls], 'pdf');
                $result->saveFiles(storage_path('app/public/results/' . $order->brand . '/' . $orderNumber. '-'. rand() . '.pdf'));

                return [
                    'success' => true,
                    'data' => storage_path('app/public/results/' . $order->brand . '/' . $orderNumber. '-'. rand() . '.pdf')
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

    public function getLineItemGroup(Order $order, string $ocrText): array
    {
        try {
            $lineItemGroupInstance = $order->lineItemGroups();
            if ($lineItemGroupInstance->count() > 0) {
                return [
                    'success' => true,
                    'data' => [
                        'lineItemGroups' => $lineItemGroupInstance
                            ->get()
                            ->map(fn($lineItemGroup) => ['groupTitle' => $lineItemGroup->title, 'lineItems' => $lineItemGroup->line_item_indexes])
                            ->toArray(),
                    ]
                ];
            }
            $messageText =
                <<<EOD
            please extract the line item group title from below string and group the line items. include only index number of line item into line item group and build JSON data and return json data so I can directly parse in my code.
            each line item group is divided into a line Subtotal -------:
            Include the line item only which has price.
            expected json format:
            {
              "lineItemGroups": [
                {
                  "groupTitle": "TITLE-1",
                  "lineItems": [0, 1, 2],
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
                    $lineItemGroupValues = array_map(
                        fn($lineItemGroup) =>
                            [
                                'order_id' => $order->id,
                                'title' => $lineItemGroup['groupTitle'],
                                'line_item_indexes' => json_encode($lineItemGroup['lineItems']
                        )
                    ], $parsedData['lineItemGroups']);
                    $lineItemGroupInstance->insert($lineItemGroupValues);
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
                'error' => $e->getLine() . ':' . $e->getMessage()
            ];
        }
    }

    /**
     * @param array $data
     * @return string
     */
    public function renderBladeTemplate(string $viewName, array $data = []): string
    {
        return view($viewName, $data)->render();
    }


    // Make a function for convenience
    function getPDFPages($document)
    {
        $cmd = config('services.pdfinfo.executable_path');  // Windows

        // Parse entire output
        // Surround with double quotes if file name has spaces
        exec("$cmd \"$document\"", $output);

        // Iterate through lines
        $pagecount = 0;
        foreach ($output as $op) {
            // Extract the number
            if (preg_match("/Pages:\s*(\d+)/i", $op, $matches) === 1) {
                $pagecount = intval($matches[1]);
                break;
            }
        }

        return $pagecount;
    }
}

