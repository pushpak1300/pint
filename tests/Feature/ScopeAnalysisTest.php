<?php

use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

it('checks inherited Eloquent scopes and keeps native fluent collection types', function () {
    $project = sys_get_temp_dir().'/pint-scopes-'.bin2hex(random_bytes(8));
    mkdir($project, 0700);
    try {
        symlink(base_path('vendor'), $project.'/vendor');
        file_put_contents($project.'/composer.json', '{"require":{"php":"^8.4"}}');
        file_put_contents($project.'/pint.json', '{"laravel-version":"13"}');
        file_put_contents($project.'/bootstrap.php', '<?php throw new RuntimeException("Must not boot Laravel");');
        foreach (['models', 'valid', 'invalid'] as $fixture) {
            copy(base_path('tests/Fixtures/mago/scopes-'.$fixture.'.php'), $project.'/'.ucfirst($fixture).'.php');
        }
        $analyze = function (string $file) use ($project): array {
            $process = new Process(
                [PHP_BINARY, base_path('pint'), 'analyze', $file, '--format=json'],
                $project,
                timeout: 120,
            );
            $process->run();

            return [$process->getExitCode(), json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR)];
        };
        [$status, $report] = $analyze('Valid.php');
        expect($status)->toBe(0)->and($report['issues'])->toBe([]);
        [$status, $report] = $analyze('Invalid.php');
        expect($status)
            ->toBe(1)
            ->and(array_unique(array_column($report['issues'], 'path')))
            ->toBe(['Invalid.php']);
        $lines = collect($report['issues'])
            ->groupBy('code')
            ->map(fn ($issues) => $issues->pluck('line')->unique()->values()->all())
            ->all();
        expect($lines)->toBe([
            'invalid-argument' => [13, 14, 16, 18, 20],
            'too-few-arguments' => [15],
            'invalid-named-argument' => [17],
            'invalid-pass-by-reference' => [19],
            'non-documented-method' => [21, 22, 23],
            'invalid-return-statement' => [29, 35],
        ]);
    } finally {
        new Filesystem()->remove($project);
    }
});
