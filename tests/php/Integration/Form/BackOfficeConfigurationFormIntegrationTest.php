<?php

/**
 * For the full copyright and license information, please view the
 * docs/licenses/LICENSE.txt file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Tests\Integration\Form;

use PHPUnit\Framework\TestCase;
use PrestaShop\Module\PsOnePageCheckout\Form\BackOfficeConfigurationForm;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Tests\Integration\Utility\ContextMockerTrait;
use Twig\Environment;
use Twig\Loader\ArrayLoader;

class BackOfficeConfigurationFormIntegrationTest extends TestCase
{
    use ContextMockerTrait;

    /**
     * @var array<string, mixed>
     */
    private array $backupGet = [];

    /**
     * @var array<string, mixed>
     */
    private array $backupPost = [];

    protected function setUp(): void
    {
        parent::setUp();

        self::mockContext();
        $this->backupGet = $_GET;
        $this->backupPost = $_POST;
        $_GET = [];
        $_POST = [];
        self::getContext()->controller = new IntegrationBackOfficeControllerStub();
    }

    protected function tearDown(): void
    {
        $_GET = $this->backupGet;
        $_POST = $this->backupPost;
        parent::tearDown();
    }

    public function testItRendersConfigurationFormWithModuleTwigEnvironment(): void
    {
        $twig = new Environment(new ArrayLoader([
            '@Modules/ps_onepagecheckout/views/templates/admin/checkout_layout_configuration.html.twig' => 'twig-module {{ configuration_key }}',
        ]));
        $module = new IntegrationBackOfficeModuleStub($twig, new ContainerBuilder());
        $form = new IntegrationBackOfficeConfigurationFormSpy($module, 'PS_ONE_PAGE_CHECKOUT_ENABLED');
        $form->setCurrentConfigurationValue(1);

        $content = $form->renderBackOfficeConfiguration();

        self::assertStringContainsString('twig-module PS_ONE_PAGE_CHECKOUT_ENABLED', $content);
        self::assertSame([], $module->fetchedTemplates);
        self::assertNotEmpty(self::getContext()->controller->registeredCss);
        self::assertSame([], $form->persistedValues);
    }

    public function testItThrowsWhenModuleTwigIsMissingEvenIfContainerHasTwig(): void
    {
        $containerTwig = new Environment(new ArrayLoader([
            '@Modules/ps_onepagecheckout/views/templates/admin/checkout_layout_configuration.html.twig' => 'twig-container {{ form_submit_action }}',
        ]));
        $container = new ContainerBuilder();
        $container->set('twig', $containerTwig);
        $module = new IntegrationBackOfficeModuleStub(null, $container);
        $form = new IntegrationBackOfficeConfigurationFormSpy($module, 'PS_ONE_PAGE_CHECKOUT_ENABLED');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Twig environment is required');

        $form->renderBackOfficeConfiguration();
    }

    public function testItThrowsWhenTwigIsUnavailable(): void
    {
        $module = new IntegrationBackOfficeModuleStub(null, new ContainerBuilder());
        $form = new IntegrationBackOfficeConfigurationFormSpy($module, 'PS_ONE_PAGE_CHECKOUT_ENABLED');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Twig environment is required');

        $form->renderBackOfficeConfiguration();
    }

    public function testItPersistsSubmittedValueAndRedirectsAfterSubmit(): void
    {
        $_POST['submitPsOnePageCheckoutConfiguration'] = '1';
        $_POST['PS_ONE_PAGE_CHECKOUT_ENABLED'] = '1';

        $twig = new Environment(new ArrayLoader([
            '@Modules/ps_onepagecheckout/views/templates/admin/checkout_layout_configuration.html.twig' => 'twig-after-submit',
        ]));
        $module = new IntegrationBackOfficeModuleStub($twig, new ContainerBuilder());
        $form = new IntegrationBackOfficeConfigurationFormSpy($module, 'PS_ONE_PAGE_CHECKOUT_ENABLED');
        $form->setCurrentConfigurationValue(0);

        $content = $form->renderBackOfficeConfiguration();

        self::assertSame('', $content);
        self::assertSame([1], $form->persistedValues);
        self::assertCount(1, $form->redirectedUrls);
        self::assertStringContainsString(
            'controller=AdminModules&configure=ps_onepagecheckout',
            $form->redirectedUrls[0]
        );
        self::assertCount(0, $module->confirmationMessages);
    }

    public function testItDisplaysConfirmationOnlyOnceAfterRedirect(): void
    {
        $_POST['submitPsOnePageCheckoutConfiguration'] = '1';
        $_POST['PS_ONE_PAGE_CHECKOUT_ENABLED'] = '1';

        $twig = new Environment(new ArrayLoader([
            '@Modules/ps_onepagecheckout/views/templates/admin/checkout_layout_configuration.html.twig' => 'twig-after-submit',
        ]));
        $module = new IntegrationBackOfficeModuleStub($twig, new ContainerBuilder());
        $form = new IntegrationBackOfficeConfigurationFormSpy($module, 'PS_ONE_PAGE_CHECKOUT_ENABLED');
        $form->setCurrentConfigurationValue(0);

        $form->renderBackOfficeConfiguration();

        $_POST = [];
        $_GET = [];
        $firstGetContent = $form->renderBackOfficeConfiguration();
        $secondGetContent = $form->renderBackOfficeConfiguration();

        self::assertStringStartsWith('[CONFIRMATION]', $firstGetContent);
        self::assertStringContainsString('twig-after-submit', $firstGetContent);
        self::assertStringNotContainsString('[CONFIRMATION]', $secondGetContent);
        self::assertStringContainsString('twig-after-submit', $secondGetContent);
        self::assertCount(1, $module->confirmationMessages);
    }
}

class IntegrationBackOfficeConfigurationFormSpy extends BackOfficeConfigurationForm
{
    /**
     * @var int[]
     */
    public array $persistedValues = [];

    /**
     * @var string[]
     */
    public array $redirectedUrls = [];

    private int $currentConfigurationValue = 0;

    public function setCurrentConfigurationValue(int $value): void
    {
        $this->currentConfigurationValue = $value;
    }

    protected function updateConfigurationValue(int $value): void
    {
        $this->persistedValues[] = $value;
    }

    protected function readConfigurationValue(): int
    {
        return $this->currentConfigurationValue;
    }

    protected function redirectToConfigurationForm(): void
    {
        $this->redirectedUrls[] = \Context::getContext()->link->getAdminLink(
            'AdminModules',
            true,
            [],
            ['configure' => 'ps_onepagecheckout']
        );
    }
}

class IntegrationBackOfficeModuleStub extends \Module
{
    private ?Environment $twig;
    private ContainerInterface $container;

    /**
     * @var string[]
     */
    public array $fetchedTemplates = [];

    /**
     * @var string[]
     */
    public array $confirmationMessages = [];

    public function __construct(?Environment $twig, ContainerInterface $container)
    {
        $this->twig = $twig;
        $this->container = $container;
        $this->name = 'ps_onepagecheckout';
    }

    public function getTwig(): ?Environment
    {
        return $this->twig;
    }

    public function getContainer(): ContainerInterface
    {
        return $this->container;
    }

    public function fetch($templatePath, $cache_id = null, $compile_id = null)
    {
        $this->fetchedTemplates[] = (string) $templatePath;

        return 'smarty-fallback';
    }

    public function displayConfirmation($string)
    {
        $this->confirmationMessages[] = (string) $string;

        return '[CONFIRMATION]' . $string;
    }

    public function getPathUri(): string
    {
        return '/modules/ps_onepagecheckout/';
    }
}

class IntegrationBackOfficeControllerStub
{
    /**
     * @var string[]
     */
    public array $registeredCss = [];

    /**
     * @var string[]
     */
    public array $registeredJs = [];

    public function addCSS($css): void
    {
        $this->registeredCss[] = (string) $css;
    }

    public function addJS($js): void
    {
        $this->registeredJs[] = (string) $js;
    }
}
