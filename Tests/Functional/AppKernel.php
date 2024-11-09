<?php

namespace Lexik\Bundle\JWTAuthenticationBundle\Tests\Functional;

use ApiPlatform\Metadata\Util\IriHelper;
use ApiPlatform\Symfony\Bundle\ApiPlatformBundle;
use Jose\Bundle\JoseFramework\JoseFrameworkBundle;
use Lexik\Bundle\JWTAuthenticationBundle\LexikJWTAuthenticationBundle;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Bundle\SecurityBundle\SecurityBundle;
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\Security\Core\Exception\UserNotFoundException;

class AppKernel extends Kernel
{
    private string $encoder;
    private string $userProvider;
    private $signatureAlgorithm;
    private null|string $testCase;

    public function __construct(string $environment, bool $debug, null|string $testCase = null)
    {
        parent::__construct($environment, $debug);

        $this->testCase = $testCase;
        $this->encoder = getenv('ENCODER') ?: 'default';
        $this->userProvider = getenv('PROVIDER') ?: 'in_memory';
        $this->signatureAlgorithm = getenv('ALGORITHM');
    }

    /**
     * {@inheritdoc}
     */
    public function registerBundles(): array
    {
        $bundles = [
            new FrameworkBundle(),
            new SecurityBundle(),
            new LexikJWTAuthenticationBundle(),
        ];
        if (class_exists(JoseFrameworkBundle::class)) {
            $bundles[] = new JoseFrameworkBundle();
        }
        if (class_exists(ApiPlatformBundle::class)) {
            $bundles[] = new ApiPlatformBundle();
        }

        return $bundles;
    }

    public function getRootDir(): string
    {
        return __DIR__;
    }

    /**
     * {@inheritdoc}
     */
    public function getCacheDir(): string
    {
        return sys_get_temp_dir() . '/LexikJWTAuthenticationBundle/cache';
    }

    /**
     * {@inheritdoc}
     */
    public function getLogDir(): string
    {
        return sys_get_temp_dir() . '/LexikJWTAuthenticationBundle/logs';
    }

    /**
     * {@inheritdoc}
     */
    public function registerContainerConfiguration(LoaderInterface $loader): void
    {
        $loader->load(__DIR__ . '/config/config_router_utf8.yml');

        $sessionConfig = [
            'handler_id' => null,
            'cookie_secure' => 'auto',
            'cookie_samesite' => 'lax',
            'storage_factory_id' => 'session.storage.factory.mock_file',
        ];

        if (!class_exists(Security::class)) {
            $loader->load(function (ContainerBuilder $container) {
                $container->prependExtensionConfig('security', [
                    'enable_authenticator_manager' => true,
                ]);
            });
        }

        $router = [
            'resource' => '%kernel.project_dir%/Tests/Functional/config/routing.yml',
            'utf8' => true,
        ];
        if (class_exists(ApiPlatformBundle::class)) {
            $loader->load(function (ContainerBuilder $container) use (&$router) {
                $config = [
                    'title' => 'LexikJWTAuthenticationBundle',
                    'description' => 'API Platform integration in LexikJWTAuthenticationBundle',
                    'version' => '1.0.0',
                    'use_symfony_listeners' => true,
                    'formats' => [
                        'jsonld' => ['application/ld+json'],
                        'json' => ['application/json'],
                    ]
                ];

                if (!class_exists(IriHelper::class)) {
                    $config['keep_legacy_inflector'] = false;
                }

                $container->prependExtensionConfig('api_platform', $config);
                $container->prependExtensionConfig('lexik_jwt_authentication', [
                    'api_platform' => [
                        'check_path' => '/login_check',
                        'username_path' => 'email',
                        'password_path' => 'security.credentials.password',
                    ],
                ]);
                $router['resource'] = '%kernel.project_dir%/Tests/Functional/config/routing_api_platform.yml';
            });
        }

        $loader->load(function (ContainerBuilder $container) use ($router, $sessionConfig) {
            $container->prependExtensionConfig('framework', [
                'router' => $router,
                'session' => $sessionConfig
            ]);
        });

        if ($this->testCase && file_exists(__DIR__ . '/config/' . $this->testCase . '/config.yml')) {
            $loader->load(__DIR__ . '/config/' . $this->testCase . '/config.yml');
        }

        $loader->load(__DIR__ . sprintf('/config/security_%s.yml', $this->userProvider));

        if ($this->signatureAlgorithm && file_exists($file = __DIR__ . sprintf('/config/config_%s_%s.yml', $this->encoder, strtolower($this->signatureAlgorithm)))) {
            $loader->load($file);

            return;
        }

        $loader->load(__DIR__ . sprintf('/config/config_%s.yml', $this->encoder));
    }

    public function getUserProvider()
    {
        return $this->userProvider;
    }

    public function getEncoder()
    {
        return $this->encoder;
    }

    protected function build(ContainerBuilder $container): void
    {
        $container->register('logger', NullLogger::class);
    }
}
