<?php
/**
 * Copyright (C) 2026 PatrikMol Solutions Kft. All rights reserved.
 *
 * Mail sending adapter contract for the CronAdmin module.
 */

declare(strict_types=1);

namespace CronAdmin\Contracts;

/**
 * Abstracts the host's mail-sending mechanism.
 *
 * JobRunner calls send() when a job's email_report setting triggers a report.
 * The adapter wraps the host's native sender (PHPMailer, queue-based sender,
 * simple mail(), etc.) and returns true when the message was accepted.
 *
 * Mail delivery failures must not propagate to the caller — catch internally
 * and return false. The module logs a warning but never lets a mail failure
 * affect the job's success/failure status.
 */
interface MailAdapterInterface
{
    /**
     * Sends or enqueues an email.
     *
     * Returns true when the adapter accepted the message (sent synchronously
     * or placed in a delivery queue). Returns false on any failure. Must not
     * throw — catch all exceptions internally and return false instead.
     *
     * @param string $to       Recipient email address.
     * @param string $subject  Email subject line.
     * @param string $body     Email body (HTML when $isHtml is true).
     * @param bool   $isHtml   True for HTML body, false for plain text.
     * @return bool
     */
    public function send(string $to, string $subject, string $body, bool $isHtml = true): bool;
}
