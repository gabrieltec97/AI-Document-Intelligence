<?php

namespace App\Http\Controllers;

use App\Models\Document;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use App\Jobs\ProcessDocument;

class DocumentController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'document' => [
                'required',
                'file',
                'mimes:pdf,docx,png,jpg,jpeg',
                'max:20480',
            ],
        ]);

        $file = $validated['document'];

        $path = $file->store('documents');

        $document = Document::create([
            'user_id' => $request->user()->id,
            'original_name' => $file->getClientOriginalName(),
            'file_path' => $path,
            'mime_type' => $file->getMimeType(),
            'file_size' => $file->getSize(),
            'status' => 'uploaded',
        ]);

        ProcessDocument::dispatch($document->id);

        return response()->json([
            'message' => 'Documento enviado com sucesso.',
            'document' => $document,
        ], 201);
    }
}
