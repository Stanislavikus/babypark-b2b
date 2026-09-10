<?php

namespace Tests\Feature\Sync;

use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class MagentoWebApiReflectionContractRegressionTest extends TestCase
{
    private const API_BASE_PATH = 'integrations/magento-safe-sync/Api';

    #[Test]
    public function magento_webapi_reflection_contract_regression(): void
    {
        $apiFiles = [
            ...File::files(base_path(self::API_BASE_PATH)),
            ...File::files(base_path(self::API_BASE_PATH.'/Data')),
        ];

        $processedFiles = [];

        foreach ($apiFiles as $file) {
            $relativePath = str_replace(base_path().'/', '', $file->getPathname());
            $processedFiles[] = $relativePath;

            $content = File::get($file->getPathname());

            $this->assertDoesNotMatchRegularExpression('/\b(?:list|array)<[^>]+>/', $content, $relativePath);

            preg_match_all(
                '/(?P<doc>\/\*\*[\s\S]*?\*\/)\s*public function\s+(?P<name>\w+)\s*\((?P<params>[\s\S]*?)\)\s*(?::\s*(?P<returnType>[^;]+))?\s*;/',
                $content,
                $methods,
                PREG_SET_ORDER,
            );
            preg_match_all('/public function\s+\w+\s*\(/', $content, $allPublicMethods);

            $this->assertNotEmpty($methods, $relativePath);
            $this->assertCount(count($allPublicMethods[0]), $methods, $relativePath);

            foreach ($methods as $method) {
                $doc = $method['doc'];
                $name = $method['name'];
                $params = $method['params'];
                $returnType = trim($method['returnType'] ?? '');

                $this->assertStringContainsString('@return', $doc, sprintf('%s::%s missing @return', $relativePath, $name));

                preg_match_all('/\$[A-Za-z_][A-Za-z0-9_]*/', $params, $parameterNames);
                $parameterCount = count($parameterNames[0]);

                if ($parameterCount > 0) {
                    $this->assertGreaterThanOrEqual(
                        $parameterCount,
                        substr_count($doc, '@param'),
                        sprintf('%s::%s missing @param metadata', $relativePath, $name),
                    );
                }

                if (str_starts_with($name, 'set')) {
                    $this->assertMatchesRegularExpression('/@return\s+\$this\b/', $doc, sprintf('%s::%s setter must return $this', $relativePath, $name));
                }

                if ($returnType === 'array') {
                    $this->assertMatchesRegularExpression('/@return\s+[^\n]*\[\]/', $doc, sprintf('%s::%s array return must use T[]', $relativePath, $name));
                }

                preg_match_all('/\barray\s+\$([A-Za-z_][A-Za-z0-9_]*)/', $params, $arrayParameters);

                foreach ($arrayParameters[1] as $arrayParameter) {
                    $this->assertMatchesRegularExpression(
                        '/@param\s+[^\n]*\[\]\s+\$'.preg_quote($arrayParameter, '/').'\b/',
                        $doc,
                        sprintf('%s::%s array parameter $%s must use T[]', $relativePath, $name, $arrayParameter),
                    );
                }
            }
        }

        sort($processedFiles);

        $this->assertSame([
            'integrations/magento-safe-sync/Api/Data/HandshakeResponseInterface.php',
            'integrations/magento-safe-sync/Api/Data/ProductReadResponseInterface.php',
            'integrations/magento-safe-sync/Api/Data/ProductWriteMappedAttributeInterface.php',
            'integrations/magento-safe-sync/Api/Data/ProductWriteRequestInterface.php',
            'integrations/magento-safe-sync/Api/Data/ProductWriteResponseInterface.php',
            'integrations/magento-safe-sync/Api/HandshakeManagementInterface.php',
            'integrations/magento-safe-sync/Api/ProductReadManagementInterface.php',
            'integrations/magento-safe-sync/Api/ProductWriteManagementInterface.php',
        ], $processedFiles);

        $webapi = new \SimpleXMLElement(File::get(base_path('integrations/magento-safe-sync/etc/webapi.xml')));
        $serviceClasses = [];

        foreach ($webapi->xpath('//route/service') as $service) {
            $serviceClasses[] = (string) $service['class'];
        }

        sort($serviceClasses);

        $this->assertSame([
            'B2BPlatform\MagentoSafeSync\Api\HandshakeManagementInterface',
            'B2BPlatform\MagentoSafeSync\Api\ProductReadManagementInterface',
            'B2BPlatform\MagentoSafeSync\Api\ProductWriteManagementInterface',
        ], $serviceClasses);

        foreach ($serviceClasses as $serviceClass) {
            $expectedPath = 'integrations/magento-safe-sync/'.str_replace('\\', '/', str_replace('B2BPlatform\\MagentoSafeSync\\', '', $serviceClass)).'.php';

            $this->assertContains($expectedPath, $processedFiles);
        }
    }
}
