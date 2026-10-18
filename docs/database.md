# Database overview

## Central database
`tenants`, `domains`, `super_admins`, `personal_access_tokens`

## Tenant database (one per school)

| Migration | Tables |
|---|---|
| 000001 people_and_structure | users, academic_years, terms, grade_levels, classrooms, subjects, classroom_subject_teacher, students, enrollments, guardian_student |
| 000002 auth_support | personal_access_tokens, password_reset_tokens, notifications, jobs, school_settings |
| 000003 attendance | student_attendances, staff_profiles, staff_attendances, staff_evaluations |
| 000004 grading | assessments, assessment_scores, behavior_notes |
| 000005 scheduling | rooms, periods, teacher_unavailabilities, schedule_generations, timetable_entries, exam_sessions, exam_invigilators, duty_assignments |
| 000006 teacher_tools | lesson_plans, curriculum_units, daily_summaries, homework_assignments, homework_submissions |
| 000007 motivation | point_categories, point_awards, badges, student_badges, star_of_week |
| 000008 communication | conversations, conversation_participants, messages, message_reads, announcements, stories, story_media |
| 000009 surveys | surveys, survey_questions, survey_recipients, survey_responses, survey_answers |
| 000010 finance | fee_structures, student_fees, installments, payments |
| 000011 procurement_audit | procurement_requests, audit_logs |

## Design notes
- A parent is a `users` row with role `parent`, linked to many students via `guardian_student`.
- `timetable_entries` has unique constraints on class, teacher and room per period (no conflicts).
- `is_locked` on scheduler outputs keeps manual edits when the schedule is regenerated.
- `survey_recipients` / `survey_responses` carry `student_id` because approvals are per child.
- Audit log entries are required for grades, finance and attendance changes.
