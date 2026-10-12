<?php

use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

beforeEach(function () {
    $this->project = sys_get_temp_dir().'/pint-quality-'.bin2hex(random_bytes(8));
    mkdir($this->project, 0700);
    file_put_contents($this->project.'/composer.json', '{"require":{"php":"^8.4"}}');
    $this->configure = function (array $options = []): void {
        file_put_contents(
            $this->project.'/pint.json',
            json_encode(array_replace([
                'laravel-version' => '13',
            ], $options), JSON_THROW_ON_ERROR),
        );
    };
    ($this->configure)();
    $this->pint = function (array $arguments = [], array $environment = []): array {
        $process = new Process(
            [PHP_BINARY, base_path('pint'), ...$arguments, '--format=json'],
            $this->project,
            $environment,
            timeout: 120,
        );
        $process->run();
        $report = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);

        return [$process->getExitCode(), $report];
    };
});

afterEach(function () {
    new Filesystem()->remove($this->project);
});

it('checks without writing and fixes then reports remaining correctness errors', function () {
    $source = '<?php function example(): int { return strlen(123); }';
    file_put_contents($this->project.'/Example.php', $source);
    [$status, $report] = ($this->pint)(['Example.php']);
    expect($status)
        ->toBe(1)
        ->and(file_get_contents($this->project.'/Example.php'))
        ->toBe($source)
        ->and(array_column($report['issues'], 'stage'))
        ->toContain('format', 'analyze');

    [$status, $report] = ($this->pint)(['fix']);
    expect($status)
        ->toBe(1)
        ->and($report['changed'])
        ->toContain('Example.php')
        ->and(array_column($report['issues'], 'stage'))
        ->toContain('analyze')
        ->not->toContain('format')->and(file_get_contents($this->project.'/Example.php'))
        ->not->toBe($source);

    file_put_contents($this->project.'/Example.php', '<?php function example(): int { return strlen("valid"); }');
    [$status, $report] = ($this->pint)(['fix']);
    expect($status)->toBe(0)->and($report['changed'])->toBe(['Example.php'])->and($report['issues'])->toBe([]);
    expect(($this->pint)()[0])->toBe(0);
});

it('uses native Laravel fixes without forcing finality or touching vendor', function () {
    mkdir($this->project.'/vendor');
    $vendor = '<?php class VendorRequest { public function rules() { return ["name" => "required|string"]; } }';
    file_put_contents($this->project.'/vendor/Request.php', $vendor);
    $source = '<?php class StoreRequest { public function rules() { return ["name" => "required|string"]; } }';
    file_put_contents($this->project.'/Request.php', $source);
    file_put_contents($this->project.'/Controller.php', '<?php class UserController {}');
    [$status, $report] = ($this->pint)(['lint']);
    expect($status)
        ->toBe(1)
        ->and(array_column($report['issues'], 'code'))
        ->toContain('prefer-array-validation-rules')
        ->not
        ->toContain('final-controller')
        ->and($report['issues'][0]['fixable'])
        ->toBeTrue()
        ->and(file_get_contents($this->project.'/Request.php'))
        ->toBe($source);
    [$status, $report] = ($this->pint)(['lint', '--fix']);
    expect($status)
        ->toBe(0)
        ->and($report['changed'])
        ->toBe(['Request.php'])
        ->and(file_get_contents($this->project.'/Request.php'))
        ->not
        ->toBe($source)
        ->and(file_get_contents($this->project.'/vendor/Request.php'))
        ->toBe($vendor);
    expect(($this->pint)(['lint', '--fix', 'vendor/Request.php'])[1]['changed'])->toBe([]);
});

it('reads dependency types and unselected application types without reporting their errors', function () {
    mkdir($this->project.'/vendor');
    file_put_contents(
        $this->project.'/vendor/Types.php',
        '<?php namespace Dependency; class Example { public function count(): int { return "broken vendor"; } }',
    );
    file_put_contents(
        $this->project.'/Types.php',
        '<?php namespace App; class Example extends \\Dependency\\Example {}',
    );
    file_put_contents(
        $this->project.'/Check.php',
        '<?php function example(\\App\\Example $value): string { return $value->count(); }',
    );
    [$status, $report] = ($this->pint)(['analyze', 'Check.php']);
    expect($status)
        ->toBe(1)
        ->and(array_column($report['issues'], 'path'))
        ->toBe(['Check.php'])
        ->and(array_column($report['issues'], 'code'))
        ->toContain('invalid-return-statement');
});

it('enforces Laravel namespace naming but not invented layer boundaries', function () {
    file_put_contents($this->project.'/Wrong.php', '<?php namespace App\\Http\\Requests; class StoreUser {}');
    file_put_contents($this->project.'/Right.php', '<?php namespace App\\Http\\Requests; class StoreUserRequest {}');
    [$status, $report] = ($this->pint)(['guard']);
    expect($status)
        ->toBe(1)
        ->and(array_column($report['issues'], 'path'))
        ->toBe(['Wrong.php'])
        ->and($report['issues'][0]['fixable'])
        ->toBeFalse();
});

it('treats advisory warnings as nonblocking unless strict is requested', function () {
    file_put_contents($this->project.'/Example.php', '<?php function example($request) { return $request->all(); }');
    [$status, $report] = ($this->pint)(['lint']);
    expect($status)->toBe(0)->and(array_column($report['issues'], 'code'))->toContain('no-request-all');
    expect(($this->pint)(['lint', '--strict'])[0])->toBe(1);
});

it('previews enabled behavior changes and only applies them at an explicit write boundary', function () {
    ($this->configure)(['refactor' => ['rules' => ['declare_strict_types' => true]]]);
    $source = "<?php\n\nfunction example(): int\n{\n    return 1;\n}\n";
    file_put_contents($this->project.'/Example.php', $source);
    [$status, $report] = ($this->pint)(['refactor', '-v']);
    expect($status)
        ->toBe(1)
        ->and($report['issues'][0]['diff'])
        ->toContain('strict_types')
        ->and(file_get_contents($this->project.'/Example.php'))
        ->toBe($source);
    expect(($this->pint)(['refactor', '--apply'])[0])
        ->toBe(0)
        ->and(file_get_contents($this->project.'/Example.php'))
        ->toMatch('/declare\s*\(strict_types=1\)/');
});

it('generates baselines only explicitly and never suppresses formatting differences', function () {
    file_put_contents($this->project.'/Example.php', '<?php function example(): int { return strlen(123); }');
    [$status, $report] = ($this->pint)(['--baseline=baseline.json']);
    expect($status)
        ->toBe(1)
        ->and(file_exists($this->project.'/baseline.json'))
        ->toBeFalse();
    [$status, $report] = ($this->pint)(['--baseline=baseline.json', '--generate-baseline']);
    expect($status)->toBe(1)->and(array_column($report['issues'], 'stage'))->toBe(['format']);
    [$status, $report] = ($this->pint)(['--baseline=baseline.json']);
    expect($status)->toBe(1)->and(array_column($report['issues'], 'stage'))->toBe(['format']);
    file_put_contents($this->project.'/New.php', '<?php function other(): int { return strlen(123); }');
    [$status, $report] = ($this->pint)(['analyze', '--baseline=baseline.json']);
    expect($status)->toBe(1)->and(array_column($report['issues'], 'path'))->toBe(['New.php']);

    file_put_contents(
        $this->project.'/Example.php',
        '<?php function example(): int { return strlen(123) + strlen(123); }',
    );
    [$status, $report] = ($this->pint)(['analyze', '--baseline=baseline.json']);
    expect($status)->toBe(1)->and(array_column($report['issues'], 'path'))->toBe(['Example.php', 'New.php']);
});

it('respects disabled stages and isolates native configuration and environment', function () {
    ($this->configure)([
        'format' => ['enabled' => false],
        'analyze' => ['enabled' => false],
        'refactor' => ['enabled' => false],
    ]);
    $source = '<?php function example(): int { return strlen(123); }';
    file_put_contents($this->project.'/Example.php', $source);
    file_put_contents($this->project.'/mago.toml', 'invalid TOML');
    expect(($this->pint)([], ['MAGO_PHP_VERSION' => '4.0'])[0])
        ->toBe(0)
        ->and(($this->pint)(['fix'])[0])
        ->toBe(0)
        ->and(($this->pint)(['format'])[0])
        ->toBe(0)
        ->and(file_get_contents($this->project.'/Example.php'))
        ->toBe($source);
});

it('rejects invalid configuration and conflicting write flags before changing files', function (
    array $config,
    array $arguments,
) {
    ($this->configure)($config);
    $source = '<?php class StoreRequest { public function rules() { return ["name" => "required|string"]; } }';
    file_put_contents($this->project.'/Request.php', $source);
    $composer = file_get_contents($this->project.'/composer.json');
    [$status, $report] = ($this->pint)($arguments);
    expect($status)
        ->toBe(1)
        ->and($report['changed'])
        ->toBe([])
        ->and(file_get_contents($this->project.'/Request.php'))
        ->toBe($source)
        ->and(file_get_contents($this->project.'/composer.json'))
        ->toBe($composer);
})->with([
    'formatter option' => [['format' => ['single-qoute' => true]], ['fix']],
    'conflicting flags' => [[], ['fix', '--test']],
    'missing baseline' => [['baseline' => 'missing.json'], ['fix']],
    'source cache' => [[], ['fix', '--cache-file=Request.php']],
    'composer baseline' => [[], ['--generate-baseline', '--baseline=composer.json']],
]);

it('keeps refactor import policy stable through the final formatting stage', function () {
    ($this->configure)(['refactor' => ['rules' => ['global_namespace_import' => ['import_classes' => false]]]]);
    file_put_contents(
        $this->project.'/Example.php',
        '<?php namespace App; use DateTimeImmutable; function example(): DateTimeImmutable { return new DateTimeImmutable; }',
    );
    [$status, $report] = ($this->pint)(['fix']);
    expect($status)
        ->toBe(0)
        ->and($report['changed'])
        ->toBe(['Example.php'])
        ->and(file_get_contents($this->project.'/Example.php'))
        ->toContain(': \\DateTimeImmutable', 'new \\DateTimeImmutable');
    expect(($this->pint)()[0])->toBe(0);
    expect(($this->pint)(['fix'])[1]['changed'])->toBe([]);
});

it('succeeds when git selection contains no files', function () {
    new Process(['git', 'init', '--quiet'], $this->project)->mustRun();
    [$status, $report] = ($this->pint)(['--dirty']);
    expect($status)->toBe(0)->and($report['issues'])->toBe([])->and($report['changed'])->toBe([]);
});

it('infers Laravel model reads without booting the application or overriding explicit contracts', function () {
    symlink(base_path('vendor'), $this->project.'/vendor');
    copy(base_path('tests/Fixtures/mago/models.php'), $this->project.'/Models.php');
    file_put_contents($this->project.'/bootstrap.php', '<?php throw new RuntimeException("Must not boot Laravel");');
    $valid = <<<'PHP'
        <?php namespace ValidChecks;
        use App\Models\{User, Post, InheritedUser, DocumentedUser, DeclaredUser, PropertyUser, DynamicUser};
        function active(User $user): ?bool { return $user->active; }
        function price(User $user): ?string { return $user->price; }
        function display(User $user): string { return $user->display_name; }
        function score(User $user): int { return $user->score; }
        function inherited(InheritedUser $user): ?string { return $user->price; }
        function documented(DocumentedUser $user): int { return $user->active; }
        function declared(DeclaredUser $user): int { return $user->active; }
        function propertyOnly(PropertyUser $user): ?bool { return $user->active; }
        function posts(User $user): ?Post { return $user->posts->first(); }
        function author(Post $post): ?User { return $post->author; }
        function dynamic(DynamicUser $user): mixed { return $user->price; }
        function write(User $user): void { $user->active = 1; }
        function setterOnly(User $user): mixed { return $user->secret; }
        PHP;
    file_put_contents($this->project.'/Valid.php', $valid);
    [$status, $report] = ($this->pint)(['analyze', 'Valid.php']);
    expect($status)
        ->toBe(0)
        ->and(array_column($report['issues'], 'line'))
        ->toBe([13, 15])
        ->and(array_column($report['issues'], 'code'))
        ->toBe(array_fill(0, 2, 'non-documented-property'));

    $invalid = <<<'PHP'
        <?php namespace InvalidChecks;
        use App\Models\{User, Post, InheritedUser, DocumentedUser, DeclaredUser, PropertyUser};
        function active(User $user): ?int { return $user->active; }
        function price(User $user): ?int { return $user->price; }
        function display(User $user): int { return $user->display_name; }
        function score(User $user): string { return $user->score; }
        function inherited(InheritedUser $user): ?int { return $user->price; }
        function documented(DocumentedUser $user): string { return $user->active; }
        function declared(DeclaredUser $user): string { return $user->active; }
        function propertyOnly(PropertyUser $user): ?int { return $user->active; }
        function posts(User $user): ?User { return $user->posts->first(); }
        function author(Post $post): ?Post { return $post->author; }
        function nullableCast(User $user): bool { return $user->active; }
        function nullableRelation(Post $post): User { return $post->author; }
        PHP;
    file_put_contents($this->project.'/Invalid.php', $invalid);
    [$status, $report] = ($this->pint)(['analyze', 'Invalid.php']);
    $lines = collect($report['issues'])
        ->groupBy('code')
        ->map(fn ($issues) => $issues->pluck('line')->all())
        ->all();
    expect($status)
        ->toBe(1)
        ->and($lines)
        ->toBe(['invalid-return-statement' => range(3, 14), 'nullable-return-statement' => [13, 14]])
        ->and(array_unique(array_column($report['issues'], 'path')))
        ->toBe(['Invalid.php'])
        ->and(file_get_contents($this->project.'/Valid.php'))
        ->toBe($valid)
        ->and(file_get_contents($this->project.'/Invalid.php'))
        ->toBe($invalid);
});

it('checks enum and custom cast contracts separately for reads and writes', function () {
    symlink(base_path('vendor'), $this->project.'/vendor');
    copy(base_path('tests/Fixtures/mago/casts-models.php'), $this->project.'/Models.php');
    file_put_contents($this->project.'/Valid.php', <<<'PHP'
        <?php namespace ValidCasts;
        use CastFixtures\{Invoice, Amount, State};
        function state(Invoice $invoice): ?State { return $invoice->state; }
        function amount(Invoice $invoice): Amount { return $invoice->amount; }
        function cash(Invoice $invoice): Amount { return $invoice->cash; }
        function label(Invoice $invoice): ?\Illuminate\Support\Stringable { return $invoice->label; }
        function items(Invoice $invoice): ?\Illuminate\Support\Collection { return $invoice->items; }
        function options(Invoice $invoice): ?\Illuminate\Database\Eloquent\Casts\ArrayObject { return $invoice->options; }
        function date(Invoice $invoice): ?\Carbon\CarbonImmutable { return $invoice->paid_at; }
        function payload(Invoice $invoice): ?array { return $invoice->payload; }
        /** @return numeric-string|null */
        function decimal(Invoice $invoice): ?string { return $invoice->price; }
        function writes(Invoice $invoice): void {
            $invoice->state = State::Open;
            $invoice->cash = '12.50';
            $invoice->amount = '5';
            $invoice->secret = 'password';
            $invoice->enabled = 1;
            $invoice->score = 'input';
            $invoice->reference = 'input';
            $invoice->price = 2.5;
        }
        PHP);
    [$status, $report] = ($this->pint)(['analyze', 'Valid.php']);
    expect($report['issues'])->toBe([])->and($status)->toBe(0);

    file_put_contents($this->project.'/Invalid.php', <<<'PHP'
        <?php namespace InvalidCasts;
        use CastFixtures\Invoice;
        function state(Invoice $invoice): ?int { return $invoice->state; }
        function amount(Invoice $invoice): string { return $invoice->amount; }
        function cash(Invoice $invoice): int { return $invoice->cash; }
        function label(Invoice $invoice): ?int { return $invoice->label; }
        function date(Invoice $invoice): ?string { return $invoice->paid_at; }
        function writes(Invoice $invoice): void {
            $invoice->state = false;
            $invoice->cash = new \stdClass;
            $invoice->amount = new \stdClass;
            $invoice->secret = [];
            $invoice->enabled = 3;
            $invoice->score = 123;
            $invoice->reference = 123;
            $invoice->price = 'not numeric';
        }
        PHP);
    [$status, $report] = ($this->pint)(['analyze', 'Invalid.php']);
    expect($status)
        ->toBe(1)
        ->and(array_unique(array_column($report['issues'], 'path')))
        ->toBe(['Invalid.php'])
        ->and(array_column($report['issues'], 'line'))
        ->toBe([...range(3, 7), ...range(9, 16)])
        ->and(array_column($report['issues'], 'code'))
        ->not->toContain('mixed-return-statement', 'non-documented-property');
});
