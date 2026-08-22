<?php

namespace App\Services;

use RuntimeException;

class CsvDataService
{
    /** @return list<string> */
    public function headers(string $resource): array
    {
        return match ($resource) {
            'clients' => ['code', 'name', 'email', 'phone', 'address', 'status'],
            'projects' => ['code', 'name', 'description', 'client_code', 'manager_email', 'status_code', 'priority', 'start_date', 'end_date'],
            'tasks' => ['project_code', 'code', 'title', 'description', 'status_code', 'priority', 'assignee_email', 'assigned_at', 'start_at', 'due_at', 'estimated_minutes', 'notes'],
            default => throw new RuntimeException('نوع بيانات CSV غير مدعوم.'),
        };
    }

    /**
     * @return array{
     *   rows: list<array<string, string>>,
     *   errors: list<array{row_number: int|null, field: string|null, code: string, message: string}>,
     *   encoding: string,
     *   transformations: array{trimmed_cells: int, blank_rows: int, bom_removed: bool, encoding_converted: bool}
     * }
     */
    public function parse(string $path, string $resource): array
    {
        $contents = file_get_contents($path);

        if ($contents === false) {
            throw new RuntimeException('تعذر قراءة ملف CSV.');
        }

        $maxBytes = (int) config('project-desk.data_center.csv_max_kilobytes', 10 * 1024) * 1024;

        if (strlen($contents) > $maxBytes) {
            throw new RuntimeException('يتجاوز ملف CSV الحجم المسموح.');
        }

        $bomRemoved = str_starts_with($contents, "\xEF\xBB\xBF");

        if ($bomRemoved) {
            $contents = substr($contents, 3);
        }

        $supportedEncodings = array_map('strtoupper', mb_list_encodings());
        $candidateEncodings = array_values(array_filter(
            ['UTF-8', 'Windows-1256', 'Windows-1252', 'ISO-8859-1'],
            fn (string $encoding): bool => in_array(strtoupper($encoding), $supportedEncodings, true),
        ));
        $encoding = mb_detect_encoding($contents, $candidateEncodings, true);

        if ($encoding === false) {
            throw new RuntimeException('ترميز الملف غير معروف؛ استخدم UTF-8.');
        }

        $encodingConverted = $encoding !== 'UTF-8';

        if ($encodingConverted) {
            $contents = mb_convert_encoding($contents, 'UTF-8', $encoding);
        }

        if (is_string($contents) === false) {
            throw new RuntimeException('تعذر تحويل ترميز ملف CSV إلى UTF-8.');
        }

        $stream = fopen('php://temp', 'w+b');

        if ($stream === false) {
            throw new RuntimeException('تعذر تجهيز الملف للمعاينة.');
        }

        fwrite($stream, $contents);

        rewind($stream);

        $expected = $this->headers($resource);
        $headerRow = fgetcsv($stream, separator: ',', enclosure: '"', escape: '');
        $errors = [];
        $rows = [];
        $trimmedCells = 0;
        $blankRows = 0;

        if ($headerRow === false) {
            fclose($stream);

            return $this->result([], [[
                'row_number' => 1,
                'field' => null,
                'code' => 'missing_header',
                'message' => 'ملف CSV فارغ أو لا يحتوي صف عناوين.',
            ]], $encoding, $trimmedCells, $blankRows, $bomRemoved, $encodingConverted);
        }

        $headers = array_map(fn ($value): string => mb_strtolower(trim((string) $value)), $headerRow);
        $duplicates = array_keys(array_filter(array_count_values($headers), fn (int $count): bool => $count > 1));

        foreach ($duplicates as $duplicate) {
            $errors[] = $this->error(1, $duplicate, 'duplicate_header', "عنوان العمود {$duplicate} مكرر.");
        }

        foreach (array_diff($expected, $headers) as $missing) {
            $errors[] = $this->error(1, $missing, 'missing_header', "العمود المطلوب {$missing} غير موجود.");
        }

        foreach (array_diff($headers, $expected) as $unknown) {
            $errors[] = $this->error(1, $unknown, 'unknown_header', "العمود {$unknown} غير معروف في القالب الثابت.");
        }

        if ($errors !== []) {
            fclose($stream);

            return $this->result([], $errors, $encoding, $trimmedCells, $blankRows, $bomRemoved, $encodingConverted);
        }

        $maxRows = (int) config('project-desk.data_center.csv_max_rows', 5000);
        $line = 1;

        while (($csvRow = fgetcsv($stream, separator: ',', enclosure: '"', escape: '')) !== false) {
            $line += 1;

            if (count($rows) >= $maxRows) {
                $errors[] = $this->error($line, null, 'row_limit', "يتجاوز الملف الحد الأقصى البالغ {$maxRows} صفاً.");

                break;
            }

            $values = array_map(function ($value) use (&$trimmedCells): string {
                $raw = str_replace("\0", '', (string) $value);
                $trimmed = trim($raw);

                if ($raw !== $trimmed) {
                    $trimmedCells += 1;
                }

                return $trimmed;
            }, $csvRow);

            if (count(array_filter($values, fn (string $value): bool => $value !== '')) === 0) {
                $blankRows += 1;

                continue;
            }

            if (count($values) !== count($headers)) {
                $errors[] = $this->error($line, null, 'column_count', 'عدد خلايا الصف لا يطابق عدد عناوين القالب.');

                continue;
            }

            /** @var array<string, string> $mapped */
            $mapped = array_combine($headers, $values);

            foreach ($mapped as $field => $value) {
                if ($value !== '' && $this->isFormulaRisk($field, $value)) {
                    $errors[] = $this->error($line, $field, 'spreadsheet_formula', 'القيمة تبدأ برمز قد يُفسر كصيغة جدول بيانات.');
                }
            }

            $mapped['_row_number'] = (string) $line;
            $rows[] = $mapped;
        }

        fclose($stream);

        return $this->result($rows, $errors, $encoding, $trimmedCells, $blankRows, $bomRemoved, $encodingConverted);
    }

    /**
     * @param  list<array<string, string>>  $rows
     * @param  list<array{row_number: int|null, field: string|null, code: string, message: string}>  $errors
     * @return array{
     *   rows: list<array<string, string>>,
     *   errors: list<array{row_number: int|null, field: string|null, code: string, message: string}>,
     *   encoding: string,
     *   transformations: array{trimmed_cells: int, blank_rows: int, bom_removed: bool, encoding_converted: bool}
     * }
     */
    private function result(
        array $rows,
        array $errors,
        string $encoding,
        int $trimmedCells,
        int $blankRows,
        bool $bomRemoved,
        bool $encodingConverted,
    ): array {
        return [
            'rows' => $rows,
            'errors' => $errors,
            'encoding' => $encoding,
            'transformations' => [
                'trimmed_cells' => $trimmedCells,
                'blank_rows' => $blankRows,
                'bom_removed' => $bomRemoved,
                'encoding_converted' => $encodingConverted,
            ],
        ];
    }

    /** @return array{row_number: int|null, field: string|null, code: string, message: string} */
    private function error(?int $row, ?string $field, string $code, string $message): array
    {
        return ['row_number' => $row, 'field' => $field, 'code' => $code, 'message' => $message];
    }

    private function isFormulaRisk(string $field, string $value): bool
    {
        if ($field === 'phone' && preg_match('/^\+[0-9 ()-]+$/', $value) === 1) {
            return false;
        }

        return preg_match('/^[=+\-@\t\r]/u', $value) === 1;
    }
}
