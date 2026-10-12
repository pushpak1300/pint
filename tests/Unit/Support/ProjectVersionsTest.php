<?php

use App\Support\ProjectVersions;

it('infers the lowest allowed PHP version from Composer constraints', function (string $constraint, string $expected) {
    $root = freshBladeTempDirectory();
    file_put_contents($root.'/composer.json', json_encode(['require' => ['php' => $constraint]]));

    expect(ProjectVersions::php($root))->toBe($expected);
})->with([
    ['^8.2', '8.2'],
    ['~8.3.4', '8.3.4'],
    ['>=8.2 <8.4', '8.2'],
    ['^8.4 || ^8.2', '8.2'],
    ['^8.3 || >=8.1.5 <8.2', '8.1.5'],
    ['8.2.*', '8.2'],
    ['8.2 - 8.4', '8.2'],
    ['>8.2.0 <8.3', '8.2.1'],
    ['>=8.2 !=8.2.0 <8.3', '8.2.1'],
]);

it('rejects missing or indeterminate PHP requirements', function (array $composer, string $message) {
    $root = freshBladeTempDirectory();
    file_put_contents($root.'/composer.json', json_encode($composer));

    expect(fn () => ProjectVersions::php($root))->toThrow(RuntimeException::class, $message);
})->with([
    [[], 'require.php'],
    [['require' => ['php' => '*']], 'Cannot infer a PHP floor'],
    [['require' => ['php' => '<8.5']], 'Cannot infer a PHP floor'],
    [['require' => ['php' => 'banana']], 'Invalid Composer PHP constraint'],
    [['require' => ['php' => '>=8.4 <8.2']], 'Cannot infer a PHP floor'],
    [['require' => ['php' => 'dev-main']], 'Cannot infer a PHP floor'],
]);

it('reads Laravel from either Composer installed package layout', function (array $installed, string $expected) {
    $root = freshBladeTempDirectory();
    mkdir($root.'/vendor/composer', recursive: true);
    file_put_contents($root.'/vendor/composer/installed.json', json_encode($installed));

    expect(ProjectVersions::laravel($root))->toBe($expected);
})->with([
    [[['name' => 'laravel/framework', 'version' => 'v12.2.3']], '12.2.3'],
    [['packages' => [['name' => 'laravel/framework', 'version' => 'v13.1.0']], 'dev' => true], '13.1'],
    [
        [
            'packages' => [
                ['name' => 'other/package', 'version' => '1.0.0'],
                ['name' => 'laravel/framework', 'version_normalized' => '12.0.0.0'],
            ],
        ],
        '12.0',
    ],
]);

it('rejects unknown and unsupported installed Laravel versions', function (array $packages, string $message) {
    $root = freshBladeTempDirectory();
    mkdir($root.'/vendor/composer', recursive: true);
    file_put_contents($root.'/vendor/composer/installed.json', json_encode(['packages' => $packages]));

    expect(fn () => ProjectVersions::laravel($root))->toThrow(RuntimeException::class, $message);
})->with([
    [[], 'install [laravel/framework]'],
    [[['name' => 'illuminate/support', 'version' => 'v12.0.0']], 'install [laravel/framework]'],
    [[['name' => 'laravel/framework', 'version' => 'v11.9.0']], 'Unsupported Laravel version'],
    [[['name' => 'laravel/framework', 'version' => 'v14.0.0']], 'Unsupported Laravel version'],
    [[['name' => 'laravel/framework', 'version' => 'dev-main']], 'specify a numeric version'],
]);

it('does not mistake a lockfile for an installed application', function () {
    $root = freshBladeTempDirectory();
    file_put_contents($root.'/composer.lock', '{"packages":[{"name":"laravel/framework","version":"v12.0.0"}]}');

    expect(fn () => ProjectVersions::laravel($root))
        ->toThrow(RuntimeException::class, 'vendor/composer/installed.json');
});

it('lets explicit versions win even without Composer files', function () {
    $root = freshBladeTempDirectory();

    expect(ProjectVersions::php($root, '8.4'))->toBe('8.4')->and(ProjectVersions::laravel($root, '13'))->toBe('13.0');

    file_put_contents($root.'/composer.json', 'invalid json');
    mkdir($root.'/vendor/composer', recursive: true);
    file_put_contents(
        $root.'/vendor/composer/installed.json',
        '{"packages":[{"name":"laravel/framework","version":"v11.0.0"}]}',
    );

    expect(ProjectVersions::php($root, '8.2'))->toBe('8.2')->and(ProjectVersions::laravel($root, '12'))->toBe('12.0');
});

it('rejects invalid explicit versions instead of falling back', function () {
    $root = freshBladeTempDirectory();

    expect(fn () => ProjectVersions::php($root, '^8.4'))
        ->toThrow(RuntimeException::class, 'php-version')
        ->and(fn () => ProjectVersions::laravel($root, '11'))
        ->toThrow(RuntimeException::class, 'Unsupported Laravel version');
});

it('reports malformed project manifests clearly', function () {
    $root = freshBladeTempDirectory();
    file_put_contents($root.'/composer.json', '{');
    mkdir($root.'/vendor/composer', recursive: true);
    file_put_contents($root.'/vendor/composer/installed.json', 'null');

    expect(fn () => ProjectVersions::php($root))
        ->toThrow(RuntimeException::class, 'Invalid JSON')
        ->and(fn () => ProjectVersions::laravel($root))
        ->toThrow(RuntimeException::class, 'Expected a JSON object');

    file_put_contents($root.'/vendor/composer/installed.json', '{"packages":true}');
    expect(fn () => ProjectVersions::laravel($root))->toThrow(RuntimeException::class, 'Invalid package list');
});
