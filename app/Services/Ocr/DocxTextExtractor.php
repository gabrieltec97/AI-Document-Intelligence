<?php

namespace App\Services\Ocr;

use DOMDocument;
use DOMXPath;
use RuntimeException;
use ZipArchive;

class DocxTextExtractor
{
    private const MAX_XML_SIZE = 50 * 1024 * 1024;

    public function extract(string $filePath): string
    {
        if (!is_file($filePath) || !is_readable($filePath)) {
            throw new RuntimeException(
                'Arquivo DOCX não encontrado ou sem permissão de leitura.'
            );
        }

        $zip = new ZipArchive();

        $opened = $zip->open($filePath);

        if ($opened !== true) {
            throw new RuntimeException(
                'Não foi possível abrir o arquivo DOCX. Verifique se ele é válido.'
            );
        }

        try {
            $entry = $zip->statName('word/document.xml');

            if ($entry === false) {
                throw new RuntimeException(
                    'O arquivo DOCX não contém o documento principal.'
                );
            }

            if ($entry['size'] > self::MAX_XML_SIZE) {
                throw new RuntimeException(
                    'O conteúdo XML do DOCX excede o limite permitido.'
                );
            }

            $xml = $zip->getFromName('word/document.xml');

            if ($xml === false) {
                throw new RuntimeException(
                    'Não foi possível ler o XML do documento DOCX.'
                );
            }

            return $this->extractFromXml($xml);
        } finally {
            $zip->close();
        }
    }

    private function extractFromXml(string $xml): string
    {
        // Não permitir declarações DTD ou entidades externas.
        if (preg_match('/<!DOCTYPE|<!ENTITY/i', $xml)) {
            throw new RuntimeException(
                'O documento DOCX contém declarações XML não permitidas.'
            );
        }

        $previousErrorMode = libxml_use_internal_errors(true);

        try {
            $dom = new DOMDocument();

            $loaded = $dom->loadXML(
                $xml,
                LIBXML_NONET | LIBXML_NOBLANKS
            );

            if (!$loaded) {
                throw new RuntimeException(
                    'O XML interno do documento DOCX é inválido.'
                );
            }

            $xpath = new DOMXPath($dom);

            $xpath->registerNamespace(
                'w',
                'http://schemas.openxmlformats.org/wordprocessingml/2006/main'
            );

            $paragraphs = $xpath->query('//w:p');

            if ($paragraphs === false) {
                throw new RuntimeException(
                    'Não foi possível localizar os parágrafos do DOCX.'
                );
            }

            $extractedParagraphs = [];

            foreach ($paragraphs as $paragraph) {
                $nodes = $xpath->query(
                    './/w:t | .//w:tab | .//w:br | .//w:cr',
                    $paragraph
                );

                if ($nodes === false) {
                    continue;
                }

                $text = '';

                foreach ($nodes as $node) {
                    switch ($node->localName) {
                        case 't':
                            $text .= $node->textContent;
                            break;

                        case 'tab':
                            $text .= "\t";
                            break;

                        case 'br':
                        case 'cr':
                            $text .= "\n";
                            break;
                    }
                }

                $text = trim($text);

                if ($text !== '') {
                    $extractedParagraphs[] = $text;
                }
            }

            return trim(implode("\n", $extractedParagraphs));
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previousErrorMode);
        }
    }
}
