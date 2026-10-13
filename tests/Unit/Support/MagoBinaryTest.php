<?php

use App\Support\MagoBinary;

it('selects the supported official binary', function (string $os, string $arch, string $expected) {
    $method = new ReflectionMethod(MagoBinary::class, 'platform');

    expect($method->invoke(null, $os, $arch))->toBe($expected);
})->with([
    ['Linux', 'x86_64', 'linux-x64'],
    ['Linux', 'aarch64', 'linux-arm64'],
    ['Darwin', 'arm64', 'darwin-arm64'],
    ['Darwin', 'x86_64', 'darwin-x64'],
    ['Windows', 'AMD64', 'windows-x64'],
]);

it('rejects unsupported platforms with actionable guidance', function (string $os, string $arch) {
    $method = new ReflectionMethod(MagoBinary::class, 'platform');

    expect(fn () => $method->invoke(null, $os, $arch))->toThrow(RuntimeException::class, 'Use 64-bit PHP');
})->with([['Windows', 'ARM64'], ['FreeBSD', 'amd64'], ['Linux', 'i686']]);

it('verifies the staged native binary and can execute it', function () {
    if (! is_file(dirname(__DIR__, 3).'/resources/mago/manifest.json')) {
        $this->markTestSkipped('Run python3 scripts/stage-mago.py to stage native artifacts.');
    }

    $process = new Symfony\Component\Process\Process([MagoBinary::path(), '--version']);
    $process->mustRun();

    expect($process->getOutput())->toContain(MagoBinary::VERSION);
});

it('rejects a binary with an incorrect digest', function () {
    $method = new ReflectionMethod(MagoBinary::class, 'verify');

    expect(fn () => $method->invoke(null, __FILE__, str_repeat('0', 64)))
        ->toThrow(RuntimeException::class, 'checksum mismatch');
});

it('extracts from a PHAR into a private cache and repairs corrupted cached bytes', function () {
    $root = dirname(__DIR__, 3);
    if (! is_file($root.'/resources/mago/manifest.json')) {
        $this->markTestSkipped('Run python3 scripts/stage-mago.py first.');
    }
    $directory = sys_get_temp_dir().'/pint-mago-test-'.bin2hex(random_bytes(8));
    mkdir($directory, 0700);

    try {
        $code = <<<'PHP'
            require $argv[1].'/app/Support/MagoBinary.php';
            $binary = App\Support\MagoBinary::path();
            $relative = basename(dirname($binary)).'/'.basename($binary);
            $phar = new Phar($argv[2].'/test.phar');
            $phar->addFile($argv[1].'/app/Support/MagoBinary.php', 'app/Support/MagoBinary.php');
            $phar->addFile($argv[1].'/resources/mago/manifest.json', 'resources/mago/manifest.json');
            $phar->addFile($binary, 'resources/mago/'.$relative);
            $phar->setStub('<?php require "phar://".__FILE__."/app/Support/MagoBinary.php"; echo App\Support\MagoBinary::path(); __HALT_COMPILER();');
            PHP;
        new Symfony\Component\Process\Process([
            PHP_BINARY,
            '-d',
            'phar.readonly=0',
            '-r',
            $code,
            $root,
            $directory,
        ])->mustRun();
        $process = new Symfony\Component\Process\Process([PHP_BINARY, $directory.'/test.phar'], env: [
            'HOME' => $directory,
            'LOCALAPPDATA' => $directory,
        ]);
        $process->mustRun();
        $path = $process->getOutput();

        expect($path)->toStartWith($directory.'/.pint-mago/');
        $digest = hash_file('sha256', $path);
        file_put_contents($path, 'corrupted');
        $process->mustRun();

        expect($process->getOutput())->toBe($path)->and(hash_file('sha256', $path))->toBe($digest);
        new Symfony\Component\Process\Process([$path, '--version'])->mustRun();
        if (PHP_OS_FAMILY !== 'Windows') {
            expect(fileperms(dirname($path)) & 0077)->toBe(0);
            chmod(dirname($path), 0777);
            $process->run();
            expect($process->getExitCode())
                ->not
                ->toBe(0)
                ->and($process->getErrorOutput())
                ->toContain('Unsafe Mago cache');
        }
    } finally {
        foreach (new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        ) as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($directory);
    }
});
