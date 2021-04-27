<?php
/**
 * This file is part of modstore publisher package.
 * For complete copyright and license information, see the LICENSE file
 * found in the top-level directory of this distribution.
 **/

declare(strict_types = 1);

namespace Alroniks\Publisher\Command;

use Alroniks\Publisher\SignatureException;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use JsonException;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;
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
                        'Minimal version of MODX which required for running the package code.',
                        '2.8'
                    ),
                    new InputOption(
                        self::OPTION_REQUIRED_MODX_VERSION_MAX, null, InputOption::VALUE_REQUIRED,
                        'Up to what maximum version of MODX the package code is guaranteed to work.
                        May be useful to limit packages, which are not compatible with MODX 3.'
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
            ->setHelp('Sends and publishes new version of the package to the repository.');
    }

    /**
     * @throws \JsonException
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
//        $io = new SymfonyStyle($input, $output);

        $this->client = new Client(['base_uri' => self::CLIENT_BASE_URI, 'cookies' => true]);

        try {
            // 01. Attempt to login
            $output->writeln($this->formatServiceAnswer(
                $this->login($input->getOption(self::OPTION_LOGIN), $input->getOption(self::OPTION_PASSWORD))
            ));

            // 02. Parse archive name
            [$package, $version] = $this->parsePackage($input->getArgument(self::ARGUMENT_PACKAGE));

            // 03. Override parsed name and versions, if defined
            $package = $input->getOption(self::OPTION_PACKAGE) ?? $package;
            $version = $input->getOption(self::OPTION_RELEASE) ?? $version;

            // 04. Getting the page of the package
            $content = $this->client
                ->request('GET', sprintf('office/packages/%s', $package))
                ->getBody()->getContents();

            $token = $this->parseToken($content);
//            $extra = $this->parseExtra($content); // нужен только id + потом доступные версии для override

            echo $token;

            // prepare form?

            // 03. Upload the compiled package
//            $answer = $this->upload([], $token, true);

//            $output->writeln($this->formatServiceAnswer($answer));

            return self::SUCCESS;
        } catch (GuzzleException $gex) {
            $output->writeln($this->formatServiceAnswer($gex->getResponse()));
            return self::FAILURE;
        } catch (SignatureException $e) {
            $output->writeln($e->getMessage()); // make an error
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

    private function parseExtra(string $content): array
    {
        // new one exception

    }

    private function parseToken(string $content): string
    {
        $tempFile = tmpfile();
        fwrite($tempFile, $content);
        $metatags = get_meta_tags(stream_get_meta_data($tempFile)['uri']);

        // token exception
        return $metatags['csrf-token'];
    }

    private function uploadExtraForm(): array
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

        return [
            [
            'name'     => 'action',
            'contents' => 'office/versions/create'
        ],
            ];

    }

    /**
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    private function upload(array $form, string $token, bool $debug = false): ResponseInterface
    {
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
//        var_dump($content);

        $decoded = json_decode($content, true, 512, JSON_THROW_ON_ERROR);

        $success = (bool) ($decoded['success'] ?? false);

        return sprintf("<%s>\n%s\n</>", $success ? 'info' : 'error', $decoded['message'] ?? '');
    }
}

