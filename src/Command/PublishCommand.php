<?php
/**
 * This file is part of modstore publisher package.
 * For complete copyright and license information, see the LICENSE file
 * found in the top-level directory of this distribution.
 **/

declare(strict_types = 1);

namespace Alroniks\Publisher\Command;

use Alroniks\Publisher\ContentParser;
use Alroniks\Publisher\ExtraException;
use Alroniks\Publisher\PackageForm;
use Alroniks\Publisher\SignatureException;
use Alroniks\Publisher\TokenException;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
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
    public const OPTION_DISABLE = 'disable';
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
        self::OPTION_DISABLE => "Disable existing version of the package\n",
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

    // minimum_supports todo: move to parser
    // todo: parse lists with options from modstore, not hard defined
    private const MODX_VERSIONS = ['2.2', '2.3', '2.4', '2.5', '2.6', '2.7', '2.8', '3.0'];

    // supports
    // todo: parse lists with options from modstore, not hard defined
    private const MODX_VERSIONS_UNTIL = ['2.2', '2.3', '2.4', '2.5', '2.6', '2.7', '2.8', '3.0'];

    // todo: parse lists with options from modstore
    private const PHP_VERSIONS = ['5.3', '5.4', '5.5', '5.6', '7.0', '7.1', '7.2'];

    private Client $client;
    private \DriverInterface $driver;
    private SymfonyStyle $io;

    public function __construct(string $name = null, \DriverInterface $driver) {
        parent::__construct($name);

        $this->driver = $driver;
    }

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
                        self::OPTION_DEPRECATE, 'd',
                        InputOption::VALUE_NONE,
                        self::DESCRIPTIONS_MAP[self::OPTION_DEPRECATE],
                    ),
                    new InputOption(
                        self::OPTION_DISABLE, null,
                        InputOption::VALUE_NONE,
                        self::DESCRIPTIONS_MAP[self::OPTION_DISABLE],
                    ),
                    new InputOption(
                        self::OPTION_OVERRIDE, 'r',
                        InputOption::VALUE_NONE,
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

Read more in <href=https://github.com/alroniks/modstore-publisher#readme>Documentation</>

EOT
                );
    }

    public function initialize(InputInterface $input, OutputInterface $output): void
    {
        $this->io = new SymfonyStyle($input, $output);

        $this->driver = new \StoreDriver();

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

        $io->section('Processing uploading');

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

            // 03. Get the page of the package todo: fetch
            $content = $this->getClient()
                ->request('GET', sprintf('office/packages/%s', $package))
                ->getBody()->getContents();

            $parser = new ContentParser($content);

            // 04. Get the CSRF token
            $token = $parser->token();

            // 05. Get meta information about extra
            $extra = $parser->extra(); //

            // versions modx, php etc. ?

            if (in_array($version, array_column($extra['versions'], 'version'), true)) {
                // version found, I will replace existing

                $io->section('Existing versions');
                $io->block('We found the next versions on the marketplace.');
                $io->table(array_keys($extra['versions'][0]), $extra['versions']);

                // updating form
            } else {
                $io->info(sprintf("Package with version `%s` is not published in the marketplace yet.\nNew version will be created and published.", $version));

                if (!$input->getOption(self::OPTION_DEPRECATE)) {
                    $input->setOption(self::OPTION_DEPRECATE, $io->confirm('Disable all previous versions of a package?', true));
                }

                $dto = new PackageForm(
                    changelog: 'dgfdfg',
                );
                // creating form
                // ask about versions? in case updatig, it can be fetched from form
                // as well as changelog
            }


            // 1. Закончить с отправкой данных на сервис
            // 2. Сделать класс DTO формы для отправки и обновления
            // 3. Вынести функции отправки запросов в отдельный сервис
            // 5. Проверить установку и работу в глобальном режиме
            // 6. Добавить пакет на packagist
            // 7. Вынести парссинг в отдельный класс

            // basic form?

            // creating form
            // confirm?

            // create form/
            // update form/


            var_dump($input->getOptions());
            // just confirm adding


            // какую версию обновить?
            // депрекейтитть или нет?
            // а если новая - то деперкейтить старые или нет?
            // что делать в случае не интерактивного режима?
            // -- если нашлась версия, то ее обновить, статус активности оставить как есть?


            exit(0);

            // настроить/оставить по умолчанию/без ограничений

            // спросить про версию modx, php и прочее
            // в случае не интерактивного режима показать табличку все равно для отладки
            //


            // если версии совпадают и есть опция override, то нужно обновить
            // если опции нет - то просто добавиь
            // или спросить вопрос, перезаписать ли?

//            $form = new PackageForm(
//                action: self::ACTION_CREATE,
//                package_id: $extra['id']
//            );

//            print_r($extra);
//            print_r(array_column($extra['versions'], 'active'));

            // todo: confirm publishing on the last step
            // сводная таблица всех параметров - нужно брать из DTO

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
        $io->block('Follow the questions and to answer them to get package published.');
        $io->note('If you have specified parameters for the command, then questions about them will be skipped.');

        if (!$input->getOption(self::OPTION_PACKAGE)) {
            $io->section('Defining path to the package');
            $io->text('Please, enter an valid path to file with compiled MODX package.');

            $input->setOption(self::OPTION_PACKAGE, $io->ask('Path to the file with package archive'));
        }

        if (!$input->getOption(self::OPTION_LOGIN) && !$input->getOption(self::OPTION_PASSWORD)) {

            $io->section('Signing in marketplace');
            $io->text('Please, enter login and password of your account on modstore.pro.');

            if (!$input->getOption(self::OPTION_LOGIN)) {
                $input->setOption(self::OPTION_LOGIN, $io->ask('Login'));
            }

            if (!$input->getOption(self::OPTION_PASSWORD)) {
                $input->setOption(self::OPTION_PASSWORD, $io->askHidden('Password'));
            }
        }

        $io->section('Configuring the uploading version');

        if (!$input->getOption(self::OPTION_CHANGELOG)) {
            $input->setOption(self::OPTION_CHANGELOG, $io->ask('Path to the file with changelog on Russian'));
        }

        if (!$input->getOption(self::OPTION_CHANGELOG_ENGLISH)
            && $io->confirm('Do you have different changelog for English language?', false)
        ) {
            $input->setOption(self::OPTION_CHANGELOG_ENGLISH, $io->ask('Path to file with changelog on English'));
        }
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

    // todo: rename
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

