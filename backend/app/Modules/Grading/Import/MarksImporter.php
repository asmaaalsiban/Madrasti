<?php

declare(strict_types=1);

namespace App\Modules\Grading\Import;

use App\Modules\Academic\Import\Lookups;
use App\Modules\Academic\Models\Enrollment;
use App\Modules\Academic\Models\Student;
use App\Modules\Academic\Models\TeachingAssignment;
use App\Modules\Academic\Models\Term;
use App\Modules\Grading\Http\Requests\AssessmentRequest;
use App\Modules\Grading\Models\Assessment;
use App\Modules\Grading\Models\AssessmentScore;
use App\Modules\People\Import\Importer;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Marks of past or current work: one row = one student's mark in one assessment (a quiz, an exam...). The assessment is
 * created the first time its title is seen for that section, subject and term, and reused after; a second import of
 * the same file only updates the marks. The section is the one the student sat in that year.
 */
class MarksImporter extends Importer
{
    use Lookups;

    /** @var array<string, array<string, array{max: float, weight: float}>> assessments planned by this file, per section|subject|term */
    private array $planned = [];

    public function kind(): string
    {
        return 'marks';
    }

    public function columns(): array
    {
        return [
            'student_number' => ['en' => 'Student number', 'ar' => 'رقم الطالب', 'required' => true, 'example' => '2026001', 'aliases' => ['رقم الطالب', 'رقم الجلوس', 'رقم', 'student number', 'student id', 'id']],
            'subject' => ['en' => 'Subject', 'ar' => 'المادة', 'required' => true, 'example' => 'MATH', 'aliases' => ['المادة', 'المادة الدراسية', 'رمز المادة', 'subject', 'subject code']],
            'term' => ['en' => 'Term', 'ar' => 'الفصل', 'required' => true, 'example' => 'Term 1', 'aliases' => ['الفصل', 'الفصل الدراسي', 'الفصل الدراسى', 'term', 'semester']],
            'title' => ['en' => 'Assessment', 'ar' => 'التقييم', 'required' => true, 'example' => 'Midterm exam', 'aliases' => ['التقييم', 'الاختبار', 'اسم الاختبار', 'عنوان التقييم', 'assessment', 'exam', 'title', 'test']],
            'max_score' => ['en' => 'Out of', 'ar' => 'العلامة العظمى', 'required' => true, 'example' => '20', 'aliases' => ['العلامة العظمى', 'من', 'العلامة الكاملة', 'الدرجة العظمى', 'out of', 'max', 'max score', 'total']],
            'score' => ['en' => 'Mark', 'ar' => 'العلامة', 'required' => false, 'example' => '17.5', 'aliases' => ['العلامة', 'الدرجة', 'علامة الطالب', 'mark', 'score', 'grade']],
            'status' => ['en' => 'Status', 'ar' => 'الحالة', 'required' => false, 'example' => '', 'aliases' => ['الحالة', 'ملاحظة الحالة', 'status']],
            'type' => ['en' => 'Type', 'ar' => 'النوع', 'required' => false, 'example' => 'exam', 'aliases' => ['النوع', 'نوع التقييم', 'type', 'kind']],
            'weight' => ['en' => 'Weight %', 'ar' => 'الوزن %', 'required' => false, 'example' => '30', 'aliases' => ['الوزن', 'الوزن %', 'النسبة', 'weight']],
            'held_on' => ['en' => 'Date', 'ar' => 'التاريخ', 'required' => false, 'example' => '2026-11-10', 'aliases' => ['التاريخ', 'تاريخ الاختبار', 'date', 'held on']],
            'year' => ['en' => 'Academic year', 'ar' => 'السنة الدراسية', 'required' => false, 'example' => '2025-2026', 'aliases' => ['السنه الدراسيه', 'السنة', 'العام الدراسي', 'العام', 'year', 'academic year']],
            'published' => ['en' => 'Visible to parents', 'ar' => 'ظاهرة للأهل', 'required' => false, 'example' => 'yes', 'aliases' => ['ظاهره للاهل', 'ظاهرة', 'منشورة', 'نشر', 'published', 'visible']],
        ];
    }

    public function missingFields(array $mapped): array
    {
        $missing = array_diff(['student_number', 'subject', 'term', 'title', 'max_score'], $mapped);
        if (! in_array('score', $mapped, true) && ! in_array('status', $mapped, true)) {
            $missing[] = 'score';
        }

        return array_values($missing);
    }

    public function check(array $row): array
    {
        $errors = [];
        $number = trim((string) ($row['student_number'] ?? ''));
        $student = $number === '' ? null : Student::where('student_number', $number)->first();
        $student === null && $errors[] = __('import.unknown_student', ['value' => $number]);

        $year = isset($row['year']) && trim((string) $row['year']) !== '' ? $this->academicYearNamed((string) $row['year']) : \App\Modules\Academic\Models\AcademicYear::where('is_current', true)->first();
        if ($year === null) {
            $errors[] = isset($row['year']) && trim((string) $row['year']) !== '' ? __('import.unknown_year', ['name' => $row['year']]) : __('import.no_current_year');
        }

        $enrollment = $student && $year ? Enrollment::with('classroom')->where('student_id', $student->id)->where('academic_year_id', $year->id)->first() : null;
        $student && $year && $enrollment === null && $errors[] = __('import.student_no_section', ['value' => $number, 'year' => $year->name]);

        $subject = isset($row['subject']) ? $this->subjectNamed((string) $row['subject']) : null;
        isset($row['subject']) && $subject === null && $errors[] = __('import.unknown_subject', ['name' => $row['subject']]);
        $term = $year && isset($row['term']) ? $this->termNamed((string) $row['term'], $year->id) : null;
        $year && isset($row['term']) && $term === null && $errors[] = __('import.unknown_term', ['name' => $row['term']]);

        $title = trim((string) ($row['title'] ?? ''));
        $title === '' && $errors[] = __('import.title_needed');
        $type = $this->type($row['type'] ?? null);
        $type === null && $errors[] = __('import.bad_type', ['value' => $row['type'], 'types' => implode(', ', AssessmentRequest::TYPES)]);

        $max = $this->number($row['max_score'] ?? null);
        ($max === null || $max <= 0 || $max > 9999) && $errors[] = __('import.bad_max_score', ['value' => $row['max_score'] ?? '']);
        $weight = filled($row['weight'] ?? null) ? $this->number($row['weight']) : 0.0;
        ($weight === null || $weight < 0 || $weight > 100) && $errors[] = __('import.bad_weight', ['value' => $row['weight'] ?? '']);

        $status = $this->status($row['status'] ?? null);
        $status === false && $errors[] = __('import.bad_status', ['value' => $row['status']]);
        $score = filled($row['score'] ?? null) ? $this->number($row['score']) : null;
        // Schools often write "غ" (absent) or "إعادة" in the mark column itself.
        if (filled($row['score'] ?? null) && $score === null && ! filled($row['status'] ?? null) && ($fromMark = $this->status((string) $row['score'])) !== false && $fromMark !== null) {
            $status = $fromMark;
            $row['score'] = null;
        }
        filled($row['score'] ?? null) && ($score === null || $score < 0) && $errors[] = __('import.bad_mark', ['value' => $row['score']]);
        if ($score === null && ! filled($row['score'] ?? null) && ($status === null || $status === false) && $status !== false) {
            $errors[] = __('import.mark_needed');
        }
        if ($status === 'absent' && $score !== null) {
            $errors[] = __('grading.absent_has_score');
        }
        if ($score !== null && $max !== null && $max > 0 && $score > $max) {
            $errors[] = __('grading.score_above_max', ['max' => $max]);
        }

        $held = null;
        if (filled($row['held_on'] ?? null)) {
            try {
                $held = Carbon::parse((string) $row['held_on'])->format('Y-m-d');
            } catch (\Throwable) {
                $errors[] = __('import.bad_date', ['value' => $row['held_on']]);
            }
        }
        $published = ! in_array(mb_strtolower(trim((string) ($row['published'] ?? ''))), ['no', 'n', 'false', '0', 'لا', 'غير منشور'], true);

        $classroom = $enrollment?->classroom;
        $assignment = $classroom && $subject ? TeachingAssignment::where('classroom_id', $classroom->id)->where('subject_id', $subject->id)->first() : null;
        $classroom && $subject && $assignment === null && $errors[] = __('import.no_teacher_for', ['classroom' => $classroom->name, 'subject' => $subject->name_ar]);

        if ($errors === [] && $classroom && $subject && $term && $student) {
            $errors = [...$errors, ...$this->assessmentProblems($classroom->id, $subject->id, $term->id, $title, (float) $max, (float) $weight, $student->id)];
        }
        if ($errors !== []) {
            return [null, $errors];
        }

        return [[
            'classroom_id' => $classroom->id, 'subject_id' => $subject->id, 'term_id' => $term->id, 'teacher_id' => $assignment->teacher_id,
            'title' => $title, 'type' => $type, 'max_score' => (float) $max, 'weight' => (float) $weight, 'held_on' => $held, 'is_published' => $published,
            'student_id' => $student->id, 'score' => $score, 'status' => $status,
        ], []];
    }

    public function create(array $data): array
    {
        return DB::transaction(function () use ($data): array {
            $assessment = Assessment::firstOrCreate(
                ['classroom_id' => $data['classroom_id'], 'subject_id' => $data['subject_id'], 'term_id' => $data['term_id'], 'title' => $data['title']],
                ['teacher_id' => $data['teacher_id'], 'type' => $data['type'], 'max_score' => $data['max_score'], 'weight' => $data['weight'], 'held_on' => $data['held_on'], 'is_published' => $data['is_published']],
            );
            AssessmentScore::updateOrCreate(
                ['assessment_id' => $assessment->id, 'student_id' => $data['student_id']],
                ['score' => $data['score'], 'status' => $data['status']],
            );
            $student = Student::find($data['student_id']);

            return ['student_number' => $student->student_number, 'name' => $student->name_ar, 'assessment' => $assessment->title, 'score' => $data['score'] ?? $data['status']];
        });
    }

    /** The rules that look at the whole assessment: the same title keeps one maximum and weight, the weights of a subject in a term add up to 100 at most, a student is graded once. */
    private function assessmentProblems(int $classroom, int $subject, int $term, string $title, float $max, float $weight, int $student): array
    {
        $key = "$classroom|$subject|$term";
        $existing = Assessment::where('classroom_id', $classroom)->where('subject_id', $subject)->where('term_id', $term)->get();
        $same = $existing->first(fn (Assessment $a) => mb_strtolower($a->title) === mb_strtolower($title));

        if ($this->repeated('mark', "$key|".mb_strtolower($title)."|$student")) {
            return [__('import.duplicate_in_file', ['value' => $title])];
        }
        if ($same !== null) {
            return abs($same->max_score - $max) > 0.0001 ? [__('import.assessment_differs', ['title' => $title, 'max' => $same->max_score])] : [];
        }

        $planned = $this->planned[$key] ??= [];
        $name = mb_strtolower($title);
        if (isset($planned[$name])) {
            return abs($planned[$name]['max'] - $max) > 0.0001 || abs($planned[$name]['weight'] - $weight) > 0.0001 ? [__('import.assessment_differs', ['title' => $title, 'max' => $planned[$name]['max']])] : [];
        }
        $used = (float) $existing->sum('weight') + array_sum(array_column($planned, 'weight'));
        if ($weight > 0 && $used + $weight > 100) {
            return [__('grading.weight_over_100', ['used' => rtrim(rtrim(number_format($used, 2, '.', ''), '0'), '.')])];
        }
        $this->planned[$key][$name] = ['max' => $max, 'weight' => $weight];

        return [];
    }

    private function number(mixed $value): ?float
    {
        return \App\Shared\Import\Numbers::parse($value);
    }

    /** The term by its name, or by its number in the year ("1" = the first). */
    private function termNamed(string $value, int $yearId): ?Term
    {
        $terms = ($this->memo['terms'.$yearId] ??= Term::where('academic_year_id', $yearId)->orderBy('starts_on')->orderBy('id')->get());
        $wanted = $this->norm($value);
        $byName = $terms->first(fn (Term $t) => $this->norm($t->name) === $wanted);
        if ($byName !== null) {
            return $byName;
        }
        $ordinals = [['1', 'اول', 'الاول', 'first', '1st'], ['2', 'ثاني', 'الثاني', 'second', '2nd'], ['3', 'ثالث', 'الثالث', 'third', '3rd']];
        foreach ($ordinals as $index => $words) {
            if (in_array($wanted, $words, true)) {
                return $terms->values()->get($index);
            }
        }

        return null;
    }

    /** Assessment type; empty means an exam. */
    private function type(?string $value): ?string
    {
        $v = $this->norm((string) $value);
        if ($v === '') {
            return 'exam';
        }
        $words = [
            'exam' => ['exam', 'اختبار', 'امتحان', 'اختبارنهائي', 'نهائي', 'منتصففصل', 'midterm', 'final'],
            'quiz' => ['quiz', 'اختبارقصير', 'اختبارمصغر', 'كويز', 'مذاكره', 'مذاكرة'],
            'assignment' => ['assignment', 'واجب', 'وظيفه', 'وظيفة', 'homework'],
            'project' => ['project', 'مشروع', 'بحث'],
            'participation' => ['participation', 'مشاركه', 'مشاركة', 'سلوك'],
        ];
        foreach ($words as $type => $list) {
            if (in_array($v, array_map(fn ($w) => $this->norm($w), $list), true)) {
                return $type;
            }
        }

        return null;
    }

    /** null = none, false = not understood. */
    private function status(?string $value): string|false|null
    {
        $v = $this->norm((string) $value);
        if ($v === '') {
            return null;
        }
        foreach (['absent' => ['absent', 'غائب', 'غايب', 'غياب', 'غ', 'غائبه', 'abs'], 'makeup' => ['makeup', 'تعويضي', 'تعويض', 'اعاده اختبار'], 'retake' => ['retake', 'اعاده', 'إعادة', 'رسوب', 'راسب']] as $status => $list) {
            if (in_array($v, array_map(fn ($w) => $this->norm($w), $list), true)) {
                return $status;
            }
        }

        return false;
    }
}
