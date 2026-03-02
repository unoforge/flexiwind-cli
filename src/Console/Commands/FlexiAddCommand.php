<?php

namespace FlexiLaravel\Console\Commands;

use FlexiCore\Core\Constants;
use FlexiCore\Core\RegistryStore;
use FlexiCore\Installer\PackageInstaller;
use FlexiCore\Service\ProjectDetector;
use FlexiCore\Utils\HttpUtils;
use Illuminate\Console\Command;
use Symfony\Component\Yaml\Yaml;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\spin;

class FlexiAddCommand extends Command
{
    protected $signature = 'flexi:add
        {components* : Component names to add}
        {--namespace= : Namespace to use for all components}
        {--skip-deps : Skip dependency installation}';

    protected $description = 'Add UI components to your project from component registries';

    private string $defaultSource;
    private array $registries;
    private string $projectRoot;
    private array $installedRegistryComponents = [];
    private array $pendingCommands = [];
    private array $createdFiles = [];
    private array $postInstallMessages = [];
    private bool $skipPackageInstallation = false;

    public function __construct(
        private readonly RegistryStore $store = new RegistryStore()
    ) {
        parent::__construct();
        $this->projectRoot = getcwd();
        $this->loadConfiguration();
    }

    public function handle(): int
    {
        $components = (array) $this->argument('components');
        $namespace = $this->option('namespace');
        $skipDeps = (bool) $this->option('skip-deps');

        if (!$this->configExists()) {
            $this->error('Flexiwind not initialized. Run flexi:init first.');
            return self::FAILURE;
        }

        $this->store->init();

        foreach ($components as $component) {
            $this->addComponent($component, is_string($namespace) ? $namespace : null, $skipDeps);
        }

        if (!empty($this->createdFiles)) {
            $this->info('====== Everything installed ======');
            foreach ($this->createdFiles as $fileCreated) {
                $this->line("✓ Created : {$fileCreated}");
            }

            $this->renderPostInstallMessages();
            $this->info('====== Operation completed ======');
        }

        return self::SUCCESS;
    }

    private function configExists(): bool
    {
        return file_exists($this->projectRoot . '/flexiwind.yaml');
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

    private function addComponent(string $component, ?string $namespace, bool $skipDeps = false): void
    {
        $source = $this->determineSource($component, $namespace);
        $registryJson = $this->fetchRegistry($component, $source);

        if (!$registryJson) {
            $this->warn("Registry not found for component: {$component}");
            return;
        }
        if (!isset($registryJson['files']) || !is_array($registryJson['files'])) {
            $this->warn("Invalid registry: no files for {$component}");
            return;
        }

        $this->line("Adding component: {$component}");

        if (isset($registryJson['registryDependencies']) && is_array($registryJson['registryDependencies'])) {
            $this->handleRegistryDependencies($registryJson['registryDependencies'], $namespace);
        }
        if (!$skipDeps) {
            $this->handlePackageDependencies($registryJson);
        }

        spin(message: 'Processing files...', callback: function () use ($registryJson): void {
            foreach ($registryJson['files'] as $file) {
                $this->processFile($file);
            }
        });

        $this->installedRegistryComponents[] = $component;

        if (isset($registryJson['message'])) {
            $this->collectPostInstallMessage($registryJson['message']);
        }

        $nameSpace = str_starts_with($component, '@')
            ? explode('/', $component)[0]
            : ($namespace ? $namespace : 'flexiwind');
        $this->store->add($component, $nameSpace, $registryJson['version'], $registryJson['message'] ?? null);
        $this->info("{$component} added successfully");
    }

    private function handleRegistryDependencies(array $registryDependencies, ?string $namespace): void
    {
        foreach ($registryDependencies as $dependency) {
            if (in_array($dependency, $this->installedRegistryComponents, true)) {
                continue;
            }
            if ($this->isRegistryComponentInstalled($dependency)) {
                $this->line("Registry dependency already installed: {$dependency}");
                continue;
            }

            $this->line("Installing registry dependency: {$dependency}");
            if ($this->skipPackageInstallation) {
                $this->showPendingCommands();
            }
            $this->addComponent($dependency, $namespace, false);
        }
    }

    private function handlePackageDependencies(array $registryJson): void
    {
        $dependencies = $registryJson['dependencies'] ?? [];
        $devDependencies = $registryJson['devDependencies'] ?? [];
        $composerDeps = array_merge($dependencies['composer'] ?? [], $devDependencies['composer'] ?? []);
        $nodeDeps = array_merge($dependencies['node'] ?? [], $devDependencies['node'] ?? []);

        if (empty($composerDeps) && empty($nodeDeps)) {
            return;
        }

        if (count($composerDeps) > 0) {
            $this->line('Composer requires dependencies:');
            foreach ($composerDeps as $dep) {
                $this->line("  - {$dep}");
            }
        }
        if (count($nodeDeps) > 0) {
            $this->line('Node dependencies:');
            foreach ($nodeDeps as $dep) {
                $this->line("  - {$dep}");
            }
        }

        if (!confirm('Install dependencies now?', true)) {
            $this->warn('Skipping dependency installation. You may need to install them manually.');
            $this->skipPackageInstallation = true;
            $this->savePendingCommands($dependencies, $devDependencies);
            return;
        }

        if (ProjectDetector::check_Composer($this->projectRoot) && !empty($composerDeps)) {
            $this->installComposerDependencies($dependencies['composer'] ?? [], $devDependencies['composer'] ?? []);
        }

        $packageManager = ProjectDetector::getNodePackageManager();
        if ($packageManager && file_exists($this->projectRoot . '/package.json') && !empty($nodeDeps)) {
            $this->installNodeDependencies($dependencies['node'] ?? [], $devDependencies['node'] ?? [], $packageManager);
        }
    }

    private function installComposerDependencies(array $dependencies, array $devDependencies): void
    {
        if (empty($dependencies) && empty($devDependencies)) {
            return;
        }
        $composer = PackageInstaller::composer($this->projectRoot);
        $this->line('Installing Composer dependencies...');

        foreach ($dependencies as $dep) {
            $packageName = $this->extractPackageName($dep);
            if (!$composer->isInstalled($packageName)) {
                spin(message: "Installing {$dep}...", callback: fn() => $composer->install($dep, false));
                $this->line("✓ Installed {$dep}");
            }
        }
        foreach ($devDependencies as $dep) {
            $packageName = $this->extractPackageName($dep);
            if (!$composer->isInstalled($packageName)) {
                spin(message: "Installing {$dep} (dev)...", callback: fn() => $composer->install($dep, true));
                $this->line("✓ Installed {$dep} (dev)");
            }
        }
    }

    private function installNodeDependencies(array $dependencies, array $devDependencies, string $packageManager): void
    {
        if (empty($dependencies) && empty($devDependencies)) {
            return;
        }

        $node = PackageInstaller::node($packageManager, $this->projectRoot);
        $this->line('Installing Node dependencies...');

        foreach ($dependencies as $dep) {
            $packageName = $this->extractPackageName($dep);
            if (!$node->isInstalled($packageName)) {
                spin(message: "Installing {$dep}...", callback: fn() => $node->install($dep, false));
                $this->line("✓ Installed {$dep}");
            }
        }
        foreach ($devDependencies as $dep) {
            $packageName = $this->extractPackageName($dep);
            if (!$node->isInstalled($packageName)) {
                spin(message: "Installing {$dep} (dev)...", callback: fn() => $node->install($dep, true));
                $this->line("✓ Installed {$dep} (dev)");
            }
        }
    }

    private function isRegistryComponentInstalled(string $component): bool
    {
        $nameSpace = str_starts_with($component, '@') ? explode('/', $component)[0] : 'flexiwind';
        return $this->store->exists($component, $nameSpace);
    }

    private function extractPackageName(string $dependency): string
    {
        return explode('@', $dependency)[0];
    }

    private function determineSource(string $component, ?string $namespace): array
    {
        if ($namespace) {
            if (!isset($this->registries[$namespace])) {
                throw new \RuntimeException("Namespace {$namespace} not found in configuration.");
            }
            return $this->parseRegistryConfig($this->registries[$namespace]);
        }
        if (str_starts_with($component, '@')) {
            $prefix = explode('/', $component, 2)[0];
            if (!isset($this->registries[$prefix])) {
                throw new \RuntimeException("Namespace {$prefix} not found in configuration.");
            }
            return $this->parseRegistryConfig($this->registries[$prefix]);
        }
        return ['baseUrl' => $this->defaultSource];
    }

    private function parseRegistryConfig(mixed $config): array
    {
        if (is_string($config)) {
            return ['baseUrl' => $config];
        }
        if (is_array($config)) {
            $result = ['baseUrl' => $config['url'] ?? $config['baseUrl'] ?? ''];
            if (isset($config['headers'])) {
                $result['headers'] = $this->expandEnvironmentVariables($config['headers']);
            }
            if (isset($config['params'])) {
                $result['params'] = $config['params'];
            }
            return $result;
        }
        throw new \RuntimeException('Invalid registry configuration format');
    }

    private function expandEnvironmentVariables(array $headers): array
    {
        $expanded = [];
        foreach ($headers as $key => $value) {
            $expanded[$key] = preg_replace_callback('/\$\{([^}]+)\}/', fn($matches) => $_ENV[$matches[1]] ?? $matches[0], $value);
        }
        return $expanded;
    }

    private function fetchRegistry(string $component, array $source): ?array
    {
        $componentName = str_starts_with($component, '@') ? (explode('/', $component, 2)[1] ?? $component) : $component;
        $url = str_replace('{name}', $componentName, $source['baseUrl']);
        $json = HttpUtils::getJson($url, $source['headers'] ?? [], $source['params'] ?? []);
        return is_array($json) ? $json : null;
    }

    private function processFile(array $file): void
    {
        $targetPath = $this->projectRoot . '/' . $file['target'];
        $dir = dirname($targetPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        if (file_exists($targetPath)) {
            $this->warn("File exists, skipping: {$file['target']}");
            return;
        }
        file_put_contents($targetPath, $file['content']);
        $this->createdFiles[] = $file['target'];
    }

    private function savePendingCommands(array $dependencies, array $devDependencies): void
    {
        $composerDeps = $dependencies['composer'] ?? [];
        $composerDevDeps = $devDependencies['composer'] ?? [];
        $nodeDeps = $dependencies['node'] ?? [];
        $nodeDevDeps = $devDependencies['node'] ?? [];

        if (!empty($composerDeps) && ProjectDetector::check_Composer($this->projectRoot)) {
            $this->pendingCommands[] = 'composer require ' . implode(' ', array_map('escapeshellarg', $composerDeps));
        }
        if (!empty($composerDevDeps) && ProjectDetector::check_Composer($this->projectRoot)) {
            $this->pendingCommands[] = 'composer require --dev ' . implode(' ', array_map('escapeshellarg', $composerDevDeps));
        }

        $packageManager = ProjectDetector::getNodePackageManager();
        if ($packageManager && file_exists($this->projectRoot . '/package.json')) {
            if (!empty($nodeDeps)) {
                $this->pendingCommands[] = $this->buildNodeInstallCommand($nodeDeps, false, $packageManager);
            }
            if (!empty($nodeDevDeps)) {
                $this->pendingCommands[] = $this->buildNodeInstallCommand($nodeDevDeps, true, $packageManager);
            }
        }
    }

    private function buildNodeInstallCommand(array $packages, bool $isDevDep, string $packageManager): string
    {
        $packagesString = implode(' ', array_map('escapeshellarg', $packages));
        return PackageInstaller::node($packageManager)->buildInstallCommand($packagesString, $isDevDep);
    }

    private function showPendingCommands(): void
    {
        if (count($this->pendingCommands) > 0) {
            $this->line('Manual installation required. Run:');
            foreach ($this->pendingCommands as $command) {
                $this->line("  {$command}");
            }
        }
    }

    private function collectPostInstallMessage(mixed $message): void
    {
        if (is_string($message)) {
            $trimmed = trim($message);
            if ($trimmed !== '') {
                $this->postInstallMessages[] = $trimmed;
            }
            return;
        }

        if (is_array($message)) {
            foreach ($message as $entry) {
                $this->collectPostInstallMessage($entry);
            }
        }
    }

    private function renderPostInstallMessages(): void
    {
        $messages = array_values(array_unique($this->postInstallMessages));
        if (empty($messages)) {
            return;
        }

        $this->line('');
        $this->warn('Remember to do this:');
        foreach ($messages as $message) {
            $this->line("- {$message}");
        }
    }
}
