<?php

namespace Tests\Unit\Connectors\OAuth1;

use App\Support\Connectors\OAuth1\OAuth1BaseStringUriBuilder;
use App\Support\Connectors\OAuth1\OAuth1RequestUrl;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class OAuth1RequestSignerBaseStringUriTest extends TestCase
{
    private OAuth1BaseStringUriBuilder $builder;

    protected function setUp(): void
    {
        parent::setUp();

        $this->builder = new OAuth1BaseStringUriBuilder;
    }

    #[Test]
    public function omits_default_https_port(): void
    {
        $requestUrl = OAuth1RequestUrl::parse('https://host/path');

        $this->assertSame('https://host/path', $this->builder->build($requestUrl));
    }

    #[Test]
    public function explicit_default_https_port_is_omitted(): void
    {
        $requestUrl = OAuth1RequestUrl::parse('https://host:443/path');

        $this->assertSame('https://host/path', $this->builder->build($requestUrl));
    }

    #[Test]
    public function retains_non_default_port(): void
    {
        $requestUrl = OAuth1RequestUrl::parse('https://host:8443/path');

        $this->assertSame('https://host:8443/path', $this->builder->build($requestUrl));
    }

    #[Test]
    public function absent_path_normalizes_to_slash(): void
    {
        $requestUrl = OAuth1RequestUrl::parse('https://host');

        $this->assertSame('https://host/', $this->builder->build($requestUrl));
    }

    #[Test]
    public function preserves_existing_path_percent_encoding(): void
    {
        $requestUrl = OAuth1RequestUrl::parse('https://host/path%2Fmore');

        $this->assertSame('https://host/path%2Fmore', $this->builder->build($requestUrl));
    }
}
