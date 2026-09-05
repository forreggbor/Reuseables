<?php
/**
 * Copyright (C) 2026 PatrikMol Solutions Kft. All rights reserved.
 *
 * CronAdmin Hungarian (hu_HU) translation strings.
 *
 * Contains TEXT_CRON_* (admin UI) and TEXT_DAY_OF_WEEK_* (schedule formatter) keys only.
 * Per-job TEXT_JOB_* labels belong in each host's own locale file.
 *
 * Host bootstrap merges this file into its own translations:
 *   $cron = require __DIR__ . '/../lib/CronAdmin/locale/hu_HU/messages.php';
 *   $host = require __DIR__ . '/../locale/hu_HU/messages.php';
 *   $messages = array_merge($cron, $host); // host keys win on collision
 */

return [
    'TEXT_CRON_CSRF_FAILED'                          => 'Érvénytelen CSRF token. Kérjük, töltse újra az oldalt, és próbálja meg újra.',
    'TEXT_CRON_DAYS_OF_MONTH'                        => 'Hónap napjai',
    'TEXT_CRON_DAYS_OF_MONTH_HIGH_DAY_WARNING'       => 'Ez a nap nem létezik minden hónapban; a feladat ilyenkor nem fut le.',
    'TEXT_CRON_DAYS_OF_WEEK'                         => 'Hét napjai',
    'TEXT_CRON_DISPATCHER_DISABLED_BANNER'           => 'A cron ütemező ki van kapcsolva — egyetlen ütemezett feladat sem fut.',
    'TEXT_CRON_DISPATCHER_ENABLED'                   => 'Ütemező engedélyezve',
    'TEXT_CRON_DISPATCHER_ENABLED_HELP'              => 'Ha kikapcsolt, egyetlen ütemezett feladat sem fut le, függetlenül az egyes feladatok beállításaitól.',
    'TEXT_CRON_EDIT_SCHEDULE'                        => 'Ütemezés szerkesztése',
    'TEXT_CRON_EMAIL_REPORT'                         => 'E-mail értesítés',
    'TEXT_CRON_EMAIL_REPORT_DELIVERY_FAILED'         => 'Ütemezett feladat e-mail értesítés küldése sikertelen',
    'TEXT_CRON_EMAIL_REPORT_EVERY_RUN'               => 'Minden futás után',
    'TEXT_CRON_EMAIL_REPORT_HIGH_FREQUENCY_WARNING'  => 'Magas frekvencián ez sok e-mailt generál. Kérjük, váltson „Csak hiba esetén" módra, vagy tiltsa le az értesítést.',
    'TEXT_CRON_EMAIL_REPORT_OFF'                     => 'Ki',
    'TEXT_CRON_EMAIL_REPORT_ON_FAILURE'              => 'Csak hiba esetén',
    'TEXT_CRON_EMAIL_REPORT_SUBJECT'                 => 'Cron feladat {label}: {status}',
    'TEXT_CRON_ENABLED'                              => 'Engedélyezve',
    'TEXT_CRON_EVERY_N_MINUTES'                      => 'Minden {n}. percben',
    'TEXT_CRON_ERROR_GENERIC'                        => 'Általános hiba',
    'TEXT_CRON_FREQUENCY'                            => 'Ütemezés',
    'TEXT_CRON_FREQUENCY_DAILY'                      => 'Naponta',
    'TEXT_CRON_FREQUENCY_EVERY_N_MINUTES'            => 'Minden N percben',
    'TEXT_CRON_FREQUENCY_HOURLY'                     => 'Óránként',
    'TEXT_CRON_FREQUENCY_MONTHLY'                    => 'Havonta',
    'TEXT_CRON_FREQUENCY_WEEKLY'                     => 'Hetente',
    'TEXT_CRON_HOUR'                                 => 'Óra',
    'TEXT_CRON_INVALID_SINCE_TS'                     => 'Érvénytelen since_ts formátum. Elvárt: ÉÉÉÉ-HH-NN ÓÓ:PP:MM',
    'TEXT_CRON_JOB_NAME'                             => 'Feladat',
    'TEXT_CRON_LAST_DURATION'                        => 'Utolsó futás ideje',
    'TEXT_CRON_LAST_RUN_AT'                          => 'Utolsó futás',
    'TEXT_CRON_LAST_STATUS'                          => 'Utolsó állapot',
    'TEXT_CRON_LOG_TO_DB'                            => 'DB naplózás',
    'TEXT_CRON_LOG_TO_DB_HIGH_FREQUENCY_WARNING'     => 'Ez a feladat naponta sokszor fut; az adatbázis-naplózás sok sort generál az activity_logs táblában.',
    'TEXT_CRON_MANIFEST_BROKEN'                      => 'A cron manifest érvényesítési hibákat tartalmaz — szerkesztés letiltva a hiba javításáig.',
    'TEXT_CRON_MINUTE'                               => 'Perc',
    'TEXT_CRON_NO_JOBS_DECLARED'                     => 'Nincsenek feladatok deklarálva. Adjon hozzá elemeket a cron/jobs.php fájlhoz.',
    'TEXT_CRON_QUEUED'                               => 'Sorban',
    'TEXT_CRON_QUEUED_TOOLTIP'                       => 'Sorba helyezte: %1$s, ekkor: %2$s',
    'TEXT_CRON_QUEUED_TOOLTIP_NO_USER'               => 'Sorba helyezve: %s',
    'TEXT_CRON_RUN_NOW'                              => 'Futtatás most',
    'TEXT_CRON_RUN_NOW_ALREADY_PENDING'              => 'Ez a feladat már sorban áll futtatásra.',
    'TEXT_CRON_RUN_NOW_CONFIRM'                      => 'Biztosan futtatja most ezt a feladatot?',
    'TEXT_CRON_RUN_NOW_QUEUED'                       => 'Sorba helyezve — indul 1 percen belül',
    'TEXT_CRON_RUN_NOW_STILL_RUNNING'                => 'A feladat még fut — nézze meg később',
    'TEXT_CRON_SAVE_SUCCESS'                         => 'Cron beállítások mentve',
    'TEXT_CRON_SCHEDULE_DAILY_AT'                    => 'Naponta {time}-kor',
    'TEXT_CRON_SCHEDULE_EVERY_N_HOURS'               => 'Minden {n}. órában',
    'TEXT_CRON_SCHEDULE_EVERY_N_MINUTES'             => 'Minden {n}. percben',
    'TEXT_CRON_SCHEDULE_HOURLY_AT'                   => 'Óránként :{minute} perckor',
    'TEXT_CRON_SCHEDULE_LABEL'                       => 'Ütemezés',
    'TEXT_CRON_SCHEDULE_MONTHLY_AT'                  => 'Havonta {days}. napján {time}-kor',
    'TEXT_CRON_SCHEDULE_WEEKLY_AT'                   => 'Hetente: {days} {time}-kor',
    'TEXT_CRON_STATUS_FAILURE'                       => 'hiba',
    'TEXT_CRON_STATUS_SKIPPED'                       => 'kihagyva',
    'TEXT_CRON_STATUS_SUCCESS'                       => 'sikeres',
    'TEXT_CRON_VALIDATION_DAYS_OF_MONTH_REQUIRED'    => 'A hónap napjainak megadása kötelező havonta futó feladatnál.',
    'TEXT_CRON_VALIDATION_DAYS_OF_WEEK_REQUIRED'     => 'A hét napjainak megadása kötelező hetente futó feladatnál.',
    'TEXT_CRON_VALIDATION_EVERY_N_MINUTES_INVALID'   => 'Érvénytelen N perces érték.',
    'TEXT_CRON_VALIDATION_HOUR_MINUTE_REQUIRED'      => 'Az óra és perc megadása kötelező ennél az ütemezésnél.',
    'TEXT_CRON_VALIDATION_INVALID_EVERY_N_MINUTES'   => 'Érvénytelen N perces érték. Engedélyezett értékek: 1, 5, 10, 15, 20, 30, 60, 120, 180, 240, 360, 720, 1440.',
    'TEXT_CRON_VIEW_LAST_OUTPUT'                     => 'Utolsó kimenet',
    'TEXT_DAY_OF_WEEK_FRI'                           => 'P',
    'TEXT_DAY_OF_WEEK_MON'                           => 'H',
    'TEXT_DAY_OF_WEEK_SAT'                           => 'Szo',
    'TEXT_DAY_OF_WEEK_SUN'                           => 'V',
    'TEXT_DAY_OF_WEEK_THU'                           => 'Cs',
    'TEXT_DAY_OF_WEEK_TUE'                           => 'K',
    'TEXT_DAY_OF_WEEK_WED'                           => 'Sze',
];
