<?php

declare(strict_types=1);

use App\Modules\Academic\Http\Controllers\AcademicYearController;
use App\Modules\Academic\Http\Controllers\AdminOverviewController;
use App\Modules\Academic\Http\Controllers\BuildingController;
use App\Modules\Academic\Http\Controllers\ClassroomController;
use App\Modules\Academic\Http\Controllers\CurriculumController;
use App\Modules\Academic\Http\Controllers\FloorController;
use App\Modules\Academic\Http\Controllers\GradeLevelController;
use App\Modules\Academic\Http\Controllers\RoomController;
use App\Modules\Academic\Http\Controllers\SchoolBrandingController;
use App\Modules\Academic\Http\Controllers\SubjectController;
use App\Modules\Academic\Http\Controllers\TeachingAssignmentController;
use App\Modules\Academic\Http\Controllers\TermController;
use App\Modules\Attendance\Http\Controllers\AttendanceController;
use App\Modules\Attendance\Http\Controllers\AttendanceReportController;
use App\Modules\Auth\Http\Controllers\AuthController;
use App\Modules\Grading\Http\Controllers\AssessmentController;
use App\Modules\Grading\Http\Controllers\GradingOptionsController;
use App\Modules\Grading\Http\Controllers\ResultsController;
use App\Modules\Grading\Http\Controllers\StudentGradesController;
use App\Modules\People\Http\Controllers\DepartmentController;
use App\Modules\People\Http\Controllers\GuardianController;
use App\Modules\People\Http\Controllers\StaffController;
use App\Modules\People\Http\Controllers\StaffTypeController;
use App\Modules\People\Http\Controllers\StudentController;
use App\Modules\People\Http\Controllers\UserPasswordController;
use Illuminate\Support\Facades\Route;

/*
| School API. Prefix "api" and tenancy middleware come from routes/tenant.php.
| Add one block per module below, protected with auth:sanctum and role:...
*/

// Public: school name, logo and colors for the login screen.
Route::get('school', SchoolBrandingController::class);

Route::prefix('auth')->group(function (): void {
    Route::post('login', [AuthController::class, 'login'])->middleware('throttle:login');

    Route::middleware('auth:sanctum')->group(function (): void {
        Route::post('logout', [AuthController::class, 'logout']);
        Route::get('me', [AuthController::class, 'me']);
        Route::post('change-password', [AuthController::class, 'changePassword'])->middleware('throttle:change-password');
    });
});

// School structure: admin only.
Route::middleware(['school-user', 'role:admin'])->group(function (): void {
    Route::get('admin/overview', AdminOverviewController::class);
    Route::apiResource('academic-years', AcademicYearController::class);
    Route::apiResource('grade-levels', GradeLevelController::class);
    Route::post('classrooms/generate', [ClassroomController::class, 'generate']);
    Route::apiResource('classrooms', ClassroomController::class);
    Route::apiResource('subjects', SubjectController::class);
    Route::get('curriculum', [CurriculumController::class, 'show']);
    Route::put('curriculum', [CurriculumController::class, 'save']);
    Route::post('curriculum/copy', [CurriculumController::class, 'copy']);
    Route::post('curriculum/standard-plan', [CurriculumController::class, 'standardPlan']);
    Route::apiResource('terms', TermController::class);
    // The building: buildings > floors > rooms (classrooms and labs).
    Route::apiResource('buildings', BuildingController::class)->except('show');
    Route::apiResource('floors', FloorController::class)->only('store', 'update', 'destroy');
    Route::get('rooms', [RoomController::class, 'index']);
    Route::put('rooms/{room}', [RoomController::class, 'update']);
    Route::post('teaching-assignments/auto', [TeachingAssignmentController::class, 'auto']);
    Route::apiResource('teaching-assignments', TeachingAssignmentController::class);

    // People: staff (every kind of school employee), students and their accounts.
    Route::get('staff-types', StaffTypeController::class);
    Route::apiResource('staff', StaffController::class);
    Route::apiResource('students', StudentController::class);
    Route::get('guardians', [GuardianController::class, 'index']);
    Route::post('users/{user}/reset-password', [UserPasswordController::class, 'reset']);
});

// Attendance: the homeroom teacher (or an admin) takes the classroom's day and sees the reports.
Route::middleware(['school-user', 'role:admin,teacher'])->prefix('attendance')->group(function (): void {
    Route::get('classrooms', [AttendanceController::class, 'classrooms']);
    Route::get('/', [AttendanceController::class, 'show']);
    Route::put('/', [AttendanceController::class, 'update']);
    Route::get('report', [AttendanceReportController::class, 'index']);
});

// One student's days: admin, the homeroom teacher, the student and the student's parents (checked by a gate).
Route::middleware('school-user')->get('attendance/students/{student}', [AttendanceReportController::class, 'student']);

// A head of department sees the teachers of their subject (the controller checks the staff type).
Route::middleware(['school-user', 'role:teacher'])->get('department', DepartmentController::class);

// Grades: the teacher of the subject (or an admin) creates assessments and enters the scores.
Route::middleware(['school-user', 'role:admin,teacher'])->group(function (): void {
    Route::get('grading/options', GradingOptionsController::class);
    Route::get('grading/results', ResultsController::class);
    Route::apiResource('assessments', AssessmentController::class)->except('store', 'update');
    Route::post('assessments', [AssessmentController::class, 'store']);
    Route::put('assessments/{assessment}', [AssessmentController::class, 'update']);
    Route::put('assessments/{assessment}/scores', [AssessmentController::class, 'scores']);
    Route::post('assessments/{assessment}/publish', [AssessmentController::class, 'publish']);
    Route::post('assessments/{assessment}/unpublish', [AssessmentController::class, 'unpublish']);
});

// The published grades of one student: the student, their parents and admins (checked by a gate).
Route::middleware('school-user')->get('students/{student}/grades', StudentGradesController::class);
