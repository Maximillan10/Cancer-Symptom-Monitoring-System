<?php

return [
    /*
     | Email addresses allowed to add and edit patients, record check-ins, resolve alerts and change thresholds.
     | Everyone else who signs up can view the dashboard and reports only.
     | Set in .env as a comma separated list:  EDITOR_EMAILS=dr.mwansa@example.com
     | Leave it empty and every signed-in clinician can edit (the original behaviour).
     */
    'editor_emails' => array_values(array_filter(array_map(
        fn ($email) => strtolower(trim($email)),
        explode(',', (string) env('EDITOR_EMAILS', ''))
    ))),
];
