<?php

namespace App\Support\Connectors\AdobePaaS\Validation;

enum AdobeStage3EValidationTransportFaultShape: string
{
    case TransportUnknown = 'transport_unknown';
    case SyntheticNon2xx = 'synthetic_non_2xx';
    case InconclusiveBody = 'inconclusive_body';
}
