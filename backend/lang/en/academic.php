<?php

return [
    'in_use' => 'This :resource is in use and cannot be deleted.',
    'term_outside_year' => 'The term must be inside its academic year.',
    'term_overlaps' => 'This term overlaps another term of the same academic year.',
    'subject_already_taught' => 'This subject already has a teacher in this classroom.',
    'teacher_not_for_subject' => 'The teacher must be an active teacher of this subject.',
    'rooms_in_use' => ':count room(s) in use cannot be removed. Free them first or choose a higher number.',
    'floor_grade_range_incomplete' => 'Choose both the first and the last grade of the floor, or neither.',
    'floor_grade_range_reversed' => 'The last grade cannot come before the first grade.',
    'floor_level_taken' => 'This building already has a floor with this level.',
    'room_name_taken' => 'Another room in this building has this name.',
    'too_many_sections' => 'A grade can have at most :max sections.',
    'room_taken' => 'Another classroom already uses this room in this academic year.',
    'curriculum_duplicate' => 'A subject can appear only once for a grade.',
    'subject_not_in_curriculum' => 'This subject is not in the curriculum of the classroom\'s grade.',
    'year_locked' => 'This classroom has enrolled students, so its academic year cannot be changed.',
    'resources' => [
        'subject' => 'subject',
        'grade_level' => 'grade level',
        'academic_year' => 'academic year',
        'classroom' => 'classroom',
        'floor' => 'floor',
        'building' => 'building',
        'term' => 'term',
        'teaching_assignment' => 'teaching assignment',
    ],
];
