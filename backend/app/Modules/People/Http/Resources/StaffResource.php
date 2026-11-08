<?php

declare(strict_types=1);

namespace App\Modules\People\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin User
 */
class StaffResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $profile = $this->staffProfile;
        $subject = $profile?->subject;

        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'phone' => $this->phone,
            'is_active' => $this->is_active,
            'must_change_password' => $this->must_change_password,
            'staff_type' => $profile?->staff_type->value,
            'category' => $profile?->staff_type->category(),
            'subject' => $subject ? ['id' => $subject->id, 'name_ar' => $subject->name_ar, 'name_en' => $subject->name_en] : null,
            'floor' => $profile?->floor,
            'grade_from' => $profile?->gradeFrom?->only(['id', 'name_ar', 'name_en']),
            'grade_to' => $profile?->gradeTo?->only(['id', 'name_ar', 'name_en']),
            'extra_subjects' => $profile?->extraSubjects->map(fn ($x) => ['subject_id' => $x->subject_id, 'grade_ids' => $x->grade_ids ?? []])->values() ?? [],
            'is_class_teacher' => (bool) $profile?->is_class_teacher,
            'degree' => $profile?->degree,
            'specialization' => $profile?->specialization,
            'university' => $profile?->university,
            'graduation_year' => $profile?->graduation_year,
            'experience_years' => $profile?->experience_years,
            'courses' => $profile?->courses,
            // Periods a week this year (0 for a teacher with none yet); only teaching staff have a load.
            'weekly_load' => $profile?->staff_type->category() === 'teaching' && array_key_exists('teaching_load', $this->getAttributes()) ? max(0, (int) $this->teaching_load - (self::jointDuplicates()[$this->id] ?? 0)) : null,
            'max_load' => $profile?->staff_type->category() === 'teaching' ? self::maxLoad() : null,
            'teaches_gender' => $profile?->teaches_gender,
            'classroom_ids' => $profile?->classrooms->pluck('id')->sort()->values() ?? [],
            'classrooms' => $profile?->classrooms->sortBy('name')->map->only(['id', 'name'])->values() ?? [],
        ];
    }

    /** The weekly limit of periods (the school's setting, otherwise 24), read once per request. */
    private static function maxLoad(): int
    {
        static $max = null;

        return $max ??= app(\App\Modules\Academic\Services\TeacherPlanner::class)->maxLoad();
    }

    /** Periods counted twice because of joint lessons, per teacher, read once per request. @return array<int, int> */
    private static function jointDuplicates(): array
    {
        $bag = request()->attributes;
        if (! $bag->has('joint_duplicates')) {
            $bag->set('joint_duplicates', app(\App\Modules\Scheduling\Services\JointLoad::class)->duplicates());
        }

        return $bag->get('joint_duplicates');
    }
}
