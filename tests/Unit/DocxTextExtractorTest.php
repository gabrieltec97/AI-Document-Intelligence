<?php

namespace Tests\Unit;

use App\Services\Ocr\DocxTextExtractor;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use ZipArchive;
class DocxTextExtractorTest extends TestCase
{
    private string $tempDirectory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tempDirectory = sys_get_temp_dir()
            . '/docx_test_' . uniqid();

        mkdir($this->tempDirectory, 0755, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->tempDirectory . '/*') as $file) {
            unlink($file);
        }

        rmdir($this->tempDirectory);

        parent::tearDown();
    }

    public function test_it_extracts_text_from_docx(): void
    {
        $path = $this->createDocx(
            '<w:p><w:r><w:t>Documento de teste</w:t></w:r></w:p>'
        );

        $extractor = new DocxTextExtractor();

        $this->assertSame(
            'Documento de teste',
            $extractor->extract($path)
        );
    }

    public function test_it_extracts_text_from_tables(): void
    {
        $table = '
            <w:tbl>
                <w:tr>
                    <w:tc>
                        <w:p><w:r><w:t>Nome</w:t></w:r></w:p>
                    </w:tc>
                    <w:tc>
                        <w:p><w:r><w:t>Gabriel</w:t></w:r></w:p>
                    </w:tc>
                </w:tr>
            </w:tbl>
        ';

        $path = $this->createDocx($table);

        $extractor = new DocxTextExtractor();

        $this->assertSame(
            "Nome\nGabriel",
            $extractor->extract($path)
        );
    }

    public function test_it_rejects_invalid_docx(): void
    {
        $path = $this->tempDirectory . '/invalid.docx';

        file_put_contents($path, 'arquivo inválido');

        $extractor = new DocxTextExtractor();

        $this->expectException(RuntimeException::class);

        $extractor->extract($path);
    }

    public function test_it_returns_empty_string_for_empty_document(): void
    {
        $path = $this->createDocx('');

        $extractor = new DocxTextExtractor();

        $this->assertSame('', $extractor->extract($path));
    }

    private function createDocx(string $body): string
    {
        $path = $this->tempDirectory . '/' . uniqid() . '.docx';

        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
        <w:document
            xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">
            <w:body>' . $body . '</w:body>
        </w:document>';

        $zip = new ZipArchive();

        $zip->open($path, ZipArchive::CREATE);

        $zip->addFromString('word/document.xml', $xml);

        $zip->close();

        return $path;
    }
}
