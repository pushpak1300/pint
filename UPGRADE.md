# Upgrading to Pint 2.x

Pint 2.x replaces PHP-CS-Fixer with bundled Mago 1.53.0. Existing commands,
file selection, report formats, and CI exit codes remain. Exact formatting and
cleanup are not identical to Pint 1.x. Review the first formatting commit
separately from application changes.

Running Pint 2.x requires PHP 8.4 or later within PHP 8.x. The `php-version`
setting controls the source code target and may still be set to an older version.

## Configuration

Keep `pint.json`, `preset`, `extend`, `in`, `exclude`, `notName`, `notPath`, and
`cache-file`. Replace the old top-level `rules` object with Mago settings:

```json
{
    "preset": "laravel",
    "blade": true,
    "php-version": "8.4",
    "formatter": {
        "print-width": 120,
        "single-quote": true
    },
    "linter": {
        "rules": {
            "no-redundant-use": { "enabled": false }
        }
    }
}
```

`formatter` accepts Mago formatter options; explicit values override the Pint
preset. `linter.rules` accepts Mago rule option objects. Use `enabled: false` to
disable a preset cleanup. Pint applies available **safe fixes**, not general
lint diagnostics. Rules that only report problems do not become formatting
failures. Unknown rules/settings and legacy `rules` produce errors.

Potentially unsafe and unsafe edits require explicit `"fix-safety":
"potentially-unsafe"` or `"fix-safety": "unsafe"` respectively. This applies
only to the selected rules. For example, adding `strict_types` requires enabling
`strict-types` and allowing its unsafe fix. The default is `safe`; changing the
setting can change program behavior, so review these edits carefully.

`php-version` defaults to the running PHP major/minor version; pin it to keep
formatting consistent across machines. Pint ignores `mago.toml` and `MAGO_*`
environment variables, and does not load arbitrary Mago extensions.

## Presets and cleanup

- `laravel`: Mago's Laravel style plus available options matching Pint 1.x.
  Safe cleanup enables short arrays, unused/redundant import removal, and
  shortening/importing fully qualified class-like references in PHP code.
- `per`: Mago's PER-CS default with unused/redundant import removal.
- `psr12`: Mago's PSR-12 preset with unused/redundant import removal.
- `symfony`: an approximation using PSR-12, single quotes, tight concatenation,
  blank lines before returns, short arrays, and unused/redundant import removal.
- `empty` has been removed. Select another preset and explicit settings.

Mago can wrap lines at its print width and add trailing commas beyond arrays.
PHPDoc ordering/alignment/tag removal/import rewriting, PHPUnit method renaming
and lifecycle visibility, interface/trait sorting, and current-class accessor
rewrites are **not carried over**. Alias-function replacement is not enabled by
default. Custom extensions for these gaps are deferred pending review, not
silently emulated. The old filename-based class-renaming rule is also absent.

## Blade

`--blade` and `"blade": true` keep the existing Prettier/Blade pipeline and its
Node.js or Bun dependencies. Embedded PHP uses Mago while retaining imports and
closing tags. Incomplete PHP islands are left untouched. Fragment cleanup is
restricted to safe array-style changes; whole-file lint rules and unsafe fixes
never run on fragments. Some wrapping, comments' layout, and anonymous-class
formatting differ. A standalone PHAR still needs the accompanying Pint JavaScript
resources to support Blade, as in 1.x.

## Execution and reports

`--parallel` enables Mago's native threads for PHP batches. `--max-processes`
retains its name but now limits those threads and must be positive. Blade
continues through the existing JavaScript worker. `--bail` stops after the first
file with a change or error and never modifies source.

`--test` never writes source; `--repair` writes fixes but returns 1 when files
change. Invalid source is reported and left untouched. Both modes return 1 on
errors. Normal successful fixing returns 0.

Report shapes remain, but `appliedFixers`/`fixers` identifies PHP changes as
`mago` rather than pretending to attribute them to PHP-CS-Fixer rules. Blade
retains `Pint/laravel_blade`. Report metadata identifies Pint. Cache contents
have a new signature and SHA-256 hashes; old cache entries are invalidated.

## Bundled executable

The PHAR embeds verified native executables for Linux x64/ARM64, macOS
x64/ARM64, and Windows x64. Windows ARM64 and 32-bit PHP are unsupported.
No network access is needed to obtain Mago at runtime. PHP must permit subprocess
execution. On first use Pint extracts the host binary into a private writable
`$HOME/.pint-mago` or `%LOCALAPPDATA%/.pint-mago` directory. That directory must
permit native execution; noexec mounts and OS application-control policies can
prevent execution. Binaries are version/hash-specific and verified before use.

Build instructions, upstream licenses, and size details are in
[scripts/README.md](scripts/README.md). Native compatibility must be validated
on each supported platform before a public release.
