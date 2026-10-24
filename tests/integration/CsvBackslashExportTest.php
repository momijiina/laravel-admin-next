<?php

namespace LaravelAdminNext\Integration;

use Composer\Autoload\ClassLoader;
use Encore\Admin\Grid\Exporters\CsvExporter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

require_once __DIR__.'/fixtures/csv_headers.php';

class CsvBackslashExportTest extends TestCase
{
    private function export(array $options): array
    {
        $tracePath = tempnam(sys_get_temp_dir(), 'csv-backslash-');
        $vendor = array_key_first(ClassLoader::getRegisteredLoaders());
        $source = dirname((new \ReflectionClass(CsvExporter::class))->getFileName(), 3).'/';

        try {
            $process = new Process([PHP_BINARY, '-d', 'error_reporting=-1', __DIR__.'/fixtures/export_csv_headers.php', $vendor.'/autoload.php', $source, json_encode($options, JSON_THROW_ON_ERROR), $tracePath]);
            $process->setTimeout(30);
            $process->run();
            $this->assertTrue($process->isSuccessful(), $process->getErrorOutput().$process->getOutput());
            $this->assertSame('', $process->getErrorOutput());
            $bytes = $process->getOutput();
            $this->assertStringStartsWith("\xEF\xBB\xBF", $bytes);

            $handle = fopen('php://memory', 'r+');
            fwrite($handle, substr($bytes, 3));
            rewind($handle);
            $rows = [];
            // CSV quotes are doubled; a backslash is always literal data.
            while (($row = fgetcsv($handle, null, ',', '"', '')) !== false) {
                $rows[] = $row;
            }
            fclose($handle);

            return [$rows, json_decode(file_get_contents($tracePath), true, 512, JSON_THROW_ON_ERROR)];
        } finally {
            unlink($tracePath);
        }
    }

    public static function literalValues(): iterable
    {
        yield 'single backslash' => ['\\'];
        yield 'path ending in backslash' => ['C:\\exports\\'];
        yield 'backslash before quote' => ['before\\"quote'];
        yield 'two backslashes before quote' => ['before\\\\"quote'];
        yield 'quoted text followed by backslash' => ['"quoted"\\'];
        yield 'comma and trailing backslash' => ['comma,then\\'];
        yield 'newline and trailing backslash' => ["line\nends\\"];
        yield 'Unicode and mixed quoting' => ["日本語 Café, \\\"quoted\\\"\nC:\\exports\\"];
    }

    #[DataProvider('literalValues')]
    public function test_literal_backslashes_round_trip_in_headers_and_final_column(string $value): void
    {
        [$rows, $trace] = $this->export(['text' => $value, 'title' => $value, 'only' => ['text']]);

        $this->assertSame(array_fill(0, 7, [$value]), $rows);
        $events = array_count_values(array_column($trace, 0));
        $this->assertSame(1, $events['title']);
        $this->assertSame(6, $events['column']);
    }

    public function test_filtered_sorted_export_scopes_keep_columns_and_chunk_boundaries(): void
    {
        $value = "日本語, \\\"quoted\\\"\nC:\\exports\\";

        foreach ([false, true] as $named) {
            foreach (['all' => range(211, 1, -2), 'page' => [207, 205], 'selected' => [7]] as $scope => $ids) {
                [$rows, $trace] = $this->export([
                    'named' => $named, 'scope' => $scope, 'large' => true,
                    'default_driver' => true, 'text' => $value, 'title' => $value,
                ]);

                $expected = [['ID', 'Category', $value, 'Zero']];
                foreach ($ids as $id) {
                    $expected[] = [(string) $id, '1', $value, '0'];
                }
                $this->assertSame($expected, $rows, ($named ? 'named ' : '').$scope);
                $events = array_count_values(array_column($trace, 0));
                $this->assertSame(1, $events['title']);
                $this->assertSame(count($ids), $events['column']);
                $this->assertSame($scope === 'selected' ? 1 : 2, $events['query']);
            }
        }
    }

    public function test_empty_results_preserve_backslashes_in_one_header_record(): void
    {
        $title = "Header, \\\"quoted\\\"\nends\\";

        foreach ([false, true] as $named) {
            foreach ([['empty' => 'table'], ['empty' => 'filter'], ['empty' => 'selected', 'scope' => 'selected'], ['empty' => 'table', 'scope' => 'page']] as $case) {
                [$rows, $trace] = $this->export($case + ['named' => $named, 'title' => $title, 'only' => ['text']]);

                $this->assertSame([[$title]], $rows);
                $events = array_count_values(array_column($trace, 0));
                $this->assertSame(1, $events['title']);
                $this->assertArrayNotHasKey('display', $events);
                $this->assertArrayNotHasKey('column', $events);
            }
        }
    }

    public function test_original_json_ignores_display_text_and_skips_exporter_column_callback(): void
    {
        $payload = ['label' => '日本語 "quoted"', 'path' => 'C:\\exports\\', 'zero' => 0, 'null' => null];
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        [$rows, $trace] = $this->export([
            'text' => $json, 'display_text' => 'Displayed label', 'title' => 'JSON',
            'only' => ['text'], 'original' => ['text'],
        ]);

        $this->assertSame(['JSON'], array_shift($rows));
        $this->assertSame(array_fill(0, 6, [$json]), $rows);
        foreach ($rows as $row) {
            $this->assertSame($payload, json_decode($row[0], true, 512, JSON_THROW_ON_ERROR));
        }
        $events = array_count_values(array_column($trace, 0));
        $this->assertSame(6, $events['display']);
        $this->assertArrayNotHasKey('column', $events);
    }
}
