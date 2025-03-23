<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class ImageController extends Controller
{
    public function upload(Request $request)
    {
        try {
            $request->validate([
                'image' => 'required|image|mimes:jpeg,png,jpg,gif|max:3072' // Max 3MB
            ]);

            if ($request->hasFile('image')) {
                $file = $request->file('image');
                
                // Cloudinary configuration
                $cloudName = "dugeyusti";
                $presetName = "expert_upload";
                $folderName = "BitStorm";
                
                // Create multipart form data
                $response = Http::attach(
                    'file', 
                    file_get_contents($file->path()), 
                    $file->getClientOriginalName()
                )->post("https://api.cloudinary.com/v1_1/{$cloudName}/image/upload", [
                    'upload_preset' => $presetName,
                    'folder' => $folderName,
                ]);
                
                if ($response->successful()) {
                    $responseData = $response->json();
                    
                    return response()->json([
                        'url' => $responseData['secure_url']
                    ]);
                } else {
                    return response()->json([
                        'error' => 'Lỗi khi tải lên Cloudinary',
                        'message' => $response->body()
                    ], 500);
                }
            }

            return response()->json([
                'error' => 'Không tìm thấy file'
            ], 400);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Lỗi khi tải lên hình ảnh',
                'message' => $e->getMessage()
            ], 500);
        }
    }
}