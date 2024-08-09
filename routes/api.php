<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

use App\Http\Controllers\UploadController;

Route::post('upload', [UploadController::class, 'upload']);

// Add this to routes/api.php
Route::get('files', [UploadController::class, 'getFiles']);

Route::post('combine-chunks', [UploadController::class, 'combineChunks']);


Route::get('test', function () {
    return response()->json(['message' => 'API is working']);
});



/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "api" middleware group. Make something great!
|
*/

Route::middleware('auth:sanctum')->get('/user', function (Request $request) {
    return $request->user();
});

