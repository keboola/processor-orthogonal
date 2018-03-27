<?php

declare(strict_types=1);

namespace Keboola\ProcessorOrthogonal;

use Keboola\Component\BaseComponent;
use Keboola\Component\UserException;
use Keboola\Csv\CsvFile;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Finder\SplFileInfo;
use Symfony\Component\Serializer\Encoder\JsonDecode;
use Symfony\Component\Serializer\Encoder\JsonEncode;
use Symfony\Component\Serializer\Encoder\JsonEncoder;

class Component extends BaseComponent
{
    /**
     * @var JsonEncode
     */
    private $jsonEncode;

    /**
     * @var JsonDecode
     */
    private $jsonDecode;

    /**
     * @var Filesystem
     */
    private $fs;

    public function __construct()
    {
        parent::__construct();
        $this->fs = new Filesystem();
        $this->jsonEncode = new JsonEncode();
        $this->jsonDecode = new JsonDecode(true);
    }

    public function run(): void
    {
        $finder = new Finder();
        $finder->in($this->getDataDir() . '/in/tables')->notName('*.manifest')->depth(0);
        foreach ($finder as $file) {
            $manifest = $this->readManifest($file);
            $header = $manifest['columns'];
            $delimiter = $manifest['delimiter'];
            $enclosure = $manifest['enclosure'];

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
                        $this->getMaxColCount($slicedFile, $delimiter, $enclosure)
                    );
                }
                $header = $this->fillHeader($header, $maxColCount);
                foreach ($sliceFinder as $slicedFile) {
                    $destination = $this->getDataDir() . '/out/tables/' .
                        $file->getFilename() . '/' . $slicedFile->getFilename();
                    $this->orthogonalize($slicedFile, $destination, $maxColCount, $delimiter, $enclosure);
                }
            } else {
                $maxColCount = $this->getMaxColCount($file, $delimiter, $enclosure);
                $header = $this->fillHeader($header, $maxColCount);
                $destination = $this->getDataDir() . '/out/tables/' . $file->getFilename();
                $this->orthogonalize($file, $destination, $maxColCount, $delimiter, $enclosure);
            }

            $this->writeManifest($file, $manifest, $header);
        }
    }

    private function readManifest(SplFileInfo $file) : array
    {
        $manifestFile = $file->getPathname() . '.manifest';
        if (!$this->fs->exists($manifestFile)) {
            throw new UserException(
                'Table ' . $file->getBasename() . ' does not have a manifest file.'
            );
        }

        $manifest = $this->jsonDecode->decode(file_get_contents($manifestFile), JsonEncoder::FORMAT);
        $this->validateManifest($manifest, $file->getFilename());
        return $manifest;
    }

    private function validateManifest(array $manifest, string $fileName) : void
    {
        if (!isset($manifest['columns'])) {
            throw new UserException(
                'Manifest file for table ' . $fileName . ' does not specify columns.'
            );
        }
        if (!isset($manifest['delimiter'])) {
            throw new UserException(
                'Manifest file for table ' . $fileName . ' does not specify delimiter.'
            );
        }
        if (!isset($manifest['enclosure'])) {
            throw new UserException(
                'Manifest file for table ' . $fileName . ' does not specify enclosure.'
            );
        }
    }

    private function getMaxColCount(SplFileInfo $file, string $delimiter, string $enclosure) : int
    {
        $csvFile = new CsvFile($file->getPathname(), $delimiter, $enclosure);
        $maxColCount = 0;
        foreach ($csvFile as $row) {
            $maxColCount = max($maxColCount, count($row));
        }
        return $maxColCount;
    }

    private function fillHeader(array $header, int $maxColCount) : array
    {
        for ($i = 0; $i < $maxColCount; $i++) {
            if (empty($header[$i])) {
                $header[$i] = 'auto_col_' . $i;
            }
        }
        return $header;
    }

    private function orthogonalize(
        SplFileInfo $file,
        string $destination,
        int $maxColCount,
        string $delimiter,
        string $enclosure
    ) : void {
        $sourceCsv = new CsvFile($file->getPathname(), $delimiter, $enclosure);
        $destinationCsv = new CsvFile($destination, $delimiter, $enclosure);
        foreach ($sourceCsv as $index => $row) {
            $destinationCsv->writeRow(array_pad($row, $maxColCount, ''));
        }
    }

    private function writeManifest(SplFileInfo $file, array $manifest, array $header) : void
    {
        $manifest["columns"] = $header;
        $targetManifest = $this->getDataDir() . '/out/tables/' . $file->getFilename() . ".manifest";
        file_put_contents(
            $targetManifest,
            $this->jsonEncode->encode($manifest, JsonEncoder::FORMAT)
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
