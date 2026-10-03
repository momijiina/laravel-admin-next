<?php

namespace LaravelAdminNext\Integration;

use Composer\Autoload\ClassLoader;
use Encore\Admin\Grid\Column;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

require_once __DIR__.'/fixtures/csv_headers.php';

class CsvHeadersTest extends TestCase
{
    private function export(array $options): array
    {
        $tracePath = tempnam(sys_get_temp_dir(), 'csv-header-trace-');
        $vendor = array_key_first(ClassLoader::getRegisteredLoaders());
        $source = dirname((new \ReflectionClass(Column::class))->getFileName(), 2).'/';
        try {
            $process = new Process([PHP_BINARY, '-d', 'error_reporting=-1', __DIR__.'/fixtures/export_csv_headers.php', $vendor.'/autoload.php', $source, json_encode($options, JSON_THROW_ON_ERROR), $tracePath]);
            $process->setTimeout(30);
            $process->run();
            $this->assertTrue($process->isSuccessful(), $process->getErrorOutput().$process->getOutput());
            $this->assertSame('', $process->getErrorOutput());
            $output = $process->getOutput();
            $this->assertStringStartsWith("\xEF\xBB\xBF", $output);
            $handle = fopen('php://memory', 'r+');
            fwrite($handle, substr($output, 3));
            rewind($handle);
            $rows = [];
            while (($row = fgetcsv($handle, null, ',', '"', '\\')) !== false) {
                $rows[] = $row;
            }
            fclose($handle);
            $trace = json_decode(file_get_contents($tracePath), true, 512, JSON_THROW_ON_ERROR);
            $this->assertSame(['configure'], $trace[0]);
            $this->assertContains('query', array_column($trace, 0));
            return [$rows, $trace];
        } finally {
            unlink($tracePath);
        }
    }

    private function events(array $trace, string $event): array
    {
        return array_values(array_filter($trace, function ($entry) use ($event) { return $entry[0] === $event; }));
    }

    public function test_empty_table_filter_selected_and_current_page_emit_one_header(): void
    {
        foreach ([false, true] as $named) {
            foreach ([['empty' => 'table'], ['empty' => 'filter'], ['empty' => 'selected', 'scope' => 'selected'], ['empty' => 'table', 'scope' => 'page']] as $options) {
                [$rows, $trace] = $this->export($options + ['named' => $named]);
                $this->assertSame([['ID', 'Category', 'Text', 'Zero']], $rows);
                $this->assertSame([], $this->events($trace, 'display'));
                $this->assertSame([], $this->events($trace, 'column'));
                $this->assertSame([['title', 'Text', ['id', 'category', 'text', 'zero']]], $this->events($trace, 'title'));
                $this->assertSame('title', $trace[count($trace) - 1][0]);
            }
        }
    }

    public function test_empty_headers_follow_existing_visibility_and_title_apis(): void
    {
        $cases = [
            [['hidden' => ['category']], ['ID', 'Text', 'Zero'], 1],
            [['hidden' => ['text'], 'request_columns' => 'zero,text'], ['Text', 'Zero'], 1],
            [['hidden' => ['text']], ['ID', 'Category', 'Zero'], 0],
            [['only' => ['zero', 'text']], ['Text', 'Zero'], 1],
            [['except' => ['category', 'zero']], ['ID', 'Text'], 1],
            [['only' => ['id', 'text', 'zero'], 'except' => ['zero'], 'title' => CsvHeadersFixture::TITLE], ['ID', CsvHeadersFixture::TITLE], 1],
            [['only' => ['text'], 'title' => '0'], ['0'], 1],
            [['only' => ['text'], 'title' => ''], [null], 1],
            [['no_columns' => true], [null], 0],
            [['only' => ['missing']], [null], 1],
            [['except' => ['id', 'category', 'text', 'zero']], [null], 1],
            [['request_columns' => 'missing'], [null], 0],
            // Empty only/except and hiding every column retain their legacy meaning.
            [['only' => [], 'except' => []], ['ID', 'Category', 'Text', 'Zero'], 1],
            [['hidden' => ['id', 'category', 'text', 'zero']], ['ID', 'Category', 'Text', 'Zero'], 1],
        ];
        foreach ($cases as [$options, $header, $titleCalls]) {
            foreach ([['table', 'all'], ['filter', 'all'], ['selected', 'selected'], ['table', 'page'], ['', 'all']] as [$empty, $scope]) {
                [$rows, $trace] = $this->export($options + ['empty' => $empty, 'scope' => $scope]);
                $this->assertSame($header, array_shift($rows), json_encode($options));
                $this->assertCount($empty === '' ? 6 : 0, $rows);
                $this->assertCount($titleCalls, $this->events($trace, 'title'));
                if ($empty !== '') {
                    $this->assertSame([], $this->events($trace, 'display'));
                    $this->assertSame([], $this->events($trace, 'column'));
                }
            }
        }
    }

    public function test_filtered_sorted_nonempty_scopes_keep_values_and_callback_order(): void
    {
        foreach ([false, true] as $named) {
            foreach (['all' => [11, 9, 7, 5, 3, 1], 'page' => [7, 5], 'selected' => [7]] as $scope => $ids) {
                [$rows, $trace] = $this->export(['named' => $named, 'scope' => $scope]);
                $this->assertSame(['ID', 'Category', 'Text', 'Zero'], array_shift($rows));
                $this->assertSame(array_map(function ($id) { return [(string) $id, '1', CsvHeadersFixture::TEXT, '0']; }, $ids), $rows);
                $events = array_column($trace, 0);
                $this->assertCount(1, $this->events($trace, 'title'));
                $this->assertCount(count($ids), $this->events($trace, 'display'));
                $this->assertCount(count($ids), $this->events($trace, 'column'));
                $this->assertLessThan(array_search('title', $events), array_search('display', $events));
                $this->assertLessThan(array_search('column', $events), array_search('title', $events));
            }
        }
    }

    public function test_multiple_chunks_write_exactly_one_header_even_with_no_visible_columns(): void
    {
        foreach ([[], ['only' => ['text'], 'title' => '0'], ['only' => ['missing']], ['request_columns' => 'missing'], ['no_columns' => true]] as $options) {
            [$rows, $trace] = $this->export($options + ['large' => true]);
            $header = isset($options['no_columns']) || isset($options['request_columns']) || ($options['only'] ?? []) === ['missing'] ? [null] : (isset($options['title']) ? ['0'] : ['ID', 'Category', 'Text', 'Zero']);
            $this->assertSame($header, array_shift($rows));
            $this->assertCount(106, $rows);
            $this->assertCount(isset($options['no_columns']) || isset($options['request_columns']) ? 0 : 1, $this->events($trace, 'title'));
            $this->assertCount(isset($options['no_columns']) ? 0 : 106, $this->events($trace, 'display'));
            $this->assertCount(2, $this->events($trace, 'query'));
            foreach ($rows as $index => $row) {
                $this->assertSame($header === [null] ? [null] : (isset($options['title']) ? [CsvHeadersFixture::TEXT] : [(string) (211 - $index * 2), '1', CsvHeadersFixture::TEXT, '0']), $row);
            }
        }
    }
}
