<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use JsonException;

/**
 * LOCAL / TEST USE ONLY. Loads a catalogue-export.json snapshot (document_type
 * and certificate_type rows) into the current database so the local catalogue
 * mirrors production.
 *
 *  - Rows in the snapshot are inserted, or updated in place by primary key.
 *  - Local rows that are NOT in the snapshot are archived, never deleted, so
 *    existing requests keep a valid foreign key.
 *  - Layout/logo columns of certificate_type are never touched.
 *  - Runs as a preview unless --apply is given; a preview rolls everything back.
 *  - Refuses to run when APP_ENV=production.
 */
class ImportCatalogueSnapshot extends Command
{
    protected $signature = 'catalogue:import-snapshot
        {file : Path to catalogue-export.json}
        {--apply : Write the changes (default is a preview that rolls back)}';

    protected $description = 'LOCAL ONLY: mirror a production catalogue snapshot into document_type and certificate_type';

    private const MAX_BYTES = 5_000_000;

    private const SETS = [
        'documents' => [
            'table'   => 'document_type',
            'pk'      => 'document_type_id',
            'name'    => 'document_name',
            'columns' => [
                'document_name', 'document_description', 'document_requirements',
                'document_process_period', 'access_id', 'cashier_document_patterns',
                'is_archived', 'logbook_category_id', 'requires_source_submission',
                'fulfillment_track_id', 'is_free_eligible', 'free_issuance_limit',
            ],
        ],
        'certificates' => [
            'table'   => 'certificate_type',
            'pk'      => 'certificate_type_id',
            'name'    => 'certificate_name',
            'columns' => [
                'certificate_name', 'certificate_requirements', 'certificate_process_period',
                'access_id', 'cashier_document_patterns', 'is_archived',
                'logbook_category_id', 'requires_source_submission',
                'fulfillment_track_id', 'is_free_eligible', 'free_issuance_limit',
            ],
        ],
    ];

    private const BOOL_COLUMNS = ['is_archived', 'requires_source_submission', 'is_free_eligible'];

    /** Optional references: if the target row is missing locally, store NULL. */
    private const OPTIONAL_REFS = [
        'logbook_category_id'  => ['logbook_category', 'logbook_category_id'],
        'fulfillment_track_id' => ['fulfillment_track', 'fulfillment_track_id'],
    ];

    public function handle(): int
    {
        if ($this->laravel->environment('production')) {
            $this->error('Refusing to run: this command is for local/test databases only.');

            return self::FAILURE;
        }

        $data = $this->loadSnapshot((string) $this->argument('file'));
        if ($data === null) {
            return self::FAILURE;
        }

        $apply = (bool) $this->option('apply');
        $this->info($apply ? 'APPLY mode: changes will be written.' : 'PREVIEW mode: nothing will be saved.');

        try {
            DB::beginTransaction();

            foreach (self::SETS as $key => $set) {
                $problems = $this->importSet($key, $set, $data[$key]);
                if ($problems !== []) {
                    DB::rollBack();
                    foreach ($problems as $problem) {
                        $this->error($problem);
                    }

                    return self::FAILURE;
                }
            }

            if ($apply) {
                DB::commit();
                $this->resetSequences();
                $this->info('Done. Clear caches (php artisan cache:clear) before re-running the benchmark.');
            } else {
                DB::rollBack();
                $this->info('Preview finished. Re-run with --apply to write these changes.');
            }
        } catch (\Throwable $e) {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            $this->error('Import failed and was rolled back: ' . $e->getMessage());

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /** @return array<string, list<array<string, mixed>>>|null */
    private function loadSnapshot(string $path): ?array
    {
        if (! is_file($path) || ! is_readable($path)) {
            $this->error("Cannot read file: {$path}");

            return null;
        }
        if (filesize($path) > self::MAX_BYTES) {
            $this->error('Snapshot file is unexpectedly large; refusing to load it.');

            return null;
        }

        try {
            $data = json_decode((string) file_get_contents($path), true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            $this->error('Invalid JSON: ' . $e->getMessage());

            return null;
        }

        foreach (self::SETS as $key => $set) {
            if (! isset($data[$key]) || ! is_array($data[$key])) {
                $this->error("Snapshot is missing the \"{$key}\" list.");

                return null;
            }

            $seen = [];
            foreach ($data[$key] as $i => $row) {
                $id   = is_array($row) ? ($row[$set['pk']] ?? null) : null;
                $name = is_array($row) ? ($row[$set['name']] ?? null) : null;
                if (! is_int($id) || $id < 1 || ! is_string($name) || trim($name) === '') {
                    $this->error("{$key}[{$i}] needs an integer {$set['pk']} and a non-empty {$set['name']}.");

                    return null;
                }
                if (isset($seen[$id])) {
                    $this->error("{$key}: duplicate id {$id}.");

                    return null;
                }
                $seen[$id] = true;
            }
        }

        return $data;
    }

    /**
     * @param  array{table:string,pk:string,name:string,columns:list<string>}  $set
     * @param  list<array<string, mixed>>  $rows
     * @return list<string>  blocking problems (empty when fine)
     */
    private function importSet(string $key, array $set, array $rows): array
    {
        $table   = $set['table'];
        $pk      = $set['pk'];
        $columns = array_values(array_filter(
            $set['columns'],
            fn (string $c): bool => Schema::hasColumn($table, $c)
        ));

        $accessIds = DB::table('access_type')->pluck('access_id')->map(fn ($v) => (int) $v)->all();
        $optional  = [];
        foreach (self::OPTIONAL_REFS as $col => [$refTable, $refKey]) {
            $optional[$col] = Schema::hasTable($refTable)
                ? DB::table($refTable)->pluck($refKey)->map(fn ($v) => (int) $v)->all()
                : [];
        }

        $problems = [];
        $payloads = [];
        $nulled   = 0;

        foreach ($rows as $row) {
            $id      = (int) $row[$pk];
            $payload = [];

            foreach ($columns as $col) {
                $payload[$col] = $this->castValue($col, $row[$col] ?? null);
            }

            if (array_key_exists('access_id', $payload) && $payload['access_id'] !== null
                && ! in_array((int) $payload['access_id'], $accessIds, true)) {
                $problems[] = "{$key} {$id}: access_id {$payload['access_id']} does not exist in local access_type. "
                    . 'Seed access types first (php artisan db:seed).';
            }

            foreach (array_keys(self::OPTIONAL_REFS) as $col) {
                if (($payload[$col] ?? null) !== null && ! in_array((int) $payload[$col], $optional[$col], true)) {
                    $payload[$col] = null;
                    $nulled++;
                }
            }

            if (($payload['is_archived'] ?? 0) === 1) {
                $payload['archived_on'] = Schema::hasColumn($table, 'archived_on') ? now() : null;
            }
            if (! Schema::hasColumn($table, 'archived_on')) {
                unset($payload['archived_on']);
            }

            $payloads[$id] = $payload;
        }

        if ($problems !== []) {
            return $problems;
        }

        $inserted = 0;
        $updated  = 0;
        foreach ($payloads as $id => $payload) {
            $exists = DB::table($table)->where($pk, $id)->exists();
            if ($exists) {
                // Keep an existing archived_on if the row stays archived.
                if (($payload['is_archived'] ?? 0) === 1) {
                    unset($payload['archived_on']);
                } elseif (Schema::hasColumn($table, 'archived_on')) {
                    $payload['archived_on'] = null;
                }
                DB::table($table)->where($pk, $id)->update($payload);
                $updated++;
            } else {
                DB::table($table)->insert([$pk => $id] + $payload);
                $inserted++;
            }
        }

        $archiveQuery = DB::table($table)
            ->whereNotIn($pk, array_keys($payloads))
            ->where('is_archived', false);
        $toArchive = (clone $archiveQuery)->count();
        $archiveUpdate = ['is_archived' => true];
        if (Schema::hasColumn($table, 'archived_on')) {
            $archiveUpdate['archived_on'] = now();
        }
        $archiveQuery->update($archiveUpdate);

        $this->line(sprintf(
            '%-13s inserted=%d updated=%d archived-because-not-in-snapshot=%d refs-set-to-null=%d',
            $key,
            $inserted,
            $updated,
            $toArchive,
            $nulled
        ));

        return [];
    }

    private function castValue(string $column, mixed $value): mixed
    {
        if ($column === 'cashier_document_patterns') {
            if (! is_array($value) || $value === []) {
                return null;
            }

            return json_encode(
                array_values(array_filter($value, 'is_string')),
                JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
            );
        }
        if (in_array($column, self::BOOL_COLUMNS, true)) {
            return $value ? 1 : 0;
        }
        if (is_array($value)) {
            return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        }

        return $value;
    }

    /** MySQL and SQLite advance their counters on explicit ids; PostgreSQL does not. */
    private function resetSequences(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }
        foreach (self::SETS as $set) {
            DB::statement(
                "SELECT setval(pg_get_serial_sequence('{$set['table']}', '{$set['pk']}'), "
                . "COALESCE((SELECT MAX({$set['pk']}) FROM {$set['table']}), 1))"
            );
        }
    }
}
