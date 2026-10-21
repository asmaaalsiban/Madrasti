<?php

namespace Tests\Feature;

use App\Central\Actions\SeedDemoSchool;
use App\Models\User;
use App\Shared\Enums\Role;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Tests\TenantTestCase;

class SeedDemoTest extends TenantTestCase
{
    private function seed_(): array
    {
        return app(SeedDemoSchool::class)->handle('admin@demo.test', 'Admin12345', 'demo.test', 'Teacher12345');
    }

    public function test_it_creates_admin_teachers_and_structure(): void
    {
        $counts = $this->seed_();

        $admin = User::where('email', 'admin@demo.test')->first();
        $this->assertSame(Role::Admin, $admin->role);
        $this->assertTrue(Hash::check('Admin12345', $admin->password));
        $this->assertSame(33, User::where('role', Role::Teacher)->count());
        $this->assertEqualsCanonicalizing(
            ['teacher' => 24, 'head_of_department' => 8, 'nurse' => 1],
            DB::table('staff_profiles')->selectRaw('staff_type, count(*) as n')->groupBy('staff_type')->pluck('n', 'staff_type')->all(),
        );
        $this->assertSame(32, DB::table('staff_profiles')->whereNotNull('subject_id')->count());
        $this->assertSame(8, DB::table('staff_profiles')->where('staff_type', 'head_of_department')->distinct()->count('subject_id'), 'one head for each subject');
        $this->assertSame([3], array_values(array_unique(DB::table('staff_profiles')->where('staff_type', 'teacher')->selectRaw('subject_id, count(*) as n')->groupBy('subject_id')->pluck('n')->all())), 'three teachers for each subject');
        $this->assertSame(['users' => 241, 'academic_years' => 1, 'grade_levels' => 6, 'subjects' => 8, 'classrooms' => 12, 'students' => 120], $counts);
        $this->assertSame(1, DB::table('academic_years')->where('is_current', true)->count());
        $this->assertSame(2, DB::table('terms')->count(), 'two terms in the year');
        $this->assertSame(12, DB::table('classrooms')->whereNotNull('homeroom_teacher_id')->count());
        $this->assertSame(12, DB::table('classrooms')->distinct()->count('homeroom_teacher_id'), 'a teacher is homeroom teacher of one classroom only');
        $this->assertSame(0, DB::table('classrooms')->join('staff_profiles', 'staff_profiles.user_id', '=', 'classrooms.homeroom_teacher_id')->where('staff_type', '!=', 'teacher')->count(), 'heads supervise, they are not homeroom teachers');
        $this->assertSame(96, DB::table('classroom_subject_teacher')->count(), 'every subject is taught in every classroom');
        $this->assertSame(4, DB::table('classroom_subject_teacher')->where('subject_id', DB::table('subjects')->where('code', 'MTH')->value('id'))->where('teacher_id', DB::table('users')->where('email', 'teacher7@demo.test')->value('id'))->count(), 'a teacher covers four classrooms of the subject');
    }

    public function test_demo_students_have_accounts_with_the_default_password_and_parents_are_shared(): void
    {
        $this->seed_();

        $this->assertFalse(User::where('email', 'admin@demo.test')->value('must_change_password'));
        $students = User::where('role', Role::Student)->get();
        $this->assertCount(120, $students);
        foreach ($students as $student) {
            $this->assertTrue($student->must_change_password);
            $this->assertTrue(Hash::check(config('accounts.default_password'), $student->password));
        }
        $this->assertContains('ahmad.d1001@school.edu', $students->pluck('email')->all());
        $this->assertSame(120, $students->pluck('email')->unique()->count());

        $this->assertSame(120, DB::table('enrollments')->count());
        $perClassroom = DB::table('enrollments')->selectRaw('classroom_id, count(*) as n')->groupBy('classroom_id')->pluck('n')->all();
        $this->assertSame([10], array_values(array_unique($perClassroom)), 'ten students in every classroom');

        $parents = User::where('role', Role::Guardian)->withCount('children')->get();
        $this->assertGreaterThan(80, $parents->count());
        $this->assertLessThan(120, $parents->count());
        $this->assertSame(120, $parents->sum('children_count'));
        $this->assertGreaterThan(1, $parents->max('children_count'), 'some parents have several children');
        $this->assertSame($parents->count(), $parents->whereNotNull('phone')->count());
    }

    public function test_running_twice_does_not_duplicate(): void
    {
        $first = $this->seed_();
        $second = $this->seed_();

        $this->assertSame($first, $second);
    }

    public function test_it_repairs_a_wrong_admin_password(): void
    {
        User::factory()->role(Role::Admin)->create(['email' => 'admin@demo.test', 'password' => 'something-else']);

        $this->seed_();

        $this->assertSame(1, User::where('email', 'admin@demo.test')->count());
        $this->assertTrue(Hash::check('Admin12345', User::where('email', 'admin@demo.test')->first()->password));
    }

    public function test_command_refuses_outside_local_environment(): void
    {
        $this->app['env'] = 'production';

        $this->artisan('school:seed-demo', ['tenant' => 'school2'])
            ->expectsOutputToContain('only allowed when APP_ENV=local')
            ->assertFailed();
        $this->assertSame(0, User::count());
    }

    public function test_command_reports_unknown_school(): void
    {
        $this->app['env'] = 'local';
        Schema::create('tenants', function ($t) {
            $t->string('id')->primary();
            $t->json('data')->nullable();
        });

        $this->artisan('school:seed-demo', ['tenant' => 'nope'])->expectsOutputToContain("School 'nope' not found.")->assertFailed();
    }

    public function test_demo_grades_exist_for_every_subject_in_every_classroom_and_are_consistent(): void
    {
        $this->seed_();

        $this->assertSame(96 * 4, DB::table('assessments')->count());
        $this->assertSame(0, DB::table('assessments')->where('is_published', false)->count());
        $firstTermId = DB::table('terms')->orderBy('starts_on')->value('id');
        $weights = DB::table('assessments')->where('term_id', $firstTermId)->selectRaw('classroom_id, subject_id, sum(weight) as w')->groupBy('classroom_id', 'subject_id')->pluck('w')->map(fn ($w) => (float) $w)->unique()->all();
        $this->assertSame([100.0], $weights, 'the first term adds up to 100%');
        $this->assertSame(0, DB::table('assessment_scores')->join('assessments', 'assessments.id', '=', 'assessment_scores.assessment_id')->whereColumn('score', '>', 'max_score')->count());
        $this->assertSame(96 * 4 * 10, DB::table('assessment_scores')->count(), 'a row for every student of every assessment');
        $this->assertGreaterThan(3000, DB::table('assessment_scores')->whereNotNull('score')->count());
        $this->assertSame(0, DB::table('assessments')->join('classroom_subject_teacher as cst', fn ($j) => $j->on('cst.classroom_id', '=', 'assessments.classroom_id')->on('cst.subject_id', '=', 'assessments.subject_id'))->whereColumn('cst.teacher_id', '!=', 'assessments.teacher_id')->count(), 'graded by the assigned teacher');
    }

    public function test_running_the_seeder_twice_does_not_duplicate_grades(): void
    {
        $this->seed_();
        $assessments = DB::table('assessments')->count();
        $scores = DB::table('assessment_scores')->count();
        $this->seed_();

        $this->assertSame($assessments, DB::table('assessments')->count());
        $this->assertSame($scores, DB::table('assessment_scores')->count());
    }
}
