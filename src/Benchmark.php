<?php declare(strict_types=1);

namespace Bruha\ParallelPhpUnit;

use SimpleXMLElement;

/**
 * Class Benchmark
 *
 * @package Bruha\ParallelPhpUnit
 */
final class Benchmark
{

    private const CONFIGURATIONS = [
        __DIR__ . '/../../../../phpunit.xml.dist',
        __DIR__ . '/../../../../phpunit.xml',
        __DIR__ . '/../phpunit.xml.dist',
        __DIR__ . '/../phpunit.xml',
    ];

    /**
     * @var int
     */
    private $concurrency;

    /**
     * @var int
     */
    private $rounds;

    /**
     * @var bool
     */
    private $coverage;

    /**
     * Benchmark constructor
     *
     * @param int  $concurrency
     * @param int  $rounds
     * @param bool $coverage
     */
    public function __construct(int $concurrency, int $rounds, bool $coverage)
    {
        $this->concurrency = $concurrency;
        $this->rounds      = $rounds;
        $this->coverage    = $coverage;
    }

    /**
     * @return int
     */
    public function run(): int
    {
        /** @var SimpleXMLElement $configuration */
        $configuration = simplexml_load_file($this->getConfiguration());
        $target        = (string) $configuration->xpath("(//log[@type='junit'])[1]")[0]['target'];
        $results       = [];

        for ($i = 1; $i <= $this->rounds; $i++) {
            for ($y = 1; $y <= $this->concurrency; $y++) {
                echo sprintf(
                    '%s===== %03.0f / %03.0f ===== %02.0f / %02.0f ===== %02.0f / %02.0f ======%s',
                    PHP_EOL,
                    ($i - 1) * $this->concurrency + $y,
                    $this->concurrency * $this->rounds,
                    $i,
                    $this->rounds,
                    $y,
                    $this->concurrency,
                    str_repeat(PHP_EOL, 2)
                );

                (new Command($y, $this->coverage))->run();

                /** @var SimpleXMLElement $result */
                $result      = simplexml_load_file($target);
                $results[$y] = ($results[$y] ?? 0) + (float) $result->xpath('(//testsuites)[1]')[0]['concurrencyTime'];
            }
        }

        asort($results);

        $results = array_filter(
            $results,
            static function ($value): bool {
                return (int) $value !== 0;
            }
        );

        $best = array_values($results)[0];

        echo sprintf(
            '%s%07.3fs (100.000%%): Time analysis of %02.0f concurrency levels%s',
            PHP_EOL,
            array_sum($results) / $this->rounds,
            $this->concurrency,
            PHP_EOL
        );

        foreach ($results as $key => $result) {
            echo sprintf(
                '%07.3fs (%06.3f%%): %02.0f concurrency%s',
                $result / $this->rounds,
                $result / $best * 100,
                $key,
                PHP_EOL
            );
        }

        return 0;
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

}