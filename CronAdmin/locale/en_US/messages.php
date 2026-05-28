<?php
/**
 * Copyright (C) 2026 PatrikMol Solutions Kft. All rights reserved.
 *
 * CronAdmin English (en_US) translation strings.
 *
 * Contains TEXT_CRON_* (admin UI) and TEXT_DAY_OF_WEEK_* (schedule formatter) keys only.
 * Per-job TEXT_JOB_* labels belong in each host's own locale file.
 *
 * Host bootstrap merges this file into its own translations:
 *   $cron = require __DIR__ . '/../lib/CronAdmin/locale/en_US/messages.php';
 *   $host = require __DIR__ . '/../locale/en_US/messages.php';
 *   $messages = array_merge($cron, $host); // host keys win on collision
 */

return [
    'TEXT_CRON_CSRF_FAILED'                          => 'Invalid CSRF token. Please reload and try again.',
    'TEXT_CRON_DAYS_OF_MONTH'                        => 'Days of month',
    'TEXT_CRON_DAYS_OF_MONTH_HIGH_DAY_WARNING'       => 'This day does not exist in some months; the job will not fire then.',
    'TEXT_CRON_DAYS_OF_WEEK'                         => 'Days of week',
    'TEXT_CRON_DISPATCHER_DISABLED_BANNER'           => 'The cron dispatcher is disabled — no scheduled jobs are running.',
    'TEXT_CRON_DISPATCHER_ENABLED'                   => 'Dispatcher enabled',
    'TEXT_CRON_DISPATCHER_ENABLED_HELP'              => 'When disabled, no scheduled jobs will run regardless of individual job settings.',
    'TEXT_CRON_EDIT_SCHEDULE'                        => 'Edit schedule',
    'TEXT_CRON_EMAIL_REPORT'                         => 'Email report',
    'TEXT_CRON_EMAIL_REPORT_DELIVERY_FAILED'         => 'Scheduled job email report delivery failed',
    'TEXT_CRON_EMAIL_REPORT_EVERY_RUN'               => 'Every run',
    'TEXT_CRON_EMAIL_REPORT_HIGH_FREQUENCY_WARNING'  => 'At high frequency this generates many emails. Switch to "On failure only" or disable email reporting.',
    'TEXT_CRON_EMAIL_REPORT_OFF'                     => 'Off',
    'TEXT_CRON_EMAIL_REPORT_ON_FAILURE'              => 'On failure only',
    'TEXT_CRON_EMAIL_REPORT_SUBJECT'                 => 'Cron job {label}: {status}',
    'TEXT_CRON_ENABLED'                              => 'Enabled',
    'TEXT_CRON_EVERY_N_MINUTES'                      => 'Every {n} minutes',
    'TEXT_CRON_FREQUENCY'                            => 'Frequency',
    'TEXT_CRON_FREQUENCY_DAILY'                      => 'Daily',
    'TEXT_CRON_FREQUENCY_EVERY_N_MINUTES'            => 'Every N minutes',
    'TEXT_CRON_FREQUENCY_HOURLY'                     => 'Hourly',
    'TEXT_CRON_FREQUENCY_MONTHLY'                    => 'Monthly',
    'TEXT_CRON_FREQUENCY_WEEKLY'                     => 'Weekly',
    'TEXT_CRON_HOUR'                                 => 'Hour',
    'TEXT_CRON_INVALID_SINCE_TS'                     => 'Invalid since_ts format. Expected: YYYY-MM-DD HH:MM:SS',
    'TEXT_CRON_JOB_NAME'                             => 'Job',
    'TEXT_CRON_LAST_DURATION'                        => 'Last duration',
    'TEXT_CRON_LAST_RUN_AT'                          => 'Last run',
    'TEXT_CRON_LAST_STATUS'                          => 'Last status',
    'TEXT_CRON_LOG_TO_DB'                            => 'Log to DB',
    'TEXT_CRON_LOG_TO_DB_HIGH_FREQUENCY_WARNING'     => 'This job runs very frequently; DB logging will generate many rows in the activity_logs table.',
    'TEXT_CRON_MANIFEST_BROKEN'                      => 'Cron manifest has validation errors — editing is disabled until the manifest is fixed.',
    'TEXT_CRON_MINUTE'                               => 'Minute',
    'TEXT_CRON_NO_JOBS_DECLARED'                     => 'No jobs declared. Add entries to cron/jobs.php.',
    'TEXT_CRON_QUEUED'                               => 'Queued',
    'TEXT_CRON_QUEUED_TOOLTIP'                       => 'Queued by %1$s at %2$s',
    'TEXT_CRON_QUEUED_TOOLTIP_NO_USER'               => 'Queued at %s',
    'TEXT_CRON_RUN_NOW'                              => 'Run now',
    'TEXT_CRON_RUN_NOW_ALREADY_PENDING'              => 'This job is already queued to run.',
    'TEXT_CRON_RUN_NOW_CONFIRM'                      => 'Are you sure you want to run this job now?',
    'TEXT_CRON_RUN_NOW_QUEUED'                       => 'Queued — will start within 1 minute',
    'TEXT_CRON_RUN_NOW_STILL_RUNNING'                => 'Still running — check back later',
    'TEXT_CRON_SAVE_SUCCESS'                         => 'Cron settings saved',
    'TEXT_CRON_SCHEDULE_DAILY_AT'                    => 'Daily at {time}',
    'TEXT_CRON_SCHEDULE_EVERY_N_HOURS'               => 'Every {n} hours',
    'TEXT_CRON_SCHEDULE_EVERY_N_MINUTES'             => 'Every {n} minutes',
    'TEXT_CRON_SCHEDULE_HOURLY_AT'                   => 'Hourly at :{minute}',
    'TEXT_CRON_SCHEDULE_LABEL'                       => 'Schedule',
    'TEXT_CRON_SCHEDULE_MONTHLY_AT'                  => 'Monthly on {days} at {time}',
    'TEXT_CRON_SCHEDULE_WEEKLY_AT'                   => 'Weekly: {days} at {time}',
    'TEXT_CRON_STATUS_FAILURE'                       => 'failure',
    'TEXT_CRON_STATUS_SKIPPED'                       => 'skipped',
    'TEXT_CRON_STATUS_SUCCESS'                       => 'success',
    'TEXT_CRON_VALIDATION_DAYS_OF_MONTH_REQUIRED'    => 'Days of month is required for monthly jobs.',
    'TEXT_CRON_VALIDATION_DAYS_OF_WEEK_REQUIRED'     => 'Days of week is required for weekly jobs.',
    'TEXT_CRON_VALIDATION_EVERY_N_MINUTES_INVALID'   => 'Invalid every_n_minutes value.',
    'TEXT_CRON_VALIDATION_HOUR_MINUTE_REQUIRED'      => 'Hour and minute are required for this frequency.',
    'TEXT_CRON_VALIDATION_INVALID_EVERY_N_MINUTES'   => 'Invalid every_n_minutes value. Allowed: 1, 5, 10, 15, 20, 30, 60, 120, 180, 240, 360, 720, 1440.',
    'TEXT_CRON_VIEW_LAST_OUTPUT'                     => 'View last output',
    'TEXT_DAY_OF_WEEK_FRI'                           => 'Fri',
    'TEXT_DAY_OF_WEEK_MON'                           => 'Mon',
    'TEXT_DAY_OF_WEEK_SAT'                           => 'Sat',
    'TEXT_DAY_OF_WEEK_SUN'                           => 'Sun',
    'TEXT_DAY_OF_WEEK_THU'                           => 'Thu',
    'TEXT_DAY_OF_WEEK_TUE'                           => 'Tue',
    'TEXT_DAY_OF_WEEK_WED'                           => 'Wed',
];
