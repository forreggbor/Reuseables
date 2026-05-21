<?php
/**
 * Per-job edit modal. Rendered once; JS populates it with data-* values before opening.
 *
 * @var string $baseUrl
 * @var string $csrf_token
 */
?>
<div class="cra-modal" id="craEditModal" role="dialog" aria-modal="true" aria-labelledby="craEditModalTitle" hidden>
    <div class="cra-modal-backdrop cra-modal-close-btn"></div>
    <div class="cra-modal-dialog">
        <div class="cra-modal-header">
            <h5 class="cra-modal-title" id="craEditModalTitle"><?= htmlspecialchars(__('TEXT_CRON_EDIT_SCHEDULE'), ENT_QUOTES, 'UTF-8') ?></h5>
            <button type="button" class="cra-modal-close cra-modal-close-btn" aria-label="<?= htmlspecialchars(__('TEXT_BUTTON_CLOSE'), ENT_QUOTES, 'UTF-8') ?>">✕</button>
        </div>
        <form id="craEditForm" novalidate>
            <input type="hidden" name="csrf_token" id="craEditCsrf" value="<?= htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="job_id"    id="craEditJobId"  value="">
            <div class="cra-modal-body">

                <!-- Frequency -->
                <div class="cra-form-group">
                    <label class="cra-label" for="craFrequency"><?= htmlspecialchars(__('TEXT_CRON_FREQUENCY'), ENT_QUOTES, 'UTF-8') ?></label>
                    <div class="cra-row cra-gap-sm cra-align-center">
                        <select class="cra-select" id="craFrequency" name="frequency">
                            <option value="every_n_minutes"><?= htmlspecialchars(__('TEXT_CRON_FREQUENCY_EVERY_N_MINUTES'), ENT_QUOTES, 'UTF-8') ?></option>
                            <option value="hourly"><?= htmlspecialchars(__('TEXT_CRON_FREQUENCY_HOURLY'), ENT_QUOTES, 'UTF-8') ?></option>
                            <option value="daily"><?= htmlspecialchars(__('TEXT_CRON_FREQUENCY_DAILY'), ENT_QUOTES, 'UTF-8') ?></option>
                            <option value="weekly"><?= htmlspecialchars(__('TEXT_CRON_FREQUENCY_WEEKLY'), ENT_QUOTES, 'UTF-8') ?></option>
                            <option value="monthly"><?= htmlspecialchars(__('TEXT_CRON_FREQUENCY_MONTHLY'), ENT_QUOTES, 'UTF-8') ?></option>
                        </select>

                        <!-- every_n_minutes -->
                        <div class="cra-field-every_n_minutes">
                            <select class="cra-select" name="every_n_minutes" id="craEveryN">
                                <?php foreach ([1,5,10,15,20,30,60,120,180,240,360,720,1440] as $n):
                                    $label = ($n >= 60 && $n % 60 === 0)
                                        ? __('TEXT_CRON_SCHEDULE_EVERY_N_HOURS', ['n' => $n / 60])
                                        : __('TEXT_CRON_SCHEDULE_EVERY_N_MINUTES', ['n' => $n]);
                                ?>
                                <option value="<?= $n ?>"><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <!-- hour -->
                        <div class="cra-field-hour">
                            <input type="number" min="0" max="23" class="cra-input cra-input--narrow"
                                   name="hour" id="craHour"
                                   placeholder="<?= htmlspecialchars(__('TEXT_CRON_HOUR'), ENT_QUOTES, 'UTF-8') ?>">
                        </div>

                        <!-- minute -->
                        <div class="cra-field-minute">
                            <input type="number" min="0" max="59" class="cra-input cra-input--narrow"
                                   name="minute" id="craMinute"
                                   placeholder="<?= htmlspecialchars(__('TEXT_CRON_MINUTE'), ENT_QUOTES, 'UTF-8') ?>">
                        </div>
                    </div>
                </div>

                <!-- days_of_week -->
                <div class="cra-form-group cra-field-days_of_week">
                    <label class="cra-label"><?= htmlspecialchars(__('TEXT_CRON_DAYS_OF_WEEK'), ENT_QUOTES, 'UTF-8') ?></label>
                    <div class="cra-row cra-gap-sm cra-flex-wrap">
                        <?php
                        $dowKeys = [
                            0 => 'TEXT_DAY_OF_WEEK_SUN',
                            1 => 'TEXT_DAY_OF_WEEK_MON',
                            2 => 'TEXT_DAY_OF_WEEK_TUE',
                            3 => 'TEXT_DAY_OF_WEEK_WED',
                            4 => 'TEXT_DAY_OF_WEEK_THU',
                            5 => 'TEXT_DAY_OF_WEEK_FRI',
                            6 => 'TEXT_DAY_OF_WEEK_SAT',
                        ];
                        foreach ($dowKeys as $dowNum => $dowKey): ?>
                        <label class="cra-check-label">
                            <input type="checkbox" class="cra-check-input cra-dow-cb"
                                   name="days_of_week_cb[<?= $dowNum ?>]" value="1"
                                   data-dow="<?= $dowNum ?>">
                            <span><?= htmlspecialchars(__($dowKey), ENT_QUOTES, 'UTF-8') ?></span>
                        </label>
                        <?php endforeach; ?>
                    </div>
                </div>

                <!-- days_of_month -->
                <div class="cra-form-group cra-field-days_of_month">
                    <label class="cra-label" for="craDaysOfMonth"><?= htmlspecialchars(__('TEXT_CRON_DAYS_OF_MONTH'), ENT_QUOTES, 'UTF-8') ?></label>
                    <input type="text" class="cra-input" id="craDaysOfMonth" name="days_of_month"
                           placeholder="1,15,28">
                    <div class="cra-form-hint cra-text-warning cra-dom-high-day-warning" hidden>
                        <?= htmlspecialchars(__('TEXT_CRON_DAYS_OF_MONTH_HIGH_DAY_WARNING'), ENT_QUOTES, 'UTF-8') ?>
                    </div>
                </div>

                <!-- log_to_db -->
                <div class="cra-form-group">
                    <label class="cra-toggle-label">
                        <input type="checkbox" class="cra-toggle-input cra-log-to-db" name="log_to_db" value="1" id="craLogToDB">
                        <span class="cra-toggle-slider"></span>
                        <span><?= htmlspecialchars(__('TEXT_CRON_LOG_TO_DB'), ENT_QUOTES, 'UTF-8') ?></span>
                    </label>
                    <div class="cra-form-hint cra-text-warning cra-log-high-freq-warning" hidden>
                        <?= htmlspecialchars(__('TEXT_CRON_LOG_TO_DB_HIGH_FREQUENCY_WARNING'), ENT_QUOTES, 'UTF-8') ?>
                    </div>
                </div>

                <!-- email_report -->
                <div class="cra-form-group">
                    <label class="cra-label" for="craEmailReport"><?= htmlspecialchars(__('TEXT_CRON_EMAIL_REPORT'), ENT_QUOTES, 'UTF-8') ?></label>
                    <select class="cra-select" id="craEmailReport" name="email_report">
                        <option value="off"><?= htmlspecialchars(__('TEXT_CRON_EMAIL_REPORT_OFF'), ENT_QUOTES, 'UTF-8') ?></option>
                        <option value="on_failure"><?= htmlspecialchars(__('TEXT_CRON_EMAIL_REPORT_ON_FAILURE'), ENT_QUOTES, 'UTF-8') ?></option>
                        <option value="every_run"><?= htmlspecialchars(__('TEXT_CRON_EMAIL_REPORT_EVERY_RUN'), ENT_QUOTES, 'UTF-8') ?></option>
                    </select>
                    <div class="cra-form-hint cra-text-warning cra-email-high-freq-warning" hidden>
                        <?= htmlspecialchars(__('TEXT_CRON_EMAIL_REPORT_HIGH_FREQUENCY_WARNING'), ENT_QUOTES, 'UTF-8') ?>
                    </div>
                </div>

            </div>
            <div class="cra-modal-footer">
                <button type="button" class="cra-btn cra-btn--outline cra-modal-close-btn"><?= htmlspecialchars(__('TEXT_BUTTON_CANCEL'), ENT_QUOTES, 'UTF-8') ?></button>
                <button type="submit" class="cra-btn cra-btn--primary"><?= htmlspecialchars(__('TEXT_BUTTON_SAVE'), ENT_QUOTES, 'UTF-8') ?></button>
            </div>
        </form>
    </div>
</div>
