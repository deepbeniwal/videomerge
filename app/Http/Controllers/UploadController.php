<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use App\Models\File;
use Illuminate\Support\Facades\Log;
use FFMpeg\FFMpeg;
use FFMpeg\Format\Video\X264;

class UploadController extends Controller
{
    public function upload(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'file' => 'required|file|mimes:mp4,mov,avi,flv|max:20480', // Adjust the max size per chunk
            'chunk_number' => 'required|integer',
            'total_chunks' => 'required|integer',
            'file_identifier' => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json(['error' => $validator->errors()], 400);
        }

        if ($request->hasFile('file')) {
            $file = $request->file('file');
            $chunkPath = $file->storeAs('chunks/' . $request->file_identifier, 'chunk_' . $request->chunk_number, 'public');

            // Store chunk details in the database
            $fileDetails = File::create([
                'name' => $file->getClientOriginalName(),
                'path' => $chunkPath,
                'type' => $file->getClientMimeType(),
                'size' => $file->getSize(),
                'chunk_number' => $request->chunk_number,
                'total_chunks' => $request->total_chunks,
                'file_identifier' => $request->file_identifier,
            ]);


            return response()->json(['message' => 'Chunk uploaded successfully', 'file' => $fileDetails], 200);        }

        return response()->json(['error' => 'No file uploaded'], 400);
    }

    public function getFiles()
{
    $files = File::all();
    return response()->json(['files' => $files], 200);
}

public function combineChunksold(Request $request)
{
    $fileIdentifier = $request->input('file_identifier');
    
    // Retrieve chunks from the database, ordered by chunk number
    $chunks = File::where('file_identifier', $fileIdentifier)
                  ->orderBy('chunk_number')
                  ->get();

    if ($chunks->count() !== $chunks->first()->total_chunks) {
        return response()->json(['error' => 'Not all chunks are uploaded'], 400);
    }

    // Determine the final path for the combined file
    $finalPath = 'uploads/' . $fileIdentifier . '/' . $chunks->first()->name;
    Storage::disk('public')->makeDirectory('uploads/' . $fileIdentifier);

    // Open the final file in append mode
    $finalFileHandle = fopen(Storage::disk('public')->path($finalPath), 'ab');

    foreach ($chunks as $chunk) {
        Log::info('Processing chunk ' . $chunk->chunk_number);

        $chunkPath = Storage::disk('public')->path($chunk->path);
        
        // Ensure the chunk exists
        if (!file_exists($chunkPath)) {
            Log::error('Chunk not found: ' . $chunkPath);
            continue; // Skip missing chunk
        }

        // Open the chunk in binary read mode
        $chunkHandle = fopen($chunkPath, 'rb');

        if ($chunkHandle) {
            // Write the chunk to the final file
            while ($chunkData = fread($chunkHandle, 8192)) {
                fwrite($finalFileHandle, $chunkData);
            }
            fclose($chunkHandle);
        } else {
            Log::error('Failed to open chunk ' . $chunk->chunk_number);
            fclose($finalFileHandle);
            return response()->json(['error' => 'Failed to open chunk ' . $chunk->chunk_number], 500);
        }
    }

    fclose($finalFileHandle);

    // Optionally, clean up chunk files after combining
    Storage::disk('public')->deleteDirectory('chunks/' . $fileIdentifier);
    File::where('file_identifier', $fileIdentifier)->delete();

    Log::info('File combined successfully: ' . $finalPath);
    return response()->json(['message' => 'File combined successfully', 'path' => $finalPath], 200);
}



public function combineChunks(Request $request)
{
    $fileIdentifier = $request->input('file_identifier');

    // Retrieve chunks from the database, ordered by chunk number
    $chunks = File::where('file_identifier', $fileIdentifier)
                  ->orderBy('chunk_number')
                  ->get();

    if ($chunks->count() !== $chunks->first()->total_chunks) {
        return response()->json(['error' => 'Not all chunks are uploaded'], 400);
    }

    // Initialize FFMpeg
    $ffmpeg = FFMpeg::create();
    $videoPaths = [];

    foreach ($chunks as $chunk) {
        $chunkPath = Storage::disk('public')->path($chunk->path);
        $videoPaths[] = $chunkPath;
    }

    // Temporary file for concatenation
    $tempFilePath = sys_get_temp_dir() . '/temp_concat.mp4';

    // Final output file path
    $finalPath = Storage::disk('public')->path('uploads/' . $fileIdentifier . '/final_video.mp4');

    try {
        $video = $ffmpeg->open($videoPaths[0]); // Open the first video to start
        $video->concat($videoPaths)
              ->saveFromSameCodecs($tempFilePath, true);
        
        if (!file_exists($tempFilePath)) {
            Log::error('Temporary file not created: ' . $tempFilePath);
            return response()->json(['error' => 'Failed to create temporary file'], 500);
        }

        // Ensure the destination directory exists
        $finalDirectory = dirname($finalPath);
        if (!file_exists($finalDirectory)) {
            mkdir($finalDirectory, 0755, true); // Create the directory if it doesn't exist
        }

        // Move the temporary file to the final location
        rename($tempFilePath, $finalPath);

        // Clean up chunk files after combining
        Storage::disk('public')->deleteDirectory('chunks/' . $fileIdentifier);
        File::where('file_identifier', $fileIdentifier)->delete();

        return response()->json(['message' => 'File combined successfully', 'path' => $finalPath], 200);
    } catch (\Exception $e) {
        Log::error('Error during concatenation: ' . $e->getMessage());
        Log::error('Trace: ' . $e->getTraceAsString());
        return response()->json(['error' => 'Failed to combine video chunks: ' . $e->getMessage()], 500);
    }
}


}
