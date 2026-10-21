<?php

return [
    // A student passes a subject with at least this percentage of the weighted grade.
    'pass_percent' => (float) env('GRADING_PASS_PERCENT', 50),
];
