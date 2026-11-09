<?php

namespace Tests\Unit;

use App\Services\Ocr\DocxTextExtractor;
use App\Services\Ocr\TesseractOcrService;
use PHPUnit\Framework\TestCase;

class TesseractOcrServiceTest extends TestCase
{
    public function test_it_extracts_native_text_from_pdf(): void
    {
        $filePath = dirname(__DIR__).'/http/teste.pdf';

        $service = new TesseractOcrService(
            new DocxTextExtractor
        );

        $text = $service->extractText($filePath);

        $this->assertNotEmpty($text);
        $this->assertStringContainsString(
            'GABRIEL DA SILVA PEREIRA',
            $text
        );
        $this->assertStringContainsString(
            'EXPERIÊNCIA PROFISSIONAL',
            $text
        );
        $this->assertStringContainsString(
            'FORMAÇÃO ACADÊMICA',
            $text
        );
    }

    public function test_it_rejects_nonexistent_files(): void
    {
        $service = new TesseractOcrService(
            new DocxTextExtractor
        );

        $this->expectException(\RuntimeException::class);

        $service->extractText('/arquivo/inexistente.pdf');
    }

    public function test_it_extracts_text_from_scanned_pdf_using_ocr(): void
    {
        $filePath = dirname(__DIR__).'/http/teste-escaneado.pdf';

        $service = new TesseractOcrService(
            new DocxTextExtractor
        );

        $text = $service->extractText($filePath);

        $this->assertNotEmpty($text);

        $this->assertStringContainsString(
            'DOCUMENTO ESCANEADO',
            $text
        );

        $this->assertStringContainsString(
            'RECONHECIMENTO OCR',
            $text
        );

        $this->assertStringContainsString(
            'LARAVEL',
            $text
        );
    }
    public function test_it_extracts_text_from_mixed_pdf_using_native_text_and_ocr(): void
    {
        $filePath = dirname(__DIR__).'/http/teste-misto.pdf';

        $service = new TesseractOcrService(
            new DocxTextExtractor
        );

        $text = $service->extractText($filePath);

        // Conteúdo das páginas digitais.
        $this->assertStringContainsString(
            'GABRIEL DA SILVA PEREIRA',
            $text
        );

        // Conteúdo da página escaneada.
        $this->assertStringContainsString(
            'DOCUMENTO ESCANEADO',
            $text
        );

        $this->assertStringContainsString(
            'RECONHECIMENTO OCR',
            $text
        );

        $this->assertStringContainsString(
            'LARAVEL',
            $text
        );
    }
}
