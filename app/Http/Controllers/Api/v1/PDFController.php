<?php

namespace App\Http\Controllers\Api\v1;

use App\Services\PdfService;
use App\Services\VeryfiService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PDFController
{
    public function __construct(
        private PDFService $pdfService,
        private VeryfiService $veryfiService,
    )
    {
    }

    public function upload(Request $request): JsonResponse
    {
        try {
            if ($request->file('file')) {
                $filePath = $request->file('file')->store('uploads', 'public');
                $getSignedUrlResult = $this->pdfService->getUploadPreSignedUrl('application/pdf');
                if ($getSignedUrlResult['success']) {
                    $uploadUri = $getSignedUrlResult['data']['uploadUri'];
                    $assetID = $getSignedUrlResult['data']['assetID'];
                    $uploadResult = $this->pdfService->uploadPDF($uploadUri, $filePath);
                    if ($uploadResult['success']) {
                        $extractResult = $this->pdfService->ocrPDF($assetID);
                        if ($extractResult['success']) {
                            return response()->json($extractResult['data']);
                        }
                        return response()->json($extractResult['error'], 400);
                    }
                    return response()->json($uploadResult['error'], 400);
                }
                // Optionally, store the file path in the database or process further
                return response()->json($getSignedUrlResult['error'], 400);
            }
            return response()->json(['error' => 'File upload failed'], 400);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 400);
        }
    }

    public function callback(Request $request): void
    {
        file_put_contents(storage_path('response.txt'), json_encode($request->all()));
    }

    public function process(Request $request)
    {
        try {
            if ($request->file('file')) {
                $filePath = $request->file('file')->storeAs('orders', $request->file('file')->getClientOriginalName(), 'public');
                $result = $this->veryfiService->run($filePath);
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
