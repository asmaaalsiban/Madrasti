<?php

declare(strict_types=1);

namespace App\Modules\People\Support;

use RuntimeException;

/** Thrown when adding students would pass the school's limit; rendered as 422 `student_limit_reached`. */
class StudentLimitReached extends RuntimeException
{
    public function __construct(public readonly int $limit, public readonly int $current, public readonly int $allowed)
    {
        parent::__construct(__('people.student_limit_reached', ['limit' => $limit]));
    }
}
