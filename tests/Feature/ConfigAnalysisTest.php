<?php

use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

beforeEach(function () {
    $this->project = sys_get_temp_dir().'/pint-config-'.bin2hex(random_bytes(8));
    mkdir($this->project, 0700);
    mkdir($this->project.'/config');
    mkdir($this->project.'/app/config', 0700, true);
    symlink(base_path('vendor'), $this->project.'/vendor');
    file_put_contents($this->project.'/composer.json', '{"require":{"php":"^8.4"}}');
    file_put_contents($this->project.'/pint.json', '{"laravel-version":"13"}');
    file_put_contents($this->project.'/.env', 'PINT_CONFIG_RUNTIME=must-not-be-read');
    file_put_contents($this->project.'/bootstrap.php', '<?php throw new RuntimeException("Must not boot Laravel");');
    file_put_contents($this->project.'/app/config/settings.php', '<?php return ["name" => 123];');
    file_put_contents($this->project.'/app/config/foreign.php', '<?php return ["name" => 123];');
    file_put_contents($this->project.'/config/settings.php', <<<'PHP'
        <?php
        use DateTimeImmutable as Clock;
        return [
            'name' => 'Pint',
            'enabled' => true,
            'disabled' => false,
            'count' => -12,
            'nil' => null,
            'clock' => Clock::class,
            'nested' => ['port' => 8080, 'deep' => ['label' => 'nested']],
            'list' => ['one', 'two'],
            'empty' => [],
            'legacy' => array('label' => 'old'),
            'escaped' => "line\nvalue",
            'runtime' => env('PINT_CONFIG_RUNTIME'),
            'call' => strlen('runtime'),
            'partial' => ['known' => 42, 'unknown' => env('PINT_CONFIG_RUNTIME')],
            'dot.key' => 'not reached by dot traversal',
        ];
        PHP);
    file_put_contents(
        $this->project.'/config/dynamic.php',
        '<?php return [env("PINT_CONFIG_RUNTIME") => 1, "known" => true];',
    );
    file_put_contents($this->project.'/config/runtime.php', '<?php return getenv("PINT_CONFIG_RUNTIME");');

    $this->analyzeConfig = function (string $source): array {
        file_put_contents($this->project.'/Check.php', $source);
        $process = new Process(
            [PHP_BINARY, base_path('pint'), 'analyze', 'Check.php', '--format=json'],
            $this->project,
            timeout: 120,
        );
        $process->run();
        $report = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
        expect(file_get_contents($this->project.'/Check.php'))->toBe($source);
        expect(array_unique(array_column($report['issues'], 'path')))
            ->toBe($report['issues'] === [] ? [] : ['Check.php']);

        return [$process->getExitCode(), $report];
    };
});

afterEach(function () {
    new Filesystem()->remove($this->project);
});

it('infers unselected static config values and uses defaults only for absent keys', function () {
    [$status, $report] = ($this->analyzeConfig)(<<<'PHP'
        <?php
        namespace ConfigValidChecks;
        use Illuminate\Config\Repository;
        use Illuminate\Contracts\Config\Repository as Contract;
        use Illuminate\Support\Facades\Config;
        function name(): string { return config('settings.name'); }
        function enabled(): bool { return config('settings.enabled'); }
        function disabled(): bool { return config('settings.disabled'); }
        function count(): int { return config('settings.count'); }
        function nil(): null { return config('settings.nil', 'not a replacement'); }
        function nested(): int { return config('settings.nested.port'); }
        function deep(): string { return config('settings.nested.deep.label'); }
        /** @return array{port: int, deep: array{label: string}} */
        function shape(): array { return config('settings.nested'); }
        /** @return array{0: string, 1: string} */
        function listValue(): array { return config('settings.list'); }
        function emptyValue(): array { return config('settings.empty'); }
        function legacy(): string { return config('settings.legacy.label'); }
        function escaped(): string { return config('settings.escaped'); }
        /** @return class-string<\DateTimeImmutable> */
        function clock(): string { return config('settings.clock'); }
        function missing(): null { return config('settings.nested.absent'); }
        function defaultValue(): string { return config('settings.absent', 'fallback'); }
        function scalarPath(): int { return config('settings.name.absent', 10); }
        function named(): int { return config(default: 10, key: 'settings.absent'); }
        function actualWins(): string { return config('settings.name', 10); }
        function partial(): int { return config('settings.partial.known'); }
        /** @return array{known: int, unknown: mixed} */
        function partialArray(): array { return config('settings.partial'); }
        function repository(Repository $config): string { return $config->get('settings.name'); }
        function contract(Contract $config): int { return $config->get('settings.count'); }
        function facade(): bool { return Config::get('settings.enabled'); }
        function lazyDefault(): string { return config('settings.absent', fn (): string => 'fallback'); }
        function dotted(): null { return config('settings.dot.key'); }
        PHP);

    expect($status)->toBe(0)->and($report['issues'])->toBe([]);
});

it('rejects incompatible static config returns including defaults and repository reads', function () {
    [$status, $report] = ($this->analyzeConfig)(<<<'PHP'
        <?php
        namespace ConfigInvalidChecks;
        use Illuminate\Config\Repository;
        use Illuminate\Contracts\Config\Repository as Contract;
        use Illuminate\Support\Facades\Config;
        function name(): int { return config('settings.name'); }
        function enabled(): string { return config('settings.enabled'); }
        function disabled(): int { return config('settings.disabled'); }
        function count(): string { return config('settings.count'); }
        function nil(): string { return config('settings.nil', 'not a replacement'); }
        function nested(): string { return config('settings.nested.port'); }
        function deep(): int { return config('settings.nested.deep.label'); }
        function shape(): string { return config('settings.nested'); }
        function listValue(): string { return config('settings.list'); }
        function emptyValue(): int { return config('settings.empty'); }
        function legacy(): int { return config('settings.legacy.label'); }
        function clock(): int { return config('settings.clock'); }
        function missing(): string { return config('settings.nested.absent'); }
        function partialArray(): int { return config('settings.partial'); }
        function defaultValue(): int { return config('settings.absent', 'fallback'); }
        function named(): string { return config(default: 10, key: 'settings.absent'); }
        function actualWins(): int { return config('settings.name', 10); }
        function partial(): string { return config('settings.partial.known'); }
        function repository(Repository $config): int { return $config->get('settings.name'); }
        function contract(Contract $config): string { return $config->get('settings.count'); }
        function facade(): int { return Config::get('settings.enabled'); }
        function lazyDefault(): int { return config('settings.absent', fn (): string => 'fallback'); }
        PHP);

    $invalid = array_values(array_filter(
        $report['issues'],
        fn ($issue) => $issue['code'] === 'invalid-return-statement',
    ));
    expect($status)->toBe(1)->and(array_column($invalid, 'line'))->toBe(range(6, 27));
    expect(array_unique(array_column($report['issues'], 'code')))->toBe(['invalid-return-statement']);
});

it('preserves native mixed fallback for dynamic keys and unknown config expressions', function () {
    [$status, $report] = ($this->analyzeConfig)(<<<'PHP'
        <?php
        namespace ConfigUnknownChecks;
        function environment(): int { return config('settings.runtime', 'not inferred'); }
        function runtimeCall(): int { return config('settings.call', 'not inferred'); }
        function partialUnknown(): int { return config('settings.partial.unknown', 'not inferred'); }
        function dynamic(string $key): int { return config($key, 'not inferred'); }
        function dynamicArray(): int { return config('dynamic.known', 'not inferred'); }
        function unknownRoot(): int { return config('runtime.child', 'not inferred'); }
        function missingFile(): null { return config('absent.value'); }
        function frameworkDefault(): string { return config('database.default', 'not inferred'); }
        function foreign(): int { return config('foreign.name'); }
        PHP);

    expect($status)
        ->toBe(1)
        ->and(array_column($report['issues'], 'line'))
        ->toBe(range(3, 11))
        ->and(array_column($report['issues'], 'code'))
        ->toBe(array_fill(0, 9, 'mixed-return-statement'));
});
