<?php

declare(strict_types=1);

namespace App\Shared\Handover;

/** One part of the handover package: writes its files into a folder. It only reads the school's data. */
interface HandoverSection
{
    /** The folder name of the section inside the package (also the key of its title in lang/handover.php). */
    public function name(): string;

    /** Writes the section's files into `$dir` (which exists and is empty). */
    public function write(string $dir): void;
}
