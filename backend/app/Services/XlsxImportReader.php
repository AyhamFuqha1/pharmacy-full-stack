<?php

namespace App\Services;

use RuntimeException;

/** A deliberately small, read-only XLSX reader for import data (no formulas/macros). */
class XlsxImportReader
{
    /** @return array{worksheets: array<int,string>, selected:string, rows:array<int,array<int,mixed>>} */
    public function read(string $path, ?string $requestedSheet = null): array
    {
        $archive = file_get_contents($path);
        if ($archive === false || ! str_starts_with($archive, "PK\x03\x04")) {
            throw new RuntimeException('The uploaded file is not a valid XLSX workbook.');
        }

        $files = $this->unzip($archive);
        if (isset($files['xl/vbaProject.bin'])) {
            throw new RuntimeException('Macro-enabled workbooks are not supported.');
        }
        $workbook = $this->xml($files['xl/workbook.xml'] ?? null, 'Workbook metadata is missing.');
        $relations = $this->xml($files['xl/_rels/workbook.xml.rels'] ?? null, 'Workbook relationships are missing.');
        $relationMap = [];
        foreach ($relations->getElementsByTagName('Relationship') as $relation) {
            $relationMap[$relation->getAttribute('Id')] = $relation->getAttribute('Target');
        }
        $sheets = [];
        foreach ($workbook->getElementsByTagName('sheet') as $sheet) {
            $name = $sheet->getAttribute('name');
            $rid = $sheet->getAttributeNS('http://schemas.openxmlformats.org/officeDocument/2006/relationships', 'id');
            $target = $relationMap[$rid] ?? '';
            if ($name !== '' && $target !== '') {
                $sheets[$name] = 'xl/'.ltrim(preg_replace('#^/?xl/#', '', $target), '/');
            }
        }
        if ($sheets === []) {
            throw new RuntimeException('The workbook has no readable worksheets.');
        }
        $selected = $requestedSheet ?: array_key_first($sheets);
        if (! isset($sheets[$selected])) {
            throw new RuntimeException('The selected worksheet does not exist.');
        }

        $shared = [];
        if (isset($files['xl/sharedStrings.xml'])) {
            foreach ($this->xml($files['xl/sharedStrings.xml'], 'Shared strings are invalid.')->getElementsByTagName('si') as $item) {
                $value = '';
                foreach ($item->getElementsByTagName('t') as $text) {
                    $value .= $text->textContent;
                }
                $shared[] = $value;
            }
        }
        $sheetXml = $this->xml($files[$sheets[$selected]] ?? null, 'The selected worksheet is missing.');
        if ($sheetXml->getElementsByTagName('f')->length > 0) {
            throw new RuntimeException('Formula cells are not supported; upload values-only data.');
        }
        $rows = [];
        foreach ($sheetXml->getElementsByTagName('row') as $rowNode) {
            $row = [];
            foreach ($rowNode->getElementsByTagName('c') as $cell) {
                $reference = $cell->getAttribute('r');
                preg_match('/^[A-Z]+/i', $reference, $match);
                $index = $this->columnIndex($match[0] ?? 'A');
                $type = $cell->getAttribute('t');
                $valueNode = $cell->getElementsByTagName('v')->item(0);
                $value = $valueNode?->textContent ?? '';
                if ($type === 's') {
                    $value = $shared[(int) $value] ?? '';
                } elseif ($type === 'inlineStr') {
                    $value = $cell->getElementsByTagName('t')->item(0)?->textContent ?? '';
                } elseif ($type === 'b') {
                    $value = $value === '1';
                }
                $row[$index] = $value;
            }
            if ($row !== []) {
                $max = max(array_keys($row));
                $rows[] = array_replace(array_fill(0, $max + 1, ''), $row);
            }
        }

        return ['worksheets' => array_keys($sheets), 'selected' => $selected, 'rows' => $rows];
    }

    /** @return array<string,string> */
    private function unzip(string $data): array
    {
        $eocd = strrpos($data, "PK\x05\x06");
        if ($eocd === false) throw new RuntimeException('The XLSX archive is incomplete.');
        $directory = unpack('ventries/Vsize/Voffset', substr($data, $eocd + 10, 10));
        $position = $directory['offset'];
        $files = [];
        for ($i = 0; $i < $directory['entries']; $i++) {
            if (substr($data, $position, 4) !== "PK\x01\x02") throw new RuntimeException('The XLSX directory is invalid.');
            $h = unpack('@10/vmethod/@20/Vcompressed/@24/Vuncompressed/@28/vname/@30/vextra/@32/vcomment/@42/Voffset', substr($data, $position, 46));
            $name = substr($data, $position + 46, $h['name']);
            if ($h['uncompressed'] > 20 * 1024 * 1024) throw new RuntimeException('An XLSX worksheet is too large to import safely.');
            $local = $h['offset'];
            $lh = unpack('@26/vname/vextra', substr($data, $local, 30));
            $start = $local + 30 + $lh['name'] + $lh['extra'];
            $compressed = substr($data, $start, $h['compressed']);
            $contents = match ($h['method']) {
                0 => $compressed,
                8 => gzinflate($compressed),
                default => throw new RuntimeException('The XLSX compression method is unsupported.'),
            };
            if ($contents === false || strlen($contents) !== $h['uncompressed']) throw new RuntimeException('An XLSX entry could not be decompressed.');
            $files[str_replace('\\', '/', $name)] = $contents;
            $position += 46 + $h['name'] + $h['extra'] + $h['comment'];
        }
        return $files;
    }

    private function xml(?string $contents, string $message): \DOMDocument
    {
        if ($contents === null || strlen($contents) > 20 * 1024 * 1024) throw new RuntimeException($message);
        $document = new \DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $loaded = $document->loadXML($contents, LIBXML_NONET | LIBXML_COMPACT);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        if (! $loaded) throw new RuntimeException($message);
        return $document;
    }

    private function columnIndex(string $letters): int
    {
        $number = 0;
        foreach (str_split(strtoupper($letters)) as $letter) $number = $number * 26 + ord($letter) - 64;
        return max(0, $number - 1);
    }
}
