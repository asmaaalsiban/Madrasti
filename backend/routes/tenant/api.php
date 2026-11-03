<?php

declare(strict_types=1);

use App\Modules\Academic\Http\Controllers\AcademicYearController;
use App\Modules\Academic\Http\Controllers\AdminOverviewController;
use App\Modules\Academic\Http\Controllers\BuildingController;
use App\Modules\Academic\Http\Controllers\ClassroomController;
use App\Modules\Academic\Http\Controllers\CurriculumController;
use App\Modules\Academic\Http\Controllers\FloorController;
use App\Modules\Academic\Http\Controllers\GradeLevelController;
use App\Modules\Academic\Http\Controllers\RenewalController;
use App\Modules\Academic\Http\Controllers\RoomController;
use App\Modules\Academic\Http\Controllers\SchoolBrandingController;
use App\Modules\Academic\Http\Controllers\SubjectController;
use App\Modules\Academic\Http\Controllers\TeachingAssignmentController;
use App\Modules\Academic\Http\Controllers\TermController;
use App\Modules\Attendance\Http\Controllers\AttendanceController;
use App\Modules\Attendance\Http\Controllers\AttendanceReportController;
use App\Modules\Audit\Http\Controllers\AuditLogController;
use App\Modules\Auth\Http\Controllers\AuthController;
use App\Modules\Auth\Http\Controllers\LayoutController;
use App\Modules\Communication\Http\Controllers\AnnouncementController;
use App\Modules\Communication\Http\Controllers\BadgesController;
use App\Modules\Communication\Http\Controllers\ChatController;
use App\Modules\Communication\Http\Controllers\NotificationController;
use App\Modules\Communication\Http\Controllers\PushSubscriptionController;
use App\Modules\Communication\Http\Controllers\StoryController;
use App\Modules\Finance\Http\Controllers\FinanceController;
use App\Modules\Finance\Http\Controllers\OnlinePaymentController;
use App\Modules\Finance\Http\Controllers\StudentFeesController;
use App\Modules\Grading\Http\Controllers\AssessmentController;
use App\Modules\Grading\Http\Controllers\GradingOptionsController;
use App\Modules\Grading\Http\Controllers\ResultsController;
use App\Modules\Grading\Http\Controllers\StudentGradesController;
use App\Modules\HR\Http\Controllers\ContractController;
use App\Modules\HR\Http\Controllers\PayrollController;
use App\Modules\HR\Http\Controllers\StaffAttendanceController;
use App\Modules\Motivation\Http\Controllers\BadgeController;
use App\Modules\Motivation\Http\Controllers\MotivationController;
use App\Modules\Motivation\Http\Controllers\PointCategoryController;
use App\Modules\People\Http\Controllers\DepartmentController;
use App\Modules\People\Http\Controllers\GuardianController;
use App\Modules\People\Http\Controllers\ImportController;
use App\Modules\People\Http\Controllers\StaffController;
use App\Modules\People\Http\Controllers\StaffTypeController;
use App\Modules\People\Http\Controllers\StaffVisibilityController;
use App\Modules\People\Http\Controllers\StudentController;
use App\Modules\People\Http\Controllers\UserPasswordController;
use App\Modules\Procurement\Http\Controllers\ProcurementController;
use App\Modules\Reports\Http\Controllers\ReportCardController;
use App\Modules\Reports\Http\Controllers\SignatureController;
use App\Modules\Scheduling\Http\Controllers\DutyController;
use App\Modules\Scheduling\Http\Controllers\ExamController;
use App\Modules\Scheduling\Http\Controllers\PeriodController;
use App\Modules\Scheduling\Http\Controllers\TimetableController;
use App\Modules\Surveys\Http\Controllers\MySurveyController;
use App\Modules\Surveys\Http\Controllers\SurveyController;
use App\Modules\Tasks\Http\Controllers\TaskController;
use App\Modules\TeacherTools\Http\Controllers\BehaviorController;
use App\Modules\TeacherTools\Http\Controllers\BehaviorCriteriaController;
use App\Modules\TeacherTools\Http\Controllers\BehaviorSpecialController;
use App\Modules\TeacherTools\Http\Controllers\DailySummaryController;
use App\Modules\TeacherTools\Http\Controllers\HomeworkController;
use App\Modules\TeacherTools\Http\Controllers\HomeworkFileController;
use App\Modules\TeacherTools\Http\Controllers\HomeworkSubmissionController;
use App\Modules\TeacherTools\Http\Controllers\LessonPlanController;
use App\Modules\Transport\Http\Controllers\TransportController;
use App\Shared\Attachments\AttachmentController;
use Illuminate\Support\Facades\Route;

/*
| School API. Prefix "api" and tenancy middleware come from routes/tenant.php.
| Add one block per module below, protected with auth:sanctum and role:...
*/

// Public: school name, logo and colors for the login screen.
Route::get('school', SchoolBrandingController::class);

Route::prefix('auth')->group(function (): void {
    Route::post('login', [AuthController::class, 'login'])->middleware('throttle:login');
    Route::post('forgot-password', [AuthController::class, 'forgotPassword'])->middleware('throttle:forgot-password');
    Route::post('reset-password', [AuthController::class, 'resetPassword'])->middleware('throttle:reset-password');

    Route::middleware('auth:sanctum')->group(function (): void {
        Route::post('logout', [AuthController::class, 'logout']);
        Route::get('me', [AuthController::class, 'me']);
        Route::post('change-password', [AuthController::class, 'changePassword'])->middleware('throttle:change-password');
    });
});

// How a person arranged their own screens (a signed-in, active account that has chosen its own password).
Route::middleware('school-user')->put('auth/layout', [LayoutController::class, 'update']);

// A parent signed in by phone number puts a verified email on the account (after choosing their own password).
Route::middleware(['school-user', 'throttle:email-code'])->prefix('auth')->group(function (): void {
    Route::post('email/request', [AuthController::class, 'requestEmail']);
    Route::post('email/confirm', [AuthController::class, 'confirmEmail']);
});

// Polls, surveys and parent approvals: admin and teachers write them, parents answer.
Route::middleware(['school-user', 'role:admin,teacher'])->prefix('surveys')->group(function (): void {
    Route::get('/', [SurveyController::class, 'index']);
    Route::post('/', [SurveyController::class, 'store']);
    Route::get('staff', [SurveyController::class, 'staff']);
    Route::get('{survey}', [SurveyController::class, 'show']);
    Route::get('{survey}/export', [SurveyController::class, 'export']);
    Route::post('{survey}/close', [SurveyController::class, 'close']);
    Route::post('{survey}/remind', [SurveyController::class, 'remind']);
    Route::delete('{survey}', [SurveyController::class, 'destroy']);
});
Route::middleware(['school-user', 'role:parent,student,teacher,admin'])->prefix('my-surveys')->group(function (): void {
    Route::get('/', [MySurveyController::class, 'index']);
    Route::get('pending', [MySurveyController::class, 'pending']);
    Route::post('{recipient}/respond', [MySurveyController::class, 'respond']);
});

// Staff working day: everyone checks in and out; the principal can fix any day.
Route::middleware(['school-user', 'role:admin,teacher'])->prefix('staff-attendance')->group(function (): void {
    Route::get('me', [StaffAttendanceController::class, 'me']);
    Route::post('check-in', [StaffAttendanceController::class, 'checkIn']);
    Route::post('check-out', [StaffAttendanceController::class, 'checkOut']);
    Route::get('day', [StaffAttendanceController::class, 'day']);
    Route::put('{user}', [StaffAttendanceController::class, 'set']);
});

// Audit log: who created, changed or deleted what (school management only).
Route::middleware(['school-user', 'role:admin'])->prefix('audit-logs')->group(function (): void {
    Route::get('/', [AuditLogController::class, 'index']);
    Route::get('filters', [AuditLogController::class, 'filters']);
    Route::get('export', [AuditLogController::class, 'export']);
});

// The purchase board: staff ask, the principal approves, the accountant records the purchase.
Route::middleware(['school-user', 'role:admin,teacher'])->prefix('procurement')->group(function (): void {
    Route::get('/', [ProcurementController::class, 'index']);
    Route::post('/', [ProcurementController::class, 'store']);
    Route::put('{procurement}', [ProcurementController::class, 'update']);
    Route::delete('{procurement}', [ProcurementController::class, 'destroy']);
    Route::post('{procurement}/approve', [ProcurementController::class, 'approve']);
    Route::post('{procurement}/reject', [ProcurementController::class, 'reject']);
    Route::post('{procurement}/purchase', [ProcurementController::class, 'purchase']);
    Route::post('{procurement}/receive', [ProcurementController::class, 'receive']);
});

// The class feed (stories): everyone reads their own; teachers and the principal post.
Route::middleware('school-user')->prefix('stories')->group(function (): void {
    Route::get('/', [StoryController::class, 'index']);
    Route::get('targets', [StoryController::class, 'targets']);
    Route::post('/', [StoryController::class, 'store']);
    Route::get('media/{media}', [StoryController::class, 'media']);
    Route::delete('comments/{comment}', [StoryController::class, 'removeComment']);
    Route::put('{story}', [StoryController::class, 'update']);
    Route::delete('{story}', [StoryController::class, 'destroy']);
    Route::post('{story}/react', [StoryController::class, 'react']);
    Route::get('{story}/comments', [StoryController::class, 'comments']);
    Route::post('{story}/comments', [StoryController::class, 'comment']);
});

// Yearly re-registration of students: school management and the accountant.
Route::middleware(['school-user', 'role:admin,teacher'])->prefix('renewals')->group(function (): void {
    Route::get('/', [RenewalController::class, 'index']);
    Route::get('sections', [RenewalController::class, 'sections']);
    Route::post('open', [RenewalController::class, 'open']);
    Route::post('renew-all', [RenewalController::class, 'renewAll']);
    Route::post('{renewal}/renew', [RenewalController::class, 'renew']);
    Route::post('{renewal}/decline', [RenewalController::class, 'decline']);
    Route::post('{renewal}/reopen', [RenewalController::class, 'reopen']);
});

// Staff contracts and payroll: the accountant manages, the principal (admin role) approves, everyone reads their own payslips.
Route::middleware(['school-user', 'role:admin,teacher'])->prefix('payroll')->group(function (): void {
    Route::get('staff', [ContractController::class, 'staff']);
    Route::get('contracts/expiring', [ContractController::class, 'expiring']);
    Route::get('staff/{user}/contracts', [ContractController::class, 'history']);
    Route::post('contracts', [ContractController::class, 'store']);
    Route::put('contracts/{contract}', [ContractController::class, 'update']);
    Route::post('contracts/{contract}/renew', [ContractController::class, 'renew']);
    Route::post('contracts/{contract}/end', [ContractController::class, 'end']);
    Route::get('runs', [PayrollController::class, 'runs']);
    Route::post('runs', [PayrollController::class, 'prepare']);
    Route::get('runs/{run}', [PayrollController::class, 'show']);
    Route::delete('runs/{run}', [PayrollController::class, 'destroy']);
    Route::post('runs/{run}/submit', [PayrollController::class, 'submit']);
    Route::post('runs/{run}/refresh-commitment', [PayrollController::class, 'refreshCommitment']);
    Route::post('runs/{run}/approve', [PayrollController::class, 'approve']);
    Route::post('runs/{run}/send-back', [PayrollController::class, 'sendBack']);
    Route::post('runs/{run}/pay', [PayrollController::class, 'pay']);
    Route::put('payslips/{payslip}/payment', [PayrollController::class, 'setPayment']);
    Route::post('payslips/{payslip}/items', [PayrollController::class, 'addItem']);
    Route::delete('payslip-items/{item}', [PayrollController::class, 'removeItem']);
    Route::delete('payslips/{payslip}', [PayrollController::class, 'removePayslip']);
    Route::get('templates', [PayrollController::class, 'templates']);
    Route::post('templates', [PayrollController::class, 'storeTemplate']);
    Route::put('templates/{template}', [PayrollController::class, 'updateTemplate']);
    Route::delete('templates/{template}', [PayrollController::class, 'destroyTemplate']);
    Route::get('templates/{template}/assignments', [PayrollController::class, 'assignments']);
    Route::post('templates/{template}/assignments', [PayrollController::class, 'assign']);
    Route::delete('assignments/{assignment}', [PayrollController::class, 'unassign']);
    Route::post('runs/{run}/apply-template', [PayrollController::class, 'applyTemplate']);
    Route::get('rules', [StaffAttendanceController::class, 'rules']);
    Route::put('rules', [StaffAttendanceController::class, 'saveRules']);
    Route::get('commitment', [StaffAttendanceController::class, 'commitment']);
    Route::get('my-contract', [PayrollController::class, 'myContract']);
    Route::get('payslips/mine', [PayrollController::class, 'mine']);
    Route::get('payslips/{payslip}', [PayrollController::class, 'payslip']);
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
    Route::get('periods', [PeriodController::class, 'index']);
    Route::put('periods/setup', [PeriodController::class, 'setup']);
    Route::put('periods/grades', [PeriodController::class, 'grades']);
    Route::get('duties/posts', [DutyController::class, 'posts']);
    Route::post('duties/posts', [DutyController::class, 'storePost']);
    Route::put('duties/posts/{post}', [DutyController::class, 'updatePost']);
    Route::delete('duties/posts/{post}', [DutyController::class, 'destroyPost']);
    Route::get('duties/staff', [DutyController::class, 'staff']);
    Route::put('duties/staff', [DutyController::class, 'saveStaff']);
    Route::post('duties/generate', [DutyController::class, 'generate']);
    Route::get('duties', [DutyController::class, 'index']);
    Route::post('duties', [DutyController::class, 'store']);
    Route::put('duties/{duty}', [DutyController::class, 'update']);
    Route::delete('duties/{duty}', [DutyController::class, 'destroy']);
    Route::get('exams', [ExamController::class, 'index']);
    Route::delete('exams', [ExamController::class, 'destroyMany']);
    Route::put('exams', [ExamController::class, 'updateMany']);
    Route::post('exams/generate', [ExamController::class, 'generate']);
    Route::put('exams/{session}', [ExamController::class, 'update']);
    Route::delete('exams/{session}', [ExamController::class, 'destroy']);
    Route::post('timetable/generate', [TimetableController::class, 'generate']);
    Route::get('timetable', [TimetableController::class, 'show']);
    Route::put('timetable/entries', [TimetableController::class, 'place']);
    Route::put('timetable/entries/{entry}/lock', [TimetableController::class, 'lock']);
    Route::delete('timetable/entries/{entry}', [TimetableController::class, 'destroy']);
    Route::get('teachers/{user}/unavailability', [TimetableController::class, 'unavailability']);
    Route::put('teachers/{user}/unavailability', [TimetableController::class, 'saveUnavailability']);
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
    Route::get('import/{kind}/template', [ImportController::class, 'template'])->whereIn('kind', ['students', 'staff']);
    Route::post('import/{kind}/analyze', [ImportController::class, 'analyze'])->whereIn('kind', ['students', 'staff']);
    Route::post('import/{kind}', [ImportController::class, 'store'])->whereIn('kind', ['students', 'staff']);
    Route::get('guardians', [GuardianController::class, 'index']);
    Route::post('users/{user}/reset-password', [UserPasswordController::class, 'reset']);
});

// Attendance: the homeroom teacher (or an admin) takes the classroom's day and sees the reports.
Route::middleware(['school-user', 'role:admin,teacher'])->prefix('attendance')->group(function (): void {
    Route::get('classrooms', [AttendanceController::class, 'classrooms']);
    Route::get('/', [AttendanceController::class, 'show']);
    Route::put('/', [AttendanceController::class, 'update']);
    Route::get('report', [AttendanceReportController::class, 'index']);
    Route::get('report/export', [AttendanceReportController::class, 'export']);
});

// One student's days: admin, the homeroom teacher, the student and the student's parents (checked by a gate).
Route::middleware('school-user')->get('attendance/students/{student}', [AttendanceReportController::class, 'student']);

// The week of the signed-in teacher or student, and of a student (the student, their parents, admins: a gate checks).
Route::middleware('school-user')->get('duties/me', [DutyController::class, 'mine']);
Route::middleware('school-user')->get('exams/me', [ExamController::class, 'mine']);
Route::middleware('school-user')->get('students/{student}/exams', [ExamController::class, 'forStudent']);
Route::middleware('school-user')->get('timetable/me', [TimetableController::class, 'mine']);
Route::middleware('school-user')->get('students/{student}/timetable', [TimetableController::class, 'forStudent']);

// A head of department sees the teachers of their subject (the controller checks the staff type).
Route::middleware(['school-user', 'role:teacher'])->get('department', DepartmentController::class);

// Grades: the teacher of the subject (or an admin) creates assessments and enters the scores.
Route::middleware(['school-user', 'role:admin,teacher'])->group(function (): void {
    Route::get('grading/options', GradingOptionsController::class);
    Route::get('grading/results', ResultsController::class);
    Route::get('grading/results/export', [ResultsController::class, 'export']);
    Route::apiResource('assessments', AssessmentController::class)->except('store', 'update');
    Route::post('assessments', [AssessmentController::class, 'store']);
    Route::put('assessments/{assessment}', [AssessmentController::class, 'update']);
    Route::put('assessments/{assessment}/scores', [AssessmentController::class, 'scores']);
    Route::post('assessments/{assessment}/publish', [AssessmentController::class, 'publish']);
    Route::post('assessments/{assessment}/unpublish', [AssessmentController::class, 'unpublish']);
});

// Daily summary: the teacher writes what a lesson covered; the section's students and their parents read it.
Route::middleware(['school-user', 'role:teacher'])->group(function (): void {
    Route::get('daily-summaries', [DailySummaryController::class, 'index']);
    Route::put('daily-summaries', [DailySummaryController::class, 'save']);
    Route::delete('daily-summaries/{summary}', [DailySummaryController::class, 'destroy']);
});
Route::middleware('school-user')->get('daily-summaries/me', [DailySummaryController::class, 'mine']);
Route::middleware('school-user')->get('students/{student}/daily-summaries', [DailySummaryController::class, 'forStudent']);

// Chat: direct conversations and section groups, with photos and read receipts.
Route::middleware('school-user')->prefix('chat')->group(function (): void {
    Route::get('contacts', [ChatController::class, 'contacts']);
    Route::get('unread-count', [ChatController::class, 'unreadCount']);
    Route::get('conversations', [ChatController::class, 'conversations']);
    Route::post('conversations', [ChatController::class, 'direct']);
    Route::post('groups', [ChatController::class, 'group']);
    Route::get('conversations/{id}', [ChatController::class, 'show'])->whereNumber('id');
    Route::get('conversations/{id}/messages', [ChatController::class, 'messages'])->whereNumber('id');
    Route::post('conversations/{id}/messages', [ChatController::class, 'send'])->whereNumber('id');
    Route::delete('messages/{id}', [ChatController::class, 'destroy'])->whereNumber('id');
    Route::get('messages/{id}/image', [ChatController::class, 'image'])->whereNumber('id');
});

// All the header counters in one answer (notifications, announcements, chat, surveys).
Route::middleware('school-user')->get('badges', BadgesController::class);

// The bell: notifications of the signed-in user.
Route::middleware('school-user')->prefix('notifications')->group(function (): void {
    Route::get('/', [NotificationController::class, 'index']);
    Route::get('unread-count', [NotificationController::class, 'unread']);
    Route::post('read-all', [NotificationController::class, 'readAll']);
    Route::post('{id}/read', [NotificationController::class, 'read']);
});

// Notifications on the phone / browser (web push): availability, allow, stop.
Route::middleware('school-user')->prefix('push')->group(function (): void {
    Route::get('config', [PushSubscriptionController::class, 'config']);
    Route::post('subscribe', [PushSubscriptionController::class, 'subscribe']);
    Route::post('unsubscribe', [PushSubscriptionController::class, 'unsubscribe']);
});

// Announcements: the admin (to everyone, a role or a section) and teachers (to their sections) publish; everyone reads theirs.
Route::middleware('school-user')->prefix('announcements')->group(function (): void {
    Route::get('/', [AnnouncementController::class, 'index']);
    Route::get('unread-count', [AnnouncementController::class, 'unread']);
    Route::get('options', [AnnouncementController::class, 'options']);
    Route::post('/', [AnnouncementController::class, 'store']);
    Route::post('{id}/read', [AnnouncementController::class, 'read'])->whereNumber('id');
    Route::put('{announcement}', [AnnouncementController::class, 'update']);
    Route::delete('{announcement}', [AnnouncementController::class, 'destroy']);
});

// Finance: the accountant manages fees and payments, the admin reads; a parent reads the statement of their child.
Route::middleware(['school-user', 'role:admin,teacher'])->prefix('finance')->group(function (): void {
    Route::get('options', [FinanceController::class, 'options']);
    Route::get('summary', [FinanceController::class, 'summary']);
    Route::get('accounts', [FinanceController::class, 'accounts']);
    Route::get('accounts/export', [FinanceController::class, 'exportAccounts']);
    Route::get('students/{student}', [FinanceController::class, 'statement']);
    Route::post('students/{student}/fees', [FinanceController::class, 'storeFee']);
    Route::put('students/{student}/transport', [FinanceController::class, 'setTransport']);
    Route::get('settings', [FinanceController::class, 'settings']);
    Route::put('settings', [FinanceController::class, 'saveSettings']);
    Route::get('fee-structures', [FinanceController::class, 'structures']);
    Route::post('fee-structures', [FinanceController::class, 'storeStructure']);
    Route::put('fee-structures/{structure}', [FinanceController::class, 'updateStructure']);
    Route::delete('fee-structures/{structure}', [FinanceController::class, 'destroyStructure']);
    Route::post('fee-structures/{structure}/apply', [FinanceController::class, 'applyStructure']);
    Route::put('fees/{fee}', [FinanceController::class, 'updateFee']);
    Route::delete('fees/{fee}', [FinanceController::class, 'destroyFee']);
    Route::post('payments', [FinanceController::class, 'pay']);
    Route::delete('payments/{payment}', [FinanceController::class, 'voidPayment']);
});
Route::middleware('school-user')->get('students/{student}/fees', StudentFeesController::class);

// Online payment by a parent. The webhook is called by the payment provider, not by a signed-in user.
Route::middleware('school-user')->group(function (): void {
    Route::post('students/{student}/online-payments', [OnlinePaymentController::class, 'start']);
    Route::post('online-payments/{reference}/demo-confirm', [OnlinePaymentController::class, 'demoConfirm'])->middleware('throttle:20,1');
    Route::get('online-payments/{reference}', [OnlinePaymentController::class, 'status']);
});
Route::post('payments/webhook/{provider}', [OnlinePaymentController::class, 'webhook'])->middleware('throttle:120,1');

// Lesson plans: the teacher writes and submits, the head of department reviews, approved shared plans reach students and parents.
Route::middleware(['school-user', 'role:teacher'])->group(function (): void {
    Route::get('lesson-plans', [LessonPlanController::class, 'index']);
    Route::put('lesson-plans', [LessonPlanController::class, 'save']);
    Route::post('lesson-plans/{plan}/submit', [LessonPlanController::class, 'submit']);
    Route::post('lesson-plans/{plan}/withdraw', [LessonPlanController::class, 'withdraw']);
    Route::delete('lesson-plans/{plan}', [LessonPlanController::class, 'destroy']);
    // Only a head of department passes the controller's check: they review the plans of their department's teachers.
    Route::get('lesson-plans/review', [LessonPlanController::class, 'review']);
    Route::post('lesson-plans/{plan}/review', [LessonPlanController::class, 'decide']);
});
Route::middleware('school-user')->get('lesson-plans/me', [LessonPlanController::class, 'mine']);
Route::middleware('school-user')->get('students/{student}/lesson-plans', [LessonPlanController::class, 'forStudent']);

// Behaviour evaluation: teachers evaluate the students they teach each week (months and terms are built from the weeks).
Route::middleware(['school-user', 'role:admin,teacher'])->prefix('behavior')->group(function (): void {
    Route::get('criteria', [BehaviorCriteriaController::class, 'index']);
    Route::post('criteria', [BehaviorCriteriaController::class, 'store']); // a teacher can add a permanent criterion too
    Route::get('classrooms', [BehaviorController::class, 'classrooms']);
    Route::get('week', [BehaviorController::class, 'week']);
    Route::put('evaluations', [BehaviorController::class, 'save']);
    Route::post('notes', [BehaviorController::class, 'addNote']);
    Route::delete('notes/{note}', [BehaviorController::class, 'deleteNote']);
    // A student's special evaluation: criteria for that student alone.
    Route::get('students/{student}/special', [BehaviorSpecialController::class, 'index']);
    Route::post('students/{student}/special', [BehaviorSpecialController::class, 'store']);
    Route::put('special/{criterion}', [BehaviorSpecialController::class, 'update']);
    Route::delete('special/{criterion}', [BehaviorSpecialController::class, 'destroy']);
});
Route::middleware(['school-user', 'role:admin'])->prefix('behavior/criteria')->group(function (): void {
    Route::put('{criterion}', [BehaviorCriteriaController::class, 'update']);
    Route::delete('{criterion}', [BehaviorCriteriaController::class, 'destroy']);
});
Route::middleware('school-user')->get('students/{student}/behavior', [BehaviorController::class, 'student']);
Route::middleware('school-user')->get('students/{student}/report-card', ReportCardController::class);
// Signatures printed on the report card: the admin manages the school's and anyone's; a teacher only their own.
Route::middleware(['school-user', 'role:admin,teacher'])->prefix('signatures')->group(function (): void {
    Route::get('/', [SignatureController::class, 'index']);
    Route::post('/', [SignatureController::class, 'store']);
    Route::post('{signature}/reprocess', [SignatureController::class, 'reprocess']);
    Route::put('{signature}', [SignatureController::class, 'update']);
    Route::delete('{signature}', [SignatureController::class, 'destroy']);
});

// Motivation: points, badges, star of the week.
Route::middleware(['school-user', 'role:admin,teacher'])->prefix('motivation')->group(function (): void {
    Route::get('classroom', [MotivationController::class, 'classroom']);
    Route::post('awards', [MotivationController::class, 'award']);
    Route::put('star', [MotivationController::class, 'crown']);
    Route::delete('awards/{award}', [MotivationController::class, 'undo']);
    Route::post('students/{student}/badges', [MotivationController::class, 'giveBadge']);
    Route::get('categories', [PointCategoryController::class, 'index']);
    Route::post('categories', [PointCategoryController::class, 'store']); // a teacher can add a reason with its points
    Route::get('badges', [BadgeController::class, 'index']);
    Route::post('badges', [BadgeController::class, 'store']); // a teacher can create a badge of their own
});
Route::middleware(['school-user', 'role:admin'])->prefix('motivation')->group(function (): void {
    Route::put('categories/{category}', [PointCategoryController::class, 'update']);
    Route::delete('categories/{category}', [PointCategoryController::class, 'destroy']);
    Route::put('badges/{badge}', [BadgeController::class, 'update']);
    Route::delete('badges/{badge}', [BadgeController::class, 'destroy']);
});
Route::middleware('school-user')->get('students/{student}/motivation', [MotivationController::class, 'student']);
// Homework: teachers send it and see who answered; the student answers; the student and their parents read it.
Route::middleware(['school-user', 'role:teacher'])->group(function (): void {
    Route::get('homework', [HomeworkController::class, 'index']);
    Route::post('homework', [HomeworkController::class, 'store']);
    Route::put('homework/{homework}', [HomeworkController::class, 'update']);
    Route::delete('homework/{homework}', [HomeworkController::class, 'destroy']);
    Route::post('homework/{homework}/images', [HomeworkFileController::class, 'store']);
    Route::get('homework/{homework}/submissions', [HomeworkSubmissionController::class, 'index']);
});
Route::middleware(['school-user', 'role:student'])->group(function (): void {
    Route::post('homework/{homework}/submission', [HomeworkSubmissionController::class, 'store']);
    Route::delete('homework/{homework}/submission', [HomeworkSubmissionController::class, 'destroy']);
});
Route::middleware('school-user')->get('homework/files/{file}', [HomeworkFileController::class, 'show']);
Route::middleware(['school-user', 'role:teacher,student'])->delete('homework/files/{file}', [HomeworkFileController::class, 'destroy']);
Route::middleware('school-user')->get('homework/me', [HomeworkController::class, 'mine']);
Route::middleware('school-user')->get('students/{student}/homework', [HomeworkController::class, 'forStudent']);

// The published grades of one student: the student, their parents and admins (checked by a gate).
Route::middleware('school-user')->get('students/{student}/grades', StudentGradesController::class);

// School transport: the principal sets rounds, buses and rides; a student and their parents read their own.
Route::middleware(['school-user', 'role:admin'])->prefix('transport')->group(function (): void {
    Route::get('/', [TransportController::class, 'overview']);
    Route::post('rounds', [TransportController::class, 'storeRound']);
    Route::put('rounds/{round}', [TransportController::class, 'updateRound']);
    Route::delete('rounds/{round}', [TransportController::class, 'destroyRound']);
    Route::post('buses', [TransportController::class, 'storeBus']);
    Route::put('buses/{bus}', [TransportController::class, 'updateBus']);
    Route::delete('buses/{bus}', [TransportController::class, 'destroyBus']);
    Route::get('buses/{bus}/riders', [TransportController::class, 'riders']);
    Route::get('assignments', [TransportController::class, 'assignments']);
    Route::put('students/{student}', [TransportController::class, 'assign']);
    Route::delete('students/{student}', [TransportController::class, 'unassign']);
});
Route::middleware('school-user')->get('students/{student}/transport', [TransportController::class, 'forStudent']);

// Tasks for staff: management gives to any teacher, a head of department to their department; each assignee moves their own part.
Route::middleware(['school-user', 'role:admin,teacher'])->prefix('tasks')->group(function (): void {
    Route::get('mine', [TaskController::class, 'mine']);
    Route::get('given', [TaskController::class, 'given']);
    Route::get('people', [TaskController::class, 'people']);
    Route::post('/', [TaskController::class, 'store']);
    Route::put('{task}', [TaskController::class, 'update']);
    Route::delete('{task}', [TaskController::class, 'destroy']);
    Route::put('{task}/status', [TaskController::class, 'status']);
});

// Files attached to tasks, a task's hand-in and surveys: the checks are by owner (see AttachmentAccess).
Route::middleware('school-user')->prefix('attachments')->group(function (): void {
    Route::post('{type}/{id}', [AttachmentController::class, 'store'])->whereIn('type', ['task', 'task_part', 'survey'])->whereNumber('id');
    Route::get('{attachment}', [AttachmentController::class, 'show'])->whereNumber('attachment');
    Route::delete('{attachment}', [AttachmentController::class, 'destroy'])->whereNumber('attachment');
});

// What each member of staff sees: the principal switches screens on or off per person.
Route::middleware(['school-user', 'role:admin'])->prefix('staff-visibility')->group(function (): void {
    Route::get('/', [StaffVisibilityController::class, 'index']);
    Route::put('/', [StaffVisibilityController::class, 'update']);
});
