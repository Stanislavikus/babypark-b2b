Кандидатная архитектура «переиспользовать Filament Import и только подменить job» не выдерживает проверку по исходному коду Filament 5.7.6 и OpenSpout 4.32.0. Безопасный Import v1 — это собственный прогон с `workspace_id`, OpenSpout только как потоковый чтец/писатель, и запись исключительно через уже существующие domain writers. PDF, картинки и Filament `imports` / `failed_import_rows` в v1 не входят.

База: `origin/develop` @ `06bf85e9c1d6335a3f69bf0c7cee3499cf3094af`. Код, миграции и PR не создавались.

### PRE-CODE ARCHITECTURAL ALIGNMENT

* **Task Type:** import / architecture study
* **Docs Checked:** `docs/Project_Documentation_Map.md`; `docs/00-WHY.md` (через карту и GAP-004); `docs/01-PRODUCT_VISION.md` (Smart Import); `docs/02-ATTRIBUTE_DICTIONARY.md` (цепочка сопоставления и `workspace_import_aliases`); `docs/03-DOMAIN_MODEL.md` (alias boundary, Field Foundation); `docs/04-ARCHITECTURE_PRINCIPLES.md` (checklist 1–22); `docs/05-AI_WORKING_AGREEMENT.md`; `docs/IMPLEMENTATION_GAPS.md` GAP-004; `docs/CANONICAL_PRODUCT_FIELD_REGISTRY.md`; `docs/reviews/MASTER_ASSETS_V1_CORE_2026_10_08.md`; `docs/reviews/CATEGORY_CLASSIFICATION_ARCHITECTURE_2026_10_07.md`
* **Affected Domain Contexts:** Workspace, Product Catalogue, Attribute Dictionary, Pricing и Availability только как граница «вне v1», Media как граница «вне v1»
* **Primary Sources & Standards:** vendor Filament Actions 5.7.6 (`7e75d8da`), OpenSpout 4.32.0 (`41f045c1`), официальная документация Filament Import, OWASP CWE-1236, ZIP/XML preflight
* **Architecture Checklist Result:** пункты 1, 2, 3, 4, 7, 8, 9, 17, 20, 21 применимы и ниже разобраны. Пункты 5, 6 применимы к динамическим значениям: писать только через `GovernedDynamicFieldValueWriter`. Пункты 10–16 и 19 не про файловый импорт товаров. Пункт 18: никаких клиентских заголовков в коде. Пункт 22 не про этот файл.
* **Architecture Risks Identified:** chunk-commit после пойманной ошибки строки; `WorkspaceContext` default-only; таблицы Filament без `workspace_id`; формулы; ZIP без лимита; повторная доставка job
* **Chosen Technical Approach:** описан в конце. Реализация в этом исследовании не делалась.
* **Non-Technical Simplicity Check:** пользователь выбирает один способ узнать товар, видит превью и ошибки строк, скачивает шаблон с внутренним ID.
* **Stop & Amend Required:** No для этого исследования. Код писать рано: сначала закрыть BLOCKER-инварианты отдельным контрактом на реализацию.

`ImportDispatcher` в `vendor/filament` отсутствует.

## Рынок

Платный SaaS (Flatfile, OneSchema, Dromo, CSVBox) отпадает: данные уходят наружу, это не бесплатная библиотека внутри нашего процесса.

| Кандидат | Functionality | License | Activity | Upgrade | Integration | Итог |
|---|---|---|---|---|---|---|
| OpenSpout 4.32.0, уже в lockfile | PARTIAL: CSV/XLSX/ODS stream read/write, PDF нет, лимита ZIP нет, заголовки не сопоставляет | PASS: MIT для кода после форка, Apache-2.0 у наследия Box | PASS: линия 5.x жива (релиз 5.12.0 от 2026-09-24); мы остаёмся на зафиксированной 4.32.0 | PASS, если не прыгать на 5.x | PASS только как чтец/писатель | Берём |
| Filament 5.7.6 `ImportAction` / `ImportCsv` | PARTIAL: только CSV через League CSV | PASS MIT | PASS, это текущий lock | FAIL: свой job обязан повторить конструктор `ImportCsv` | FAIL: chunk-транзакция, нет `workspace_id`, файл не сохраняется | Не берём как конвейер |
| Filament 5.7.6 Exporter | PARTIAL: CSV и XLSX, ODS нет | PASS MIT | PASS | PARTIAL | FAIL: нет `workspace_id`, защита от формул по умолчанию выключена, `Row::fromValues()` превращает `=` в формулу | Не берём как писатель каталога |
| `spatie/simple-excel` 3.10 | PARTIAL: тонкая обёртка над OpenSpout ^4.30 | PASS MIT | PASS, релиз 2026-06-15 | PASS | FAIL: новый пакет без workspace, preview и writers | Не добавлять |
| `maatwebsite/excel` + PhpSpreadsheet | PARTIAL: больше форматов и стилей, тяжёлый DOM | MIT заявлен у Laravel Excel; XXE-история PhpSpreadsheet (CVE-2024-45293 и соседние advisory) | PASS | FAIL: новая зависимость и другая модель памяти | FAIL | Не добавлять |
| `cube-agency/filament-excel` | PARTIAL: XLSX-мастер поверх Laravel Excel | PASS по README | не проверялся глубже | FAIL | FAIL: прямая запись моделей | Не добавлять |
| `romansulzhyk/filament-import` | PARTIAL: CSV/XLSX, заявлена транзакция на строку, ODS нет, очередь нет | PASS MIT по Packagist | пакет существует | неизвестно на нашем Filament patch | FAIL: пишет модели, не знает workspace и наших writers | Не добавлять |
| `box/spout` | FAIL: предшественник | Apache-2.0 | FAIL: архив | FAIL | FAIL | Не добавлять |
| `smalot/pdfparser` 2.12.5 | FAIL для таблиц товаров: текст и метаданные, не сетка | LGPL-3.0 | PASS | FAIL | FAIL | PDF вне v1 |

Автораспознавание колонок готовым OSS не закрывается. Filament угадывает заголовок точным нижним регистром по `ImportColumn::guesses()`. Словаря, workspace-памяти и ручного подтверждения у него нет. Это остаётся нашим кодом поверх чтеца.

## Публичные точки Filament 5.7.6

Они есть, и их недостаточно.

`ImportAction::job()` подменяет класс job. Диспетчер всё равно вызывает `app($job, ['import', 'rows', 'columnMap', 'options'])`, где `rows` — это `base64(serialize(chunk))`, уже вычитанный League CSV в HTTP-запросе. Другой конструктор через `job()` не подключить. Сменного reader-а нет. Принятые MIME — CSV-семейство. XLSX/ODS этот action не читает.

`Importer` даёт `resolveRecord()`, `saveRecord()` и хуки `beforeSave` / `afterSave` / `beforeCreate` / `afterCreate`. `saveRecord()` делает `$this->record->save()` и затем связи. Политик на строку нет: это прямо написано в шапке класса.

`app(Import::class)` и `app(FailedImportRow::class)` позволяют подменить модель в контейнере на путях, которые идут через контейнер. Скачивание ошибок идёт иначе: маршрут `filament.imports.failed-rows.download` типизирован конкретным `Filament\Actions\Imports\Models\Import`. Implicit binding поднимает vendor-класс. Глобальный scope наследника на этот маршрут не попадает.

События `ImportStarted`, `ImportChunkProcessed`, `ImportCompleted` есть. Отдельного per-row transaction hook нет.

## 1. RowAtomicity — BLOCKER

Инвариант: упавшая строка не оставляет частичных записей.

`ImportCsv::handle()` открывает одну `DB::transaction` на весь chunk. Исключение строки ловится внутри неё. Наружу транзакции оно не выходит, поэтому chunk коммитится.

Контрпример. Строка обновляет название и динамическое поле. `GovernedProductVariantColumnMutationService::set()` и `GovernedDynamicFieldValueWriter::set()` каждый открывают свою транзакцию. Вложенный успешный вызов в Laravel только снимает savepoint, изменения остаются во внешней транзакции. Дальше динамический writer бросает исключение, свой savepoint откатывается, `ImportCsv` ловит его и пишет failed row. Внешний commit сохраняет уже сменённое `products.name`. В журнале строка ошибочная, название уже другое.

Вложенная транзакция на каждый writer это не лечит: откатывается только тот вызов, который бросил исключение. Предыдущие успешные вызовы той же строки остаются.

Короткий безопасный fix: свой job, в котором транзакция строки — самая внешняя. Все writers вызываются внутри неё. Исключение выходит из этой транзакции, и только потом, уже снаружи, пишется ledger ошибки. Chunk-транзакции нет. `job()` здесь не используется, потому что конвейер `ImportAction` нам не подходит.

Отдельный риск: у column writer пять попыток при deadlock. В MySQL deadlock откатывает всю транзакцию, не savepoint. Повтор внутри чужого chunk-transaction ненадёжен. Поэтому строка должна быть внешней транзакцией.

Regression: строка из двух writer-вызовов, второй бросает исключение. После прогона нет нового товара, нет изменённого имени, нет `product_field_values` / `variant_field_values` от этой строки, ledger строки = error.

## 2. WorkspaceIsolation — BLOCKER для таблиц Filament

Инвариант: workspace-owned данные несут `workspace_id` и не резолвятся через default workspace.

`imports` и `failed_import_rows` в миграциях Filament содержат `user_id`, не `workspace_id`. Скачивание ошибок пускает владельца `user_id` либо policy `view`. Пользователь двух workspace скачает ошибки чужого workspace тем же аккаунтом.

`WorkspaceContext::current()` всегда возвращает default workspace. В комментарии прямо указан GAP-004: очередь и scheduled-команды контекст не получают. Job, который возьмёт workspace из контекста, запишет каталог в default tenant.

Контейнерная подмена `Import` закрывает создание через `app(Import::class)` и связь `failedRows()`, если код идёт через контейнер. Она не закрывает route binding, не добавляет колонку и не чинит контекст очереди. Уведомление `sendToDatabase($import->user)` тоже пользовательское, не workspace-овое.

Сравнение: наследник таблиц Filament проигрывает отдельным `import_runs`. Отдельные таблицы с первого дня имеют `workspace_id`, `BelongsToWorkspace`, явный `Workspace` в job и свой авторизованный маршрут. Апгрейд Filament не меняет их схему.

Regression: прогон workspace B из очереди при существующем default workspace A не создаёт и не меняет товары A. Скачивание отчёта B пользователем, который также состоит в A, без явного workspace B возвращает 403.

## 3. PreviewIsReadOnly — BLOCKER, если превью вызывает writers

Инвариант: превью не вызывает writers, даже внутри rollback.

Откат не равен отсутствию эффекта. Auto-increment `products.id` в InnoDB и sequence в PostgreSQL не возвращаются. Вложенные writer-транзакции при внешнем rollback всё равно держат блокировки. Превью, которое «применяет и откатывает», ещё и расходится с правилом «apply читает файл заново».

Превью: resolver + валидация + счётчики create/update/skip/error + первые N строк. Сохранённый план мутаций apply не исполняет. Между превью и apply товар мог появиться; apply обязан увидеть это сам.

Regression: mock всех writers, превью файла с валидными и битыми строками, ноль вызовов, число товаров не меняется.

## 4. Identity — BLOCKER на двусмысленном SKU

Доказанный инвариант: `UNIQUE(workspace_id, sku)` отдельно на `products` и отдельно на `product_variants`. Несколько `NULL` разрешены. `barcode_ean` уникальным не является. Имя категории и имя бренда уникальным индексом не защищены. EAN, имя и fuzzy не являются идентификатором.

Словарь кладёт SKU в `product_variants.sku`. `MasterProductDraftCreator` при создании пишет один SKU и в товар, и в скрытый вариант. Форма товара потом сохраняет `products.sku` обычным Eloquent update и вариант не трогает. После такого редактирования два SKU могут разъехаться.

Контракт v1, который это переживает:

- Один режим на прогон, его выбирает человек: Master Product ID или exact SKU.
- Create требует непустой SKU. Повтор того же SKU в режиме Create — ошибка строки, новая запись не создаётся.
- Update по Product ID: `products.id` плюс `workspace_id`. Чужой workspace — ошибка, не создание.
- Update по SKU: непустой SKU находится и в `products`, и в `product_variants` того же workspace и указывает на один товар. Любое другое сочетание — ошибка строки.
- Смешивать «сначала ID, потом SKU» в одном прогоне нельзя.
- `internal_product_id` (`products.id`, сид «Внутрішній ID товару») входит в экспорт и в шаблон.

Idempotency бизнес-повторa и idempotency повтора job — разные вещи. Повтор Create-файла не обновляет. Повтор job после commit не создаёт второй товар: ledger строки `applied` заставляет retry пропустить строку. Уникальный SKU — страховка, не журнал.

Regression: два товара с одним EAN не сходятся; одинаковое имя не сходится; SKU только на `products` и другой SKU на варианте дают error и ноль записей; ID из другого workspace даёт error; повтор Create с тем же SKU не создаёт вторую строку; повтор job после успешного commit не создаёт вторую строку.

## 5. ConfigurableIdentity — выдерживается, если v1 не строит семьи вариантов

`ProductVariantStructureService::addVariant()` требует уже объявленные оси и точную комбинацию опций. Похожий SKU, имя или атрибуты он не использует. UI прямо говорит, что декартово произведение само не строится.

В v1 одна строка — простой товар: продукт и его скрытый вариант через `MasterProductDraftCreator`. Колонка «родитель» не интерпретируется. Семья вариантов остаётся в карточке товара.

Regression: файл с родительским и дочерним SKU, похожими на семью, создаёт только явные простые товары либо ошибки, и ноль новых `product_variant_axes`.

## 6. Mapping contract

Цепочка из словаря: точный `code` → глобальный alias → локализованный label → `workspace_import_aliases`. Низкая уверенность — ручной выбор.

Фактически в runtime есть `FieldDefinition.code`, `localized_labels` и `workspace_import_aliases.field_binding_id` после `FieldFoundationMigrator`. `ImportHeaderNormalizer` в `app/` нет. `docs/data/canonical_product_field_aliases.csv` — документальный артефакт, реестр прямо пишет, что runtime из него не следует. Глобальные синонимы вроде `Title` → `name` сегодня сами не сработают.

Это SHOULD FIX для удобства, не дыра безопасности: несовпавшее уходит в ручной mapping. Автосвязка v1 только при одном кандидате среди Product и ProductVariant bindings: точный code, либо нормализованный localized label, либо нормализованный workspace alias. Два кандидата — ручной выбор. Fuzzy в коде нет; в v1 его не включать.

Сырой заголовок как доказательство хранится в снимке mapping этого прогона. В `alias_name` пишется нормализованный ключ: доменная модель называет его normalized token, а уникальный индекс завязан на `alias_name`. Писать в эту колонку сырой и нормализованный вид одновременно нельзя. Отдельная колонка `raw_header` — SHOULD FIX, если понадобится вечное доказательство орфографии, а не только снимок прогона.

## 7. Writers

Санкционированные границы, которые v1 может звать:

| Операция | Writer | Что реально умеет |
|---|---|---|
| Создать простой товар | `MasterProductDraftCreator::create(Workspace, input)` | name, sku, EAN, brand id, category id, description, url, физические поля, lifecycle; сам создаёт скрытый вариант с тем же SKU |
| Название и описание | `GovernedProductVariantColumnMutationService` | allowlist только `products.name` и `products.description` |
| Динамические значения | `GovernedDynamicFieldValueWriter::set/clear` | Product и Variant: text, long text, number, decimal, boolean, date, select, multiselect, url. Money, Image, Computed закрыты |
| Бренд существующего товара | `BrandManager::assign` | id бренда; source-owned 1С блокируется внутри |
| Жизненный цикл | `MasterProductLifecycleMutationService::transition` | source-owned блокируется |
| Новый вариант | `ProductVariantStructureService::addVariant` | только явные оси; в v1 не звать |
| Категория как узел | `CategoryTreeMutationService::create` | создаёт узел дерева; привязки товара к категории там нет |
| Цена | `MasterOfferMutationService::setPrice` | нужен ожидаемый текущий price; в v1 не звать |
| Медиа | `OriginalImageIngestService` | локальный ingest, не URL |

Форма редактирования пишет SKU, EAN и прочие скаляры через `parent::handleRecordUpdate()`, то есть прямым Eloquent. Для импорта этот путь запрещён.

Отсутствующие границы, без которых полный round-trip невозможен:

- обновление `product_variants.sku` и парного `products.sku` одной операцией;
- обновление GTIN/EAN;
- смена `category_id` у существующего товара;
- обновление url и физических колонок;
- привязка категории по пути или имени: уникального инварианта нет, поэтому v1 принимает только id существующей активной категории на create.

Короткий v1 не изобретает эти writers. Create пишет то, что принимает `DraftCreator`, и следом в той же транзакции динамические поля. Update пишет name, description, динамические поля и brand id. Сопоставленная колонка вне этого набора, если значение меняется, валит строку до первой записи.

Цена, остаток и картинки в v1 не импортируются.

## 8. Durable run

`ImportAction` кладёт в `file_path` временный путь Livewire и `storeFiles(false)`. После запроса файла нет. Повторно прочитать его нельзя: клетки живут в payload job. `completed_at` ставится в `finally` batch даже когда все строки упали. Статуса прогона нет. SHA-256 нет.

Нужны свои записи:

- `import_runs`: `workspace_id`, актор, исходное имя, SHA-256, приватный диск и путь, режим идентичности, mapping со сырыми заголовками, статус (`uploaded`, `previewed`, `applying`, `completed`, `failed`), счётчики, время;
- `import_run_rows`: `workspace_id`, прогон, номер строки, `applied` или `error`, текст ошибки, `product_id`.

Файл остаётся до конца прогона. Apply читает его снова. Retry job пропускает `applied`. Ошибка валидации записывается отдельной короткой транзакцией после отката доменной и повторно не крутится. Инфраструктурный сбой ledger не пишет, job пробует снова. Повторная загрузка того же SHA не применяется молча: оператор видит историю и сам запускает новый прогон.

## 9. Security — BLOCKER на наивном чтении и на `Row::fromValues`

OpenSpout 4.32.0, `CellValueFormatter`: ячейка с `<f>` становится `FormulaCell`. `getValue()` возвращает `='.$formula`. `getComputedValue()` — кэш. `Row::toArray()` вызывает `getValue()`, то есть в данные попал бы текст формулы, а не кэш. Кэш всё равно доступен отдельным методом. Политика: `FormulaCell` и `ErrorCell` на сопоставленной колонке валят строку. `getComputedValue()` не читается.

`Cell::fromValue()` и `Row::fromValues()` строку с первым символом `=` делают `FormulaCell`. XLSX writer пишет её как `<f>`. CSV writer печатает `getValue()` как текст, и Excel исполняет `=`, `+`, `-`, `@`, tab и CR. У Filament та же шестёрка есть в `CanFormatState`, но флаг по умолчанию `null`, то есть защита выключена.

Запись v1: XLSX и ODS только через `StringCell` (`t="s"` / inline string), не через `fromValue()`. CSV — префикс `'` по тем же шести символам, кроме чисто числовых `-5` и `+42`.

ZIP. `XLSX\Options` не содержит лимита несжатого размера. `Reader::open()` сразу вызывает `extractSharedStrings()` и читает `zip://` с `LIBXML_NONET`. Сеть для XML этим флагом закрыта. Размер распаковки не закрыт, а атрибут `uniqueCount` в shared strings можно соврать. До `Reader::open()` свой preflight по `ZipArchive::statIndex`: сумма несжатых размеров, число записей, число листов, затем потоковый потолок строк и длины ячейки. Несовпадение расширения и содержимого отвергается: CSV, внутри которого PKZIP; XLSX без ZIP; ODS без `application/vnd.oasis.opendocument.spreadsheet`. Битый workbook даёт ошибку файла, не частичный импорт.

PDF в v1 отвергается по сигнатуре `%PDF` с понятным сообщением. Парсера нет.

Regression: XLSX-формула с кэшем `42` не создаёт товар и не сохраняет `42`; CSV-экспорт значения `=1+1` открывается как текст; ZIP с заявленным огромным несжатым размером отвергается до OpenSpout; `.xlsx` без ZIP отвергается.

## 10. Queue payload — BLOCKER для схемы Filament на больших файлах

`ImportAction` кладёт весь chunk в job. Chunk по умолчанию 100. `jobs.payload` и `failed_jobs.payload` — `longText`, но пакет MySQL режется `max_allowed_packet`, а failed jobs хранят ту же копию, пока кто-то их не почистит. Широкий файл на десятки тысяч строк размножает клетки в очереди, в failed jobs и в `failed_import_rows`.

Свой payload: id прогона, id workspace, id актора, диапазон номеров строк. Клетки остаются в сохранённом файле. Failed job тогда весит сотни байт и не содержит каталог.

## 11. Media

Замороженный контур — `MediaAsset` плюс `ProductMedia` / `VariantMedia` / логотип бренда. `OriginalImageIngestService` принимает подготовленный локальный файл. Метода «скачать URL и создать asset» нет. `MediaAssetSourceResolver` только читает уже сохранённый `source_url`. URL картинки добавил бы новый путь и SSRF (пункт 21 чеклиста). Картинки и URL картинок вне v1.

## Короткий Import v1

Новых Composer-пакетов нет. OpenSpout 4.32.0 читает и пишет CSV, XLSX и ODS. Filament остаётся UI: загрузка, таблица прогонов, уведомление. `ImportAction`, `ImportCsv`, модели `Import` и `FailedImportRow`, Exporter каталога не используются.

1. Загрузка на приватный диск, SHA-256, исходное имя, проверка MIME и расширения, ZIP-лимиты, один выбранный лист.
2. Превью без writers. Сопоставление: один уверенный кандидат или ручной выбор. Снимок сырых заголовков. Счётчики и первые строки.
3. Оператор выбирает Create или Update, и для Update ещё Product ID либо exact SKU.
4. Apply-job несёт только идентификаторы и диапазон строк, заново читает файл и заново валидирует.
5. Каждая строка — внешняя транзакция: resolve, затем `DraftCreator` и динамический writer, либо column writer, dynamic writer и `BrandManager::assign`. Чужое поле с изменением валит строку до записи.
6. Ledger строки пишется в той же транзакции при успехе и отдельной транзакцией после отката при ошибке валидации.
7. Экспорт и шаблон тем же OpenSpout: `StringCell` для таблиц, префикс для CSV, колонка `products.id` обязательна. Категория в шаблоне — id. Имя категории, цена, остаток и картинки в импорт не принимаются.

BLOCKER, без которых этот контур врать будет: chunk-commit Filament; отсутствие `workspace_id` и default `WorkspaceContext`; превью через writers; двусмысленный SKU; формулы и ZIP без preflight; клетки внутри payload job.

SHOULD FIX следом, не вместо безопасности: сид глобальных alias отдельным решением, не чтением research CSV из продакшена; колонка сырого заголовка в памяти alias; Windows-1251 для CSV через уже существующий `Options::ENCODING`; update-writers для SKU, EAN, категории и физических полей, когда понадобится полный round-trip.

NON-BLOCKING: ODS как третий формат уже умеет OpenSpout; fuzzy-подсказки; автосоздание брендов и категорий; семьи вариантов; PDF.