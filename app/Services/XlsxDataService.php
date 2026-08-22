<?php

namespace App\Services;

use PhpOffice\PhpSpreadsheet\Cell\Cell;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use RuntimeException;
use Throwable;
use ZipArchive;

class XlsxDataService
{
    public const TEMPLATE_VERSION = 1;

    public function __construct(private readonly CsvDataService $contracts) {}

    /**
     * @return array{
     *   rows: list<array<string, string>>,
     *   errors: list<array{sheet?: string|null, row_number: int|null, field: string|null, code: string, message: string}>,
     *   encoding: string,
     *   sheet: string,
     *   transformations: array{trimmed_cells: int, blank_rows: int, bom_removed: bool, encoding_converted: bool}
     * }
     */
    public function parse(string $path, string $resource): array
    {
        $this->assertSafePackage($path);
        $reader = IOFactory::createReader('Xlsx');
        $reader->setReadDataOnly(false);
        $worksheetInfo = $reader->listWorksheetInfo($path);

        if (count($worksheetInfo) !== 1) {
            return $this->result([], [[
                'sheet' => null,
                'row_number' => null,
                'field' => null,
                'code' => 'worksheet_count',
                'message' => 'يجب أن يحتوي قالب Excel على ورقة عمل واحدة فقط.',
            ]], 'Workbook', 0, 0);
        }

        $info = $worksheetInfo[0];
        $sheetName = $info['worksheetName'];
        $maxRows = (int) config('project-desk.data_center.csv_max_rows', 5000);
        $totalRows = $info['totalRows'];

        if (max(0, $totalRows - 1) > $maxRows) {
            return $this->result([], [[
                'sheet' => $sheetName,
                'row_number' => $maxRows + 2,
                'field' => null,
                'code' => 'row_limit',
                'message' => "يتجاوز الملف الحد الأقصى البالغ {$maxRows} صفاً.",
            ]], $sheetName, 0, 0);
        }

        $reader->setLoadSheetsOnly($sheetName);
        $spreadsheet = $reader->load($path);

        try {
            $properties = $spreadsheet->getProperties();
            $templateVersion = $properties->getCustomPropertyValue('project_desk_template_version');
            $templateResource = $properties->getCustomPropertyValue('project_desk_resource');
            if ((string) $templateVersion !== (string) self::TEMPLATE_VERSION
                || $templateResource !== $resource) {
                return $this->result([], [[
                    'sheet' => $sheetName,
                    'row_number' => 1,
                    'field' => null,
                    'code' => 'template_version',
                    'message' => 'إصدار قالب Excel أو نوع بياناته غير مدعوم؛ نزّل القالب الحالي من مركز البيانات.',
                ]], $sheetName, 0, 0);
            }

            $sheet = $spreadsheet->getSheet(0);
            $expected = $this->contracts->headers($resource);
            $headerRow = [];
            $highestColumn = max($info['totalColumns'], count($expected));
            for ($column = 1; $column <= $highestColumn; $column++) {
                $headerRow[] = mb_strtolower(trim($this->rawValue($sheet->getCell(Coordinate::stringFromColumnIndex($column).'1'))));
            }
            while ($headerRow !== [] && end($headerRow) === '') {
                array_pop($headerRow);
            }

            $errors = [];
            if ($headerRow === []) {
                $errors[] = $this->error($sheetName, 1, null, 'missing_header', 'ملف Excel فارغ أو لا يحتوي صف عناوين.');

                return $this->result([], $errors, $sheetName, 0, 0);
            }

            $duplicates = array_keys(array_filter(array_count_values($headerRow), fn (int $count): bool => $count > 1));
            foreach ($duplicates as $duplicate) {
                $errors[] = $this->error($sheetName, 1, $duplicate, 'duplicate_header', "عنوان العمود {$duplicate} مكرر.");
            }
            foreach (array_diff($expected, $headerRow) as $missing) {
                $errors[] = $this->error($sheetName, 1, $missing, 'missing_header', "العمود المطلوب {$missing} غير موجود.");
            }
            foreach (array_diff($headerRow, $expected) as $unknown) {
                $errors[] = $this->error($sheetName, 1, $unknown, 'unknown_header', "العمود {$unknown} غير معروف في القالب الثابت.");
            }
            if ($errors !== []) {
                return $this->result([], $errors, $sheetName, 0, 0);
            }

            $rows = [];
            $trimmedCells = 0;
            $blankRows = 0;
            for ($rowNumber = 2; $rowNumber <= $totalRows; $rowNumber++) {
                $values = [];
                foreach ($headerRow as $columnIndex => $field) {
                    $cell = $sheet->getCell(Coordinate::stringFromColumnIndex($columnIndex + 1).$rowNumber);
                    $raw = $this->rawValue($cell);
                    $trimmed = trim(str_replace("\0", '', $raw));
                    if ($raw !== $trimmed) {
                        $trimmedCells++;
                    }
                    if ($cell->getDataType() === DataType::TYPE_FORMULA || ($trimmed !== '' && $this->isFormulaRisk($field, $trimmed))) {
                        $errors[] = $this->error(
                            $sheetName,
                            $rowNumber,
                            $field,
                            'spreadsheet_formula',
                            'الخلية تحتوي صيغة أو تبدأ برمز قد يُفسر كصيغة جدول بيانات.',
                        );
                    }
                    $values[] = $trimmed;
                }

                if (count(array_filter($values, fn (string $value): bool => $value !== '')) === 0) {
                    $blankRows++;

                    continue;
                }

                /** @var array<string, string> $mapped */
                $mapped = array_combine($headerRow, $values);
                $mapped['_row_number'] = (string) $rowNumber;
                $rows[] = $mapped;
            }

            return $this->result($rows, $errors, $sheetName, $trimmedCells, $blankRows);
        } finally {
            $spreadsheet->disconnectWorksheets();
        }
    }

    private function assertSafePackage(string $path): void
    {
        $maxBytes = (int) config('project-desk.data_center.xlsx_max_kilobytes', 10 * 1024) * 1024;
        $size = filesize($path);
        if ($size === false || $size > $maxBytes) {
            throw new RuntimeException('يتجاوز ملف Excel الحجم المسموح.');
        }
        $signature = file_get_contents($path, false, null, 0, 4);
        if ($signature !== "PK\x03\x04") {
            throw new RuntimeException('توقيع ملف Excel غير صالح.');
        }

        $archive = new ZipArchive;
        if ($archive->open($path) !== true) {
            throw new RuntimeException('بنية حزمة Excel غير صالحة.');
        }

        try {
            $totalUncompressed = 0;
            $maxEntries = (int) config('project-desk.data_center.xlsx_max_archive_entries', 2000);
            $maxUncompressedBytes = (int) config('project-desk.data_center.xlsx_max_uncompressed_megabytes', 100) * 1024 * 1024;
            if ($archive->numFiles > $maxEntries) {
                throw new RuntimeException('يحتوي ملف Excel عدداً غير آمن من العناصر.');
            }
            for ($index = 0; $index < $archive->numFiles; $index++) {
                $stat = $archive->statIndex($index);
                if (! is_array($stat)) {
                    throw new RuntimeException('تعذر فحص أحد عناصر ملف Excel.');
                }
                $name = $stat['name'];
                $totalUncompressed += $stat['size'];
                if ($totalUncompressed > $maxUncompressedBytes) {
                    throw new RuntimeException('يتجاوز حجم Excel بعد فك الضغط الحد الآمن.');
                }
                if (str_contains(mb_strtolower($name), 'vbaproject.bin') || str_starts_with($name, 'xl/externalLinks/')) {
                    throw new RuntimeException('يحتوي ملف Excel روابط خارجية أو شيفرة غير مسموحة.');
                }
                if (str_ends_with(mb_strtolower($name), '.rels')) {
                    $contents = $archive->getFromIndex($index);
                    if (is_string($contents) && preg_match('/TargetMode\s*=\s*["\']External["\']/i', $contents) === 1) {
                        throw new RuntimeException('يحتوي ملف Excel مرجعاً خارجياً غير مسموح.');
                    }
                }
            }
        } finally {
            $archive->close();
        }
    }

    private function rawValue(Cell $cell): string
    {
        $value = $cell->getValue();

        if ($cell->getDataType() !== DataType::TYPE_FORMULA && is_numeric($value) && Date::isDateTime($cell)) {
            try {
                return Date::excelToDateTimeObject((float) $value)->format('Y-m-d H:i:s');
            } catch (Throwable) {
                return (string) $value;
            }
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        return is_scalar($value) ? (string) $value : '';
    }

    private function isFormulaRisk(string $field, string $value): bool
    {
        if ($field === 'phone' && preg_match('/^\+[0-9 ()-]+$/', $value) === 1) {
            return false;
        }

        return preg_match('/^[=+\-@\t\r]/u', $value) === 1;
    }

    /**
     * @param  list<array<string, string>>  $rows
     * @param  list<array{sheet?: string|null, row_number: int|null, field: string|null, code: string, message: string}>  $errors
     * @return array{rows: list<array<string, string>>, errors: list<array{sheet?: string|null, row_number: int|null, field: string|null, code: string, message: string}>, encoding: string, sheet: string, transformations: array{trimmed_cells: int, blank_rows: int, bom_removed: bool, encoding_converted: bool}}
     */
    private function result(array $rows, array $errors, string $sheet, int $trimmedCells, int $blankRows): array
    {
        return [
            'rows' => $rows,
            'errors' => $errors,
            'encoding' => 'UTF-8',
            'sheet' => $sheet,
            'transformations' => [
                'trimmed_cells' => $trimmedCells,
                'blank_rows' => $blankRows,
                'bom_removed' => false,
                'encoding_converted' => false,
            ],
        ];
    }

    /** @return array{sheet: string, row_number: int|null, field: string|null, code: string, message: string} */
    private function error(string $sheet, ?int $row, ?string $field, string $code, string $message): array
    {
        return ['sheet' => $sheet, 'row_number' => $row, 'field' => $field, 'code' => $code, 'message' => $message];
    }
}
