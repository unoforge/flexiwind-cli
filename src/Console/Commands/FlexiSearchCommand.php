<?php

namespace FlexiLaravel\Console\Commands;

use FlexiCore\Core\Constants;
use FlexiCore\Service\{RegistryListService, RegistrySearchService};
use Illuminate\Console\Command;
use Symfony\Component\Yaml\Yaml;

class FlexiSearchCommand extends Command
{
    protected $signature = 'flexi:search
        {query : Search query (component name, description)}
        {--registry= : Search in specific registry}
        {--type= : Filter by type (e.g., registry:ui)}
        {--version= : Minimum version}';

    protected $description = 'Search for components in registries';

    private string $projectRoot;
    private array $registries = [];
    private string $defaultSource;

    public function __construct(
        private RegistrySearchService $searchService = new RegistrySearchService(),
        private RegistryListService $listService = new RegistryListService()
    ) {
        parent::__construct();
        $this->projectRoot = getcwd();
        $this->loadConfiguration();
    }

    public function handle(): int
    {
        $query = $this->argument('query');
        $registryFilter = $this->option('registry');

        $filters = [];
        if ($this->option('type')) {
            $filters['type'] = $this->option('type');
        }
        if ($this->option('version')) {
            $filters['version'] = $this->option('version');
        }

        // Get components
        $allComponents = [];
        $registryMap = [];

        if ($registryFilter) {
            $result = $this->listService->listComponents($registryFilter, [], [], []);
            if ($result['success']) {
                $allComponents = $result['components'];
                foreach ($allComponents as $comp) {
                    $registryMap[$comp['name']] = $registryFilter;
                }
            }
        } else {
            // Search across all registries
            foreach ($this->registries as $name => $config) {
                $result = $this->listService->listComponents($name, [], [], []);
                if ($result['success']) {
                    foreach ($result['components'] as $comp) {
                        $allComponents[] = $comp;
                        $registryMap[$comp['name']] = $name;
                    }
                }
            }
        }

        if (empty($allComponents)) {
            $this->warn('No registries configured or accessible.');
            return self::FAILURE;
        }

        // Search
        $result = $this->searchService->search($query, $allComponents, $filters);

        if (!$result['success']) {
            $this->error($result['message']);
            return self::FAILURE;
        }

        if (empty($result['results'])) {
            $this->warn('No components found for: ' . $query);
            return self::SUCCESS;
        }

        // Display
        $this->line('<fg=blue>Search Results</>');
        $this->line('');

        if ($registryFilter) {
            $formatted = $this->searchService->formatResults($result['results'], $query);
        } else {
            $grouped = $this->searchService->groupByRegistry($result['results'], $registryMap);
            $formatted = $this->searchService->formatGroupedResults($grouped, $query);
        }

        $this->line($formatted);

        return self::SUCCESS;
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
