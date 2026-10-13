# Bundled Mago

Run `python3 scripts/stage-mago.py` from a source checkout before using Mago or
building Pint. It requires Python 3, curl, and network access **at build time
only**. The publish workflow runs it before the existing PHAR build.

The script downloads all five official Mago 1.56.0 release archives, checks
SHA256 values pinned from the official GitHub release API, and stages their
executables and original MIT/Apache-2.0 license notices in `resources/mago/`.
Its generated manifest records archive and executable hashes. Generated files
are deliberately ignored by Git; do not commit 142 MB of native executables.
Box includes this directory as binary data, without compacting its contents.
The existing single PHAR contains all five platforms; runtime never downloads.

Composer also installs Mago's PHP extension SDK and nikic/php-parser for the
bundled Pint cleanup worker. Box includes those PHP sources, not Mago's Rust
source tree. The worker runs with the same PHP executable as Pint and loads
the PHAR's own autoloader in packaged builds. It never invokes the SDK's binary
downloader or loads project-defined extensions.

Use `App\Support\MagoBinary::path()` as the executable argument to a process,
for example `new Symfony\Component\Process\Process([MagoBinary::path(), '--version'])`.
Source checkouts execute the verified staged file directly. PHARs verify the
embedded file and extract it under a lock, using an atomic rename, into
`$HOME/.pint-mago` (Unix) or `%LOCALAPPDATA%/.pint-mago` (Windows). The cache is
version/platform/hash-specific and verified on every use. Unix cache directories
must be owned and mode 0700; symlink cache directories and executable paths are
rejected. Windows uses the user's LocalAppData inherited ACL, which must remain
private. No executable is trusted from the shared system temporary directory.
If execution is blocked by a noexec mount, antivirus, or application-control
policy, allow the native executable in this private cache; PHP cannot bypass
those OS policies.

Supported: 64-bit PHP on Linux x64/ARM64 (official static musl releases), macOS
x64/ARM64, and Windows x64 (official MSVC release). There is no Windows ARM64
release. Other OSes, 32-bit PHP, and other architectures produce an actionable
error. Native macOS/Windows and Linux ARM64 execution must still be checked on
those hosts; selection and hashes can be checked anywhere.

The five binaries total 142,417,912 bytes (~135.8 MiB) uncompressed and about
54,272,574 bytes (~51.8 MiB) with gzip, **in addition to** Pint's PHP payload.
Only the host binary (~25–30 MiB) is extracted into the runtime cache.

Focused checks: `php vendor/bin/pest tests/Unit/Support/MagoBinaryTest.php` and
`php vendor/laravel-zero/framework/bin/box validate box.json`.
