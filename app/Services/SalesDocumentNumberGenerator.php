<?php

namespace App\Services;

use App\Models\SalesDocument;
use Illuminate\Support\Facades\DB;
use LogicException;

class SalesDocumentNumberGenerator
{
    /** @var array<string, array{setting: string, default: string}> */
    private const PREFIXES = [
        'invoice' => ['setting' => 'invoice_prefix', 'default' => 'CT-INV'],
    ];

    public function __construct(private readonly SystemSettingsService $settings) {}

    /**
     * Reserve the next number. The caller must keep this inside the same database
     * transaction that inserts the document.
     */
    public function reserve(string $type, int $year): string
    {
        if (DB::transactionLevel() < 1) {
            throw new LogicException('Document numbers must be reserved inside a database transaction.');
        }

        $prefixDefinition = self::PREFIXES[$type] ?? throw new LogicException('Unsupported sales document type.');
        $company = $this->settings->group('company');
        $configuredPrefix = $company[$prefixDefinition['setting']] ?? null;
        $prefix = is_string($configuredPrefix) && preg_match('/^[A-Z0-9]+(?:-[A-Z0-9]+)*$/', $configuredPrefix) === 1
            ? $configuredPrefix
            : $prefixDefinition['default'];
        $padding = is_int($company['number_padding'] ?? null)
            ? max(2, min(8, $company['number_padding']))
            : 3;

        DB::table('document_sequences')->insertOrIgnore([
            'document_type' => $type,
            'year' => $year,
            'next_number' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        do {
            $sequence = DB::table('document_sequences')
                ->where('document_type', $type)
                ->where('year', $year)
                ->lockForUpdate()
                ->first();

            if ($sequence === null || ! isset($sequence->next_number)) {
                throw new LogicException('Unable to reserve a document number.');
            }

            $nextNumber = (int) $sequence->next_number;
            DB::table('document_sequences')
                ->where('document_type', $type)
                ->where('year', $year)
                ->update([
                    'next_number' => $nextNumber + 1,
                    'updated_at' => now(),
                ]);

            $number = sprintf('%s-%d-%0*d', $prefix, $year, $padding, $nextNumber);
        } while (SalesDocument::query()->where('number', $number)->exists());

        return $number;
    }
}
