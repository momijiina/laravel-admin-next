<?php

namespace LaravelAdminNext\Integration;

use Composer\Autoload\ClassLoader;
use Encore\Admin\Grid\Exporter;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

require_once __DIR__.'/fixtures/csv_headers.php';

class DefaultExporterTest extends TestCase
{
    private function export(array $options): array
    {
        $tracePath = tempnam(sys_get_temp_dir(), 'default-exporter-');
        $vendor = array_key_first(ClassLoader::getRegisteredLoaders());
        $source = dirname((new \ReflectionClass(Exporter::class))->getFileName(), 2).'/';
        try {
            $process = new Process([PHP_BINARY, '-d', 'error_reporting=-1', __DIR__.'/fixtures/export_csv_headers.php', $vendor.'/autoload.php', $source, json_encode($options, JSON_THROW_ON_ERROR), $tracePath]);
            $process->setTimeout(30);
            $process->run();
            $this->assertTrue($process->isSuccessful(), $process->getErrorOutput().$process->getOutput());
            $this->assertSame('', $process->getErrorOutput());

            return [$process->getOutput(), json_decode(file_get_contents($tracePath), true, 512, JSON_THROW_ON_ERROR)];
        } finally {
            unlink($tracePath);
        }
    }

    private function csv(array $rows): string
    {
        $handle = fopen('php://memory', 'r+');
        fwrite($handle, "\xEF\xBB\xBF");
        foreach ($rows as $row) {
            fputcsv($handle, $row, ',', '"', '\\');
        }
        rewind($handle);
        $bytes = stream_get_contents($handle);
        fclose($handle);

        return $bytes;
    }

    public function test_ordinary_http_exports_without_driver_or_callback_keep_exact_csv_and_scopes(): void
    {
        foreach ([false, true] as $named) {
            foreach (['all' => range(211, 1, -2), 'page' => [207, 205], 'selected' => [7]] as $scope => $ids) {
                $options = ['named' => $named, 'scope' => $scope, 'large' => true, 'no_callback' => true];
                [$bytes, $trace] = $this->export($options + ['default_driver' => true]);
                $this->assertSame($this->export($options), [$bytes, $trace]);
                $rows = [['ID', 'Category', 'Text', 'Zero']];
                foreach ($ids as $id) {
                    $rows[] = [(string) $id, '1', CsvHeadersFixture::TEXT, '0'];
                }
                $this->assertSame($this->csv($rows), $bytes);
                $events = array_count_values(array_column($trace, 0));
                $this->assertSame(count($ids), $events['display']);
                $this->assertSame($scope === 'selected' ? 1 : 2, $events['query']);
                $this->assertArrayNotHasKey('configure', $events);
                $this->assertArrayNotHasKey('column', $events);
                $this->assertArrayNotHasKey('title', $events);
            }
        }
    }

    public function test_default_driver_preserves_empty_headers_and_export_configuration(): void
    {
        foreach ([false, true] as $named) {
            foreach ([false, true] as $noCallback) {
                foreach ([['empty' => 'table'], ['empty' => 'filter'], ['empty' => 'selected', 'scope' => 'selected'], ['empty' => 'table', 'scope' => 'page'], ['large' => true]] as $case) {
                    $options = $case + ['named' => $named, 'no_callback' => $noCallback, 'only' => ['text'], 'title' => CsvHeadersFixture::TITLE];
                    [$bytes, $trace] = $this->export($options + ['default_driver' => true]);
                    $this->assertSame($this->export($options), [$bytes, $trace]);
                    $rows = [$noCallback ? ['ID', 'Category', 'Text', 'Zero'] : [CsvHeadersFixture::TITLE]];
                    if (isset($case['large'])) {
                        foreach (range(211, 1, -2) as $id) {
                            $rows[] = $noCallback ? [(string) $id, '1', CsvHeadersFixture::TEXT, '0'] : [CsvHeadersFixture::TEXT];
                        }
                    }
                    $this->assertSame($this->csv($rows), $bytes);
                }
            }
        }
    }
}
