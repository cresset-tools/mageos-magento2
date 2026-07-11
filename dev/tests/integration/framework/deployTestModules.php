<?php
/**
 * Copyright 2016 Adobe
 * All Rights Reserved.
 */

/**
 * phpcs:disable PSR1.Files.SideEffects
 * phpcs:disable Squiz.Functions.GlobalFunction
 * @var string $testFrameworkDir - Must be defined in parent script.
 * @var \Magento\TestFramework\Bootstrap\Settings $settings - Must be defined in parent script.
 */

/** Copy test modules to app/code/Magento to make them visible for Magento instance */
$pathToCommittedTestModules = $testFrameworkDir . '/../_files/Magento';
$pathToInstalledMagentoInstanceModules = $testFrameworkDir . '/../../../../app/code/Magento';

// In a parallel run (paratest workers), removing + re-copying the shared
// app/code/Magento/TestModule* while sibling workers are already mid-test
// yanks files out from under them. Workers therefore treat an existing
// deployment as authoritative and only the first bootstrap (serialized by
// the bootstrap lock) copies; `bin/parallel-prime` wipes the modules so
// every primed session still starts from a fresh copy.
$isParallelRun = (int)$settings->get('TESTS_PARALLEL_RUN') === 1;
$testModulesDeployed = (bool)glob(
    $pathToInstalledMagentoInstanceModules . '/TestModule*/registration.php',
    GLOB_NOSORT
);

if (!$isParallelRun) {
    // Remove stale test modules left by a previous run (e.g. restored from a warm CI cache)
    $filesystem = new \Symfony\Component\Filesystem\Filesystem();
    $staleIterator = new DirectoryIterator($pathToCommittedTestModules);
    foreach ($staleIterator as $staleModule) {
        if ($staleModule->isDir() && !$staleModule->isDot()) {
            $filesystem->remove($pathToInstalledMagentoInstanceModules . '/' . $staleModule->getFilename());
        }
    }
    unset($staleIterator, $staleModule, $filesystem);
}

if (!$isParallelRun || !$testModulesDeployed) {
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($pathToCommittedTestModules, RecursiveDirectoryIterator::FOLLOW_SYMLINKS)
    );
    /** @var SplFileInfo $file */
    foreach ($iterator as $file) {
        if (!$file->isDir()) {
            $source = $file->getPathname();
            $relativePath = substr($source, strlen($pathToCommittedTestModules));
            $destination = $pathToInstalledMagentoInstanceModules . $relativePath;
            // phpcs:ignore Magento2.Functions.DiscouragedFunction
            $targetDir = dirname($destination);
            // phpcs:ignore Magento2.Functions.DiscouragedFunction
            if (!is_dir($targetDir)) {
                // phpcs:ignore Magento2.Functions.DiscouragedFunction
                mkdir($targetDir, 0755, true);
            }
            // phpcs:ignore Magento2.Functions.DiscouragedFunction
            copy($source, $destination);
        }
    }
    unset($iterator, $file);
}

// Register the modules under '_files/'
$pathPattern = $pathToInstalledMagentoInstanceModules . '/TestModule*/registration.php';
// phpcs:ignore Magento2.Functions.DiscouragedFunction
$files = glob($pathPattern, GLOB_NOSORT);
if ($files === false) {
    throw new \RuntimeException('glob() returned error while searching in \'' . $pathPattern . '\'');
}
foreach ($files as $file) {
    // phpcs:ignore Magento2.Security.IncludeFile
    include $file;
}

if ((int)$settings->get('TESTS_PARALLEL_RUN') !== 1) {
    // Only delete modules if we are not using parallel executions
    // phpcs:ignore Magento2.Functions.DiscouragedFunction
    register_shutdown_function(
        'executeRegisteringOfDeleteTestModulesOnShutdown',
        $pathToCommittedTestModules,
        $pathToInstalledMagentoInstanceModules
    );
}

/**
 * Wrapper for registering the deleteTestModules function on shutdown.
 * This wrapper makes sure that we are running the deleteTestModules function after ALL other shutdown functions.
 * It prevents issues with SessionManager `register_shutdown_function([$this, 'writeClose']);` -
 * which used to be called AFTER modules removing - and Fatal Error was thrown.
 *
 * @param string $pathToCommittedTestModules
 * @param string $pathToInstalledMagentoInstanceModules
 */
function executeRegisteringOfDeleteTestModulesOnShutdown(
    $pathToCommittedTestModules,
    $pathToInstalledMagentoInstanceModules
) {
    register_shutdown_function(
        'deleteTestModules',
        $pathToCommittedTestModules,
        $pathToInstalledMagentoInstanceModules
    );
}

/**
 * Delete all test module directories which have been created before
 *
 * @param string $pathToCommittedTestModules
 * @param string $pathToInstalledMagentoInstanceModules
 */
function deleteTestModules($pathToCommittedTestModules, $pathToInstalledMagentoInstanceModules)
{
    $filesystem = new \Symfony\Component\Filesystem\Filesystem();
    $iterator = new DirectoryIterator($pathToCommittedTestModules);
    /** @var SplFileInfo $file */
    foreach ($iterator as $file) {
        if ($file->isDir() && !in_array($file->getFilename(), ['.', '..'])) {
            $targetDirPath = $pathToInstalledMagentoInstanceModules . '/' . $file->getFilename();
            $filesystem->remove($targetDirPath);
        }
    }
    unset($iterator, $file);
}
