<?php

namespace App\Mago;

use Mago\Sdk\Extension;
use Mago\Sdk\Linter\LintContext;
use Mago\Sdk\Linter\Rule;
use Mago\Sdk\Linter\RuleDefinition;
use Mago\Sdk\Reporting\Issue;
use Mago\Sdk\Reporting\Level;
use Mago\Sdk\Syntax\NodeKind;
use Mago\Sdk\Worker;
use RuntimeException;
use Symfony\Component\Finder\Glob;

final class EnvOutsideConfig implements Rule
{
    public const CODE = 'pint/env-outside-config';

    /** @var list<string> */
    private array $excludes;

    /** @param list<string> $exclude */
    public function __construct(
        private string $root,
        private string $level,
        array $exclude,
    ) {
        $this->root = rtrim(str_replace('\\', '/', $root), '/').'/';
        $this->excludes = array_map(self::excludePattern(...), $exclude);
    }

    /**
     * Mago 1.56 does not pass native rule options to extension rules.
     *
     * @param array<string, mixed> $options
     * @return array{enabled: bool, level: string, exclude: list<string>}
     */
    public static function options(array $options): array
    {
        foreach (array_keys($options) as $key) {
            if (! in_array($key, ['enabled', 'level', 'exclude'], true)) {
                throw new RuntimeException('Unsupported option ['.self::CODE.'.'.$key.'].');
            }
        }

        $options = array_replace([
            'enabled' => true,
            'level' => 'Warning',
            'exclude' => ['bootstrap/', 'tests/', 'scripts/', 'tools/'],
        ], $options);

        if (! is_bool($options['enabled'])) {
            throw new RuntimeException('Option ['.self::CODE.'.enabled] must be a boolean.');
        }
        if (
            ! is_string($options['level'])
            || ! in_array(strtolower($options['level']), ['note', 'help', 'warning', 'error'], true)
        ) {
            throw new RuntimeException('Option ['.self::CODE.'.level] must be note, help, warning, or error.');
        }
        if (
            ! is_array($options['exclude'])
            || ! array_is_list($options['exclude'])
            || ! array_all(
                $options['exclude'],
                fn ($path) => (
                    is_string($path)
                    && $path !== ''
                ),
            )
        ) {
            throw new RuntimeException('Option ['.self::CODE.'.exclude] must be a list of nonempty paths or globs.');
        }
        foreach ($options['exclude'] as $pattern) {
            if (@preg_match(self::excludePattern($pattern), '') === false) {
                throw new RuntimeException('Invalid exclude pattern ['.$pattern.'] for '.self::CODE.'.');
            }
        }

        return $options;
    }

    private static function excludePattern(string $pattern): string
    {
        return strpbrk($pattern, '*?[{') === false
            ? '#^'.preg_quote($pattern, '#').'#'
            : Glob::toRegex($pattern, false, false);
    }

    public static function work(string $path): void
    {
        $settings = json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);

        new Worker(new Extension(
            identifier: 'pint/laravel-lint',
            name: 'Pint Laravel lint',
            version: '1',
            linterRules: [new self($settings['root'], $settings['level'], $settings['exclude'])],
        ))->run();
    }

    public function getDefinition(): RuleDefinition
    {
        return new RuleDefinition(
            code: self::CODE,
            name: 'Environment reads outside configuration',
            description: 'Read Laravel application settings through config(), not env(), outside configuration files.',
            defaultLevel: match (strtolower($this->level)) {
                'note' => Level::Note,
                'help' => Level::Help,
                'warning' => Level::Warning,
                'error' => Level::Error,
                default => throw new RuntimeException('Unsupported lint severity ['.$this->level.'].'),
            },
            defaultEnabled: true,
            targets: [NodeKind::Program],
        );
    }

    public function lint(LintContext $context): void
    {
        $context->cancellation->throwIfCancelled();
        $path = str_replace('\\', '/', $context->file->path);
        $relative = str_starts_with($path, $this->root) ? substr($path, strlen($this->root)) : $path;

        if (
            str_starts_with($relative, 'config/')
            || array_any($this->excludes, fn ($pattern) => preg_match($pattern, $relative) === 1)
        ) {
            return;
        }

        $declared = [];
        foreach ($context->file->getNodes(NodeKind::Function) as $function) {
            $identifier = $context->file->getFirstDescendant($function, NodeKind::LocalIdentifier);
            $name = $identifier === null ? null : $context->file->getResolvedName($identifier);
            if ($name !== null) {
                $declared[strtolower($name->name)] = true;
            }
        }

        foreach ($context->file->getNodes(NodeKind::FunctionCall) as $call) {
            $name = $context->file->getResolvedName($call);
            if ($name === null || isset($declared[strtolower($name->name)])) {
                continue;
            }
            $global = strcasecmp($name->name, 'env') === 0;
            $fallback = ! $name->imported && strcasecmp($context->file->getText($name->span), 'env') === 0;
            if (! $global && ! $fallback) {
                continue;
            }

            $context->report(Issue::new(
                'Read application settings through config() instead of env() outside configuration files.',
                $call->span,
            )->withHelp(
                'Define the environment-backed value in config/*.php, then read its key with config(). '
                .'Do not mechanically replace env() with config(): the key and default must be chosen explicitly.',
            )->withNote('Laravel configuration caching can leave env() calls without values from the .env file.'));
        }
    }
}
