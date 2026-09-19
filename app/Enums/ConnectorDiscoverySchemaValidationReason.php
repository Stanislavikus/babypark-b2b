<?php

namespace App\Enums;

enum ConnectorDiscoverySchemaValidationReason: string
{
    case MissingRequiredValue = 'missing_required_value';
    case EmptyRequiredString = 'empty_required_string';
    case InvalidType = 'invalid_type';
    case InvalidUtf8 = 'invalid_utf8';
    case UnmappedValue = 'unmapped_value';
    case NegativeInteger = 'negative_integer';
    case MalformedList = 'malformed_list';
    case MalformedObject = 'malformed_object';
    case DuplicateOptionValue = 'duplicate_option_value';
    case DuplicateExternalFieldKey = 'duplicate_external_field_key';
    case InvalidCanonicalHash = 'invalid_canonical_hash';
    case UnsupportedCanonicalValue = 'unsupported_canonical_value';
    case JsonEncodingFailed = 'json_encoding_failed';
}
