<?php

use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

beforeEach(function () {
    $this->project = sys_get_temp_dir().'/pint-migration-'.bin2hex(random_bytes(8));
    mkdir($this->project, 0700);
    file_put_contents($this->project.'/pint.json', '{}');
    $this->pint = function (array $options = [], array $environment = []): Process {
        $process = new Process(
            [
                PHP_BINARY,
                base_path('pint'),
                '--config',
                $this->project.'/pint.json',
                '--format=json',
                ...$options,
                $this->project,
            ],
            $this->project,
            $environment,
            timeout: 60,
        );
        $process->run();

        return $process;
    };
});

afterEach(function () {
    new Filesystem()->remove($this->project);
});

it('checks without writes and fixes valid files without touching a broken neighbour', function () {
    $input = '<?php $items=array("first", "second");';
    $broken = '<?php function broken( {';
    file_put_contents($this->project.'/Valid.php', $input);
    file_put_contents($this->project.'/Broken.php', $broken);

    $check = ($this->pint)(['--test']);
    expect($check->getExitCode())
        ->toBe(1)
        ->and(file_get_contents($this->project.'/Valid.php'))
        ->toBe($input)
        ->and(file_get_contents($this->project.'/Broken.php'))
        ->toBe($broken)
        ->and($check->getErrorOutput())
        ->toContain('Broken.php');

    $fix = ($this->pint)(['--repair']);
    expect($fix->getExitCode())
        ->toBe(1)
        ->and(file_get_contents($this->project.'/Valid.php'))
        ->toBe("<?php\n\n\$items = ['first', 'second'];\n")
        ->and(file_get_contents($this->project.'/Broken.php'))
        ->toBe($broken);

    unlink($this->project.'/Broken.php');
    expect(($this->pint)(['--test'])->getExitCode())->toBe(0);
});

it('isolates Pint from Mago environment and project configuration', function () {
    file_put_contents($this->project.'/Example.php', '<?php $value = array(1, 2);');
    file_put_contents($this->project.'/mago.toml', 'this is not valid TOML');
    $process = ($this->pint)([], ['MAGO_PHP_VERSION' => '4.0']);

    expect($process->getExitCode())
        ->toBe(0)
        ->and(file_get_contents($this->project.'/Example.php'))
        ->toBe("<?php\n\n\$value = [1, 2];\n");
});

it('invalidates the cache when cleanup configuration or source changes', function () {
    $path = $this->project.'/Example.php';
    $cache = $this->project.'/cache.json';
    file_put_contents($path, "<?php\n\nuse App\\Unused;\n\n\$value = 1;\n");
    file_put_contents($this->project.'/pint.json', json_encode([
        'linter' => ['rules' => ['no-redundant-use' => ['enabled' => false]]],
    ]));
    expect(($this->pint)(['--cache-file='.$cache])->getExitCode())
        ->toBe(0)
        ->and(file_get_contents($path))
        ->toContain('use App\\Unused;');
    $first = json_decode(file_get_contents($cache), true);
    file_put_contents($this->project.'/pint.json', '{}');
    expect(($this->pint)(['--cache-file='.$cache, '--test'])->getExitCode())
        ->toBe(1)
        ->and(file_get_contents($path))
        ->toContain('use App\\Unused;');
    expect(($this->pint)(['--cache-file='.$cache])->getExitCode())
        ->toBe(0)
        ->and(file_get_contents($path))
        ->not->toContain('Unused');
    $second = json_decode(file_get_contents($cache), true);
    expect($second['signature'])->not->toBe($first['signature']);
    file_put_contents($path, '<?php $value=3;');
    expect(($this->pint)(['--cache-file='.$cache, '--test'])->getExitCode())->toBe(1);
});

it('produces the same output with sequential and parallel execution', function () {
    $files = ['A.php' => '<?php $a=array(1, 2);', 'B.php' => '<?php class B { public function value(){return "b";} }'];
    foreach ($files as $name => $code) {
        file_put_contents($this->project.'/'.$name, $code);
    }
    expect(($this->pint)()->getExitCode())->toBe(0);
    $expected = [];
    foreach ($files as $name => $code) {
        $expected[$name] = file_get_contents($this->project.'/'.$name);
        file_put_contents($this->project.'/'.$name, $code);
    }
    expect(($this->pint)(['--parallel', '--max-processes=2'])->getExitCode())->toBe(0);
    foreach ($expected as $name => $code) {
        expect(file_get_contents($this->project.'/'.$name))->toBe($code);
    }
    expect(($this->pint)(['--parallel', '--test'])->getExitCode())->toBe(0);
});

it('rejects unknown formatter settings and rules before modifying files', function (array $configuration) {
    $input = '<?php $x=1;';
    file_put_contents($this->project.'/Example.php', $input);
    file_put_contents($this->project.'/pint.json', json_encode($configuration));

    expect(($this->pint)()->getExitCode())
        ->toBe(1)
        ->and(file_get_contents($this->project.'/Example.php'))
        ->toBe($input);
})->with([
    'formatter typo' => [['formatter' => ['single-qoute' => true]]],
    'unknown rule' => [['linter' => ['rules' => ['not-a-rule' => ['enabled' => true]]]]],
]);

it('requires explicit consent for unsafe fixes and leaves them off by default', function () {
    $path = $this->project.'/Example.php';
    file_put_contents($path, '<?php function identity($x) { return $x; }');
    $configuration = ['linter' => ['rules' => ['strict-types' => ['enabled' => true]]]];
    file_put_contents($this->project.'/pint.json', json_encode($configuration));
    expect(($this->pint)()->getExitCode())
        ->toBe(0)
        ->and(file_get_contents($path))
        ->not->toContain('declare(strict_types=1)');
    $configuration['fix-safety'] = 'unsafe';
    file_put_contents($this->project.'/pint.json', json_encode($configuration));
    expect(($this->pint)()->getExitCode())
        ->toBe(0)
        ->and(file_get_contents($path))
        ->toContain('declare(strict_types=1)');
});

it('keeps generated syntax compatible with the configured PHP version', function () {
    $path = $this->project.'/Example.php';
    file_put_contents($path, '<?php (new Example())->run();');
    file_put_contents($this->project.'/pint.json', '{"php-version":"8.3"}');
    expect(($this->pint)()->getExitCode())
        ->toBe(0)
        ->and(file_get_contents($path))
        ->toBe("<?php\n\n(new Example)->run();\n");

    file_put_contents($this->project.'/pint.json', '{"php-version":"8.4"}');
    expect(($this->pint)()->getExitCode())
        ->toBe(0)
        ->and(file_get_contents($path))
        ->toBe("<?php\n\nnew Example()->run();\n");
});
