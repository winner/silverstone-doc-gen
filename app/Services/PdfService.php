<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class PdfService
{
    private string $adobeOrgDomain;
    private string $adobeClientId;
    private string $adobeClientSecret;
    private string $adobeCallbackDomain;
    public function __construct()
    {
        $this->adobeOrgDomain = config('services.adobe.organization_domain');
        $this->adobeClientId = config('services.adobe.client_id');
        $this->adobeClientSecret = config('services.adobe.client_secret');
        $this->adobeCallbackDomain = config('services.adobe.callback_domain');
    }

    public function getAccessToken(): array
    {
        try {
            // Check if a token is already cached and still valid
            if (Cache::has('adobe_access_token')) {
                return [
                    'success' => true,
                    'data' => Cache::get('adobe_access_token')
                ];
            }

            // Define your API endpoint
            $url = $this->adobeOrgDomain . '/token';

            // Make the POST request to fetch a new token
            $response = Http::asForm()->post($url, [
                'client_id' => config('services.adobe.client_id'),
                'client_secret' => config('services.adobe.client_secret'),
            ]);

            // Check if the request was successful
            if ($response->successful()) {

                $data = $response->json();

                // Calculate expiration time in seconds and cache the token and its expiry time
                $expiresIn = $data['expires_in']; // Adobe API gives expiry time in seconds
                Cache::put('adobe_access_token', $data['access_token'], $expiresIn);
                Cache::put('adobe_token_expires_in', $expiresIn, $expiresIn);

                return [
                    'success' => true,
                    'data' => $data['access_token']
                ];
            }
            // Handle error response
            return [
                'success' => false,
                'status' => $response->reason(),
                'error' => $response->body()
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'status' => $e->getMessage(),
                'error' => $e->getCode()
            ];
        }
    }

    /**
     * Create an Asset in Adobe
     */
    public function getUploadPreSignedUrl($mediaType): array
    {
        try {
            $tokenResponse = $this->getAccessToken();
            if (!$tokenResponse['success']) return $tokenResponse;
            $token = $tokenResponse['data'];
            $url = $this->adobeOrgDomain . '/assets';

            $response = Http::withHeaders([
                'X-API-Key' => $this->adobeClientId,
                'Authorization' => 'Bearer ' . $token,
                'Content-Type' => 'application/json'
            ])->post($url, [
                'mediaType' => $mediaType
            ]);

            if ($response->successful()) {

                $result = $response->json();

                return [
                    'success' => true,
                    'data' => [
                        'assetID' => $result['assetID'],
                        'uploadUri' => $result['uploadUri'],
                    ]
                ];
            }
            return [
                'success' => false,
                'error' => $response->reason(),
                'status' => $response->status(),
            ];
        }catch (\Exception $e){
            return [
                'success' => false,
                'error' => $e->getMessage(),
                'status' => $e->getCode(),
            ];
        }
    }

    public function uploadPDF(string $uploadUri, string $pdfPath): array
    {
        try {
            // Retrieve the file contents
            $fileContents = file_get_contents(storage_path('app/public/'.$pdfPath));
            // Send the PUT request with the file contents
            $response = Http::withHeaders([
                'Content-Type' => 'application/pdf', // Adjust content type as needed
            ])->put($uploadUri, $fileContents);
            // Check for a successful upload
            if ($response->successful()) {
                return [
                    'success' => true,
                    'data' => $response->body()
                ];
            }
            return [
                'success' => false,
                'error' => $response->body(),
                'status' => $response->status()
            ];
        }catch (\Exception $e){
            return [
                'success' => false,
                'error' => $e->getMessage(),
                'status' => $e->getCode(),
            ];
        }
    }

    public function extractPDF(string $assetID): array
    {
        try {
            $tokenResponse = $this->getAccessToken();
            if (!$tokenResponse['success']) return $tokenResponse;
            $token = $tokenResponse['data'];
            $url = $this->adobeOrgDomain . '/operation/extractpdf';

            $response = Http::withHeaders([
                'X-API-Key' => $this->adobeClientId,
                'Authorization' => 'Bearer ' . $token,
                'Content-Type' => 'application/json'
            ])->post($url, [
                'assetID' => $assetID,
                'getCharBounds' => true,
                'includeStyling' => false,
                'elementsToExtract' => ['text', 'tables'],
                'tableOutputFormat' => 'xlsx',
                'renditionsToExtract' => ['tables', 'figures'],
                'includeHeaderFooter' => false,
                'tagEncapsulatedText' => 'Figure',
                'notifiers' => [
                    'type' => 'CALLBACK',
                    'data' => [
                        'url' => $this->adobeCallbackDomain . '/api/v1/pdf/callback',
                        'headers' => [
                            'x-api-key' => 'dummykey',
                            'access-token' => 'dummytoken',
                        ]
                    ]
                ]
            ]);
            if ($response->successful()) {

                return [
                    'success' => true,
                    'data' => $response->body()
                ];
            }
            return [
                'success' => false,
                'error' => $response->body(),
                'status' => $response->status(),
            ];
        }catch (\Exception $e){
            return [
                'success' => false,
                'error' => $e->getMessage(),
                'status' => $e->getCode(),
            ];
        }
    }

    public function getStatus(string $jobId): array
    {
        try {
            $tokenResponse = $this->getAccessToken();
            if (!$tokenResponse['success']) return $tokenResponse;
            $token = $tokenResponse['data'];
            $url = $this->adobeOrgDomain . '/operation/extractpdf'. $jobId . '/status';

            $response = Http::withHeaders([
                'X-API-Key' => $this->adobeClientId,
                'Authorization' => 'Bearer ' . $token,
                'Content-Type' => 'application/json'
            ])->get($url);

            if ($response->successful()) {
                $result = $response->json();
                if($result['status'] === 'done') {
                    return [
                        'success' => true,
                        'data' => [
                            'status' => $result['status'],
                            'content' => $result['content'],
                            'resource' => $result['resource'],
                        ]
                    ];
                }
                return [
                    'success' => false,
                    'error' => $result['error'],
                    'status' => $response->getStatusCode(),
                ];
            }
            return [
                'success' => false,
                'error' => $response->reason(),
                'status' => $response->status(),
            ];
        }catch (\Exception $e){
            return [
                'success' => false,
                'error' => $e->getMessage(),
                'status' => $e->getCode(),
            ];
        }
    }
}
