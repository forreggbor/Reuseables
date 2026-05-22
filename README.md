# What are reuseable components?
- These are independent components that can be used in multiple projects.
- Mostly these components are single file classes or functions which can be easily integrated into any project.
- Each component has its own folder and README.md file.
- Components are written in PHP 8.3+, JS, HTML, Bash.
- Components are documented using PHPDoc.
- Components are version controlled using Git.

## Components

| Component                           | Description                                                                          |
|-------------------------------------|--------------------------------------------------------------------------------------|
| [ActivityLogs](ActivityLogs/)       | Framework-agnostic PHP activity logging with flexible schema and integrity checks    |
| [CronAdmin](CronAdmin/)             | Framework-agnostic PHP cron job administration: manifest-driven dispatcher, admin UI, POSIX locking, Run-Now, audit logging |
| [CodeWarden](CodeWarden/)           | Bash utility for project maintenance (PO/MO localization, ownership, permissions)    |
| [DotEnv](DotEnv/)                   | Lightweight framework-agnostic PHP .env parser, zero dependencies, phpdotenv-compatible API |
| [ErrorHandling](ErrorHandling/)     | Framework-agnostic PHP error and exception logging with severity levels              |
| [GettextFallback](GettextFallback/) | Gettext translation with MO parser fallback for servers without installed locales    |
| [MFA](MFA/)                         | RFC 6238 TOTP multi-factor authentication with built-in QR code generator            |
| [LicenseModule](LicenseModule/)     | Framework-agnostic PHP module for license validation and tier-based feature gating   |
| [PatchCreator](PatchCreator/)       | Bash patch package builder for PatchModule with git diff and SHA-256 verification     |
| [PatchModule](PatchModule/)         | Framework-agnostic patch management with update checking, installation, and rollback  |
| [SzamlazzHuAgent](SzamlazzHuAgent/) | Framework-agnostic PHP module for Szamlazz.hu invoice integration                    |
| [Virtualjog](Virtualjog/)           | Framework-agnostic PHP client for Virtualjog legaltech (documents, cookie consent)   |
| [WYSIWYGEditor](WYSIWYGEditor/)     | Lightweight WYSIWYG rich text editor with tables, images, colors, and code view      |

