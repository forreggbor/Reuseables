# CronAdmin — Adapter Interfaces

Every adapter is a thin host-side glue class. The module never reaches into host singletons directly.

---

## DatabaseAdapterInterface

```php
namespace CronAdmin\Contracts;

interface DatabaseAdapterInterface {
    public function fetchAll(string $sql, array $params = []): array;
    public function fetchOne(string $sql, array $params = []): ?array;
    public function execute(string $sql, array $params = []): int;       // returns affected rows
    public function lastInsertId(): string;
    public function withTransaction(callable $fn): mixed;
}
```

### Bundled implementations

| Class | When to use |
|-------|-------------|
| `CronAdmin\Adapters\Database\PdoAdapter` | Ready PDO instance at construction time |
| `CronAdmin\Adapters\Database\CallableAdapter` | Lazy factory — connection opened only on first use |

**PDO identity requirement:** the PDO instance passed here MUST be the same instance passed to `ActivityLogs\ActivityLogger::init($pdo)`.

---

## AuthAdapterInterface

```php
public function getCurrentUserId(): ?int;
public function isAuthorized(string $action): bool;
public function getUserMap(array $ids): array;   // id→name; returning [] is valid
```

**Action strings** used by AdminActions: `'view'`, `'save'`, `'toggle'`, `'run_now'`, `'toggle_dispatcher'`.

### JupitERP example

```php
class JupitErpAuthAdapter implements AuthAdapterInterface {
    public function getCurrentUserId(): ?int  { return \App\Helpers\Auth::id(); }
    public function isAuthorized(string $action): bool { return \App\Helpers\Auth::isSysadmin(); }
    public function getUserMap(array $ids): array {
        if (empty($ids)) return [];
        $pdo = \App\Core\Database::getInstance()->getConnection();
        $in  = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $pdo->prepare("SELECT id, CONCAT(firstname, ' ', lastname) FROM users WHERE id IN ({$in})");
        $stmt->execute($ids);
        return $stmt->fetchAll(\PDO::FETCH_KEY_PAIR);
    }
}
```

---

## CsrfAdapterInterface

```php
public function generate(): string;    // returns token for the hidden input
public function validate(): bool;      // reads $_POST['csrf_token'] internally
```

**Field name is hard-coded** to `csrf_token` in both the views and the JS — do not change it in the adapter.

### JupitERP example

```php
class JupitErpCsrfAdapter implements CsrfAdapterInterface {
    public function generate(): string {
        // JupitERP stores CSRF in session; generateCsrfToken() returns the current token.
        return \App\Core\Controller::generateCsrfToken();
    }
    public function validate(): bool {
        return \App\Core\Controller::validateCsrf();
    }
}
```

---

## DispatcherKillSwitchAdapterInterface

```php
public function get(): bool;           // true = dispatcher enabled
public function set(bool $enabled): void;
```

**Required always** (CLI dispatcher and admin UI both use it). ConfigValidator throws if absent.

### JupitERP example

```php
class JupitErpKillSwitchAdapter implements DispatcherKillSwitchAdapterInterface {
    public function get(): bool  { return (bool) \App\Services\SystemSettingsService::get('cron_dispatcher_enabled', '1'); }
    public function set(bool $enabled): void {
        \App\Services\SystemSettingsService::set('cron_dispatcher_enabled', $enabled ? '1' : '0', null);
    }
}
```

---

## MailAdapterInterface

```php
public function send(string $to, string $subject, string $body, bool $isHtml = true): bool;
```

Must return `true` when accepted (sent or queued). Must not throw — catch internally and return `false`.

### Per-project examples

**JupitERP:**
```php
class JupitErpMailAdapter implements MailAdapterInterface {
    public function send(string $to, string $subject, string $body, bool $isHtml = true): bool {
        try {
            return (new \App\Services\EmailService())->send($to, $subject, $body, $isHtml, null, 'cron_report');
        } catch (\Throwable) { return false; }
    }
}
```

**TrafficJournal:**
```php
class TrafficJournalMailAdapter implements MailAdapterInterface {
    public function send(string $to, string $subject, string $body, bool $isHtml = true): bool {
        try { return \App\Services\EmailService::send($to, $subject, $body); }
        catch (\Throwable) { return false; }
    }
}
```

**LicenseManager:**
```php
class LicenseManagerMailAdapter implements MailAdapterInterface {
    public function send(string $to, string $subject, string $body, bool $isHtml = true): bool {
        try { return enqueue_email($to, $subject, $body) !== false; }
        catch (\Throwable) { return false; }
    }
}
```

---

## MailRecipientResolverInterface

```php
public function getRecipients(?string $jobKey = null): array;   // list<string>
```

`$jobKey` is the current job's key — MAY be used for per-job routing. Most hosts return a single global list.

```php
class SimpleRecipientResolver implements MailRecipientResolverInterface {
    public function getRecipients(?string $jobKey = null): array {
        return ['admin@example.com'];
    }
}
```

---

## LoggerInterface

```php
public function log(string $message, string $level, array $context = []): void;
public function debug(string $message, array $context = []): void;
public function info(string $message, array $context = []): void;
public function warning(string $message, array $context = []): void;
public function error(string $message, array $context = []): void;
```

Optional — a no-op implementation is used when not supplied.

### JupitERP example

```php
class AppLogAdapter implements LoggerInterface {
    public function log(string $msg, string $level = self::INFO, array $ctx = []): void {
        app_log($msg, strtoupper($level));
    }
    public function debug(string $msg, array $ctx = []): void   { $this->log($msg, self::DEBUG, $ctx); }
    public function info(string $msg, array $ctx = []): void    { $this->log($msg, self::INFO, $ctx); }
    public function warning(string $msg, array $ctx = []): void { $this->log($msg, self::WARNING, $ctx); }
    public function error(string $msg, array $ctx = []): void   { $this->log($msg, self::ERROR, $ctx); }
}
```

---

## Task Constructor Convention

Every task class **MUST have a no-argument constructor**. JobRunner instantiates tasks via `new $class()`. Tasks that need DB connections or application services should reach into host singletons inside their constructor or `run()` method — this matches the existing JupitERP cron task pattern.
