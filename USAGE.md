# Module Usage Matrix

## Installed version per project. 

`—` means the module is not installed. **Bold** means the installed version is behind the current release.
`n/a` means the project doesn't use the feature provided by the reusable. 
`*` means the host copy is not fully synced — see the notes below the table.

| Module          | Current | JupitERP    | TrafficJournal | UniCMS      | TFL-ERP     | PM-ERP      | PatrikMol.com | LicenseManager | NPMWaf   |
|-----------------|---------|-------------|----------------|-------------|-------------|-------------|---------------|----------------|----------|
| ActivityLogs    | 1.4.4   | 1.4.4       | 1.4.4          | 1.4.4       | 1.4.4       | 1.4.4       | 1.4.4         | 1.4.4          | 1.4.4*   |
| BackupRestore   | 0.3.4   | —           | —              | 0.3.4       | 0.3.4       | —           | —             | 0.3.4          | —        |
| CronAdmin       | 1.3.1   | 1.3.1       | 1.3.1          | n/a         | 1.3.1       | 1.3.1       | -             | 1.3.1          | 1.3.1    |
| DotEnv          | 1.00.01 | 1.00.01     | 1.00.01        | 1.00.01     | 1.00.01     | 1.00.01     | **1.00.00**   | —              | 1.00.01  |
| ErrorHandling   | 1.3.1   | 1.3.1       | 1.3.1          | 1.3.1       | 1.3.1       | 1.3.1       | -             | 1.3.1          | 1.3.1    |
| LicenseModule   | 2.1.3   | 2.1.3       | 2.1.3          | 2.1.3       | 2.1.3       | —           | -             | —              | —        |
| MFA             | 1.0.3   | —           | 1.0.3          | 1.0.3       | 1.0.3       | 1.0.3       | -             | —              | 1.0.3    |
| NavSync         | 1.2.0   | 1.2.0       | —              | —           | —           | 1.2.0       | -             | —              | —        |
| PatchModule     | 2.10.0  | 2.10.0      | 2.10.0         | 2.10.0      | 2.10.0      | 2.10.0      | -             | 2.10.0         | —        |
| SzamlazzHuAgent | 1.7.1   | 1.7.1       | n/a            | n/a         | 1.7.1       | 1.7.1       | -             | —              | n/a      |
| UiKit           | 1.00.02 | 1.00.02     | 1.00.02        | —           | —           | —           | -             | —              | —        |
| Virtualjog      | 1.0.0   | 1.0.0       | n/a            | n/a         | -           | —           | -             | —              | n/a      |

\* Partially synced. The host keeps one local file that differs from the module, so the rest of the module is current and that file is not:

- **NPMWaf** — `ActivityLogs/ActivityLogger.php`: NPMWaf's committed null-guard on the count query is kept.

## Obsolote modules

| Module                           | Current | JupitERP | TrafficJournal | UniCMS |
|----------------------------------|---------|----------|----------------|--------|
| ~~GettextFallback~~ *(obsolete)* | 1.0.0   | —        | —              | —      |
