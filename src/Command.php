<?php declare(strict_types=1);

namespace Bruha\ParallelPhpUnit;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SimpleXMLElement;
use SplFileInfo;

/**
 * Class Command
 *
 * @package Bruha\ParallelPhpUnit
 */
final class Command
{

    private const EXECUTABLES = [
        __DIR__ . '/../../../bin/phpunit',
        __DIR__ . '/../vendor/bin/phpunit',
    ];

    private const CONFIGURATIONS = [
        __DIR__ . '/../../../../phpunit.xml.dist',
        __DIR__ . '/../../../../phpunit.xml',
        __DIR__ . '/../phpunit.xml.dist',
        __DIR__ . '/../phpunit.xml',
    ];

    /**
     * @var string
     */
    private $directory;

    /**
     * @var int
     */
    private $concurrency;

    /**
     * @var bool
     */
    private $coverage;

    /**
     * Command constructor
     *
     * @param int  $concurrency
     * @param bool $coverage
     */
    public function __construct(int $concurrency, bool $coverage)
    {
        $this->concurrency = $concurrency;
        $this->coverage    = $coverage;
        $this->directory   = sprintf('%s/parallel-phpunit', sys_get_temp_dir());
    }

    /**
     * @return int
     */
    public function run(): int
    {
        $this->cleanDirectory();
        $timestamp = microtime(TRUE);

        (new Runner($this->getPhpUnit(), $this->directory, $this->concurrency, $this->coverage))->process();

        (new Coverage(
            $this->getConfiguration(),
            $this->directory,
            $this->concurrency,
            $this->coverage,
            microtime(TRUE) - $timestamp
        ))->process();

        return $this->printDefects();
    }

    /**
     *
     */
    private function printDefects(): int
    {
        $counter = 1;

        /** @var string $file */
        foreach ((array) glob(sprintf('%s/*._xml', $this->directory)) as $file) {
            /** @var SimpleXMLElement $xml */
            $xml = simplexml_load_file($file);

            foreach (['failure', 'error', 'warning'] as $event) {
                foreach ((array) $xml->xpath(sprintf('//%s', $event)) as $innerEvent) {
                    echo sprintf('%s%s) %s', PHP_EOL, $counter, $innerEvent);
                    $counter++;
                }
            }
        }

        return (int) ($counter !== 1);
    }

    /**
     * @return string
     */
    public function getPhpUnit(): string
    {
        foreach (self::EXECUTABLES as $path) {
            if (is_file($path)) {
                return $path;
            }
        }

        return '';
    }

    /**
     * @return string
     */
    private function getConfiguration(): string
    {
        foreach (self::CONFIGURATIONS as $path) {
            if (is_file($path)) {
                return $path;
            }
        }

        return '';
    }

    /**
     *
     */
    private function cleanDirectory(): void
    {
        if (is_dir($this->directory)) {
            /** @var SplFileInfo[] $objects */
            $objects = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($this->directory, RecursiveDirectoryIterator::SKIP_DOTS),
                RecursiveIteratorIterator::CHILD_FIRST
            );

            foreach ($objects as $object) {
                if (is_string($object->getRealPath())) {
                    $object->isDir() ? rmdir($object->getRealPath()) : unlink($object->getRealPath());
                }
            }

            rmdir($this->directory);
        }

        mkdir($this->directory, 0777, TRUE);
    }

}