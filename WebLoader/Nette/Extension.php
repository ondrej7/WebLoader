<?php

namespace WebLoader\Nette;

use Nette\Configurator;
use Nette\DI\Compiler;
use Nette\DI\CompilerExtension;
use Nette\DI\Config\Helpers;
use Nette\DI\ContainerBuilder;
use Nette\Utils\Finder;
use Nette;
use WebLoader\FileNotFoundException;



/**
 * @author Jan Marek
 */
class Extension extends CompilerExtension
{

	const DEFAULT_TEMP_PATH = 'webtemp';
	const EXTENSION_NAME = 'webloader';

	public function getDefaultConfig()
	{
		return [
			'jsDefaults' => [
				'checkLastModified' => TRUE,
				'debug' => FALSE,
				'sourceDir' => '%wwwDir%/js',
				'tempDir' => '%wwwDir%/' . self::DEFAULT_TEMP_PATH,
				'tempPath' => self::DEFAULT_TEMP_PATH,
				'files' => [],
				'watchFiles' => [],
				'remoteFiles' => [],
				'filters' => [],
				'fileFilters' => [],
				'joinFiles' => TRUE,
				'namingConvention' => '@' . $this->prefix('jsNamingConvention'),
			],
			'cssDefaults' => [
				'checkLastModified' => TRUE,
				'debug' => FALSE,
				'sourceDir' => '%wwwDir%/css',
				'tempDir' => '%wwwDir%/' . self::DEFAULT_TEMP_PATH,
				'tempPath' => self::DEFAULT_TEMP_PATH,
				'files' => [],
				'watchFiles' => [],
				'remoteFiles' => [],
				'filters' => [],
				'fileFilters' => [],
				'joinFiles' => TRUE,
				'namingConvention' => '@' . $this->prefix('cssNamingConvention'),
			],
			'js' => [],
			'css' => [],
			'debugger' => '%debugMode%'
		];
	}

	public function loadConfiguration()
	{
		$builder = $this->getContainerBuilder();
		$config = $this->getConfig($this->getDefaultConfig());

		$builder->addDefinition($this->prefix('cssNamingConvention'))
			->setFactory('WebLoader\DefaultOutputNamingConvention::createCssConvention');

		$builder->addDefinition($this->prefix('jsNamingConvention'))
			->setFactory('WebLoader\DefaultOutputNamingConvention::createJsConvention');

		if ($config['debugger']) {
			$builder->addDefinition($this->prefix('tracyPanel'))
				->setClass('WebLoader\Nette\Diagnostics\Panel')
				->setArguments([$builder->expand('%appDir%')]);
		}

		$builder->parameters['webloader'] = $config;

		$loaderFactoryTempPaths = [];

		foreach (['css', 'js'] as $type) {
			foreach ($config[$type] as $name => $wlConfig) {
				$wlConfig = Helpers::merge($wlConfig, $config[$type . 'Defaults']);
				$this->addWebLoader($builder, $type . ucfirst($name), $wlConfig);
				$loaderFactoryTempPaths[strtolower($name)] = $wlConfig['tempPath'];

				if (!is_dir($wlConfig['tempDir']) || !is_writable($wlConfig['tempDir'])) {
					throw new CompilationException(sprintf("You must create a writable directory '%s'", $wlConfig['tempDir']));
				}
			}
		}

		$builder->addDefinition($this->prefix('factory'))
			->setClass('WebLoader\Nette\LoaderFactory', [$loaderFactoryTempPaths, $this->name]);
	}

	private function addWebLoader(ContainerBuilder $builder, $name, $config)
	{
		$filesServiceName = $this->prefix($name . 'Files');

		$files = $builder->addDefinition($filesServiceName)
			->setClass('WebLoader\FileCollection')
			->setArguments([$config['sourceDir']]);

		foreach ($this->findFiles($config['files'], $config['sourceDir']) as $file) {
			$files->addSetup('addFile', [$file]);
		}

		foreach ($this->findFiles($config['watchFiles'], $config['sourceDir']) as $file) {
			$files->addSetup('addWatchFile', [$file]);
		}

		$files->addSetup('addRemoteFiles', [$config['remoteFiles']]);

		$compiler = $builder->addDefinition($this->prefix($name . 'Compiler'))
			->setClass('WebLoader\Compiler')
			->setArguments([
				'@' . $filesServiceName,
				$config['namingConvention'],
				$config['tempDir'],
			]);

		$compiler->addSetup('setJoinFiles', [$config['joinFiles']]);

		if ($builder->parameters['webloader']['debugger']) {
			$compiler->addSetup('@' . $this->prefix('tracyPanel') . '::addLoader', [
				$name,
				'@' . $this->prefix($name . 'Compiler')
			]);
		}

		foreach ($config['filters'] as $filter) {
			$compiler->addSetup('addFilter', [$filter]);
		}

		foreach ($config['fileFilters'] as $filter) {
			$compiler->addSetup('addFileFilter', [$filter]);
		}

		if (isset($config['debug']) && $config['debug']) {
			$compiler->addSetup('enableDebugging');
		}

		$compiler->addSetup('setCheckLastModified', [$config['checkLastModified']]);

		// todo css media
	}

	public function afterCompile(Nette\PhpGenerator\ClassType $class)
	{
		$meta = $class->properties['meta'];
		if (array_key_exists('webloader\\nette\\loaderfactory', $meta->value['types'])) {
			$meta->value['types']['webloader\\loaderfactory'] = $meta->value['types']['webloader\\nette\\loaderfactory'];
		}
		if (array_key_exists('WebLoader\\Nette\\LoaderFactory', $meta->value['types'])) {
			$meta->value['types']['WebLoader\\LoaderFactory'] = $meta->value['types']['WebLoader\\Nette\\LoaderFactory'];
		}

		$init = $class->methods['initialize'];
		$init->addBody('if (!class_exists(?, ?)) class_alias(?, ?);', ['WebLoader\\LoaderFactory', FALSE, 'WebLoader\\Nette\\LoaderFactory', 'WebLoader\\LoaderFactory']);
	}

	public function install(Configurator $configurator)
	{
		$self = $this;
		$configurator->onCompile[] = function ($configurator, Compiler $compiler) use ($self) {
			$compiler->addExtension($self::EXTENSION_NAME, $self);
		};
	}

    /**
     * @param array $filesConfig
     * @param string $sourceDir
     * @return array
     * @throws FileNotFoundException
     */
	private function findFiles(array $filesConfig, $sourceDir)
	{
		$normalizedFiles = [];

		foreach ($filesConfig as $file) {
			// finder support
			if (is_array($file) && isset($file['files']) && (isset($file['in']) || isset($file['from']))) {
				$finder = Finder::findFiles($file['files']);

				if (isset($file['exclude'])) {
					$finder->exclude($file['exclude']);
				}

				if (isset($file['in'])) {
					$finder->in(is_dir($file['in']) ? $file['in'] : $sourceDir . DIRECTORY_SEPARATOR . $file['in']);
				} else {
					$finder->from(is_dir($file['from']) ? $file['from'] : $sourceDir . DIRECTORY_SEPARATOR . $file['from']);
				}

				$foundFilesList = [];
				foreach ($finder as $foundFile) {
					/** @var \SplFileInfo $foundFile */
					$foundFilesList[] = $foundFile->getPathname();
				}

				natsort($foundFilesList);

				foreach ($foundFilesList as $foundFilePathname) {
					$normalizedFiles[] = $foundFilePathname;
				}

			} else {
				$this->checkFileExists($file, $sourceDir);
				$normalizedFiles[] = $file;
			}
		}

		return $normalizedFiles;
	}

	/**
	 * @param string $file
	 * @param string $sourceDir
	 * @throws FileNotFoundException
	 */
	protected function checkFileExists($file, $sourceDir)
	{
		if (!file_exists($file)) {
			$tmp = rtrim($sourceDir, '/\\') . DIRECTORY_SEPARATOR . $file;
			if (!file_exists($tmp)) {
				throw new FileNotFoundException(sprintf("Neither '%s' or '%s' was found", $file, $tmp));
			}
		}
	}

}
