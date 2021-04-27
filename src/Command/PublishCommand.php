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

//use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;

final class PublishCommand extends Command
{
    protected const CLIENT_BASE_URI = 'https://modstore.pro/';
    protected const CLIENT_ENTRY_POINT = 'assets/components/extras/action.php';

    public const OPTION_LOGIN = 'login';
    public const OPTION_PASSWORD = 'password';

    public const OPTION_DEPRECATE = 'deprecate';
    public const OPTION_OVERRIDE = 'override';

    public const OPTION_REQUIRED_PHP_VERSION = 'php-version';
    public const OPTION_REQUIRED_MODX_VERSION = 'modx-version';
    public const OPTION_REQUIRED_MODX_VERSION_MAX = 'modx-version-until';

    public const OPTION_CHANGELOG = 'changelog';
    public const OPTION_CHANGELOG_ENGLISH = 'changelog-english';

    public const OPTION_PACKAGE = 'package'; // make it as argument?

    private Client $client;

    protected function configure(): void
    {
        // login, password, path to the package? - changelog? анзипить?
        // имя дополнения и версия берется из пакета, но можно передать параметр, который переопределит значения

        $this
            ->setName('publish')
            ->setDescription('Sends new version.')
            ->setDefinition(
                [
                    new InputOption(
                        'login', 'l', InputOption::VALUE_REQUIRED,
                        'Email for login on modstore'
                    ),
                    new InputOption(
                        'password', 'p', InputOption::VALUE_REQUIRED,
                        'Password for login on modstore'
                    ),

                    new InputOption('package', null, InputOption::VALUE_REQUIRED,
                                    'Name of the extra to update'),

                    // binary?
                    new InputOption('binary', 'b', InputOption::VALUE_REQUIRED,
                                    'Path to the zip file with a package code'), // replace by argument?

                    new InputOption('php_min_version'),
new InputOption('modx_min_version'),
new InputOption('modx_max_version'),


                    new InputOption(
                        'deprecate', 'd', InputOption::VALUE_NONE,
                        'Disable old versions of the package'
                    ),
                ]
            )
            ->setHelp('Sends and publishes new version of the package to the repository.');

        // имя пакета - автоматом брать из zip?
        // бинарник (архив)

        //minimum_supports
        //supports
        // minimum_php
        // deprecate_other

        // интерактивный режим? нужно проверять, что опции не заданы и запрашивать
        // нужно выключать интерактивный режим в случае использования в actions

    }

    /**
     * @throws \JsonException
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->client = new Client(['base_uri' => self::CLIENT_BASE_URI, 'cookies' => true]);

        // validate fields

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

