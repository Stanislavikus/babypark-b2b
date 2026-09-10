<?php

namespace App\Support\Connectors\Transport\Dns;

use Symfony\Component\Process\Process;

final class DefaultDnsChildProcessFactory implements DnsChildProcessFactory
{
    /**
     * @param  list<string>  $command
     */
    public function create(array $command): Process
    {
        return new Process($command);
    }
}
