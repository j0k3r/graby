<?php

namespace Tests\Graby\SiteConfig;

use Graby\SiteConfig\BuiltinConfigLinesProvider;
use PHPUnit\Framework\TestCase;

class BuiltinConfigLinesProviderTest extends TestCase
{
    /**
     * @var string
     */
    private $testConfigDir;

    /**
     * @var string
     */
    private $testConfigFile;

    /**
     * @var string
     */
    private $testHost = 'example.com';

    /**
     * @var list<string>
     */
    private $testConfigContent = [
        'title: Test Title',
        'body: //div[@class="content"]',
        'date: //span[@class="date"]',
    ];

    protected function setUp(): void
    {
        $this->testConfigDir = sys_get_temp_dir() . '/graby_test_config_' . uniqid('', true);
        mkdir($this->testConfigDir, 0777, true);

        $this->testConfigFile = $this->testConfigDir . '/' . $this->testHost . '.txt';
        file_put_contents($this->testConfigFile, implode("\n", $this->testConfigContent));
    }

    protected function tearDown(): void
    {
        if (file_exists($this->testConfigFile)) {
            unlink($this->testConfigFile);
        }
        if (is_dir($this->testConfigDir)) {
            rmdir($this->testConfigDir);
        }
    }

    public function testConstructWithDirectories(): void
    {
        $provider = new BuiltinConfigLinesProvider([$this->testConfigDir]);

        $this->assertInstanceOf('Graby\SiteConfig\BuiltinConfigLinesProvider', $provider);
        $this->assertTrue($provider->supportsHost($this->testHost));
    }

    public function testConstructWithoutDirectories(): void
    {
        $provider = new BuiltinConfigLinesProvider();

        $this->assertInstanceOf('Graby\SiteConfig\BuiltinConfigLinesProvider', $provider);
        $this->assertFalse($provider->supportsHost($this->testHost));
    }

    public function testSupportsHost(): void
    {
        $provider = new BuiltinConfigLinesProvider([$this->testConfigDir]);

        $this->assertTrue($provider->supportsHost($this->testHost));
        $this->assertFalse($provider->supportsHost('nonexistent.com'));
    }

    public function testGetLinesForHostWithExistingHost(): void
    {
        $provider = new BuiltinConfigLinesProvider([$this->testConfigDir]);
        $lines = $provider->getLinesForHost($this->testHost);

        $this->assertIsArray($lines);
        $this->assertCount(3, $lines);
        $this->assertSame($this->testConfigContent, $lines);
    }

    public function testGetLinesForHostWithNonExistentHost(): void
    {
        $provider = new BuiltinConfigLinesProvider([$this->testConfigDir]);
        $lines = $provider->getLinesForHost('nonexistent.com');

        $this->assertIsArray($lines);
        $this->assertEmpty($lines);
    }

    public function testGetLinesForHostWithEmptyFile(): void
    {
        $emptyFile = $this->testConfigDir . '/empty.txt';
        touch($emptyFile);

        $provider = new BuiltinConfigLinesProvider([$this->testConfigDir]);
        $lines = $provider->getLinesForHost('empty');

        $this->assertIsArray($lines);
        $this->assertEmpty($lines);

        unlink($emptyFile);
    }

    public function testReload(): void
    {
        $provider = new BuiltinConfigLinesProvider([$this->testConfigDir]);

        // Verify initial state
        $this->assertTrue($provider->supportsHost($this->testHost));

        // Remove the config file
        unlink($this->testConfigFile);

        // Should still have the old config until reload
        $this->assertTrue($provider->supportsHost($this->testHost));

        // Reload and verify the host is no longer supported
        $provider->reload();
        $this->assertFalse($provider->supportsHost($this->testHost));
    }

    public function testMultipleDirectories(): void
    {
        $secondConfigDir = sys_get_temp_dir() . '/graby_test_config_second_' . uniqid('', true);
        mkdir($secondConfigDir, 0777, true);

        $secondHost = 'second.example.com';
        $secondConfigFile = $secondConfigDir . '/' . $secondHost . '.txt';
        file_put_contents($secondConfigFile, 'title: Second Config');

        $provider = new BuiltinConfigLinesProvider([$this->testConfigDir, $secondConfigDir]);

        // Should find configs from both directories
        $this->assertTrue($provider->supportsHost($this->testHost));
        $this->assertTrue($provider->supportsHost($secondHost));

        // Clean up
        unlink($secondConfigFile);
        rmdir($secondConfigDir);
    }
}
