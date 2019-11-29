<?php declare(strict_types=1);

namespace Bruha\ParallelPhpUnit;

use Symfony\Component\Process\Process;

/**
 * Class Runner
 *
 * @package Bruha\ParallelPhpUnit
 */
final class Runner
{

    private const REGEX = '/^((?:\.|E|F|I|R|S|W)+)\s*(?:\d+|$)/';
    private const WIDTH = 50;

    /**
     * @var string
     */
    private $phpUnit;

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
     * Runner constructor
     *
     * @param string $phpUnit
     * @param string $directory
     * @param int    $concurrency
     * @param bool   $coverage
     */
    public function __construct(string $phpUnit, string $directory, int $concurrency, bool $coverage)
    {
        $this->phpUnit     = $phpUnit;
        $this->directory   = $directory;
        $this->concurrency = $concurrency;
        $this->coverage    = $coverage;
    }

    /**
     *
     */
    public function process(): void
    {
        $this->runTests($this->getTests());
    }

    /**
     * @return array
     */
    private function getTests(): array
    {
        $process = new Process([$this->phpUnit, '--list-tests']);
        $process->run();
        $tests = [];

        if (strlen($process->getErrorOutput()) > 0) {
            echo $process->getErrorOutput();
            exit(1);
        }

        foreach (explode(PHP_EOL, $process->getOutput()) as $content) {
            if (preg_match('/^ - (.+)/', $content, $matches) === 1) {
                $tests[] = $matches[1];
            }
        }

        return $tests;
    }

    /**
     * @param array $tests
     */
    private function runTests(array $tests): void
    {
        /** @var Process[] $processes */
        $processes    = [];
        $currentCount = 0;
        $maximumCount = count($tests);

        $innerTests = [];
        $counter    = 0;

        foreach ($tests as $test) {
            $innerTests[$counter][] = $test;
            $counter++;

            if ($counter === $this->concurrency) {
                $counter = 0;
            }
        }

        foreach ($innerTests as $key => $test) {
            $callback = static function (string $test): string {
                return sprintf('^%s$', str_replace('\\', '\\\\', $test));
            };

            $innerArguments = [
                '--coverage-php',
                sprintf('%s/%s._php', $this->directory, $key),
            ];

            $arguments = array_merge(
                [
                    $this->phpUnit,
                    '--log-junit',
                    sprintf('%s/%s._xml', $this->directory, $key),
                ],
                $this->coverage ? $innerArguments : ['--no-coverage'],
                [
                    '--filter',
                    sprintf('(%s)', implode('|', array_map($callback, $test))),
                ]
            );

            $process = new Process($arguments, NULL, NULL, NULL, NULL);
            $process->start();
            $processes[] = $process;
        }

        do {
            usleep(100000);

            foreach ($processes as $key => $process) {
                if (preg_match(self::REGEX, $process->getIncrementalOutput(), $matches) === 1) {
                    $currentCount += strlen($matches[1]);

                    echo $matches[1];

                    if ($currentCount % self::WIDTH === 0) {
                        echo sprintf(
                            sprintf(' %%%1$d.0f / %%%1$d.0f (%%3.0f%%%%) %%s', strlen((string) $maximumCount)),
                            $currentCount,
                            $maximumCount,
                            (int) ($currentCount / $maximumCount * 100),
                            PHP_EOL
                        );
                    }
                }

                if (!$process->isRunning()) {
                    unset($processes[$key]);
                }
            }

            $processesCount = count($processes);
        } while ($processesCount > 0);

        echo sprintf(
            sprintf('%%s %%%1$d.0f / %%%1$d.0f (100%%%%) %%s', strlen((string) $maximumCount)),
            str_repeat(' ', (int) max(0, (ceil($currentCount / self::WIDTH) * self::WIDTH) - $maximumCount)),
            $currentCount,
            $maximumCount,
            PHP_EOL
        );
    }

}