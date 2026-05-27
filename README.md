# What are reuseable components?
- These are independent components that can be used in multiple projects.
- Mostly these components are single file classes or functions which can be easily integrated into any project.
- Each component has its own folder and README.md file.
- Components are written in PHP 8.3+, JS, HTML, Bash.
- Components are documented using PHPDoc.
- Components are version controlled using Git.

## Framework-agnostic PHP components

| Component                           | Description                                                                                   |
|-------------------------------------|-----------------------------------------------------------------------------------------------|
| [ActivityLogs](ActivityLogs/)       | Framework-agnostic PHP activity logging with flexible schema and integrity checks             |
| [CronAdmin](CronAdmin/)             | Framework-agnostic PHP cron job administration                                                |
| [DotEnv](DotEnv/)                   | Lightweight framework-agnostic PHP .env parser, zero dependencies, phpdotenv-compatible API   |
| [ErrorHandling](ErrorHandling/)     | Framework-agnostic PHP error and exception logging with severity levels                       |
| [MFA](MFA/)                         | RFC 6238 TOTP multi-factor authentication with built-in QR code generator                     |
| [LicenseModule](LicenseModule/)     | Framework-agnostic PHP module for license validation and tier-based feature gating            |
| [PatchModule](PatchModule/)         | Framework-agnostic patch management with update checking, installation, and rollback          |
| [SzamlazzHuAgent](SzamlazzHuAgent/) | Framework-agnostic PHP module for Szamlazz.hu invoice integration                             |
| [Virtualjog](Virtualjog/)           | Framework-agnostic PHP client for Virtualjog legaltech (documents, cookie consent)            |

## Obsolote components

| Component                           | Description                                                                                   |
|-------------------------------------|-----------------------------------------------------------------------------------------------|
| [GettextFallback](GettextFallback/) | ~~Gettext translation with MO parser fallback~~ — **obsolete**, kept for historical reference |
