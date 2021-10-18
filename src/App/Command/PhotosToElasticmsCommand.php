<?php

namespace App\Command;

use App\Helper\AlbumStructure;
use EMS\CommonBundle\Common\Command\AbstractCommand;
use EMS\CommonBundle\Common\CoreApi\Client;
use EMS\CommonBundle\Common\CoreApi\CoreApi;
use EMS\CommonBundle\Storage\StorageManager;
use Symfony\Component\Cache\Adapter\FilesystemAdapter;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Logger\ConsoleLogger;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;
use Symfony\Component\Finder\Finder;
use Symfony\Component\HttpFoundation\File\MimeType\MimeTypeGuesser;
use Symfony\Contracts\Cache\ItemInterface;

class PhotosToElasticmsCommand extends AbstractCommand
{
    private const ARG_APPLE_PHOTOS_PATH = 'apple-photos-path';
    private const ARG_ELASTICMS_URL = 'elasticms-url';
    private const ARG_HASH_ALGO = 'hash-algo';
    private const ARG_USERNAME = 'username';
    private const ARG_PASSWORD = 'password';
    public const ARG_LIBRARY_CONTENT_TYPE = 'library-content-type';
    public const ARG_ASSET_CONTENT_TYPE = 'asset-content-type';
    protected static $defaultName = 'ems:photos-to-elasticms';
    private ConsoleLogger $logger;
    private CoreApi $coreApi;
    private FilesystemAdapter $cache;
    private string $photosPath;
    private \SQLite3 $db;
    private string $libraryContentType;
    private string $assetContentType;
    private MimeTypeGuesser $mimeTypeGuesser;

    protected function configure(): void
    {
        $this
            ->setDescription('Import a Apple Photos library in elasticms')
            ->addArgument(
                self::ARG_ELASTICMS_URL,
                InputArgument::REQUIRED,
                'Elacticms\'s URL'
            )
            ->addArgument(
                self::ARG_APPLE_PHOTOS_PATH,
                InputArgument::OPTIONAL,
                'Path to the Apple Photos Library',
                \getenv('HOME').'/Pictures/Photos Library.photoslibrary'
            )
            ->addOption(
                self::ARG_HASH_ALGO,
                null,
                InputOption::VALUE_OPTIONAL,
                'Algorithm used to hash assets',
                'sha1'
            )
            ->addOption(
                self::ARG_LIBRARY_CONTENT_TYPE,
                null,
                InputOption::VALUE_OPTIONAL,
                'Library content type',
                'photos-library'
            )
            ->addOption(
                self::ARG_ASSET_CONTENT_TYPE,
                null,
                InputOption::VALUE_OPTIONAL,
                'Asset content type',
                'photos-asset'
            )
            ->addArgument(self::ARG_USERNAME, InputArgument::OPTIONAL, 'username', null)
            ->addArgument(self::ARG_PASSWORD, InputArgument::OPTIONAL, 'password', null);
    }

    protected function initialize(InputInterface $input, OutputInterface $output): void
    {
        parent::initialize($input, $output);
        $this->logger = new ConsoleLogger($output);
        $elasticmsUrl = $this->getArgumentString(self::ARG_ELASTICMS_URL);
        $this->photosPath = $this->getArgumentString(self::ARG_APPLE_PHOTOS_PATH);
        $this->libraryContentType = $this->getOptionString(self::ARG_LIBRARY_CONTENT_TYPE);
        $this->assetContentType = $this->getOptionString(self::ARG_ASSET_CONTENT_TYPE);
        $hash = $this->getOptionString(self::ARG_HASH_ALGO);
        $client = new Client($elasticmsUrl, $this->logger);
        $fileLocator = new FileLocator();
        $storageManager = new StorageManager($this->logger, $fileLocator, [], $hash);
        $this->coreApi = new CoreApi($client, $storageManager);
        $this->cache = new FilesystemAdapter();
        $this->db = new \SQLite3($this->getPhotosPath(['database', 'Photos.sqlite']), SQLITE3_OPEN_READONLY);
        $this->mimeTypeGuesser = MimeTypeGuesser::getInstance();
    }

    protected function interact(InputInterface $input, OutputInterface $output): void
    {
        $token = $this->cache->get('my_auth_key', function (ItemInterface $item) use ($input) {
            $item->expiresAfter(3600);

            if (null === $input->getArgument(self::ARG_USERNAME)) {
                $input->setArgument(self::ARG_USERNAME, $this->io->askQuestion(new Question('Username')));
            }

            if (null === $input->getArgument(self::ARG_PASSWORD)) {
                $input->setArgument(self::ARG_PASSWORD, $this->io->askHidden('Password'));
            }
            $this->login();

            return $this->coreApi->getToken();
        });

        $this->coreApi->setToken($token);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->io->title('Starting updating elasticms');
//        $this->uploadDerivatives();
        $this->buildAlbumStructure();
        $this->importAssets();

        return self::EXECUTE_SUCCESS;
    }

    private function login(): void
    {
        $username = $this->getArgumentString(self::ARG_USERNAME);
        $password = $this->getArgumentString(self::ARG_PASSWORD);
        $this->coreApi->authenticate($username, $password);
    }

    private function uploadDerivatives(): void
    {
        $finder = new Finder();
        $finder->files()->in($this->getPhotosPath(['resources', 'derivatives']))->name('*.jpeg');

        if (!$finder->hasResults()) {
            throw new \RuntimeException('Unexpected missing derivatives');
        }

        $this->io->comment(\sprintf('%d derivatives have been located', $finder->count()));
        $uploadedCounter = 0;
        $counter = 0;
        $this->io->progressStart($finder->count());
        foreach ($finder as $file) {
            ++$counter;
            $realPath = $file->getRealPath();
            $filename = $file->getFilename();
            if (!\is_string($realPath)) {
                $this->io->comment(\sprintf('Derivative %s not found', $file->getFilename()));
                $this->io->progressAdvance();
                continue;
            }
            if ($this->coreApi->headFile($realPath)) {
                $this->io->progressAdvance();
                continue;
            }
            if (null !== $this->coreApi->uploadFile($realPath, $filename)) {
                ++$uploadedCounter;
            }
            $this->io->progressAdvance();
        }
        $this->io->progressFinish();
        $this->io->success(\sprintf('%d (on %d) derivatives have been uploaded', $uploadedCounter, $finder->count()));
    }

    private function buildAlbumStructure(): void
    {
        $results = $this->db->query('select * from ZGENERICALBUM where ZCREATORBUNDLEID IS NOT NULL ORDER BY Z_FOK_PARENTFOLDER ASC');
        if (false === $results) {
            throw new \RuntimeException('Unexpected false results');
        }
        $structure = new AlbumStructure();
        while ($row = $results->fetchArray()) {
            /** @var array{Z_PK: int, ZTITLE: string, ZPARENTFOLDER: int, ZUUID: string} $row */
            $id = \intval($row['Z_PK']);
            $structure->addRow($row, $this->getAssetsForAlbum($id));
        }

        $raw = [
            'albums' => $structure->getStructure(),
            'path' => $this->photosPath,
        ];

        $ouuid = $this->getOUUID($this->libraryContentType, $this->photosPath);
        $dataApi = $this->coreApi->data($this->libraryContentType);
        $dataApi->save($ouuid, $raw);
    }

    /**
     * @param string[] $path
     */
    private function getPhotosPath(array $path): string
    {
        return \implode(DIRECTORY_SEPARATOR, \array_merge([$this->photosPath], $path));
    }

    private function getOUUID(string $contentType, string $getPhotosPath): string
    {
        return \sha1(\implode(':', [$contentType, $getPhotosPath]));
    }

    /**
     * @return string[]
     */
    private function getAssetsForAlbum(int $id): array
    {
        $results = $this->db->query(\sprintf('select * from Z_26ASSETS where Z_26ASSETS.Z_26ALBUMS = %d', $id));
        if (false === $results) {
            return [];
        }
        $assets = [];
        while ($row = $results->fetchArray()) {
            $assets[] = \implode(':', [$this->assetContentType, $this->getOUUID($this->assetContentType, $row['Z_3ASSETS'])]);
        }

        return $assets;
    }

    private function importAssets(): void
    {
        $results = $this->db->query('select ZASSET.ZUUID as ZUUID, ZASSET.Z_PK as ID,  ZADDITIONALASSETATTRIBUTES.ZORIGINALFILENAME as FILENAME from ZADDITIONALASSETATTRIBUTES, ZASSET where ZASSET.ZEXTENDEDATTRIBUTES = ZADDITIONALASSETATTRIBUTES.Z_PK');
        if (false === $results) {
            return;
        }

        $this->io->progressStart($results->numColumns());
        $dataApi = $this->coreApi->data($this->assetContentType);
        while ($row = $results->fetchArray()) {
            $ouuid = $this->getOUUID($this->assetContentType, $row['ID']);
            $dataApi->save($ouuid, [
                'filename' => $row['FILENAME'],
                'file' => $this->getAsset($row['ZUUID']),
            ]);
            $this->io->progressAdvance();
        }
        $this->io->progressFinish();
    }

    /**
     * @return array<string, string|int>
     */
    private function getAsset(string $uuid): array
    {
        $finder = new Finder();
        $finder->files()->in($this->getPhotosPath(['resources', 'derivatives']))->name(\sprintf('%s*.jpeg', $uuid));

        if (!$finder->hasResults()) {
            return [];
        }

        $size = 0;
        $file = [];
        $derivative = false;
        $realPath = false;
        foreach ($finder as $fileItem) {
            if ($fileItem->getSize() <= $size) {
                continue;
            }
            $size = $fileItem->getSize();
            $derivative = $fileItem;
            $realPath = $fileItem->getRealPath();
            if (false === $realPath) {
                throw new \RuntimeException('Unexpected false path');
            }
            $hash = $this->coreApi->hashFile($realPath);
            $file = [
                'sha1' => $hash,
                'filename' => $fileItem->getFilename(),
                'mimetype' => $this->mimeTypeGuesser->guess($realPath) ?? 'application/octet-stream',
                'filesize' => $fileItem->getSize(),
            ];
        }

        if (false !== $derivative && false !== $realPath && !$this->coreApi->headFile($realPath)) {
            $this->coreApi->uploadFile($realPath, $derivative->getFilename());
        }

        return $file;
    }
}
