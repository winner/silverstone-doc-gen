<?php

use App\Http\Controllers\Api\v1\PDFController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

Route::post('/v1/pdf', [PDFController::class, 'process']);
//Route::post('/v1/pdf', [PDFController::class, 'upload']);
Route::post('/v1/pdf/callback', [PDFController::class, 'callback']);
