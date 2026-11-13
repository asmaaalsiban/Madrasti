<?php

declare(strict_types=1);

namespace App\Shared\Handover;

use App\Shared\Handover\Sections\Academic;
use App\Shared\Handover\Sections\Accounting;
use App\Shared\Handover\Sections\Payroll;
use App\Shared\Handover\Sections\People;
use App\Shared\Handover\Sections\UploadedFiles;
use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use Throwable;
use ZipArchive;

/**
 * The readable package a school takes with it when it leaves: Excel files and its uploaded files, one folder per
 * section, with a README. Run it inside the school (`$tenant->run`): it reads the connected database and nothing else.
 * It only reads school data; the only thing it deletes is its own temporary working folder.
 */
class HandoverPackage
{
    /** @return list<HandoverSection> accounting first */
    protected function sections(): array
    {
        return [new Accounting(), new People(), new Payroll(), new Academic(), new UploadedFiles()];
    }

    /** Builds the zip and returns its path (a temporary file: the caller stores it). */
    public function build(): string
    {
        $work = sys_get_temp_dir().'/handover_'.bin2hex(random_bytes(6));
        $zipPath = tempnam(sys_get_temp_dir(), 'hov');
        mkdir($work);

        try {
            $sections = $this->sections();
            foreach ($sections as $section) {
                mkdir($work.'/'.$section->name());
                $section->write($work.'/'.$section->name());
            }
            file_put_contents($work.'/README.txt', $this->readme($work, $sections));

            $zip = new ZipArchive();
            if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                throw new RuntimeException('Could not create the handover package.');
            }
            $zip->addFile($work.'/README.txt', 'README.txt');
            foreach ($sections as $section) {
                $zip->addEmptyDir($section->name()); // a school with nothing to hand over in a section still gets its folder
            }
            $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($work, FilesystemIterator::SKIP_DOTS));
            $entries = [];
            foreach ($files as $file) {
                $entries[substr($file->getPathname(), strlen($work) + 1)] = $file->getPathname();
            }
            ksort($entries);
            foreach ($entries as $name => $path) {
                if ($name !== 'README.txt') {
                    $zip->addFile($path, $name);
                }
            }
            if (! $zip->close()) {
                throw new RuntimeException('Could not finish the handover package.');
            }
        } catch (Throwable $e) {
            @unlink($zipPath);
            throw $e;
        } finally {
            $this->removeDirectory($work); // our own working copy, never school data
        }

        return $zipPath;
    }

    /** @param list<HandoverSection> $sections */
    private function readme(string $work, array $sections): string
    {
        $school = (string) (tenant('name') ?? config('app.name'));
        $both = fn (string $group, string $key) => HandoverSheet::label($group, $key);
        $lines = [$both('', 'readme_title'), str_repeat('=', 40), '', $both('', 'school').': '.$school, $both('', 'date').': '.now()->toDateString(), '', $both('', 'sections_intro'), ''];
        foreach ($sections as $section) {
            $name = $section->name();
            $lines[] = $name.'/  -  '.$both('sections', $name);
            if ($name === 'uploaded_files') {
                $lines[] = '    '.$both('', 'uploaded_files');
                $lines[] = '';
                continue;
            }
            $files = array_map('basename', glob($work.'/'.$name.'/*.xlsx') ?: []);
            sort($files);
            foreach ($files as $file) {
                $lines[] = '    '.$file.'  -  '.$this->describe(basename($file, '.xlsx'));
            }
            $lines[] = '';
        }

        return implode("\n", $lines);
    }

    /** The title of a file; the yearly reports carry the year after the report's key (trial_balance_2026-2027). */
    private function describe(string $stem): string
    {
        $key = $stem;
        while ($key !== '' && ! trans()->has("handover.files.$key", 'en')) {
            $key = str_contains($key, '_') ? substr($key, 0, (int) strrpos($key, '_')) : '';
        }

        return $key === '' ? $stem : HandoverSheet::label('files', $key).($key !== $stem ? ' ('.substr($stem, strlen($key) + 1).')' : '');
    }

    private function removeDirectory(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $f) {
            $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
        }
        rmdir($dir);
    }
}
