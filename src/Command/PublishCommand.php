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
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

final class PublishCommand extends Command
{
    public const OPTION_PACKAGE = 'package';

    public const OPTION_LOGIN = 'login';
    public const OPTION_PASSWORD = 'password';

    public const OPTION_DEPRECATE = 'deprecate';
    public const OPTION_OVERRIDE = 'override';

    public const OPTION_REQUIRED_PHP_VERSION = 'php-version';
    public const OPTION_REQUIRED_MODX_VERSION = 'modx-version';
    public const OPTION_REQUIRED_MODX_VERSION_MAX = 'modx-version-until';

    public const OPTION_CHANGELOG = 'changelog';
    public const OPTION_CHANGELOG_ENGLISH = 'changelog-english';

    private const DESCRIPTIONS_MAP = [
        self::OPTION_PACKAGE => 'Path to the archive with compiled MODX package.',
        self::OPTION_LOGIN => 'User name (email) for login on modstore.',
        self::OPTION_PASSWORD => 'Password for login on modstore.',
        self::OPTION_CHANGELOG => 'Path to the file with changelog entries.',
        self::OPTION_CHANGELOG_ENGLISH => 'Alternative file for English variant in case, when original changelog on Russian. By default one file used for both cases.',
        self::OPTION_REQUIRED_MODX_VERSION => 'Minimal version of MODX which required for running the package code.',
        self::OPTION_REQUIRED_MODX_VERSION_MAX => "Up to what maximum version of MODX the package code is guaranteed to work.\n May be useful to limit packages, which are not compatible with MODX 3 yet.",
        self::OPTION_REQUIRED_PHP_VERSION => 'Minimal version of PHP which required for running the package code.',
        self::OPTION_DEPRECATE => "Disable all previous versions of the package.\n",
        self::OPTION_OVERRIDE => "Override existing version by the new binary package.\n If the version does not exist, the flag will be ignored.\n",
    ];

    private const MANDATORY_OPTIONS = [
        self::OPTION_PACKAGE,
        self::OPTION_LOGIN,
        self::OPTION_PASSWORD,
        self::OPTION_CHANGELOG
    ];

    private const SUPPORTS_VERSION_DEFAULT_MODX = '2.8';
    private const SUPPORTS_VERSION_DEFAULT_PHP = '7.2';

    // move to connector service
    private const CLIENT_BASE_URI = 'https://modstore.pro/';
    private const CLIENT_ENTRY_POINT = 'assets/components/extras/action.php';

    // todo: move to connector service
    private const ACTION_CREATE = 'office/versions/create';
    private const ACTION_UPDATE = 'office/versions/update';

    // minimum_supports
    // todo: parse lists with options from modstore, not hard defined
    private const MODX_VERSIONS = ['2.2', '2.3', '2.4', '2.5', '2.6', '2.7', '2.8', '3.0'];

    // supports
    // todo: parse lists with options from modstore, not hard defined
    private const MODX_VERSIONS_UNTIL = ['2.2', '2.3', '2.4', '2.5', '2.6', '2.7', '2.8', '3.0'];

    // todo: parse lists with options from modstore
    private const PHP_VERSIONS = ['5.3', '5.4', '5.5', '5.6', '7.0', '7.1', '7.2'];

    private Client $client;
    private SymfonyStyle $io;

    protected function configure(): void
    {
        $this
            ->setName('publish')
            ->setDescription('Publishes or updates the next package version to the marketplace.')
            ->setDefinition(
                [
                    // package
                    new InputOption(
                        self::OPTION_PACKAGE, 'e',
                        InputOption::VALUE_REQUIRED,
                        self::DESCRIPTIONS_MAP[self::OPTION_PACKAGE]
                    ),

                    // credentials
                    new InputOption(
                        self::OPTION_LOGIN, 'u',
                        InputOption::VALUE_REQUIRED,
                        self::DESCRIPTIONS_MAP[self::OPTION_LOGIN]
                    ),
                    new InputOption(
                        self::OPTION_PASSWORD, 'p',
                        InputOption::VALUE_REQUIRED,
                        self::DESCRIPTIONS_MAP[self::OPTION_PASSWORD]
                    ),

                    // changelog
                    new InputOption(
                        self::OPTION_CHANGELOG, null,
                        InputOption::VALUE_REQUIRED,
                        self::DESCRIPTIONS_MAP[self::OPTION_CHANGELOG]
                    ),
                    new InputOption(
                        self::OPTION_CHANGELOG_ENGLISH, null,
                        InputOption::VALUE_REQUIRED,
                        self::DESCRIPTIONS_MAP[self::OPTION_CHANGELOG_ENGLISH]
                    ),

                    // versions
                    new InputOption(
                        self::OPTION_REQUIRED_MODX_VERSION, null,
                        InputOption::VALUE_REQUIRED,
                        self::DESCRIPTIONS_MAP[self::OPTION_REQUIRED_MODX_VERSION],
                        self::SUPPORTS_VERSION_DEFAULT_MODX
                    ),
                    new InputOption(
                        self::OPTION_REQUIRED_MODX_VERSION_MAX, null,
                        InputOption::VALUE_REQUIRED,
                        self::DESCRIPTIONS_MAP[self::OPTION_REQUIRED_MODX_VERSION_MAX],
                    ),
                    new InputOption(
                        self::OPTION_REQUIRED_PHP_VERSION, null,
                        InputOption::VALUE_REQUIRED,
                        self::DESCRIPTIONS_MAP[self::OPTION_REQUIRED_PHP_VERSION],
                        self::SUPPORTS_VERSION_DEFAULT_PHP
                    ),

                    // flags
                    new InputOption(
                        self::OPTION_DEPRECATE, 'd', InputOption::VALUE_NONE,
                        self::DESCRIPTIONS_MAP[self::OPTION_DEPRECATE],
                    ),
                    new InputOption(
                        self::OPTION_OVERRIDE, 'r', InputOption::VALUE_NONE,
                        self::DESCRIPTIONS_MAP[self::OPTION_OVERRIDE],
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
        $io = $this->getIO();

        $options = array_filter($input->getOptions(), static fn ($option) => !empty($option));
        $common = array_intersect( self::MANDATORY_OPTIONS, array_keys($options));

        if (count($common) !== count(self::MANDATORY_OPTIONS)) {
            $io->error('Required options are missed');
            $io->block('To continue, define following options with values, please.');

            $missed = array_chunk(
                array_filter(
                    self::DESCRIPTIONS_MAP,
                    static fn($key) => in_array($key, array_diff(self::MANDATORY_OPTIONS, $common), true),
                    ARRAY_FILTER_USE_KEY
                ),
                1,
                true
            );

            $io->definitionList('Missed options:', ...$missed);

            return self::FAILURE;
        }

        try {
            // 01. Attempt to login
            $this->formatServiceAnswer(
                $this->login(
                    $input->getOption(self::OPTION_LOGIN),
                    $input->getOption(self::OPTION_PASSWORD)
                )
            );

            // 02. Parse archive name
            [$package, $version] = $this->parsePackage($input->getOption(self::OPTION_PACKAGE));

            // 03. Get the page of the package
            $content = $this->getClient()
                ->request('GET', sprintf('office/packages/%s', $package))
                ->getBody()->getContents();

            // 04. Get the CSRF token
            $token = $this->parseToken($content);

            // 05. Get meta information about extra
            $extra = $this->parseExtra($content);

            //

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
            $this->formatServiceAnswer(
                $this->upload($form, $token, true)
            );

            return self::SUCCESS;
        } catch (GuzzleException $gex) {
            $this->formatServiceAnswer($gex->getResponse());
            return self::FAILURE;
        } catch (SignatureException | TokenException | ExtraException $e) {
            $io->error($e->getMessage());
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
    private function formatServiceAnswer(ResponseInterface $response): void
    {
        $io = $this->getIO();

        $decoded = json_decode($response->getBody()->getContents(), true, 512, JSON_THROW_ON_ERROR);
        $success = (bool) ($decoded['success'] ?? false);
        $message = (string) ($decoded['message'] ?? '');

        $success ?
            $io->success($message) :
            $io->error($message);
    }
}

