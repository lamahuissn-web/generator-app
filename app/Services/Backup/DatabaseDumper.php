<?php

namespace App\Services\Backup;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use RuntimeException;

/**
 * A complete SQL copy of the panel's own database, built from inside the application.
 *
 * No shell, no `mysqldump`: the dump has to work on whatever machine the office runs, and it has to
 * be exercisable by the test suite (which runs on SQLite). Reading the schema through the connection
 * means the copy carries the structure the panel is actually running, so a restore does not depend
 * on someone replaying migrations in the right order.
 *
 * Two rules make the file trustworthy rather than merely present:
 *
 *  - IT IS WRITTEN UNDER A TEMPORARY NAME and renamed only once it is complete, so no later run —
 *    and no person — can mistake a half-written file for a copy.
 *  - IT ENDS WITH A COMPLETION MARKER carrying the table count and a checksum of everything written,
 *    which is the difference between "a file exists" and "the copy is whole". A truncated file has
 *    lost its last line; a file that grew is no longer the file that was hashed.
 */
final class DatabaseDumper
{
    /** Rows per read, so memory stays flat as the ledger grows. */
    public const CHUNK = 500;

    /** Rows per INSERT statement: small enough to be readable, large enough not to bloat the file. */
    public const ROWS_PER_STATEMENT = 100;

    private const MARKER = '-- dump complete:';

    public function __construct(private readonly string $directory) {}

    public static function make(?string $directory = null): self
    {
        return new self($directory ?? BackupSettings::path());
    }

    /**
     * Make the copy.
     *
     * @return array{path: string, name: string, size: int, tables: int, checksum: string}
     *
     * @throws RuntimeException when the destination cannot be written
     */
    public function run(): array
    {
        $connection = DB::connection();
        $driver = $connection->getDriverName();
        $tables = $this->tables($driver);

        File::ensureDirectoryExists($this->directory);

        $name = 'generator-'.now()->format('Y-m-d-Hi').'.sql';
        $final = $this->directory.'/'.$name;
        $partial = $final.'.part';

        $handle = @fopen($partial, 'wb');

        if ($handle === false) {
            throw new RuntimeException(__('bk.refuse_unwritable', ['path' => $this->directory]));
        }

        $hash = hash_init('sha1');
        $write = function (string $chunk) use ($handle, $hash): void {
            fwrite($handle, $chunk);
            hash_update($hash, $chunk);
        };

        $write(sprintf(
            "-- Generator Manager database copy\n-- Created: %s\n-- Driver: %s\n\n",
            now()->toDateTimeString(),
            $driver,
        ));

        try {
            foreach ($tables as $table) {
                $this->writeTable($table, $driver, $write);
            }
        } catch (\Throwable $e) {
            fclose($handle);
            @unlink($partial);

            throw new RuntimeException($e->getMessage(), 0, $e);
        }

        $checksum = hash_final($hash);

        // The marker is written last and is the only thing that proves the file was finished: this
        // line existing is what a truncated copy cannot fake. Written straight to the handle — the
        // hash is closed by now, and the marker must not be part of what it covers.
        fwrite($handle, sprintf("%s %d tables, sha1 %s\n", self::MARKER, count($tables), $checksum));

        fclose($handle);

        $size = (int) filesize($partial);

        if ($size <= 0) {
            @unlink($partial);

            throw new RuntimeException(__('bk.refuse_empty_dump'));
        }

        File::move($partial, $final);

        return [
            'path' => $final,
            'name' => $name,
            'size' => $size,
            'tables' => count($tables),
            'checksum' => $checksum,
        ];
    }

    /**
     * Is this file a complete copy?
     *
     * The check is the marker plus the checksum it records: a file whose last line is missing was cut
     * short, and a file whose content no longer hashes to what the marker says was changed after the
     * fact. Either way it is not a copy of anything, and must never be called one.
     */
    public static function verify(string $path): bool
    {
        if (! is_file($path) || filesize($path) <= 0) {
            return false;
        }

        $handle = fopen($path, 'rb');

        if ($handle === false) {
            return false;
        }

        $size = (int) filesize($path);

        // Read the tail to find the marker line, then hash everything BEFORE its first character.
        // Locating the marker by offset rather than searching chunk by chunk matters: a marker that
        // straddled a read boundary would otherwise look like a file without one.
        $tailSize = min(512, $size);
        fseek($handle, $size - $tailSize);
        $tail = (string) stream_get_contents($handle);

        $position = strrpos($tail, self::MARKER);

        if ($position === false) {
            fclose($handle);

            return false;
        }

        $line = trim(explode("\n", substr($tail, $position))[0]);

        if (! preg_match('/sha1 ([0-9a-f]{40})$/', $line, $matches)) {
            fclose($handle);

            return false;
        }

        $markerStart = $size - $tailSize + $position;
        $hash = hash_init('sha1');
        $read = 0;

        rewind($handle);

        while ($read < $markerStart) {
            $chunk = fread($handle, min(262144, $markerStart - $read));

            if ($chunk === '' || $chunk === false) {
                break;
            }

            hash_update($hash, $chunk);
            $read += strlen($chunk);
        }

        fclose($handle);

        return hash_final($hash) === $matches[1];
    }

    /** Every copy on the machine, newest name first. */
    public function files(): array
    {
        if (! File::isDirectory($this->directory)) {
            return [];
        }

        $files = glob($this->directory.'/generator-*.sql') ?: [];

        usort($files, fn (string $a, string $b): int => strcmp($b, $a));

        return $files;
    }

    /**
     * Keep the newest $keep copies and remove the rest.
     *
     * The newest file is never among the removals, whatever $keep says: the office asking for fewer
     * copies must not be able to end up with none. Part files left by an interrupted split go with
     * the copy they belong to.
     */
    public function prune(int $keep): int
    {
        $files = $this->files();
        $keep = max(1, $keep);
        $removed = 0;

        foreach (array_slice($files, $keep) as $file) {
            if (@unlink($file)) {
                $removed++;
            }
            foreach (glob($file.'-part*of*.sql') ?: [] as $part) {
                @unlink($part);
            }
        }

        return $removed;
    }

    // ---- the SQL itself -------------------------------------------------------

    /** @return array<int, string> */
    private function tables(string $driver): array
    {
        return match ($driver) {
            'mysql', 'mariadb' => array_map(
                fn ($row): string => (string) array_values((array) $row)[0],
                DB::select('SHOW TABLES'),
            ),
            'sqlite' => array_map(
                fn ($row): string => (string) $row->name,
                DB::select("SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%'"),
            ),
            default => throw new RuntimeException(__('bk.refuse_driver', ['driver' => $driver])),
        };
    }

    private function writeTable(string $table, string $driver, callable $write): void
    {
        $write(sprintf("--\n-- Table `%s`\n--\n\n", $table));
        $write(sprintf("DROP TABLE IF EXISTS `%s`;\n", $table));
        $write($this->createStatement($table, $driver).";\n\n");

        $columns = DB::connection()->getSchemaBuilder()->getColumnListing($table);

        if ($columns === []) {
            return;
        }

        $primary = $this->primaryKey($table);
        $buffer = [];
        $flush = function () use (&$buffer, $write, $table, $columns): void {
            if ($buffer === []) {
                return;
            }

            $write(sprintf(
                "INSERT INTO `%s` (%s) VALUES\n%s;\n",
                $table,
                implode(', ', array_map(fn (string $column): string => '`'.$column.'`', $columns)),
                implode(",\n", $buffer),
            ));

            $buffer = [];
        };

        $row = function (object|array $record) use (&$buffer, $flush, $columns): void {
            $values = array_map(
                fn (string $column): string => $this->literal(data_get((array) $record, $column)),
                $columns,
            );

            $buffer[] = '('.implode(', ', $values).')';

            if (count($buffer) >= self::ROWS_PER_STATEMENT) {
                $flush();
            }
        };

        // Read in pages when the table has a key to page by (the ledger's big tables all do);
        // otherwise read it whole, which is what a table without a primary key tends to be — small.
        if ($primary !== null) {
            DB::table($table)->orderBy($primary)->chunk(self::CHUNK, function ($rows) use ($row): void {
                foreach ($rows as $record) {
                    $row($record);
                }
            });
        } else {
            foreach (DB::table($table)->get() as $record) {
                $row($record);
            }
        }

        $flush();
        $write("\n");
    }

    /** The table exactly as the server sees it, so the copy carries the structure it had. */
    private function createStatement(string $table, string $driver): string
    {
        if ($driver === 'sqlite') {
            $row = DB::selectOne("SELECT sql FROM sqlite_master WHERE type = 'table' AND name = ?", [$table]);

            if ($row === null || empty($row->sql)) {
                throw new RuntimeException(__('bk.refuse_no_schema', ['table' => $table]));
            }

            return (string) $row->sql;
        }

        $row = DB::selectOne(sprintf('SHOW CREATE TABLE `%s`', $table));
        $values = array_values((array) $row);

        return (string) ($values[1] ?? '');
    }

    private function primaryKey(string $table): ?string
    {
        foreach (DB::connection()->getSchemaBuilder()->getIndexes($table) as $index) {
            if (($index['primary'] ?? false) === true && ! empty($index['columns'])) {
                return (string) $index['columns'][0];
            }
        }

        return null;
    }

    /**
     * One value, written exactly as stored.
     *
     * Amounts and readings are NOT reformatted: `36.50` leaves as `36.50`, never as `36.5` and never
     * rounded, so a restored ledger is the same ledger. Quoting is left to the driver, which is the
     * only thing that knows how to escape what this connection escapes.
     */
    private function literal(mixed $value): string
    {
        if ($value === null) {
            return 'NULL';
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        return DB::connection()->getPdo()->quote((string) $value);
    }
}
