<?php
/**
 * This file is part of modstore publisher package.
 * For complete copyright and license information, see the LICENSE file
 * found in the top-level directory of this distribution.
 **/

declare(strict_types = 1);

namespace Alroniks\Publisher\Command;

use Alroniks\Publisher\ExtraException;
use Alroniks\Publisher\PackageForm;
use Alroniks\Publisher\SignatureException;
use Alroniks\Publisher\TokenException;
use DiDom\Document;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use JetBrains\PhpStorm\ArrayShape;
use JsonException;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

final class PublishCommand extends Command
{
    // todo: replace by option and make it mandatory
    public const ARGUMENT_PACKAGE = 'package';

    public const OPTION_LOGIN = 'login';
    public const OPTION_PASSWORD = 'password';

    public const OPTION_DEPRECATE = 'deprecate';
    public const OPTION_OVERRIDE = 'override';

    public const OPTION_REQUIRED_PHP_VERSION = 'php-version';
    public const OPTION_REQUIRED_MODX_VERSION = 'modx-version';
    public const OPTION_REQUIRED_MODX_VERSION_MAX = 'modx-version-until';

    public const OPTION_CHANGELOG = 'changelog';
    public const OPTION_CHANGELOG_ENGLISH = 'changelog-english';

    // todo: remove fields, it should be always parsed from filename and need add validation for it
    public const OPTION_PACKAGE = 'package';
    public const OPTION_RELEASE = 'release';

    private const MANDATORY_OPTIONS = [
        self::OPTION_LOGIN,
        self::OPTION_PASSWORD,
        self::OPTION_CHANGELOG
        // package as well
    ];

    private const CLIENT_BASE_URI = 'https://modstore.pro/';
    private const CLIENT_ENTRY_POINT = 'assets/components/extras/action.php';

    // todo: replace to
    private const ACTION_CREATE = 'office/versions/create';
    private const ACTION_UPDATE = 'office/versions/update';

    // minimum_supports
    // todo: parse lists with options from modstore, not hard defined
    private const MODX_VERSIONS = ['2.2', '2.3', '2.4', '2.5', '2.6', '2.7', '2.8', '3.0'];
    private const MODX_DEFAULT_VERSION = '2.8';

    // supports
    // todo: parse lists with options from modstore, not hard defined
    private const MODX_VERSIONS_UNTIL = ['2.2', '2.3', '2.4', '2.5', '2.6', '2.7', '2.8', '3.0'];
    private const MODX_DEFAULT_MAX_VERSION = '2.8';

    // todo: parse lists with options from modstore
    private const PHP_VERSIONS = ['5.3', '5.4', '5.5', '5.6', '7.0', '7.1', '7.2'];
    private const PHP_DEFAULT_VERSION = '7.2';

    private Client $client;
    private SymfonyStyle $io;

    protected function configure(): void
    {
        $this
            ->setName('publish')
            ->setDescription('Publishes or updates the next package version to the marketplace.')
            ->setDefinition(
                [
                    new InputArgument(
                        self::ARGUMENT_PACKAGE, InputArgument::REQUIRED,
                        'Path to the archive with compiled MODX package.'
                    ),

                    // credentials
                    new InputOption(
                        self::OPTION_LOGIN, 'u', InputOption::VALUE_REQUIRED,
                        'User name (email) for login on modstore.'
                    ),
                    new InputOption(
                        self::OPTION_PASSWORD, 'p', InputOption::VALUE_REQUIRED,
                        'Password for login on modstore.'
                    ),

                    // package
                    new InputOption(
                        self::OPTION_PACKAGE, null, InputOption::VALUE_REQUIRED,
                        'The name of the package. Usually, it is taken from the filename of the archive,
                        but if defined, it will be used to fetch the package page.'
                    ),
                    new InputOption(
                        self::OPTION_RELEASE, null, InputOption::VALUE_REQUIRED,
                        'The version of the package to upload. Usually, it is taken from the filename of the archive,
                        but if defined, it will override parsed value.'
                    ),

                    // changelog
                    new InputOption(
                        self::OPTION_CHANGELOG, null, InputOption::VALUE_REQUIRED,
                        'Path to the file with changelog entries.'
                    ),
                    new InputOption(
                        self::OPTION_CHANGELOG_ENGLISH, null, InputOption::VALUE_REQUIRED,
                        'Alternative file for English variant in case, when original changelog on Russian.
                        By default one file used for both cases.'
                    ),

                    // versions
                    new InputOption(
                        self::OPTION_REQUIRED_MODX_VERSION, null, InputOption::VALUE_REQUIRED,
                        'Minimal version of MODX which required for running the package code.',
                        '2.8'
                    ),
                    new InputOption(
                        self::OPTION_REQUIRED_MODX_VERSION_MAX, null, InputOption::VALUE_REQUIRED,
                        'Up to what maximum version of MODX the package code is guaranteed to work.
                        May be useful to limit packages, which are not compatible with MODX 3 yet.'
                    ),
                    new InputOption(
                        self::OPTION_REQUIRED_PHP_VERSION, null, InputOption::VALUE_REQUIRED,
                        'Minimal version of PHP which required for running the package code.',
                        '7.2'
                    ),

                    // flags
                    new InputOption(
                        self::OPTION_DEPRECATE, 'd', InputOption::VALUE_NONE,
                        'Disable all previous versions of the package.'
                    ),
                    new InputOption(
                        self::OPTION_OVERRIDE, 'r', InputOption::VALUE_NONE,
                        'Override existing version by the new binary package.
                        If the version does not exist, the flag will be ignored.'
                    ),
                ]
            )
            ->setHelp(
                 <<<EOT
The <info>publish</info> command connects to marketplace, analizes versions
and publishes or updates the version of the package in the repository.

It is default command, so no need to define it, just run the following command to get interactive mode.

<info>bin/publisher</info>

Read more at https://github.com/alroniks/modstore-publisher#readme
EOT
                );
        // todo: replace link by static website with docs
    }

    public function initialize(InputInterface $input, OutputInterface $output): void
    {
        $this->io = new SymfonyStyle($input, $output);

        $this->client = new Client([
            'base_uri' => self::CLIENT_BASE_URI,
            'cookies' => true
        ]);

        parent::initialize($input, $output);
    }

    public function getIO(): SymfonyStyle
    {
        return $this->io;
    }

    public function getClient(): Client
    {
        return $this->client;
    }

    /**
     * @throws \Spatie\DataTransferObject\Exceptions\UnknownProperties
     * @throws \DiDom\Exceptions\InvalidSelectorException
     * @throws \JsonException
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        // todo: add validation of mandatory fields


        try {
            // 01. Attempt to login
            $output->writeln($this->formatServiceAnswer(
                $this->login($input->getOption(self::OPTION_LOGIN), $input->getOption(self::OPTION_PASSWORD))
            ), OutputInterface::VERBOSITY_NORMAL);

            // 02. Parse archive name
            [$package, $version] = $this->parsePackage($input->getArgument(self::ARGUMENT_PACKAGE));

            // 03. Override parsed name and versions, if defined
            // todo: confirm such overriding
            $package = $input->getOption(self::OPTION_PACKAGE) ?? $package;
            $version = $input->getOption(self::OPTION_RELEASE) ?? $version;

            // 04. Get the page of the package
            $content = $this->getClient()
                ->request('GET', sprintf('office/packages/%s', $package))
                ->getBody()->getContents();

            // 05. Get the CSRF token
            $token = $this->parseToken($content);

            // 06. Get meta information about extra
            $extra = $this->parseExtra($content);

            exit(0);

            // если версии совпадают и есть опция override, то нужно обновить
            // если опции нет - то просто добавиь
            // или спросить вопрос, перезаписать ли?

//            $form = new PackageForm(
//                action: self::ACTION_CREATE,
//                package_id: $extra['id']
//
//            );


//            print_r($extra);
//            print_r(array_column($extra['versions'], 'active'));

            // todo: confirm publishing on the last step

            // 07. Upload the compiled package
            $output->writeln($this->formatServiceAnswer(
                $this->upload($form, $token, true)
            ));

            return self::SUCCESS;
        } catch (GuzzleException $gex) {
            $output->writeln($this->formatServiceAnswer($gex->getResponse())); // todo: replace by styles
            return self::FAILURE;
        } catch (SignatureException | TokenException | ExtraException $e) {
            $output->writeln($e->getMessage()); // todo: make an error from styles
            return self::FAILURE;
        }
    }

    protected function interact(InputInterface $input, OutputInterface $output): void
    {
        $io = $this->getIO();

        $io->title('MODX Extra Publisher');
        $io->block('This tool will help you publish your package on modstore.pro marketplace.');
        $io->block('Follow the sections and answer the quesions to get it published.');

        if (!$input->getOption(self::OPTION_LOGIN) && !$input->getOption(self::OPTION_PASSWORD)) {

            $io->section('Signing in marketplace');
            $io->text('Please, enter login and password of your account on modstore.pro.');

            if (!$input->getOption(self::OPTION_LOGIN)) {
                $login = $io->ask('Login'); // valid email validator?
                // todo: get default value from git config? cache? composer?

                $input->setOption(self::OPTION_LOGIN, $login);
            }

            if (!$input->getOption(self::OPTION_PASSWORD)) {
                $input->setOption(self::OPTION_PASSWORD, $io->askHidden('Password'));
            }
        }

        $io->section('Configuring the version');

    }

    /**
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    private function login(string $email, string $password): ResponseInterface
    {
        return $this->getClient()->request(
            'POST',
            self::CLIENT_ENTRY_POINT,
            [
                'form_params' => [
                    'action' => 'office/login',
                    'email' => $email,
                    'password' => $password,
                ],
            ]
        );
    }

    private function parsePackage(string $path): array
    {
        $data = pathinfo($path);

        if ($data['extension'] !== 'zip') {
            throw new SignatureException('Provided file is not zip-archive.');
        }

        $pattern = '/^([a-z]+)-(\d+\.\d+\.\d+-[a-z]+)\.transport$/m';

        $check = preg_match($pattern, $data['filename'], $matches);

        if ($check === false) {
            throw new RuntimeException('Impossible to match package signature by regular expression.');
        } elseif ($check === 0) {
            throw new SignatureException('Provided file has an incompatible package name.');
        }

        return array_values(array_filter($matches, static fn($key) => in_array($key, [1, 2], true), ARRAY_FILTER_USE_KEY));
    }

    /**
     * @throws \DiDom\Exceptions\InvalidSelectorException
     */
    #[ArrayShape(['id' => "int", 'versions' => "array"])]
    private function parseExtra(string $content): array
    {
        $document = new Document($content);

        $input = $document->first('#office-package-form input[name="id"]');

        if (!$input) {
            throw new ExtraException('Element with extra ID is not found on the page.');
        }

        $versions = [];
        foreach ($document->find('#pdopage tr.version') as $version) {
            $cells = $version->find('td');

            $href = $cells[4]->first('a.fa-edit')->getAttribute('href');
            $segments = explode('/', $href);

            $versions[] = [
                'id' => (int) array_pop($segments),
                'active' => !str_contains($version->getAttribute('class'), 'inactive'),
                'version' => trim($cells[0]->text()),
                'released' => trim($cells[1]->text()),
                'downloads' => (int) trim($cells[2]->text()),
                'updated' => trim($cells[3]->text())
            ];
        }

        return [
            'id' => (int) $input->getAttribute('value'),
            'versions' => $versions
        ];
    }

    private function parseToken(string $content): string
    {
        // todo: replace by DiDom parser?
        $tempFile = tmpfile();
        fwrite($tempFile, $content);
        $metatags = get_meta_tags(stream_get_meta_data($tempFile)['uri']);

        if (!array_key_exists('csrf-token', $metatags)) {
            throw new TokenException('CSRF token not found, next operations impossible.');
        }

        return $metatags['csrf-token'];
    }

    /**
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    private function upload(PackageForm $form, string $token, bool $debug = false): ResponseInterface
    {
        return $this->getClient()->request('POST', self::CLIENT_ENTRY_POINT, [
            'multipart' => $form->multipart(),
            'headers' => ['X-CSRF-Token' => $token],
            'debug' => $debug
        ]);
    }

    /**
     * @throws JsonException
     */
    private function formatServiceAnswer(ResponseInterface $response): string
    {
        // replace by styles?

        $content = $response->getBody()->getContents();
        $decoded = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
        $success = (bool) ($decoded['success'] ?? false);

        return sprintf("<%s>\n%s\n</>", $success ? 'info' : 'error', $decoded['message'] ?? '');
    }
}

