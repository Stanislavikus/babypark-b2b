<?php

namespace App\Support\Connectors\Transport\Dns;

use Symfony\Component\Process\Process;

interface DnsChildProcessFactory
{
    /**
     * @param  list<string>  $command
     */
    public function create(array $command): Process;
}
