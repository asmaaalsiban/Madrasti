<?php

declare(strict_types=1);

namespace App\Shared\Handover\Sections;

use App\Shared\Handover\HandoverSection;
use Illuminate\Support\Facades\Storage;

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
                if (in_array('..', explode('/', str_replace('\\', '/', $name)), true)) {
                    continue; // never write outside the package folder
                }
                $source = $storage->readStream($name);
                if (! is_resource($source)) {
                    continue;
                }
                $target = "$dir/$disk/$name";
                is_dir(dirname($target)) || mkdir(dirname($target), 0777, true);
                $out = fopen($target, 'wb');
                stream_copy_to_stream($source, $out);
                fclose($out);
                fclose($source);
            }
        }
    }
}
