<?php

declare(strict_types=1);

namespace App\Shared\Handover\Sections;

use App\Shared\Handover\HandoverSection;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/** Every file on the disks the backup keeps (`config('backup.file_disks')`), under a folder per disk. Copied as streams, never read whole. */
class UploadedFiles implements HandoverSection
{
    public function name(): string
    {
        return 'uploaded_files';
    }

    public function write(string $dir): void
    {
        foreach ((array) config('backup.file_disks', []) as $disk) {
            $storage = Storage::disk($disk);
            foreach ($storage->allFiles() as $name) {
                // A package that silently misses a file is worse than none: every problem stops the build.
                $where = "disk \"$disk\", file \"$name\"";
                if (in_array('..', explode('/', str_replace('\\', '/', $name)), true)) {
                    throw new RuntimeException("Handover: unsafe path ($where).");
                }
                $source = $storage->readStream($name);
                if (! is_resource($source)) {
                    throw new RuntimeException("Handover: the file cannot be read ($where).");
                }
                $target = "$dir/$disk/$name";
                is_dir(dirname($target)) || mkdir(dirname($target), 0777, true);
                $out = @fopen($target, 'wb');
                if ($out === false) {
                    fclose($source);
                    throw new RuntimeException("Handover: the file cannot be written ($where).");
                }
                $copied = stream_copy_to_stream($source, $out);
                $flushed = fflush($out);
                fclose($source);
                fclose($out);
                if ($copied === false || ! $flushed || ($storage->size($name) !== $copied)) {
                    throw new RuntimeException("Handover: the file was not copied completely ($where).");
                }
            }
        }
    }
}
