<?php

namespace App\Enums;

enum ConnectorSchemaSourceKind: string
{
    case ApiSchema = 'api_schema';
    case OfficialWebDoc = 'official_web_doc';
    case RepositoryCode = 'repository_code';
    case RepositoryDocument = 'repository_document';
    case AccountApi = 'account_api';
    case StaticRegistry = 'static_registry';
    case ManualImport = 'manual_import';

    public function label(): string
    {
        return match ($this) {
            self::ApiSchema => 'API-схема',
            self::OfficialWebDoc => 'Офіційна веб-документація',
            self::RepositoryCode => 'Код репозиторію',
            self::RepositoryDocument => 'Документ репозиторію',
            self::AccountApi => 'API облікового запису',
            self::StaticRegistry => 'Статичний реєстр',
            self::ManualImport => 'Ручний імпорт',
        };
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $case) => [$case->value => $case->label()])
            ->all();
    }
}
