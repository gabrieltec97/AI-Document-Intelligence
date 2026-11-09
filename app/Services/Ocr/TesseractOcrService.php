<?php

namespace App\Services\Ocr;

use RuntimeException;
use Symfony\Component\Process\Process;

class TesseractOcrService implements OcrService
{
    public function __construct(
        private DocxTextExtractor $docxTextExtractor
    ) {}

    public function extractText(string $filePath): string
    {
        if (! is_file($filePath)) {
            throw new RuntimeException(
                "Arquivo não encontrado: {$filePath}"
            );
        }

        $mimeType = mime_content_type($filePath);

        return match ($mimeType) {
            'application/pdf' => $this->extractFromPdf($filePath),

            'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => $this->docxTextExtractor->extract($filePath),

            'image/jpeg',
            'image/png' => $this->extractFromImage($filePath),

            default => throw new RuntimeException(
                "Tipo de arquivo não suportado pelo OCR: {$mimeType}"
            ),
        };
    }


    private function extractFromPdf(string $filePath): string
    {
        $infoProcess = new Process([
            'pdfinfo',
            $filePath,
        ]);

        $infoProcess->setTimeout(120);
        $infoProcess->run();

        if (! $infoProcess->isSuccessful()) {
            throw new RuntimeException(
                'Erro ao consultar informações do PDF: '.
                $infoProcess->getErrorOutput()
            );
        }

        if (! preg_match('/^Pages:\s+(\d+)/m', $infoProcess->getOutput(), $matches)) {
            throw new RuntimeException(
                'Não foi possível identificar a quantidade de páginas do PDF.'
            );
        }

        $totalPages = (int) $matches[1];

        $tempDirectory = sys_get_temp_dir().'/ocr_'.uniqid();

        if (! mkdir($tempDirectory, 0755, true) && ! is_dir($tempDirectory)) {
            throw new RuntimeException(
                'Não foi possível criar o diretório temporário do OCR.'
            );
        }

        try {
            $fullText = [];

            for ($page = 1; $page <= $totalPages; $page++) {
                $textProcess = new Process([
                    'pdftotext',
                    '-f',
                    (string) $page,
                    '-l',
                    (string) $page,
                    '-layout',
                    $filePath,
                    '-',
                ]);

                $textProcess->setTimeout(120);
                $textProcess->run();

                if (! $textProcess->isSuccessful()) {
                    throw new RuntimeException(
                        "Erro ao extrair texto da página {$page}: ".
                        $textProcess->getErrorOutput()
                    );
                }

                $pageText = trim(
                    $textProcess->getOutput(),
                    " \n\r\t\v\0\f"
                );

                if ($pageText === '') {
                    $outputPrefix = $tempDirectory.'/page_'.$page;

                    $pdfProcess = new Process([
                        'pdftoppm',
                        '-f',
                        (string) $page,
                        '-l',
                        (string) $page,
                        '-png',
                        '-r',
                        '150',
                        $filePath,
                        $outputPrefix,
                    ]);

                    $pdfProcess->setTimeout(120);
                    $pdfProcess->run();

                    if (! $pdfProcess->isSuccessful()) {
                        throw new RuntimeException(
                            "Erro ao converter a página {$page} para imagem: ".
                            $pdfProcess->getErrorOutput()
                        );
                    }

                    $images = glob($outputPrefix.'-*.png');

                    if (empty($images)) {
                        throw new RuntimeException(
                            "Nenhuma imagem foi gerada para a página {$page}."
                        );
                    }

                    foreach ($images as $image) {
                        $pageText = $this->extractFromImage($image);

                        @unlink($image);
                    }
                }

                if ($pageText !== '') {
                    $fullText[] = $pageText;
                }
            }

            return trim(implode("\n\n", $fullText));
        } finally {
            foreach (glob($tempDirectory.'/*') ?: [] as $file) {
                @unlink($file);
            }

            @rmdir($tempDirectory);
        }
    }

    private function extractFromImage(string $filePath): string
    {
        $outputBase = tempnam(sys_get_temp_dir(), 'ocr_');

        if ($outputBase === false) {
            throw new RuntimeException(
                'Não foi possível criar arquivo temporário para OCR.'
            );
        }

        unlink($outputBase);

        $process = new Process([
            'tesseract',
            $filePath,
            $outputBase,
            '-l',
            'por+eng',
        ]);

        $process->setTimeout(120);
        $process->run();

        if (! $process->isSuccessful()) {
            throw new RuntimeException(
                'Erro ao executar Tesseract: '.
                $process->getErrorOutput()
            );
        }

        $textFile = $outputBase.'.txt';

        if (! is_file($textFile)) {
            throw new RuntimeException(
                'O Tesseract não gerou o arquivo de texto.'
            );
        }

        $text = file_get_contents($textFile);

        @unlink($textFile);

        if ($text === false) {
            throw new RuntimeException(
                'Não foi possível ler o texto extraído pelo Tesseract.'
            );
        }

        return trim($text);
    }
}
