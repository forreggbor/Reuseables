<?php
/**
 * Copyright (C) 2026 PatrikMol Solutions Kft. All rights reserved.
 *
 * CSRF token adapter contract for the CronAdmin module.
 */

declare(strict_types=1);

namespace CronAdmin\Contracts;

/**
 * Generates and validates CSRF tokens for CronAdmin's admin views and AJAX endpoints.
 *
 * The module hard-codes the form field name as "csrf_token" — validate() MUST
 * read $_POST['csrf_token'] and compare it against the session-stored token.
 * All CronAdmin JS posts the same field name on every AJAX call.
 */
interface CsrfAdapterInterface
{
    /**
     * Generates (or retrieves) the CSRF token for the current session.
     *
     * The returned value is written into the view's hidden input field:
     *   <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfAdapter->generate()) ?>">
     *
     * @return string
     */
    public function generate(): string;

    /**
     * Validates the CSRF token submitted with the current POST request.
     *
     * MUST read $_POST['csrf_token'] internally and compare it against the
     * session-stored token. Returns false if the token is absent, mismatched,
     * or the session has expired.
     *
     * @return bool
     */
    public function validate(): bool;
}
