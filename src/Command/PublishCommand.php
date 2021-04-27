<?php
/**
 * This file is part of modstore publisher package.
 * For complete copyright and license information, see the LICENSE file
 * found in the top-level directory of this distribution.
 **/

declare(strict_types = 1);

namespace Alroniks\Publisher\Command;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use JsonException;
use Psr\Http\Message\ResponseInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

final class PublishCommand extends Command
{
    protected const CLIENT_BASE_URI = 'https://modstore.pro/';
    protected const CLIENT_ENTRY_POINT = 'assets/components/extras/action.php';

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

    public const OPTION_PACKAGE = 'package';
    public const OPTION_RELEASE = 'release';

    private Client $client;

    protected function configure(): void
    {
        $this
            ->setName('publish')
            ->setDescription('Sends new package version.')
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
                        'Alternative file for English variant in case, when original changelog on russian.
                        By default one file used for both cases.'
                    ),

                    // versions
                    new InputOption(
                        self::OPTION_REQUIRED_MODX_VERSION, null, InputOption::VALUE_REQUIRED,
                        'Minimal version of MODX which required for running the package code.'
                    ),
                    new InputOption(
                        self::OPTION_REQUIRED_MODX_VERSION_MAX, null, InputOption::VALUE_REQUIRED,
                        'Up to what maximum version of MODX the package code is guaranteed to work.
                        May be useful to limit packages, which are not compatible with MODX 3.'
                    ),
                    new InputOption(
                        self::OPTION_REQUIRED_PHP_VERSION, null, InputOption::VALUE_REQUIRED,
                        'Minimal version of PHP which required for running the package code.'
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
            ->setHelp('Sends and publishes new version of the package to the repository.');
    }

    /**
     * @throws \JsonException
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->client = new Client(['base_uri' => self::CLIENT_BASE_URI, 'cookies' => true]);

        try {
            // 01. Attempt to login
            $this->login($input->getOption(self::OPTION_LOGIN), $input->getOption(self::OPTION_PASSWORD));

            // 02. Getting the page of the package
            $content = $this->client
                ->request('GET', 'office/packages/***') // get name here from the zip?
                ->getBody()->getContents();

            $token = $this->parseToken($content);
//            $extra = $this->parseExtra($content); // нужен только id + потом доступные версии для override

            // prepare form?

            // 03. Upload the compiled package
            $answer = $this->upload([], $token, true);

            $output->writeln($this->formatServiceAnswer($answer));

            return self::SUCCESS;
        } catch (GuzzleException $gex) {
            $output->writeln($this->formatServiceAnswer($gex->getResponse()));
            return self::FAILURE;
        }
    }

    protected function interact(InputInterface $input, OutputInterface $output): void
    {
        $helper = $this->getHelper('question');

        //min modx 2.2 -> 2.0, def - 2.8

//        $login = $input->getOption('login');
//        $question = new Question('Login [<comment>' . $login . '</comment>]: ', $login);
//        $login = $helper->ask($input, $output, $question);
//        $input->setOption('login', $login);

//        if (!$login = $input->getOption('login')) {
//            $question = new Question('Login?');
//
//            $login = $helper->ask($input, $output, $question);
//            $input->setOption('login', $login);
//        }

    }

    /**
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    private function login(string $email, string $password): ResponseInterface
    {
        return $this->client->request(
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

    private function parseExtra(string $content): array
    {

    }

    private function parseToken(string $content): string
    {
        $tempFile = tmpfile();
        fwrite($tempFile, $content);
        $metatags = get_meta_tags(stream_get_meta_data($tempFile)['uri']);

        return $metatags['csrf-token'];
    }

    private function uploadExtraForm(): array
    {
        return [

            ];

    }

    /**
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    private function upload(array $form, string $token, bool $debug = false): ResponseInterface
    {
        $form = [
            'action' => 'office/versions/create', // or update?
            'package_id' => 9,
            'changelog' => '',
            'changelog_en' => '',
            'minimum_supports' => '2.8',
            'supports' => '',
            'minimum_php' => '',
            'deprecate_other' => '',
            'package' => '' // psr stream // Psr7\Utils::tryFopen(__DIR__ . '/*.transport.zip', 'r')
        ];
//
//        [
//            'name'     => 'action',
//            'contents' => 'office/versions/create'
//        ],

        return $this->client->request('POST', self::CLIENT_ENTRY_POINT, [
            'multipart' => $form,
            'headers' => ['X-CSRF-Token' => $token],
            'debug' => $debug
        ]);
    }

    /**
     * @throws JsonException
     */
    private function formatServiceAnswer(ResponseInterface $response): string
    {
        $content = $response->getBody()->getContents();
        $decoded = json_decode($content, true, 512, JSON_THROW_ON_ERROR);

        $success = (bool) ($decoded['success'] ?? false);

        return sprintf('<%1$s>\n%2$s\n</%1$s>', $success ? 'info' : 'error', $decoded['message'] ?? '');
    }
}

