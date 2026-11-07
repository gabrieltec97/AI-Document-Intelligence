<?php

namespace Tests\Feature;

use App\Models\Document;
use App\Models\DocumentChunk;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DocumentChunkTest extends TestCase
{
    use RefreshDatabase;

    private function createDocument(): Document
    {
        $user = User::factory()->create();

        return Document::create([
            'user_id' => $user->id,
            'original_name' => 'teste.pdf',
            'file_path' => 'documents/teste.pdf',
            'mime_type' => 'application/pdf',
            'file_size' => 100,
            'status' => 'processed',
        ]);
    }

    public function test_document_can_retrieve_its_chunks(): void
    {
        $document = $this->createDocument();

        DocumentChunk::create([
            'document_id' => $document->id,
            'chunk_index' => 0,
            'content' => 'Primeiro bloco.',
            'character_count' => 15,
        ]);

        DocumentChunk::create([
            'document_id' => $document->id,
            'chunk_index' => 1,
            'content' => 'Segundo bloco.',
            'character_count' => 14,
        ]);

        $this->assertCount(2, $document->chunks);

        $this->assertSame(
            'Primeiro bloco.',
            $document->chunks[0]->content
        );

        $this->assertSame(
            'Segundo bloco.',
            $document->chunks[1]->content
        );
    }

    public function test_chunks_are_retrieved_in_correct_order(): void
    {
        $document = $this->createDocument();

        DocumentChunk::create([
            'document_id' => $document->id,
            'chunk_index' => 2,
            'content' => 'Terceiro bloco.',
            'character_count' => 15,
        ]);

        DocumentChunk::create([
            'document_id' => $document->id,
            'chunk_index' => 0,
            'content' => 'Primeiro bloco.',
            'character_count' => 15,
        ]);

        DocumentChunk::create([
            'document_id' => $document->id,
            'chunk_index' => 1,
            'content' => 'Segundo bloco.',
            'character_count' => 14,
        ]);

        $chunks = $document->chunks;

        $this->assertSame(
            [0, 1, 2],
            $chunks->pluck('chunk_index')->all()
        );
    }

    public function test_chunk_belongs_to_document(): void
    {
        $document = $this->createDocument();

        $chunk = DocumentChunk::create([
            'document_id' => $document->id,
            'chunk_index' => 0,
            'content' => 'Conteúdo de teste.',
            'character_count' => 18,
        ]);

        $this->assertTrue(
            $chunk->document->is($document)
        );
    }

    public function test_document_deletion_cascades_to_chunks(): void
    {
        $document = $this->createDocument();

        DocumentChunk::create([
            'document_id' => $document->id,
            'chunk_index' => 0,
            'content' => 'Primeiro bloco.',
            'character_count' => 15,
        ]);

        DocumentChunk::create([
            'document_id' => $document->id,
            'chunk_index' => 1,
            'content' => 'Segundo bloco.',
            'character_count' => 14,
        ]);

        $this->assertDatabaseCount('document_chunks', 2);

        $document->delete();

        $this->assertDatabaseMissing('documents', [
            'id' => $document->id,
        ]);

        $this->assertDatabaseCount('document_chunks', 0);
    }
}
