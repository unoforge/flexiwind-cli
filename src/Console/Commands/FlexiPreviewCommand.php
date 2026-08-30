<?php

namespace FlexiLaravel\Console\Commands;

use FlexiCore\Core\{Constants, RegistryComponentReference, RegistryVersionResolver};
use FlexiCore\Service\{RegistryListService, ComponentPreviewService};
use Illuminate\Console\Command;
use Symfony\Component\Yaml\Yaml;

class FlexiPreviewCommand extends Command
{
    protected $signature = 'flexi:preview
        {component : Component reference (button, @flexiwind/button@1.0.0)}
        {--files : Show file contents}
        {--install : Install after preview if confirmed}
        {--no-confirm : Skip confirmation prompt}';

    protected $description = 'Preview a component before installation';

    private string $projectRoot;
    private array $registries = [];
    private string $defaultSource;

    public function __construct(
        private RegistryListService $listService = new RegistryListService(),
        private ComponentPreviewService $previewService = new ComponentPreviewService(),
        private RegistryVersionResolver $versionResolver = new RegistryVersionResolver()
    ) {
        parent::__construct();
        $this->projectRoot = getcwd();
        $this->loadConfiguration();
    }

    public function handle(): int
    {
        $componentInput = $this->argument('component');

        try {
            $reference = RegistryComponentReference::parse($componentInput);
        } catch (\InvalidArgumentException $e) {
            $this->error('Invalid component reference: ' . $e->getMessage());
            return self::FAILURE;
        }

        $source = $this->determineSource($reference);
        if (!$source) {
            $this->error('Registry not found for: ' . $reference->toDisplay());
            return self::FAILURE;
        }

        $resolved = $this->versionResolver->resolve(
            $source['baseUrl'],
            $reference->componentName,
            $reference->version,
            $source['headers'] ?? [],
            $source['params'] ?? []
        );

        if (!$resolved) {
            $this->error('Component not found: ' . $reference->toDisplay());
            return self::FAILURE;
        }

        $component = $resolved['registry'];

        // Generate preview
        $preview = $this->previewService->preview($component, [
            'show_files' => (bool) $this->option('files'),
        ]);

        // Display
        $this->line('<fg=blue>Component Preview</>');
        $this->line('');
        $this->line($this->previewService->formatPreview($preview));
        $this->line('');

        if ($this->option('files') && !empty($component['files'])) {
            $this->line('<fg=yellow>File Contents:</>');
            $this->line('');
            foreach (array_slice($component['files'], 0, 3) as $file) {
                $this->line($this->previewService->showFilePreview($file, 15));
                $this->line('');
            }
        }

        // Install prompt
        if ($this->option('install') || (!$this->option('no-confirm') && $this->confirm('Install this component?'))) {
            return self::SUCCESS;
        }

        return self::SUCCESS;
    }

    private function determineSource(RegistryComponentReference $reference): ?array
    {
        if ($reference->namespace !== null) {
            if (isset($this->registries[$reference->namespace])) {
                $config = $this->registries[$reference->namespace];
                if (is_string($config)) {
                    return ['baseUrl' => $config];
                }
                return ['baseUrl' => $config['url'] ?? '', 'headers' => $config['headers'] ?? []];
            }
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
