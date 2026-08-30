<?php

namespace FlexiLaravel\Console\Commands;

use FlexiCore\Core\Constants;
use FlexiCore\Core\RegistryVersionResolver;
use FlexiCore\Service\RegistryListService;
use Illuminate\Console\Command;
use Symfony\Component\Yaml\Yaml;

class FlexiListCommand extends Command
{
    protected $signature = 'flexi:list
        {registry? : Registry to list from (e.g., @flexiwind)}
        {--type= : Filter by component type (e.g., registry:ui)}
        {--search= : Search for specific component}
        {--sort= : Sort by: name (default), version, type}
        {--show-files : Show file list for each component}
        {--show-deps : Show dependencies for each component}';

    protected $description = 'List all components from a registry';

    private string $projectRoot;
    private array $registries = [];
    private string $defaultSource;

    public function __construct(
        private RegistryListService $listService = new RegistryListService(),
        private RegistryVersionResolver $versionResolver = new RegistryVersionResolver()
    ) {
        parent::__construct();
        $this->projectRoot = getcwd();
        $this->loadConfiguration();
    }

    public function handle(): int
    {
        $registry = $this->argument('registry');

        if (!$registry) {
            return $this->listAllRegistries();
        }

        $source = $this->resolveRegistrySource($registry);

        if (!$source) {
            $this->error("Registry not found: {$registry}");
            return self::FAILURE;
        }

        $filters = [];
        if ($this->option('type')) {
            $filters['type'] = $this->option('type');
        }
        if ($this->option('search')) {
            $filters['search'] = $this->option('search');
        }
        if ($this->option('sort')) {
            $filters['sort'] = $this->option('sort');
        }

        $options = [
            'show_files' => (bool) $this->option('show-files'),
            'show_deps' => (bool) $this->option('show-deps'),
        ];

        $result = $this->listService->listComponents(
            $registry,
            $filters,
            $source['headers'] ?? [],
            $source['params'] ?? []
        );

        if (!$result['success']) {
            $this->warn($result['message']);
            return self::SUCCESS;
        }

        $this->displayRegistry($registry, $result, $options);

        return self::SUCCESS;
    }

    private function listAllRegistries(): int
    {
        $this->line('<fg=blue>📦 Available Registries</>');
        $this->line('');

        if (empty($this->registries)) {
            $this->warn('No registries configured. Run "flexi:init" first.');
            return self::SUCCESS;
        }

        foreach ($this->registries as $name => $config) {
            $url = is_string($config) ? $config : ($config['url'] ?? '');
            $this->line("<fg=green>✓</> <fg=cyan>{$name}</> - {$url}");
        }

        $this->line('');
        $this->line('Use: <fg=cyan>php artisan flexi:list {registry-name}</> to see components');

        return self::SUCCESS;
    }

    private function displayRegistry(
        string $registry,
        array $result,
        array $options
    ): void
    {
        $this->line('<fg=blue>[REGISTRY] ' . $registry . ' Components</>');
        $this->line('');

        $formatted = $this->listService->formatForDisplay($result['components'], $options);
        $this->line($formatted);

        $this->line('');
        $stats = $this->listService->getStatistics($result['components']);
        $total = $stats['total'];
        $this->line("<fg=yellow>Total: " . $total . " component(s)</>");

        if (!empty($stats['by_type'])) {
            $this->line('');
            foreach ($stats['by_type'] as $type => $count) {
                $this->line("  * " . $type . ": " . $count);
            }
        }
    }

    private function resolveRegistrySource(string $registry): ?array
    {
        if (isset($this->registries[$registry])) {
            $config = $this->registries[$registry];
            if (is_string($config)) {
                return ['baseUrl' => $config];
            }
            return ['baseUrl' => $config['url'] ?? '', 'headers' => $config['headers'] ?? []];
        }

        return ['baseUrl' => $this->defaultSource];
    }

    private function loadConfiguration(): void
    {
        $configPath = $this->projectRoot . '/flexiwind.yaml';

        if (!file_exists($configPath)) {
            $this->registries = [];
            $this->defaultSource = Constants::LOCAL_REGISTRY;
            return;
        }

        $config = Yaml::parseFile($configPath);
        $this->defaultSource = $config['defaultSource'] ?? Constants::LOCAL_REGISTRY;
        $this->registries = $config['registries'] ?? [];
    }
}
