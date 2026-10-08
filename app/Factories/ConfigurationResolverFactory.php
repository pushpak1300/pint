<?php

namespace App\Factories;

use App\Project;
use App\Repositories\ConfigurationJsonRepository;
use Laravel\AgentDetector\AgentDetector;
use Symfony\Component\Console\Input\InputInterface;

class ConfigurationResolverFactory
{
    /** @return list<string> */
    public static function files(InputInterface $input): array
    {
        $configuration = resolve(ConfigurationJsonRepository::class);
        $configuration->preset();
        $paths = Project::paths($input);
        $configured = $configuration->hasIncludedPaths() && $paths === [(string) Project::path()];
        $finder = ConfigurationFactory::finder($configured);
        $files = [];

        if (! $configured) {
            $directories = [];
            foreach ($paths as $path) {
                if (! file_exists($path)) {
                    abort(1, "The path [{$path}] does not exist.");
                }
                if (is_dir($path)) {
                    $directories[] = $path;
                } elseif (! ConfigurationFactory::isPathExcluded($path)) {
                    $files[] = realpath($path);
                }
            }
            if ($directories === []) {
                return array_values(array_unique($files));
            }
            $finder->in($directories);
        }

        foreach ($finder as $file) {
            $files[] = $file->getRealPath();
        }

        return array_values(array_unique($files));
    }

    public static function runningInAgent(): bool
    {
        return AgentDetector::detect()->isAgent;
    }
}
