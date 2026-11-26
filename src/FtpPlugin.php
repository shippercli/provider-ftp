<?php

declare(strict_types=1);

namespace ShipperCli\ProviderFtp;

use ShipperCli\Contracts\ShipperPluginInterface;

final class FtpPlugin implements ShipperPluginInterface
{
    public function providers(): array
    {
        return ['ftp' => FtpProvider::class];
    }
}
