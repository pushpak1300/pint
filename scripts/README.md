# Bundled Mago

Run `python3 scripts/stage-mago.py` from a source checkout before using Mago or
building Pint. It requires Python 3, curl, and network access **at build time
only**. The publish workflow runs it before the existing PHAR build.

The script downloads all five official Mago 1.53.0 release archives, checks
SHA256 values pinned from the official GitHub release API, and stages their
executables and original MIT/Apache-2.0 license notices in `resources/mago/`.
Its generated manifest records archive and executable hashes. Generated files
are deliberately ignored by Git; do not commit 144 MB of native executables.
Box includes this directory as binary data, without compacting its contents.
The existing single PHAR contains all five platforms; runtime never downloads.

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

The five binaries total 144,006,800 bytes (~137.3 MiB) uncompressed and about
55,132,189 bytes (~52.6 MiB) with gzip, **in addition to** Pint's PHP payload.
Only the host binary (~25–30 MiB) is extracted into the runtime cache.

Focused checks: `php vendor/bin/pest tests/Unit/Support/MagoBinaryTest.php` and
`php vendor/laravel-zero/framework/bin/box validate box.json`.
