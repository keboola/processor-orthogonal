<?php

declare(strict_types=1);

namespace Keboola\ProcessorOrthogonal;

use Keboola\Component\BaseComponent;
use Keboola\Component\Manifest\ManifestManager;
use Keboola\Component\Manifest\ManifestManager\Options\OutTable\ManifestOptions;
use Keboola\Component\Manifest\ManifestManager\Options\OutTable\ManifestOptionsSchema;
use Keboola\Component\UserException;
use Keboola\Csv\CsvReader;
use Keboola\Csv\CsvWriter;
use Psr\Log\LoggerInterface;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Finder\SplFileInfo;

class Component extends BaseComponent
{

    private Filesystem $fs;

    private ManifestManager $manifestManager;

    public function __construct(LoggerInterface $logger)
    {
        parent::__construct($logger);
        $this->fs = new Filesystem();
        $this->manifestManager = new ManifestManager($this->getDataDir());
    }

    protected function run(): void
    {
        $finder = new Finder();
        $finder->in($this->getDataDir() . '/in/tables')->notName('*.manifest')->depth(0);
        foreach ($finder as $file) {
            $manifest = $this->readManifest($file->getFilename());
            /** @var ManifestOptionsSchema[] $schema validated at validateManifest() */
            $schema = $manifest->getSchema();
            /** @var string $delimiter validated at validateManifest() */
            $delimiter = $manifest->getDelimiter();
            /** @var string $enclosure validated at validateManifest() */
            $enclosure = $manifest->getEnclosure();

            if (is_dir($file->getPathname())) {
                // sliced file
                $sliceFinder = new Finder();
                $sliceFinder->in($file->getPathname())->files();
                $slicedDestination = $this->getDataDir() . '/out/tables/' . $file->getFilename();
                $this->fs->mkdir($slicedDestination);
                $maxColCount = 0;
                foreach ($sliceFinder as $slicedFile) {
                    $maxColCount = max(
                        $maxColCount,
                        $this->getMaxColCount($slicedFile, $delimiter, $enclosure),
                    );
                }
                $schema = $this->fillHeader($schema, $maxColCount);
                foreach ($sliceFinder as $slicedFile) {
                    $destination = $this->getDataDir() . '/out/tables/' .
                        $file->getFilename() . '/' . $slicedFile->getFilename();
                    $this->orthogonalize($slicedFile, $destination, $maxColCount, $delimiter, $enclosure);
                }
            } else {
                $maxColCount = $this->getMaxColCount($file, $delimiter, $enclosure);
                $schema = $this->fillHeader($schema, $maxColCount);
                $destination = $this->getDataDir() . '/out/tables/' . $file->getFilename();
                $this->orthogonalize($file, $destination, $maxColCount, $delimiter, $enclosure);
            }

            $this->writeManifest($file->getFilename(), $manifest, $schema);
        }
    }

    private function readManifest(string $filename): ManifestOptions
    {
        $manifestOptions = $this->manifestManager->getTableManifest($filename);

        $this->validateManifest($manifestOptions, $filename);

        return $manifestOptions;
    }

    private function validateManifest(ManifestOptions $manifestOptions, string $fileName): void
    {
        if ($manifestOptions->getSchema() === null) {
            throw new UserException(
                'Manifest file for table ' . $fileName . ' does not specify columns.',
            );
        }
        if ($manifestOptions->getDelimiter() === null) {
            throw new UserException(
                'Manifest file for table ' . $fileName . ' does not specify delimiter.',
            );
        }
        if ($manifestOptions->getEnclosure() === null) {
            throw new UserException(
                'Manifest file for table ' . $fileName . ' does not specify enclosure.',
            );
        }
    }

    private function getMaxColCount(SplFileInfo $file, string $delimiter, string $enclosure): int
    {
        $csvFile = new CsvReader($file->getPathname(), $delimiter, $enclosure);
        $maxColCount = 0;
        /** @var string[] $row */
        foreach ($csvFile as $row) {
            $maxColCount = max($maxColCount, count($row));
        }
        return $maxColCount;
    }

    /**
     * @param ManifestOptionsSchema[] $schema
     * @return ManifestOptionsSchema[]
     */
    private function fillHeader(array $schema, int $maxColCount): array
    {
        for ($i = 0; $i < $maxColCount; $i++) {
            if (empty($schema[$i])) {
                $schema[$i] = new ManifestOptionsSchema('auto_col_' . $i);
            }
        }
        return $schema;
    }

    private function orthogonalize(
        SplFileInfo $file,
        string $destination,
        int $maxColCount,
        string $delimiter,
        string $enclosure,
    ): void {
        $sourceCsv = new CsvReader($file->getPathname(), $delimiter, $enclosure);
        $destinationCsv = new CsvWriter($destination, $delimiter, $enclosure);
        /** @var string[] $row */
        foreach ($sourceCsv as $index => $row) {
            $destinationCsv->writeRow(array_pad($row, $maxColCount, ''));
        }
    }

    /**
     * @param ManifestOptionsSchema[] $schema
     */
    private function writeManifest(string $filename, ManifestOptions $manifestOptions, array $schema): void
    {
        $manifestOptions->setSchema($schema);
        $this->manifestManager->writeTableManifest(
            $filename,
            $manifestOptions,
            $this->config->getDataTypeSupport()->usingLegacyManifest(),
        );
    }

    protected function getConfigClass(): string
    {
        return Config::class;
    }

    protected function getConfigDefinitionClass(): string
    {
        return ConfigDefinition::class;
    }
}
