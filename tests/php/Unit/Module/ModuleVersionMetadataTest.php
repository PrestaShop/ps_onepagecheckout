<?php

/**
 * For the full copyright and license information, please view the
 * docs/licenses/LICENSE.txt file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Tests\Unit\Module;

use PHPUnit\Framework\TestCase;

class ModuleVersionMetadataTest extends TestCase
{
    public function testReleaseMetadataVersionsStayAligned(): void
    {
        $moduleVersion = $this->extractMainModuleVersion();
        $configVersion = $this->extractXmlVersion($this->getModulePath() . '/config.xml');

        self::assertSame($moduleVersion, $configVersion);
    }

    private function getModulePath(): string
    {
        return _PS_ROOT_DIR_ . '/modules/ps_onepagecheckout';
    }

    private function extractMainModuleVersion(): string
    {
        $mainModuleFile = (string) file_get_contents($this->getModulePath() . '/ps_onepagecheckout.php');
        preg_match('/\$this->version\s*=\s*\'([^\']+)\'/', $mainModuleFile, $matches);

        self::assertArrayHasKey(1, $matches, 'Unable to extract module version from ps_onepagecheckout.php');

        return $matches[1];
    }

    private function extractXmlVersion(string $xmlPath): string
    {
        $xml = simplexml_load_file($xmlPath);

        self::assertNotFalse($xml, sprintf('Unable to load XML metadata file %s', $xmlPath));

        return trim((string) $xml->version);
    }
}
