<?php
/**
 * Copyright (C) 2026 PatrikMol Solutions Kft. All rights reserved.
 *
 * Mail recipient resolver contract for the CronAdmin module.
 */

declare(strict_types=1);

namespace CronAdmin\Contracts;

/**
 * Resolves the list of email recipients for a cron job report.
 *
 * JobRunner always passes the current job's key so implementations can route
 * per-job reports to different recipients. Most hosts simply return the same
 * global admin address list regardless of $jobKey.
 */
interface MailRecipientResolverInterface
{
    /**
     * Returns the email addresses to notify for the given job.
     *
     * When $jobKey is null, returns the global default recipient list.
     * When $jobKey is set, MAY return a job-specific list; falling back to
     * the global list is acceptable.
     *
     * @param string|null $jobKey  The cron_jobs.job_key value, or null for the global default.
     * @return list<string>  Email addresses. Empty list means "no recipients" — no mail is sent.
     */
    public function getRecipients(?string $jobKey = null): array;
}
