<?php

namespace Drupal\Tests\dkan_nl_query\Unit\Llm;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\dkan_nl_query\Llm\AnthropicProvider;
use Drupal\dkan_nl_query\Llm\LlmProviderFactory;
use Drupal\dkan_nl_query\Llm\LlmProviderInterface;
use Drupal\dkan_nl_query\Llm\OpenAiProvider;
use PHPUnit\Framework\TestCase;

/**
 * @coversDefaultClass \Drupal\dkan_nl_query\Llm\LlmProviderFactory
 */
class LlmProviderFactoryTest extends TestCase {

  /**
   * Create a factory with partial mock to avoid real SDK instantiation.
   */
  protected function createFactory(array $configValues = []): LlmProviderFactory {
    $config = $this->createMock(ImmutableConfig::class);
    $config->method('get')
      ->willReturnCallback(fn($key) => $configValues[$key] ?? '');

    $configFactory = $this->createMock(ConfigFactoryInterface::class);
    $configFactory->method('get')
      ->with('dkan_nl_query.settings')
      ->willReturn($config);

    // Partial mock to avoid instantiating real SDK clients.
    $factory = $this->getMockBuilder(LlmProviderFactory::class)
      ->setConstructorArgs([$configFactory])
      ->onlyMethods(['createAnthropic', 'createOpenAi'])
      ->getMock();

    $anthropicProvider = $this->createMock(AnthropicProvider::class);
    $openAiProvider = $this->createMock(OpenAiProvider::class);

    $factory->method('createAnthropic')->willReturn($anthropicProvider);
    $factory->method('createOpenAi')->willReturn($openAiProvider);

    return $factory;
  }

  /**
   * @covers ::createForModel
   */
  public function testClaudeModelRoutesToAnthropic(): void {
    $factory = $this->createFactory();
    $provider = $factory->createForModel('claude-haiku-4-5');

    $this->assertInstanceOf(LlmProviderInterface::class, $provider);
  }

  /**
   * @covers ::createForModel
   */
  public function testClaudeSonnetRoutesToAnthropic(): void {
    $factory = $this->createFactory();
    $provider = $factory->createForModel('claude-sonnet-4-20250514');

    $this->assertInstanceOf(LlmProviderInterface::class, $provider);
  }

  /**
   * @covers ::createForModel
   */
  public function testGptModelRoutesToOpenAi(): void {
    $factory = $this->createFactory();

    // We need to verify the OpenAI path is taken. Use expects.
    $config = $this->createMock(ImmutableConfig::class);
    $config->method('get')->willReturn('');
    $configFactory = $this->createMock(ConfigFactoryInterface::class);
    $configFactory->method('get')->willReturn($config);

    $factory = $this->getMockBuilder(LlmProviderFactory::class)
      ->setConstructorArgs([$configFactory])
      ->onlyMethods(['createAnthropic', 'createOpenAi'])
      ->getMock();

    $factory->expects($this->never())->method('createAnthropic');
    $factory->expects($this->once())->method('createOpenAi')
      ->willReturn($this->createMock(OpenAiProvider::class));

    $factory->createForModel('gpt-4o');
  }

  /**
   * @covers ::createForModel
   */
  public function testO1ModelRoutesToOpenAi(): void {
    $factory = $this->createFactory();

    $config = $this->createMock(ImmutableConfig::class);
    $config->method('get')->willReturn('');
    $configFactory = $this->createMock(ConfigFactoryInterface::class);
    $configFactory->method('get')->willReturn($config);

    $factory = $this->getMockBuilder(LlmProviderFactory::class)
      ->setConstructorArgs([$configFactory])
      ->onlyMethods(['createAnthropic', 'createOpenAi'])
      ->getMock();

    $factory->expects($this->once())->method('createOpenAi')
      ->willReturn($this->createMock(OpenAiProvider::class));

    $factory->createForModel('o1-preview');
  }

  /**
   * @covers ::createForModel
   */
  public function testO3ModelRoutesToOpenAi(): void {
    $factory = $this->createFactory();

    $config = $this->createMock(ImmutableConfig::class);
    $config->method('get')->willReturn('');
    $configFactory = $this->createMock(ConfigFactoryInterface::class);
    $configFactory->method('get')->willReturn($config);

    $factory = $this->getMockBuilder(LlmProviderFactory::class)
      ->setConstructorArgs([$configFactory])
      ->onlyMethods(['createAnthropic', 'createOpenAi'])
      ->getMock();

    $factory->expects($this->once())->method('createOpenAi')
      ->willReturn($this->createMock(OpenAiProvider::class));

    $factory->createForModel('o3-mini');
  }

  /**
   * @covers ::createForModel
   */
  public function testO4ModelRoutesToOpenAi(): void {
    $config = $this->createMock(ImmutableConfig::class);
    $config->method('get')->willReturn('');
    $configFactory = $this->createMock(ConfigFactoryInterface::class);
    $configFactory->method('get')->willReturn($config);

    $factory = $this->getMockBuilder(LlmProviderFactory::class)
      ->setConstructorArgs([$configFactory])
      ->onlyMethods(['createAnthropic', 'createOpenAi'])
      ->getMock();

    $factory->expects($this->once())->method('createOpenAi')
      ->willReturn($this->createMock(OpenAiProvider::class));

    $factory->createForModel('o4-mini');
  }

  /**
   * @covers ::createForModel
   */
  public function testNullModelDefaultsToAnthropic(): void {
    $config = $this->createMock(ImmutableConfig::class);
    $config->method('get')->willReturn('');
    $configFactory = $this->createMock(ConfigFactoryInterface::class);
    $configFactory->method('get')->willReturn($config);

    $factory = $this->getMockBuilder(LlmProviderFactory::class)
      ->setConstructorArgs([$configFactory])
      ->onlyMethods(['createAnthropic', 'createOpenAi'])
      ->getMock();

    $factory->expects($this->once())->method('createAnthropic')
      ->willReturn($this->createMock(AnthropicProvider::class));
    $factory->expects($this->never())->method('createOpenAi');

    $factory->createForModel(NULL);
  }

  /**
   * @covers ::createForModel
   */
  public function testUnknownPrefixDefaultsToAnthropic(): void {
    $config = $this->createMock(ImmutableConfig::class);
    $config->method('get')->willReturn('');
    $configFactory = $this->createMock(ConfigFactoryInterface::class);
    $configFactory->method('get')->willReturn($config);

    $factory = $this->getMockBuilder(LlmProviderFactory::class)
      ->setConstructorArgs([$configFactory])
      ->onlyMethods(['createAnthropic', 'createOpenAi'])
      ->getMock();

    $factory->expects($this->once())->method('createAnthropic')
      ->willReturn($this->createMock(AnthropicProvider::class));

    $factory->createForModel('gemini-pro');
  }

  /**
   * @covers ::createForModel
   */
  public function testAnthropicApiKeyFallback(): void {
    $config = $this->createMock(ImmutableConfig::class);
    $config->method('get')
      ->willReturnCallback(function ($key) {
        return match ($key) {
          'anthropic_api_key' => '',
          'api_key' => 'fallback-key',
          default => '',
        };
      });
    $configFactory = $this->createMock(ConfigFactoryInterface::class);
    $configFactory->method('get')->willReturn($config);

    $factory = $this->getMockBuilder(LlmProviderFactory::class)
      ->setConstructorArgs([$configFactory])
      ->onlyMethods(['createAnthropic', 'createOpenAi'])
      ->getMock();

    $factory->expects($this->once())
      ->method('createAnthropic')
      ->with('fallback-key')
      ->willReturn($this->createMock(AnthropicProvider::class));

    $factory->createForModel('claude-haiku-4-5');
  }

  /**
   * @covers ::createForModel
   */
  public function testOpenAiApiKeyFromConfig(): void {
    $config = $this->createMock(ImmutableConfig::class);
    $config->method('get')
      ->willReturnCallback(function ($key) {
        return match ($key) {
          'openai_api_key' => 'sk-openai-test',
          default => '',
        };
      });
    $configFactory = $this->createMock(ConfigFactoryInterface::class);
    $configFactory->method('get')->willReturn($config);

    $factory = $this->getMockBuilder(LlmProviderFactory::class)
      ->setConstructorArgs([$configFactory])
      ->onlyMethods(['createAnthropic', 'createOpenAi'])
      ->getMock();

    $factory->expects($this->once())
      ->method('createOpenAi')
      ->with('sk-openai-test')
      ->willReturn($this->createMock(OpenAiProvider::class));

    $factory->createForModel('gpt-4o');
  }

}
