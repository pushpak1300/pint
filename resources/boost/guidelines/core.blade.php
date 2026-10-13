@php
/** @var \Laravel\Boost\Install\GuidelineAssist $assist */
@endphp
# Laravel Pint Code Quality

- If you have modified PHP files, run `{{ $assist->binCommand('pint') }} format --dirty --format=agent` before finalizing changes to apply the project's configured formatting and cleanup.
- Run `{{ $assist->binCommand('pint') }} check --dirty --format=agent` to check the changed files without writing source. Bare `pint` is also read-only; it is not a formatting fix command.
- Follow the configured rule diagnostics and their remediation help rather than inventing a separate Laravel ruleset or imposing application layers. Advisory warnings are nonblocking unless `--strict` is requested.
- `--dirty` limits reported files, not necessarily analysis work: project analysis can still read the rest of the application and vendor types.
- Do not run `fix`, `lint --fix`, or `refactor --apply` blindly. They can apply enabled behavior-changing edits; use them only when those edits are intended, review the diff, and run the relevant tests. Pint does not install after-edit hooks or run Laravel Doctor.
