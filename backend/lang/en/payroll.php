<?php

return [
    'not_allowed' => 'You are not allowed to do this.',
    'has_active_contract' => 'This person already has a running contract: renew it or end it first.',
    'not_active' => 'Only a running contract can be changed.',
    'dates_order' => 'The end date must be after the start date.',
    'bad_salary' => 'The salary must be more than zero.',
    'not_staff' => 'Choose an active member of staff.',
    'locked' => 'This month\'s payroll was already submitted.',
    'empty' => 'There is nobody on this payroll: add a contract first.',
    'no_method' => 'Choose how each person is paid (bank transfer, cheque or cash) before recording the payment.',
    'already_paid' => 'This payslip is already paid.',
    'not_workday' => 'Today is not a working day.',
    'no_check_in' => 'You have not checked in today.',
    'auto' => [
        'punctuality_bonus' => 'Punctuality bonus (:score%)',
        'punctuality_deduction' => 'Punctuality deduction (:score%)',
        'daily_summary_bonus' => 'Daily summaries bonus (:score%)',
        'daily_summary_deduction' => 'Missing daily summaries (:score%)',
        'weekly_plan_bonus' => 'Weekly plans bonus (:score%)',
        'weekly_plan_deduction' => 'Missing weekly plans (:score%)',
    ],
    'wrong_state' => 'This payroll is :state: this cannot be done now.',
    'bad_amount' => 'The amount must be more than zero.',
    'deduction_too_big' => 'This deduction is more than what is left of the pay (:net).',
];
