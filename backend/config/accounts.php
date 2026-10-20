<?php

return [
    // Password given to every account the admin creates (teachers, students, parents). It must be changed at the
    // first sign-in and can never be chosen again as a new password.
    'default_password' => env('DEFAULT_ACCOUNT_PASSWORD', '123456789'),

    // Domain of the generated student emails (ahmad.d1001@<domain>). Empty: "<school code>.edu".
    'student_email_domain' => env('STUDENT_EMAIL_DOMAIN'),
];
