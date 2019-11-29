<?php declare(strict_types=1);

namespace Bruha\ParallelPhpUnit;

use DOMDocument;
use DOMElement;
use DOMXPath;
use PHPUnit\Runner\Version;
use SebastianBergmann\CodeCoverage\CodeCoverage;
use SebastianBergmann\CodeCoverage\Report\Clover;
use SebastianBergmann\CodeCoverage\Report\Crap4j;
use SebastianBergmann\CodeCoverage\Report\Html\Facade as HtmlFacade;
use SebastianBergmann\CodeCoverage\Report\PHP;
use SebastianBergmann\CodeCoverage\Report\Text;
use SebastianBergmann\CodeCoverage\Report\Xml\Facade as XmlFacade;
use SimpleXMLElement;

/**
 * Class Coverage
 *
 * @package Bruha\ParallelPhpUnit
 */
final class Coverage
{

    private const PROCESSORS_WINDOWS = 'wmic computersystem get NumberOfLogicalProcessors';
    private const PROCESSORS_LINUX   = 'cat /proc/cpuinfo | grep processor | wc -l';
    private const QUERY              = "/testsuites/testsuite[@name='%s']";
    private const INNER_QUERY        = "/testsuites/testsuite/testsuite[@name='%s']";
    private const QUERY_FINAL        = '/testsuites/testsuite/testsuite';
    private const ATTRIBUTES         = [
        'tests',
        'assertions',
        'failures',
        'skipped',
        'errors',
        'time',
    ];

    /**
     * @var SimpleXMLElement
     */
    private $configuration;

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
     * @var float
     */
    private $duration;

    /**
     * Coverage constructor
     *
     * @param string $configuration
     * @param string $directory
     * @param int    $concurrency
     * @param bool   $coverage
     * @param float  $duration
     */
    public function __construct(
        string $configuration,
        string $directory,
        int $concurrency,
        bool $coverage,
        float $duration
    ) {
        /** @var SimpleXMLElement $configuration */
        $configuration       = simplexml_load_file($configuration);
        $this->configuration = $configuration;
        $this->directory     = $directory;
        $this->concurrency   = $concurrency;
        $this->coverage      = $coverage;
        $this->duration      = $duration;
    }

    /**
     *
     */
    public function process(): void
    {
        $coverage         = new CodeCoverage();
        $partialCoverages = [];

        foreach ((array) glob(sprintf('%s/*._php', $this->directory)) as $file) {
            $partialCoverages[] = include $file;
        }

        foreach ($partialCoverages as $partialCoverage) {
            $coverage->merge($partialCoverage);
        }

        /** @var SimpleXMLElement $configuration */
        foreach ((array) $this->configuration->xpath('//log') as $configuration) {
            switch ($configuration['type']) {
                case 'coverage-clover':
                    if ($this->coverage) {
                        $this->createCloverCoverage($coverage, $configuration);
                    }

                    break;

                case 'coverage-crap4j':
                    if ($this->coverage) {
                        $this->createCrap4jCoverage($coverage, $configuration);
                    }

                    break;

                case 'coverage-html':
                    if ($this->coverage) {
                        $this->createHtmlCoverage($coverage, $configuration);
                    }

                    break;

                case 'coverage-xml':
                    if ($this->coverage) {
                        $this->createXmlCoverage($coverage, $configuration);
                    }

                    break;

                case 'coverage-php':
                    if ($this->coverage) {
                        $this->createPhpCoverage($coverage, $configuration);
                    }

                    break;

                case 'coverage-text':
                    if ($this->coverage) {
                        $this->createTextCoverage($coverage, $configuration);
                    }

                    break;

                case 'junit':
                    $this->createJunitCoverage($configuration);
                    break;

                default:
                    // Ignore other options!
                    break;
            }
        }
    }

    /**
     * @param DOMElement $element
     * @param DOMElement $innerElement
     * @param array      $attributes
     */
    private function changeAttributes(DOMElement $element, DOMElement $innerElement, array $attributes): void
    {
        foreach ($attributes as $attribute) {
            $element->setAttribute(
                $attribute,
                (string) ((float) $element->getAttribute($attribute) + (float) $innerElement->getAttribute($attribute))
            );
        }
    }

    /**
     * @param CodeCoverage     $coverage
     * @param SimpleXMLElement $configuration
     */
    private function createCloverCoverage(CodeCoverage $coverage, SimpleXMLElement $configuration): void
    {
        (new Clover())->process($coverage, $this->getTarget($configuration));
    }

    /**
     * @param CodeCoverage     $coverage
     * @param SimpleXMLElement $configuration
     */
    private function createCrap4jCoverage(CodeCoverage $coverage, SimpleXMLElement $configuration): void
    {
        (new Crap4j((int) ($configuration['threshold'] ?? 30)))->process($coverage, $this->getTarget($configuration));
    }

    /**
     * @param CodeCoverage     $coverage
     * @param SimpleXMLElement $configuration
     */
    private function createHtmlCoverage(CodeCoverage $coverage, SimpleXMLElement $configuration): void
    {
        (new HtmlFacade(
            $this->getMinimum($configuration),
            $this->getMaximum($configuration)
        ))->process(
            $coverage,
            $this->getTarget($configuration)
        );
    }

    /**
     * @param CodeCoverage     $coverage
     * @param SimpleXMLElement $configuration
     */
    private function createXmlCoverage(CodeCoverage $coverage, SimpleXMLElement $configuration): void
    {
        (new XmlFacade(Version::id()))->process($coverage, $this->getTarget($configuration));
    }

    /**
     * @param CodeCoverage     $coverage
     * @param SimpleXMLElement $configuration
     */
    private function createPhpCoverage(CodeCoverage $coverage, SimpleXMLElement $configuration): void
    {
        (new PHP())->process($coverage, $this->getTarget($configuration));
    }

    /**
     * @param CodeCoverage     $coverage
     * @param SimpleXMLElement $configuration
     */
    private function createTextCoverage(CodeCoverage $coverage, SimpleXMLElement $configuration): void
    {
        (new Text(
            $this->getMinimum($configuration),
            $this->getMaximum($configuration),
            (bool) $configuration['showUncoveredFiles'],
            (bool) $configuration['showOnlySummary']
        ))->process($coverage);
    }

    /**
     * @param DOMXPath   $path
     * @param DOMElement $element
     * @param string     $query
     *
     * @return DOMElement|NULL
     */
    private function getParentElement(DOMXPath $path, DOMElement $element, string $query): ?DOMElement
    {
        $parentElement = $path->query(sprintf($query, $element->getAttribute('name')));

        if ($parentElement->count() === 0) {
            return NULL;
        }

        /** @var DOMElement $domElement */
        $domElement = $parentElement->item(0);

        return $domElement;
    }

    /**
     * @param SimpleXMLElement $configuration
     */
    private function createJunitCoverage(SimpleXMLElement $configuration): void
    {
        $document = new DOMDocument('1.0', 'UTF-8');

        $document->formatOutput       = TRUE;
        $document->preserveWhiteSpace = FALSE;

        $path = new DOMXPath($document);
        $node = $document->createElement('testsuites');
        $document->appendChild($node);

        /** @var string $file */
        foreach ((array) glob(sprintf('%s/*._xml', $this->directory)) as $file) {
            $innerDocument = new DOMDocument();

            $innerDocument->formatOutput       = TRUE;
            $innerDocument->preserveWhiteSpace = FALSE;
            $innerDocument->loadXML((string) file_get_contents($file));

            /** @var DOMElement $child */
            foreach ($innerDocument->childNodes as $child) {
                if ($child->nodeType !== XML_ELEMENT_NODE) {
                    continue;
                }

                /** @var DOMElement $innerChild */
                foreach ($child->childNodes as $innerChild) {
                    if ($innerChild->nodeType === XML_ELEMENT_NODE) {
                        $innerNode = $this->getParentElement($path, $innerChild, self::QUERY);

                        if (!is_object($innerNode)) {
                            $node->appendChild($document->importNode($innerChild, TRUE));
                        } else {
                            $this->changeAttributes($innerNode, $innerChild, self::ATTRIBUTES);

                            /** @var DOMElement $innerInnerChild */
                            foreach ($innerChild->childNodes as $innerInnerChild) {
                                if ($innerInnerChild->nodeType !== XML_ELEMENT_NODE) {
                                    continue;
                                }

                                $innerInnerNode = $this->getParentElement($path, $innerInnerChild, self::INNER_QUERY);

                                if (!is_object($innerInnerNode)) {
                                    $innerNode->appendChild($document->importNode($innerInnerChild, TRUE));
                                } else {
                                    $this->changeAttributes($innerInnerNode, $innerInnerChild, self::ATTRIBUTES);

                                    foreach ($innerInnerChild->childNodes as $anotherChild) {
                                        $innerInnerNode->appendChild($document->importNode($anotherChild, TRUE));
                                    }
                                }
                            }
                        }
                    }
                }
            }
        }

        /** @var DOMElement $finalNode */
        foreach ($path->query(self::QUERY_FINAL) as $finalNode) {
            $children     = [];
            $sortChildren = [];

            foreach ($finalNode->childNodes as $innerNode) {
                $children[] = $innerNode;
            }

            foreach ($children as $child) {
                if ($child->nodeType !== XML_ELEMENT_NODE) {
                    continue;
                }

                $sortChildren[(int) $child->getAttribute('line')] = $child;
                $finalNode->removeChild($child);
            }

            ksort($sortChildren);

            foreach ($sortChildren as $child) {
                $finalNode->appendChild($child);
            }
        }

        $innerNode = $path->query('/testsuites/testsuite');

        if ($innerNode->count() !== 0) {
            /** @var DOMElement $element */
            $element = $innerNode->item(0);
            $time    = (float) $element->getAttribute('time');

            $multiplier = 1;
            $processors = $this->getProcessorsCount();

            if ($this->concurrency > $processors) {
                $multiplier = $processors / $this->concurrency;
            }

            $node->setAttribute('time', (string) ($time * $multiplier));
            $node->setAttribute('concurrency', (string) $this->concurrency);
            $node->setAttribute('concurrencyTime', (string) round($this->duration, 6));
            $node->setAttribute('concurrencySpeedUp', (string) round($time * $multiplier / $this->duration, 6));
        }

        $directory = dirname($this->getTarget($configuration));

        if (!is_dir($directory)) {
            mkdir($directory, 0777, TRUE);
        }

        $document->save($this->getTarget($configuration));
    }

    /**
     * @param SimpleXMLElement $configuration
     *
     * @return string
     */
    private function getTarget(SimpleXMLElement $configuration): string
    {
        return (string) $configuration['target'];
    }

    /**
     * @param SimpleXMLElement $configuration
     *
     * @return int
     */
    private function getMinimum(SimpleXMLElement $configuration): int
    {
        return (int) $configuration['lowUpperBound'];
    }

    /**
     * @param SimpleXMLElement $configuration
     *
     * @return int
     */
    private function getMaximum(SimpleXMLElement $configuration): int
    {
        return (int) $configuration['highLowerBound'];
    }

    /**
     * @return int
     */
    private function getProcessorsCount(): int
    {
        switch (PHP_OS_FAMILY) {
            case 'Windows':
                return (int) explode(PHP_EOL, (string) shell_exec(self::PROCESSORS_WINDOWS))[1];

            case 'Linux':
                return (int) shell_exec(self::PROCESSORS_LINUX);

            default:
                return 1;
        }
    }

}