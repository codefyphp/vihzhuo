# CodefyPHP 4.0 documentation

For the upcoming major release, start with the [4.0 upgrade guide](https://github.com/codefyphp/codefy/blob/4.x/docs/4-0-upgrade/upgrade-4.0.md) and
[skeleton migration guide](skeleton-4.0.md). The [release review](https://github.com/codefyphp/codefy/blob/4.x/docs/4-0-upgrade/release-review.md) records
security fixes, behavioral changes, verification, and deployment limitations.

This directory documents the behavior introduced during the 4.0 major-release review. PHP 8.4 remains the minimum supported version.

- [Pagebuilder release and migration](pagebuilder-4.0.md): application defaults, setup, and compatibility changes.
- [Upgrade from 3.x](https://github.com/codefyphp/codefy/blob/4.x/docs/4-0-upgrade/upgrade-4.0.md): breaking changes and deployment order.
- [HTTP security](https://github.com/codefyphp/codefy/blob/4.x/docs/4-0-upgrade/http-security.md): CORS, CSRF, authentication cookies, redirects, errors, and firewall inputs.
- [Rate limiting](https://github.com/codefyphp/codefy/blob/4.x/docs/4-0-upgrade/rate-limiting.md): identities, windows, retry responses, and storage requirements.
- [Validation and pipelines](https://github.com/codefyphp/codefy/blob/4.x/docs/4-0-upgrade/validation-and-pipelines.md): validated field selection, DTOs, transactions, and builders.
- [Persistent queues](https://github.com/codefyphp/codefy/blob/4.x/docs/4-0-upgrade/queues.md): explicit job payloads, worker registration, leases, retries, and migration.
- [Scheduling](https://github.com/codefyphp/codefy/blob/4.x/docs/4-0-upgrade/scheduler.md): execution results, cleanup, shell arguments, and locks.
- [Request context and middleware configuration](https://github.com/codefyphp/codefy/blob/4.x/docs/4-0-upgrade/request-context.md): lifecycle, Fiber isolation, and alias precedence.
- [Release review and verification](https://github.com/codefyphp/codefy/blob/4.x/docs/4-0-upgrade/release-review.md): dependency fixes, additional changes, coverage, and limitations.
