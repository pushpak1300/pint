# Upgrading to Pint 2.x

Pint 2.x replaces PHP-CS-Fixer with bundled Mago 1.56.0 and adds Laravel quality
checks. **Bare `pint` now checks without changing source.** Move existing
formatting scripts and editor integrations to `pint format`. Exact formatting
and cleanup are not identical to Pint 1.x. Review the first formatting commit
separately from application changes. This branch is experimental, not an official
replacement for PHPStan/Larastan or Rector.

Running Pint 2.x requires PHP 8.4 or later within PHP 8.x. The `php-version`
setting controls the source code target and may still be set to an older version.

## Commands

Run from the application's root. Quality commands initially target Laravel 12
and 13. PHP support is inferred from `composer.json`'s `require.php` floor;
Laravel is read from installed Composer metadata, not a potentially stale lockfile.
Explicit `php-version` and `laravel-version` settings override inference. Unknown
or unsupported versions fail clearly before fixes run; standalone formatting
does not require Laravel to be installed.

| Command | Behavior |
| --- | --- |
| `pint` / `pint check` | Read-only check of all enabled stages |
| `pint fix` | Apply all enabled fixes, including behavior-changing fixes; check the final source |
| `pint format` | Existing formatting/selected-cleanup pipeline and reports |
| `pint format --test` | Existing formatting-only CI check |
| `pint lint` / `pint lint --fix` | Laravel lint diagnostics / apply their available fixes |
| `pint analyze` | Project-aware type and correctness analysis |
| `pint guard` | Laravel namespace conventions and configured architecture rules |
| `pint refactor -v` | Preview enabled compatibility refactors, with diffs |
| `pint refactor --apply` | Apply enabled refactors, including behavior changes |

`fix`, `lint --fix`, and `refactor --apply` are explicit permission to apply
**all enabled fixes**, without a separate `--unsafe` flag. They do not enable
every rule. Review their diff and run application tests. Remaining errors cause
exit 1; changed files alone do not. Advisory warnings are nonblocking unless
`--strict` is supplied. Notes/help remain advisory. These commands check the
resulting files again rather than treating a successful write as a clean result.

Quality checks report by file, line, stage, rule, explanation, and actual native
fixability. `--format=json` returns `{result, changed, issues}`; `--format=agent`
returns the same data as compact single-line JSON. Use `-v` for remediation help
in text output. Formatting-only reports keep their previous shapes. Files are
sorted for stable reports. No agent hooks or autonomous repair loop are installed.

All project-owned PHP is selected by default, including tests, routes, config,
and database files. Vendor and generated files are never quality-check write
targets, even when explicitly named. Analysis can still read vendor and unselected
application types. Applications are not bootstrapped or executed. Blade formatting
is opt-in and Blade templates are not passed to the PHP analyzer.

## Configuration

Keep `pint.json`, `preset`, `extend`, `in`, `exclude`, `notName`, `notPath`, and
`cache-file`. One optional `pint.json` configures every stage:

```json
{
    "preset": "laravel",
    "format": { "print-width": 120 },
    "lint": {
        "rules": {
            "no-request-all": { "enabled": true, "level": "warning" }
        }
    },
    "analyze": { "strictness": "balanced" },
    "guard": { "enabled": true },
    "refactor": { "rules": { "strict_comparison": false } }
}
```

Each section accepts `enabled: false`. `format` accepts native formatter
options, with presets limited to Laravel and PSR-12; `lint.rules` accepts native
rule option objects and the bundled `pint/env-outside-config` rule; `analyze`
and `guard` accept native analyzer/guard settings. Pint owns file selection,
severity exit policy, and baselines: use top-level exclusions, `--strict`, and
`baseline`, not their native section equivalents. A rule's `exclude` option
exempts paths from that rule only.
Explicitly configured lint rules default to error severity unless a `level`
is supplied. Changing a rule's level retains its default path exclusions;
supplying `exclude` replaces them, and `exclude: []` removes them.

Legacy `formatter`, `linter`, and top-level `rules` remain aliases for `format`,
`lint`, and `refactor.rules`. Conflicting old/new values are rejected, not silently
chosen. Unsupported legacy rules/options still produce errors.

### Laravel defaults and current limits

Native Laravel lint checks cover request `all()` use, middleware placement,
anonymous migrations, array validation rules, casts methods, dedicated HTTP
assertions, the fake helper, and view arrays. Pint also enables native Pest and
PHP correctness checks. The following policy applies without extra configuration:

| Rule | Default | Purpose / limits |
| --- | --- | --- |
| `no-only` | Error | Prevent focused Pest tests from silently narrowing the suite. A native fix removes `->only()`. |
| `no-debug-symbols` | Error | Report debug function calls outside `tests/`, `scripts/`, and `tools/`. Method calls are not checked; `print_r` / `var_export` with a second argument are allowed. No automatic deletion. |
| `prefer-array-validation-rules` | Error | Prefer Laravel's array validation-rule syntax. Native fixes are available. |
| `no-request-all` | Warning | Flag common syntactic `all()` calls, not arbitrary unvalidated data flow. `only()` restricts fields but does not validate them; there is no automatic replacement. |
| `strict-behavior` | Warning | Prefer strict membership/search/decode operations. Adding strictness can change behavior. |
| `use-simpler-expectation` | Warning | Prefer direct Pest expectations to intermediate boolean checks where native patterns support it. This is not complete Pest analysis. |
| `pint/env-outside-config` | Warning | Read application settings through `config()` outside root configuration files. See the scope below. No automatic fix. |

Other Laravel conventions remain advisory. Forced final controllers, blanket
strict types, loose-comparison refactors, and heuristic implicit model-query
rewriting stay opt-in. Pint does not impose service/repository layers or ban
facades or raw queries. No custom copies of native Mago rules ship.

`strict-behavior` fixes can change results such as `in_array('1', [1])` by adding
strict comparison. The existing `fix` / `lint --fix` permission boundary includes
these enabled potentially unsafe fixes. Read-only `lint` never applies them;
review explicit fixes and run tests. Disable the rule if coercion is intentional.

#### Environment reads outside configuration

`pint/env-outside-config` uses Mago's parsed syntax and resolved names, not a text
search. It checks global `env()`, `\env()`, imported aliases, and unqualified
namespaced calls that can fall back to Laravel's helper. It ignores method calls,
explicitly qualified non-global helpers, and functions declared in the same file.
It cannot establish namespace shadowing from a helper declared in another file
or resolve dynamic function calls. This is an advisory configuration-cache check,
not a security guarantee or runtime analysis.

Root `config/` files, including nested configuration directories, are always
allowed. `app/config/` is not the application's root configuration directory.
By default, `bootstrap/`, `tests/`, `scripts/`, and `tools/` are exempt because
environment access can be intentional there. Override the rule in `pint.json`:

```json
{
    "lint": {
        "rules": {
            "pint/env-outside-config": {
                "level": "warning",
                "exclude": ["bootstrap/", "tests/", "scripts/", "tools/", "app/Legacy/**"]
            }
        }
    }
}
```

Only `enabled`, `level`, and `exclude` are accepted for this bundled rule.
Exclusions accept project-relative path prefixes and globs; they replace the
default exceptions, but never forbid environment reads in root `config/`.
Unknown options, invalid severities, and malformed patterns fail before fixes.
Formatting and analysis do not run this lint worker. Lint runs it alongside
native rules in one Mago pass, without starting the analyzer or cleanup workers.

For example, define the environment-backed setting in `config/services.php`,
then read `config('services.billing.endpoint')` in application code instead of
`env('BILLING_ENDPOINT')`. Pint cannot choose the configuration key and default
for you, so even explicit fix commands leave these calls unchanged.

Balanced analysis disables unused-definition/expression reporting to avoid
misclassifying Laravel entry points; `analyze.strictness: strict` additionally
checks missing type hints, throws, and property initialization. Mago reads
Laravel's PHPDoc for Eloquent builders, collections, and annotated relationships.
Pint adds the model property inference described below; it does not run PHPStan
or Larastan behind the scenes.

Guard checks class suffixes in `App\Http\Controllers`, `App\Http\Requests`,
and `App\Policies`. These are namespace conventions, not inheritance or physical
file-placement checks. Custom `guard.structural.rules` replaces these defaults;
`guard.perimeter` architecture boundaries are opt-in. Native matching is
case-insensitive. Guard does not automatically rename files/classes.

The initial refactor defaults remove superfluous `elseif` and useless `else`.
The supported compatibility rules below remain available; `strict_types`, final
classes, and behavioral changes such as strict comparisons require explicitly
enabling their rules. Major Laravel/PHP migration sets are not implemented.

### Laravel model analysis

`pint analyze`, `check`, and the final check after `fix` automatically use Pint's
bundled Mago analyzer extension. No extra configuration or application bootstrap
is required. Installed Laravel sources must be available for model ancestry and
generic metadata. Unselected application models are scanned too; only selected
files receive diagnostics. Formatting, linting, and guard do not start this worker.
Mago 1.56.0 ties extension scans to analyzed files: even a selected-file analysis
currently analyzes all first-party PHP to supply model bodies to the extension,
then filters the report. This can cost more than native selected-file analysis.

Supported **property reads and writes** on Eloquent models:

- Typed `getFooAttribute()` / `setFooAttribute()` accessors and annotated
  `Attribute<TGet, TSet>` accessors use separate read and write contracts.
- Literal `$casts` maps and simple `casts()` methods returning strings or class names.
  Method entries override property entries, including inherited declarations.
  Primitive, encrypted, enum, immutable date, collection, array-object, and
  stringable casts are recognized. Primitive and enum casts remain nullable
  without a schema or explicit property annotation. Decimal reads are numeric
  strings; writes also accept integers and floats.
- Custom `CastsAttributes` getters and setter value parameters, inbound-only
  casters, and `Castable` factories with concrete caster return types. Their
  declared contracts determine nullability; unresolved templates fall back to
  Mago rather than inventing a type.
- Generic `HasMany`, `BelongsToMany`, `MorphMany`, `MorphToMany`, and `HasManyThrough`
  methods produce typed Eloquent collections. Generic `BelongsTo`, `HasOne`,
  `MorphOne`, and `HasOneThrough` methods produce nullable related models on reads.

Declared properties and explicit `@property` contracts take precedence. Accessors
take precedence over casts; casts take precedence over relationships. Unknown
or conditional `casts()` bodies are not executed or guessed. PHPDoc generics are
still required for relation element types and modern accessor return types.

**Local scopes:** inherited `scopeFoo()` methods provide argument checks and
fluent builder/relation types, including typed custom builders. Protected
`#[Scope]` methods work through builders and relations. Direct calls such as
`User::active()` to a protected attributed method still fail Mago's native
visibility/static checks; the extension does not suppress those diagnostics.
Scalar-returning scopes keep their declared return type rather than forcing a
builder result. Unresolved generic scope contracts are not expanded.

**Eloquent query calls:** model calls such as `User::where()`, `create()`,
`findOrFail()`, and `each()` use installed Builder signatures, including argument
validation and model/collection element types. Scalar and array finder inputs
keep different return types. Typed `newEloquentBuilder()` overrides and literal
query passthrough lists are respected. Unsupported templates, custom dispatch,
and builder attributes/static properties retain native inference rather than
inventing a return type.

**Conditional chains:** `when()` and `unless()` keep the receiver type when their
callbacks return only `void` or `null`, matching Laravel's null-coalescing return.
Other callback results and higher-order calls keep native inference.

**Framework helpers:** standard `app()`, `auth()`, and `view()` calls refine to
their default concrete framework classes. Application helper overrides and
detected container rebinding calls retain native contracts. This assumes Laravel's
standard services, not arbitrary package/runtime container mutations. Auth guard
selection and the configured user model remain incomplete; Pint does not guess
an environment-dependent model class or silently treat every guard as stateful.

**HTTP client:** standard factory/facade requests return synchronous responses;
fluent `async()` calls preserve promises and unknown boolean modes preserve both
possibilities. Native argument validation remains active. Custom factory roots
and receiver mutations such as `$request->async();` without using the returned
request still need native engine support; return-type providers cannot implement
general `@phpstan-self-out` flow updates.

**Configuration:** literal values in root `config/*.php` files refine `config()`,
`Config::get()`, and config repository `get()` calls, including dot keys, defaults,
class strings, and nested array shapes. Unknown array values remain `mixed`
without discarding known siblings. Dynamic keys, expressions, dynamic array keys,
and missing config files retain native inference. Floats and nested config
directories are not yet inferred. Defaults apply to absent keys in known static
arrays, not known null values. This describes source configuration, not changes
made by service providers or `Config::set()` at runtime. Neither `.env` nor config
files are executed.

#### Larastan parity status

The reference inventory is Larastan **3.12.0**, compared with the bundled Mago
**1.56.0** SDK. The supported cases above are tested through Pint's CLI, not a
claim that Larastan's complete test suite passes. Full parity is not implemented.

| Area | Remaining work |
| --- | --- |
| Database schema, migrations, SQL dumps, model serialization shapes | Static inference not implemented. |
| Factories, collection refinements/higher-order proxies, relationship validation and contextual callbacks | Additional providers and validation are still needed; native generic support alone is not parity. |
| Auth/request helpers, queue/dispatch/model rules, views and translations | Laravel-specific coverage remains incomplete. |
| Container bindings, facade roots/fakes, macros, drivers, custom date factories | Arbitrary registrations need application runtime information; no runtime metadata export is implemented. |
| Runtime model metadata and relationships | `mergeCasts()`, dynamic relationship resolvers, overridden model metadata and custom relation types are not modeled. |
| PHPDoc `model-property`, `builder-of`, `collection-of`, `view-string` | Mago's extension SDK has no custom PHPDoc semantic resolver; copying Larastan's stubs is insufficient. |
| Synthetic method metadata and generic substitution | Effective call signatures do not populate codebase metadata, and provider types do not receive native template substitution. Native engine/SDK work is needed for a general solution. |

These are separate constraints: some features need more Pint code, others need
engine support, and runtime-derived facts require an explicit discovery mechanism.
Pint does not boot applications, run PHPStan/Larastan behind the scenes, or silence
unknown types to simulate parity. Use explicit PHPDoc where supported until the
relevant inference is available.

### Baselines

```sh
pint --generate-baseline --baseline=pint-baseline.json
pint --baseline=pint-baseline.json
```

Or set `"baseline": "pint-baseline.json"` in `pint.json`. Creation is always
explicit; missing or malformed baselines fail. Generation never overwrites an
unrelated existing JSON file. Entries count matching path/stage/code/message
occurrences, so added occurrences remain visible even when line numbers move.
Formatting differences and formatter failures are never baselined. Generating
a baseline can therefore still return exit 1 when formatting changes are needed.

### Formatting-only compatibility

`pint format` keeps the existing selected-cleanup pipeline: it applies safe
fixes rather than reporting all lint diagnostics. Native options override the
format preset. Rules that only report issues do not become formatting failures.

For this formatting-only command, potentially unsafe and unsafe edits require `"fix-safety":
"potentially-unsafe"` or `"fix-safety": "unsafe"` respectively. This applies
only to the selected rules. For example, adding `strict_types` requires enabling
`strict-types` and allowing its unsafe fix. The default is `safe`; changing the
setting can change program behavior, so review these edits carefully.

Formatting infers `php-version` from Composer when possible, with the host PHP
major/minor as a standalone fallback. Pin it to keep standalone formatting
consistent across machines. Pint ignores `mago.toml` and `MAGO_*`
environment variables, and does not load arbitrary Mago extensions.

## Presets and cleanup

Only `laravel` (the default) and `psr12` are supported. Select them with the
top-level `preset` setting or `--preset`.

- `laravel`: Mago's Laravel style plus available options matching Pint 1.x.
  Safe cleanup enables short arrays, unused/redundant import removal, and
  shortening/importing fully qualified class-like references in PHP code.
- `psr12`: Mago's PSR-12 preset with unused/redundant import removal.

`per`, `symfony`, and `empty` have been removed. Select `laravel` or `psr12`
explicitly; removed presets are rejected, not silently mapped to another style.
Native `format.preset` overrides, including the legacy `formatter` alias, accept
only `laravel` or `psr-12`; Mago's `default` (PER) preset is not available through
Pint.

Mago can wrap lines at its print width. For whole PHP files, the Laravel preset
finishes single-line anonymous-class signatures with a next-line opening brace,
and aligns Laravel pipe-style block comments. An explicit
`formatter.closure-brace-style` disables the anonymous-class finishing policy.
Other presets retain native Mago layout.
This token-based pass runs before output validation and adds no AST parse.
Chain, argument, callback, and expression wrapping can still differ from Pint 1.x.
Mago may add trailing commas beyond arrays; Pint does not strip them because
Pint 1.x preserves existing multiline call/parameter commas too.
PHPDoc ordering/alignment/tag removal, PHPUnit method renaming and lifecycle
visibility, and the old filename-based class-renaming rule are not carried over.
Alias-function replacement is not enabled by default.

## Selected Pint 1.x cleanup rules

Pint includes its own Mago extension for explicitly selected legacy rules.
PHP-CS-Fixer is not a runtime dependency. The initial subset covers Pinkary's
explicit PHP cleanup configuration:

- Classes: `final_class`, `final_internal_class`,
  `final_public_method_for_abstract_class`, `protected_to_private`,
  `visibility_required` (also `modifier_keywords`), `ordered_class_elements`,
  `ordered_interfaces`, `ordered_traits`, `self_accessor`, `self_static_accessor`.
- Imports: `global_namespace_import`, `fully_qualified_strict_types`.
- Expressions and statements: `array_push`, `backtick_to_shell_exec`,
  `date_time_immutable`, `declare_strict_types`, `mb_str_functions`,
  `modernize_types_casting`, `no_superfluous_elseif`, `no_useless_else`,
  `strict_comparison`.
- Native-backed aliases: `array_syntax` accepts `syntax: short` or `long`
  (`true` means short; `false` preserves array syntax). `concat_space` accepts
  `spacing: none` or `one` (`true` means none; `false` is unsupported).
  `blank_line_before_statement` accepts only `statements: ["return"]` or `false`.
  `no_unused_imports` enables/disables Mago's `no-redundant-use`, which also removes
  other redundant imports. Conflicting native options and aliases are rejected.
  These mappings do not start the PHP cleanup worker.
- Native formatting: `lowercase_keywords`, `lowercase_static_reference`,
  `no_multiple_statements_per_line` must be `true`; Mago always applies them.
  Only `new_with_parentheses: false` is accepted; Mago still controls parentheses.
- `Pint/laravel_blade` retains its existing meaning; a conflicting top-level
  `blade` value is rejected.

For example:

```json
{
    "preset": "laravel",
    "php-version": "8.4",
    "fix-safety": "unsafe",
    "rules": {
        "final_class": true,
        "declare_strict_types": true,
        "strict_comparison": true,
        "ordered_interfaces": true
    }
}
```

In `pint format`, risky rules require `"fix-safety": "unsafe"`: `array_push`,
`date_time_immutable`, `declare_strict_types`, the three `final_*` rules,
`mb_str_functions`, `modernize_types_casting`, `ordered_traits`, `self_accessor`,
and `strict_comparison`. Without that opt-in, these rules are skipped; safe
selected rules still run. This distinction matters when reusing Pinkary's config.

This is cleanup compatibility, not byte-for-byte PHP-CS-Fixer compatibility or
the complete Laravel preset. Unsupported options are rejected. Import rewriting
handles common PHPDoc types conservatively; complex generics, shapes, and callable
signatures are left untouched. Mago controls wrapping and native cleanup,
including unused-import removal. Review the resulting diff before adoption.

Import cleanup preserves qualified function/constant namespace prefixes. Backtick
conversion calls the global `shell_exec`, even when a local function shadows it.
`mb_str_functions` translates named `substr(offset: ...)` arguments to
`mb_substr(start: ...)`; unpacked `substr` calls remain unchanged in behavior because
their runtime argument names cannot be translated safely.

Only Pint's bundled worker runs, and only for whole PHP files needing cleanup. Arbitrary
project extension hosts remain ignored. Formatting-only cache hits do not start
Mago or a worker. Project quality checks still run native analysis/lint/guard;
the formatting cache is not an analysis cache.
During a run, exact inputs that need no compatibility edits are remembered in
the private temporary workspace, avoiding repeated cleanup on later fix passes.
Changed inputs are checked again; this temporary cache is removed with the
workspace and is never shared between configurations or invocations.

Mago 1.56.0 fixes nested-chain formatting oscillation and preserves required
parentheses in PHP 8.3 property/nullsafe-property constructor chains. Import fixes
now include their required imports and apply shared edits only once. Formatter
options are unchanged from 1.55.0, so the compatibility rules above remain Pint-owned.
Existing native rules such as `no-redundant-else` and `no-protected-in-final`
overlap only parts of their legacy equivalents; replacing the bundled transforms
would lose supported behavior. The PHP SDK is also pinned to 1.56.0. MEXT remains
at 1.0; the new MANA 2.0 protocol is analyzer-only and does not change Pint's worker.
Analyzer optimizations apply to project checks, not formatting-only runs. Rust-only
range formatting is not exposed as a Pint command. Parser/printer reuse and the
per-run cleanup cache remain enabled. Upgrading the bundled version invalidates
previous formatting-cache entries.

## Blade

`--blade` and `"blade": true` keep the existing Prettier/Blade pipeline and its
Node.js or Bun dependencies. Embedded PHP uses Mago while retaining imports and
closing tags. Incomplete PHP islands are left untouched. Fragment cleanup is
restricted to safe array-style changes; whole-file lint rules and unsafe fixes
never run on fragments. Some wrapping, comments' layout, and anonymous-class
formatting differ. A standalone PHAR still needs the accompanying Pint JavaScript
resources to support Blade, as in 1.x.

## Formatting-only execution and reports

`--parallel` enables Mago's native threads for PHP batches. `--max-processes`
limits native threads and must be positive. The bundled cleanup extension uses
one worker to avoid repeated PHP startup costs across fix passes. Blade continues
through the existing JavaScript worker. `--bail` stops after the first file with a
change or error and never modifies source.

`--test` never writes source; `--repair` writes fixes but returns 1 when files
change. Invalid source is reported and left untouched. Both modes return 1 on
errors. Normal successful fixing returns 0.

Explicit file arguments bypass directory-discovery exclusions, as in Pint 1.x.
Stdin cannot be combined with `--dirty` or `--diff`. Cache paths resolving to a
selected source file or an effective local configuration file are rejected before
writes, including symbolic-link aliases.

Generated output is validated against the configured PHP target, including after
Laravel layout finishing. Invalid output is rejected without changing the source;
Pint does not silently raise the PHP target or reconstruct source from diagnostics.

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
