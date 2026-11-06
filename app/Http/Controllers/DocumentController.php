<?php

namespace App\Http\Controllers;

use App\Http\Resources\DocumentDetailResource;
use App\Http\Resources\DocumentResource;
use App\Jobs\ProcessDocument;
use App\Models\Document;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class DocumentController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $documents = $request->user()
            ->documents()
            ->latest()
            ->paginate(15);

        return DocumentResource::collection($documents);
    }

    public function show(Request $request, int $id): DocumentDetailResource
    {
        $document = $request->user()
            ->documents()
            ->findOrFail($id);

        return new DocumentDetailResource($document);
    }

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
            'document' => DocumentResource::make($document)->resolve($request),
        ], 201);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        return DB::transaction(function () use ($request, $id) {
            $document = $request->user()
                ->documents()
                ->lockForUpdate()
                ->findOrFail($id);

            if ($document->status === 'processing') {
                return response()->json([
                    'message' => 'Não é possível excluir um documento em processamento.',
                ], 409);
            }

            $deleted = Storage::delete($document->file_path);

            if (!$deleted) {
                throw new RuntimeException(
                    'Não foi possível excluir o arquivo do documento.'
                );
            }

            $document->delete();

            return response()->json([
                'message' => 'Documento excluído com sucesso.',
            ]);
        });
    }
}
