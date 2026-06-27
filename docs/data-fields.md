# Реєстр полів даних (data-fields)

> Згенеровано з живої схеми БД (`php artisan model:show`) та grep по `app/` і `resources/views/`.
> Дата: 2026-06-27 · Інтроспекція: `php artisan model:show` на SQLite (`database/database.sqlite`)

## Конвенція

Будь-яке завдання, що **додає або змінює колонку БД**, має оновити цей файл (`docs/data-fields.md`) **в тому ж pull request**.

## Як оновлювати

```bash
# 1. Перевірити живу схему (не міграції!)
php artisan model:show Product
php artisan model:show ProductVariant
# ... інші моделі

# 2. Знайти використання поля
rg -w field_name app/ resources/views/

# 3. Додати/оновити рядок у таблиці нижче
```

## Джерела даних

| Позначка | Значення |
|---|---|
| 1С (синхронізовано) | Заповнюється з API 1С (`SyncService`, див. AGENTS.md) |
| Вручну (адмін) | Редагується в Filament admin |
| Вручну (кабінет) | Вводить контрагент у B2B-кабінеті |
| Розраховується | Обчислюється в коді — вказано метод |
| Laravel (auto) | Стандартні поля Eloquent |

## Таблиця полів

| Таблиця.Поле | Стандартна назва (GMC) | Опис | Джерело | Де використовується | Статус |
|---|---|---|---|---|---|
| `products.id` |  | Первинний ключ товару | Laravel (auto) | app/Filament/Cabinet/Resources/ProductResource.php, app/Filament/Cabinet/Resources/ProductResource/Pages/ListProducts.php, app/Filament/Resources/ProductResource.php, app/Livewire/Cabinet/Catalog.php, app/Livewire/Cabinet/ProductDetail.php, app/Models/Product.php (+8) | ✅ |
| `products.onec_guid` |  | Унікальний GUID товару в 1С | 1С (синхронізовано) | app/Models/Category.php, app/Models/Product.php, app/Models/ProductVariant.php | ⚠️ needs review |
| `products.sku` | id | Артикул (унікальний код товару) | 1С (синхронізовано) | app/Filament/Cabinet/Resources/ProductResource.php, app/Filament/Resources/ProductResource.php, app/Livewire/Cabinet/Catalog.php, app/Models/OrderItem.php, app/Models/Product.php, app/Models/ProductVariant.php (+3) | ✅ |
| `products.barcode_ean` | gtin | Штрихкод EAN товару | 1С (синхронізовано) | app/Filament/Cabinet/Resources/ProductResource.php, app/Filament/Resources/ProductResource.php, app/Models/Product.php, app/Models/ProductVariant.php, app/Support/ProductFields/ProductColumnVisibility.php, app/Support/ProductFields/ProductPanelVisibility.php | ✅ |
| `products.barcode_box` |  | Штрихкод коробки | 1С (синхронізовано) | app/Models/Product.php | ⚠️ needs review |
| `products.name` | title | Назва товару | 1С (синхронізовано) | app/Filament/Cabinet/Resources/ProductResource.php, app/Filament/Concerns/HasProductLightbox.php, app/Filament/Resources/ProductResource.php, app/Livewire/Cabinet/Catalog.php, app/Models/Category.php, app/Models/OrderItem.php (+5) | ✅ |
| `products.category_id` |  | FK на categories.id | 1С (синхронізовано) | app/Filament/Cabinet/Resources/ProductResource.php, app/Filament/Resources/ProductResource.php, app/Livewire/Cabinet/Catalog.php, app/Models/Product.php | ✅ |
| `products.brand` | brand | Бренд | 1С (синхронізовано) | app/Filament/Cabinet/Resources/ProductResource.php, app/Filament/Resources/ProductResource.php, app/Livewire/Cabinet/Catalog.php, app/Models/Product.php, app/Support/ProductFields/ProductColumnVisibility.php, app/Support/ProductFields/ProductPanelVisibility.php (+1) | ✅ |
| `products.unit` |  | Одиниця виміру | 1С (синхронізовано) | app/Models/Product.php | ⚠️ needs review |
| `products.min_order_quantity` |  | Мінімальна кількість для замовлення | 1С (синхронізовано) | app/Models/Product.php, app/Support/CatalogRowData.php | ✅ |
| `products.order_step` |  | Крок кількості при замовленні | 1С (синхронізовано) | app/Models/Product.php, app/Support/CatalogRowData.php | ✅ |
| `products.package_quantity` |  | Кількість в упаковці | 1С (синхронізовано) | app/Models/Product.php | ⚠️ needs review |
| `products.package_type` |  | Тип упаковки | 1С (синхронізовано) | app/Models/Product.php | ⚠️ needs review |
| `products.units_per_box` |  | Одиниць у коробці | 1С (синхронізовано) | app/Models/Product.php | ⚠️ needs review |
| `products.boxes_per_pallet` |  | Коробок на палеті | 1С (синхронізовано) | app/Models/Product.php | ⚠️ needs review |
| `products.lead_time_days` |  | Термін постачання (днів) | 1С (синхронізовано) | app/Models/Product.php | ⚠️ needs review |
| `products.weight_netto` |  | Вага нетто, кг | 1С (синхронізовано) | app/Models/Product.php | ⚠️ needs review |
| `products.weight_brutto` |  | Вага брутто, кг | 1С (синхронізовано) | app/Models/Product.php | ⚠️ needs review |
| `products.volume_m3` |  | Обʼєм, м³ | 1С (синхронізовано) | app/Models/Product.php | ⚠️ needs review |
| `products.depth_mm` |  | Глибина, мм | 1С (синхронізовано) | app/Models/Product.php | ⚠️ needs review |
| `products.width_mm` |  | Ширина, мм | 1С (синхронізовано) | app/Models/Product.php | ⚠️ needs review |
| `products.height_mm` |  | Висота, мм | 1С (синхронізовано) | app/Models/Product.php | ⚠️ needs review |
| `products.description` | description | Опис товару | Вручну (адмін) | app/Models/Product.php | ⚠️ needs review |
| `products.images` | image_link | JSON-масив URL фото; `[0]` → image_link | Вручну (адмін) | app/Filament/Concerns/HasProductLightbox.php, app/Models/Product.php, resources/views/filament/product-photo-entry.blade.php, resources/views/livewire/cabinet/product-detail.blade.php | ✅ |
| `products.rozetka_category_id` |  | ID категорії Rozetka | Вручну (адмін) | app/Models/Product.php | ⚠️ needs review |
| `products.meta_title` |  | SEO title | Вручну (адмін) | app/Models/Product.php | ⚠️ needs review |
| `products.meta_description` |  | SEO description | Вручну (адмін) | app/Models/Product.php | ⚠️ needs review |
| `products.is_active` |  | Чи показувати товар у каталозі | 1С (синхронізовано) | app/Filament/Cabinet/Resources/ProductResource.php, app/Filament/Resources/ProductResource.php, app/Livewire/Cabinet/Catalog.php, app/Models/Product.php, app/Models/ProductVariant.php, app/Support/CatalogRowData.php (+1) | ✅ |
| `products.synced_at` |  | Час останньої синхронізації з 1С | 1С (синхронізовано) | app/Models/Product.php, app/Models/ProductVariant.php | ⚠️ needs review |
| `products.created_at` |  | Час створення запису | Laravel (auto) | — | ⚠️ needs review |
| `products.updated_at` |  | Час оновлення запису | Laravel (auto) | app/Models/Price.php, app/Models/Stock.php | ⚠️ needs review |
| `products.product_url` | link | URL товару на babypark.ua | Вручну (адмін) | app/Filament/Cabinet/Resources/ProductResource.php, app/Filament/Resources/ProductResource.php, app/Models/Product.php, app/Support/ProductFields/ProductPanelVisibility.php, resources/views/livewire/cabinet/product-detail.blade.php | ✅ |
| `products.cost_price` |  | Вхідна ціна (внутрішня, для маржі) | Вручну (адмін) | app/Filament/Resources/ProductResource.php, app/Models/Product.php, app/Support/ProductFields/AdminProductMargin.php, app/Support/ProductFields/ProductColumnVisibility.php | ✅ |
| `product_variants.id` |  | Первинний ключ варіанту | Laravel (auto) | app/Filament/Cabinet/Resources/ProductResource.php, app/Models/Product.php, app/Models/ProductVariant.php, app/Support/CatalogRowData.php, app/Support/SessionCart.php | ✅ |
| `product_variants.product_id` |  | FK на products.id | 1С (синхронізовано) | app/Filament/Cabinet/Resources/ProductResource.php, app/Models/ProductVariant.php | ✅ |
| `product_variants.onec_guid` |  | GUID варіанту в 1С | 1С (синхронізовано) | app/Models/Product.php, app/Models/ProductVariant.php | ⚠️ needs review |
| `product_variants.sku` | id | Артикул варіанту | 1С (синхронізовано) | app/Filament/Cabinet/Resources/ProductResource.php, app/Models/OrderItem.php, app/Models/Product.php, app/Models/ProductVariant.php, app/Support/SessionCart.php | ✅ |
| `product_variants.barcode_ean` | gtin | Штрихкод EAN варіанту | 1С (синхронізовано) | app/Filament/Cabinet/Resources/ProductResource.php, app/Models/Product.php, app/Models/ProductVariant.php | ✅ |
| `product_variants.attributes` |  | JSON атрибутів (колір, розмір) | 1С (синхронізовано) | app/Models/OrderItem.php, app/Models/ProductVariant.php, resources/views/livewire/cabinet/product-detail.blade.php | ✅ |
| `product_variants.is_active` |  | Чи активний варіант | 1С (синхронізовано) | app/Filament/Cabinet/Resources/ProductResource.php, app/Models/Product.php, app/Models/ProductVariant.php, app/Support/CatalogRowData.php | ✅ |
| `product_variants.synced_at` |  | Час останньої синхронізації | 1С (синхронізовано) | app/Models/Product.php, app/Models/ProductVariant.php | ⚠️ needs review |
| `product_variants.created_at` |  | Час створення | Laravel (auto) | — | ⚠️ needs review |
| `product_variants.updated_at` |  | Час оновлення | Laravel (auto) | app/Models/Price.php, app/Models/Stock.php | ⚠️ needs review |
| `prices.id` |  | Первинний ключ ціни | Laravel (auto) | app/Filament/Cabinet/Resources/ProductResource.php, app/Livewire/Cabinet/Catalog.php, app/Models/Product.php, app/Models/ProductVariant.php, app/Support/CatalogRowData.php, app/Support/SessionCart.php (+2) | ✅ |
| `prices.contractor_id` |  | FK на contractors.id | 1С (синхронізовано) | app/Filament/Cabinet/Resources/ProductResource.php, app/Livewire/Cabinet/Catalog.php, app/Models/Price.php, app/Models/Product.php, app/Models/ProductVariant.php, app/Support/CatalogRowData.php (+1) | ✅ |
| `prices.variant_id` |  | FK на product_variants.id | 1С (синхронізовано) | app/Filament/Cabinet/Resources/ProductResource.php, app/Livewire/Cabinet/Catalog.php, app/Models/Price.php, app/Models/ProductVariant.php, app/Support/SessionCart.php | ✅ |
| `prices.price` |  | Ціна без ПДВ | 1С (синхронізовано) | app/Filament/Cabinet/Resources/ProductResource.php, app/Livewire/Cabinet/Catalog.php, app/Models/Price.php, resources/views/livewire/cabinet/catalog.blade.php | ✅ |
| `prices.price_with_vat` | price | Ціна з ПДВ | 1С (синхронізовано) | app/Filament/Cabinet/Resources/ProductResource.php, app/Livewire/Cabinet/Catalog.php, app/Models/Price.php, app/Models/Product.php, app/Models/ProductVariant.php, app/Support/SessionCart.php | ✅ |
| `prices.vat_rate` |  | Ставка ПДВ, % | 1С (синхронізовано) | app/Models/Price.php | ⚠️ needs review |
| `prices.recommended_retail_price` |  | Рекомендована роздрібна ціна | 1С (синхронізовано) | app/Filament/Cabinet/Resources/ProductResource.php, app/Livewire/Cabinet/Catalog.php, app/Models/Price.php, app/Models/Product.php | ✅ |
| `prices.min_quantity` |  | Мін. кількість для цієї ціни | 1С (синхронізовано) | app/Models/Price.php | ⚠️ needs review |
| `prices.currency` |  | Валюта | 1С (синхронізовано) | app/Models/Price.php | ⚠️ needs review |
| `prices.updated_at` |  | Час оновлення ціни | 1С (синхронізовано) | app/Models/Price.php | ⚠️ needs review |
| `stocks.id` |  | Первинний ключ залишку | Laravel (auto) | app/Filament/Cabinet/Resources/ProductResource.php, app/Filament/Resources/StockResource.php, app/Livewire/Cabinet/Catalog.php, app/Models/ProductVariant.php, app/Support/CatalogRowData.php, resources/views/livewire/cabinet/product-detail.blade.php | ✅ |
| `stocks.variant_id` |  | FK на product_variants.id | 1С (синхронізовано) | app/Filament/Cabinet/Resources/ProductResource.php, app/Livewire/Cabinet/Catalog.php, app/Models/ProductVariant.php, app/Models/Stock.php | ✅ |
| `stocks.warehouse_name` |  | Назва складу | 1С (синхронізовано) | app/Filament/Resources/StockResource.php, app/Models/Stock.php, resources/views/livewire/cabinet/product-detail.blade.php | ✅ |
| `stocks.quantity` |  | Кількість на складі | 1С (синхронізовано) | app/Filament/Cabinet/Resources/ProductResource.php, app/Filament/Resources/StockResource.php, app/Livewire/Cabinet/Catalog.php, app/Models/ProductVariant.php, app/Models/Stock.php, app/Support/CatalogRowData.php (+1) | ✅ |
| `stocks.reserved` |  | Зарезервована кількість | 1С (синхронізовано) | app/Filament/Cabinet/Resources/ProductResource.php, app/Filament/Resources/StockResource.php, app/Models/ProductVariant.php, app/Models/Stock.php, app/Support/CatalogRowData.php, resources/views/livewire/cabinet/product-detail.blade.php | ✅ |
| `stocks.expected_date` |  | Очікувана дата надходження | 1С (синхронізовано) | app/Filament/Cabinet/Resources/ProductResource.php, app/Filament/Resources/StockResource.php, app/Livewire/Cabinet/Catalog.php, app/Models/ProductVariant.php, app/Models/Stock.php, app/Support/CatalogRowData.php (+1) | ✅ |
| `stocks.expected_quantity` |  | Очікувана кількість | 1С (синхронізовано) | app/Filament/Resources/StockResource.php, app/Models/ProductVariant.php, app/Models/Stock.php, app/Support/CatalogRowData.php | ✅ |
| `stocks.updated_at` |  | Час оновлення залишку | 1С (синхронізовано) | app/Filament/Resources/StockResource.php, app/Models/Stock.php | ✅ |
| `categories.id` |  | Первинний ключ категорії | Laravel (auto) | app/Filament/Resources/StockResource.php, app/Livewire/Cabinet/Catalog.php, app/Models/Product.php, resources/views/livewire/cabinet/catalog.blade.php | ✅ |
| `categories.onec_guid` |  | GUID категорії в 1С | 1С (синхронізовано) | app/Models/Category.php, app/Models/Product.php | ⚠️ needs review |
| `categories.name` | product_type | Назва категорії | 1С (синхронізовано) | app/Filament/Resources/CategoryResource.php, app/Filament/Resources/StockResource.php, app/Livewire/Cabinet/Catalog.php, app/Models/Category.php, app/Models/Product.php, resources/views/livewire/cabinet/catalog.blade.php | ✅ |
| `categories.parent_id` |  | FK на батьківську категорію | 1С (синхронізовано) | app/Models/Category.php | ⚠️ needs review |
| `categories.stock_display_threshold` |  | Поріг відображення залишку | Вручну (адмін) | app/Filament/Resources/CategoryResource.php, app/Models/Category.php | ✅ |
| `categories.created_at` |  | Час створення | Laravel (auto) | — | ⚠️ needs review |
| `categories.updated_at` |  | Час оновлення | Laravel (auto) | app/Filament/Resources/StockResource.php | ✅ |
| `contractors.id` |  | Первинний ключ контрагента | Laravel (auto) | app/Filament/Resources/ContractorResource.php, app/Filament/Resources/ContractorResource/RelationManagers/OrdersRelationManager.php, app/Models/Product.php, app/Models/ProductVariant.php, app/Support/CatalogRowData.php, app/Support/SessionCart.php | ✅ |
| `contractors.onec_guid` |  | GUID контрагента в 1С | 1С (синхронізовано) | app/Models/Contractor.php, app/Models/Order.php, app/Models/Product.php, app/Models/ProductVariant.php | ⚠️ needs review |
| `contractors.name` |  | Повна назва | 1С (синхронізовано) | app/Filament/Resources/ContractorResource.php, app/Filament/Resources/OrderResource/Pages/ViewOrder.php, app/Models/Contractor.php, app/Models/Product.php, app/Models/User.php, app/Support/SessionCart.php | ✅ |
| `contractors.short_name` |  | Коротка назва | 1С (синхронізовано) | app/Filament/Resources/ContractorResource.php, app/Models/Contractor.php | ✅ |
| `contractors.edrpou` |  | ЄДРПОУ | 1С (синхронізовано) | app/Filament/Resources/ContractorResource.php, app/Models/Contractor.php | ✅ |
| `contractors.ipn` |  | ІПН | 1С (синхронізовано) | app/Filament/Resources/ContractorResource.php, app/Models/Contractor.php | ✅ |
| `contractors.manager_name` |  | Менеджер (текст з 1С) | 1С (синхронізовано) | app/Filament/Resources/ContractorResource.php, app/Filament/Resources/OrderResource/Pages/ViewOrder.php, app/Models/Contractor.php | ✅ |
| `contractors.manager_phone` |  | Телефон менеджера (текст) | 1С (синхронізовано) | app/Filament/Resources/ContractorResource.php, app/Filament/Resources/OrderResource/Pages/ViewOrder.php, app/Models/Contractor.php | ✅ |
| `contractors.login` |  | Логін B2B-кабінету | 1С (синхронізовано) | app/Filament/Resources/ContractorResource.php, app/Http/Middleware/ContractorAuthenticated.php, app/Models/Contractor.php | ✅ |
| `contractors.password` |  | Хеш пароля | Вручну (адмін) | app/Filament/Resources/ContractorResource.php, app/Models/Contractor.php, app/Models/User.php | ✅ |
| `contractors.is_active` |  | Чи активний контрагент | 1С (синхронізовано) | app/Filament/Resources/ContractorResource.php, app/Http/Middleware/ContractorAuthenticated.php, app/Models/Contractor.php, app/Models/Product.php, app/Models/ProductVariant.php, app/Models/User.php (+1) | ✅ |
| `contractors.payment_delay_days` |  | Відстрочка платежу (днів) | 1С (синхронізовано) | app/Filament/Resources/ContractorResource.php, app/Models/Contractor.php | ✅ |
| `contractors.credit_limit` |  | Кредитний ліміт, ₴ | 1С (синхронізовано) | app/Filament/Resources/ContractorResource.php, app/Models/Contractor.php | ✅ |
| `contractors.current_debt` |  | Поточний борг, ₴ | 1С (синхронізовано) | app/Filament/Resources/ContractorResource.php, app/Models/Contractor.php | ✅ |
| `contractors.synced_at` |  | Час останньої синхронізації | 1С (синхронізовано) | app/Models/Contractor.php, app/Models/Product.php, app/Models/ProductVariant.php | ⚠️ needs review |
| `contractors.created_at` |  | Час створення | Laravel (auto) | app/Filament/Resources/ContractorResource/RelationManagers/OrdersRelationManager.php | ✅ |
| `contractors.updated_at` |  | Час оновлення | Laravel (auto) | app/Models/Price.php | ⚠️ needs review |
| `contractors.email` |  | Email контрагента | Вручну (адмін) | app/Filament/Resources/ContractorResource.php, app/Filament/Resources/OrderResource/Pages/ViewOrder.php, app/Models/Contractor.php, app/Models/User.php | ✅ |
| `contractors.account_manager_id` |  | FK users.id — акаунт-менеджер | Вручну (адмін) | app/Filament/Resources/ContractorResource.php, app/Models/Contractor.php | ✅ |
| `contractors.backup_manager_id` |  | FK users.id — резервний менеджер | Вручну (адмін) | app/Filament/Resources/ContractorResource.php, app/Models/Contractor.php | ✅ |
| `orders.id` |  | Первинний ключ замовлення | Laravel (auto) | app/Filament/Resources/ContractorResource.php, app/Filament/Resources/ContractorResource/RelationManagers/OrdersRelationManager.php, app/Filament/Resources/OrderResource.php, app/Livewire/Cabinet/Dashboard.php, app/Support/SessionCart.php, resources/views/filament/cabinet/columns/quantity-order.blade.php (+1) | ✅ |
| `orders.contractor_id` |  | FK на contractors.id | Розраховується (кабінет при створенні) | app/Filament/Resources/OrderResource.php, app/Livewire/Cabinet/Dashboard.php, app/Models/Order.php, app/Support/SessionCart.php | ✅ |
| `orders.user_id` |  | FK на users.id (staff) | Вручну (адмін) | app/Models/Order.php | ⚠️ needs review |
| `orders.onec_guid` |  | GUID в 1С після передачі | 1С (синхронізовано) | app/Models/Contractor.php, app/Models/Order.php | ⚠️ needs review |
| `orders.onec_number` |  | Номер замовлення в 1С | 1С (синхронізовано) | app/Filament/Resources/ContractorResource/RelationManagers/OrdersRelationManager.php, app/Filament/Resources/OrderResource.php, app/Models/Order.php, resources/views/livewire/cabinet/dashboard.blade.php | ✅ |
| `orders.status` |  | Статус (OrderStatus enum) | 1С (синхронізовано) + локально при створенні | app/Filament/Resources/ContractorResource/RelationManagers/OrdersRelationManager.php, app/Filament/Resources/OrderResource.php, app/Models/Order.php, resources/views/livewire/cabinet/dashboard.blade.php | ✅ |
| `orders.total` |  | Сума без ПДВ | Розраховується (при створенні замовлення; кабінет ще не реалізовано — лише seeder) | app/Filament/Resources/OrderResource/RelationManagers/ItemsRelationManager.php, app/Models/Order.php, app/Models/OrderItem.php | ⚠️ needs review |
| `orders.total_with_vat` |  | Сума з ПДВ | Розраховується (при створенні замовлення; кабінет ще не реалізовано — лише seeder) | app/Filament/Resources/ContractorResource/RelationManagers/OrdersRelationManager.php, app/Filament/Resources/OrderResource.php, app/Models/Order.php, resources/views/livewire/cabinet/dashboard.blade.php | ✅ |
| `orders.currency` |  | Валюта | Розраховується (UAH за замовч.) | app/Models/Order.php | ⚠️ needs review |
| `orders.comment` |  | Коментар клієнта | Вручну (кабінет) | app/Filament/Resources/OrderResource.php, app/Models/Order.php | ✅ |
| `orders.manager_comment` |  | Коментар менеджера | Вручну (адмін) | app/Filament/Resources/OrderResource.php, app/Models/Order.php | ✅ |
| `orders.needs_call` |  | Потрібен дзвінок | Вручну (адмін) | app/Filament/Resources/OrderResource.php, app/Models/Order.php | ✅ |
| `orders.transmitted_at` |  | Час передачі в 1С | 1С (синхронізовано) | app/Models/Order.php | ⚠️ needs review |
| `orders.created_at` |  | Дата створення | Laravel (auto) | app/Filament/Resources/ContractorResource/RelationManagers/OrdersRelationManager.php, app/Filament/Resources/OrderResource.php, resources/views/livewire/cabinet/dashboard.blade.php | ✅ |
| `orders.updated_at` |  | Час оновлення | Laravel (auto) | — | ⚠️ needs review |
| `order_items.id` |  | Первинний ключ позиції | Laravel (auto) | — | ⚠️ needs review |
| `order_items.order_id` |  | FK на orders.id | Розраховується (при створенні замовлення; кабінет ще не реалізовано) | app/Models/OrderItem.php | ⚠️ needs review |
| `order_items.variant_id` |  | FK на product_variants.id | Розраховується (з кошика при оформленні; кабінет ще не реалізовано) | app/Models/OrderItem.php, app/Support/SessionCart.php | ⚠️ needs review |
| `order_items.sku` | id | Снапшот артикулу | Розраховується (variant.sku) | app/Filament/Resources/OrderResource/RelationManagers/ItemsRelationManager.php, app/Models/OrderItem.php | ✅ |
| `order_items.name` | title | Снапшот назви | Розраховується (product.name) | app/Filament/Resources/OrderResource/RelationManagers/ItemsRelationManager.php, app/Models/OrderItem.php | ✅ |
| `order_items.attributes` |  | Снапшот атрибутів (JSON) | Розраховується (variant.attributes) | app/Models/OrderItem.php | ⚠️ needs review |
| `order_items.quantity` |  | Кількість | Розраховується (з кошика) | app/Filament/Resources/OrderResource/RelationManagers/ItemsRelationManager.php, app/Models/OrderItem.php | ✅ |
| `order_items.price` |  | Ціна без ПДВ | Розраховується (prices.price) | app/Models/OrderItem.php | ⚠️ needs review |
| `order_items.price_with_vat` | price | Ціна з ПДВ | Розраховується (prices.price_with_vat) | app/Filament/Resources/OrderResource/RelationManagers/ItemsRelationManager.php, app/Models/OrderItem.php | ✅ |
| `order_items.total` |  | Сума позиції | Розраховується (quantity × price_with_vat; кабінет ще не реалізовано) | app/Filament/Resources/OrderResource/RelationManagers/ItemsRelationManager.php, app/Models/Order.php, app/Models/OrderItem.php | ⚠️ needs review |
| `order_items.manager_price` |  | Ціна з перевизначенням | Вручну (адмін) | app/Filament/Resources/OrderResource/RelationManagers/ItemsRelationManager.php, app/Models/OrderItem.php | ✅ |
| `order_items.created_at` |  | Час створення | Laravel (auto) | — | ⚠️ needs review |
| `order_items.updated_at` |  | Час оновлення | Laravel (auto) | — | ⚠️ needs review |
| `reservations.id` |  | Первинний ключ резерву | Laravel (auto) | app/Filament/Cabinet/Resources/ProductResource/Pages/ListProducts.php, app/Livewire/Cabinet/Catalog.php | ✅ |
| `reservations.contractor_id` |  | FK на contractors.id | Розраховується (кабінет) | app/Filament/Cabinet/Resources/ProductResource/Pages/ListProducts.php, app/Filament/Resources/ReservationResource.php, app/Livewire/Cabinet/Catalog.php, app/Models/Reservation.php | ✅ |
| `reservations.variant_id` |  | FK на product_variants.id | Розраховується (кабінет) | app/Filament/Cabinet/Resources/ProductResource/Pages/ListProducts.php, app/Filament/Resources/ReservationResource.php, app/Livewire/Cabinet/Catalog.php, app/Models/Reservation.php | ✅ |
| `reservations.quantity` |  | Зарезервована кількість | Вручну (кабінет) | app/Filament/Cabinet/Resources/ProductResource/Pages/ListProducts.php, app/Filament/Resources/ReservationResource.php, app/Livewire/Cabinet/Catalog.php, app/Models/Reservation.php | ✅ |
| `reservations.status` |  | Статус (ReservationStatus) | Вручну (адмін) + локально | app/Filament/Cabinet/Resources/ProductResource/Pages/ListProducts.php, app/Filament/Resources/ReservationResource.php, app/Livewire/Cabinet/Catalog.php, app/Models/Reservation.php | ✅ |
| `reservations.expires_at` |  | Термін дії | Розраховується (при створенні) | app/Filament/Cabinet/Resources/ProductResource/Pages/ListProducts.php, app/Filament/Resources/ReservationResource.php, app/Livewire/Cabinet/Catalog.php, app/Models/Reservation.php | ✅ |
| `reservations.created_at` |  | Час створення | Laravel (auto) | app/Filament/Resources/ReservationResource.php | ✅ |
| `reservations.updated_at` |  | Час оновлення | Laravel (auto) | — | ⚠️ needs review |

## Обчислювані значення (не колонки БД)

| Метод / ідентифікатор | GMC | Опис | Джерело | Де використовується | Статус |
|---|---|---|---|---|---|
| `Product::minPriceFor()` |  | Мін. ціна з ПДВ серед варіантів для контрагента | Розраховується (`Product::minPriceFor()`) | app/Models/Product.php, app/Support/CatalogRowData.php | ✅ |
| `Product::maxRrp()` |  | Макс. РРЦ серед варіантів | Розраховується (`Product::maxRrp()`) | app/Models/Product.php, app/Support/CatalogRowData.php | ✅ |
| `ProductVariant::priceFor()` |  | Ціна з ПДВ варіанту для контрагента | Розраховується (`ProductVariant::priceFor()`) | app/Models/ProductVariant.php, app/Support/CatalogRowData.php, resources/views/livewire/cabinet/product-detail.blade.php | ✅ |
| `ProductVariant::availabilityBadge()` |  | Бейдж наявності (label, color) | Розраховується (`ProductVariant::availabilityBadge()`, `badgeFromQty()`) | app/Models/ProductVariant.php, app/Support/CatalogRowData.php, resources/views/livewire/cabinet/product-detail.blade.php | ✅ |
| `CatalogRowData::forProduct()` |  | Дані рядка каталогу: badge, maxQty, minQty, step, myPrice, rrp | Розраховується (`CatalogRowData::forProduct()`) | app/Support/CatalogRowData.php, app/Livewire/Cabinet/Catalog.php, resources/views/livewire/cabinet/catalog.blade.php | ✅ |
| `available_qty (stocks)` |  | quantity − reserved, агрегат по складах | Розраховується (`CatalogRowData::variantAvailQty()`) | app/Support/CatalogRowData.php, app/Models/ProductVariant.php | ✅ |

## Поля зі статусом ⚠️ needs review

48 полів існують у схемі, але не знайдені в коді за межами моделі/міграцій (або потребують уточнення джерела).

- `products.onec_guid` — Унікальний GUID товару в 1С. Використання: app/Models/Category.php, app/Models/Product.php, app/Models/ProductVariant.php
- `products.barcode_box` — Штрихкод коробки. Використання: app/Models/Product.php
- `products.unit` — Одиниця виміру. Використання: app/Models/Product.php
- `products.package_quantity` — Кількість в упаковці. Використання: app/Models/Product.php
- `products.package_type` — Тип упаковки. Використання: app/Models/Product.php
- `products.units_per_box` — Одиниць у коробці. Використання: app/Models/Product.php
- `products.boxes_per_pallet` — Коробок на палеті. Використання: app/Models/Product.php
- `products.lead_time_days` — Термін постачання (днів). Використання: app/Models/Product.php
- `products.weight_netto` — Вага нетто, кг. Використання: app/Models/Product.php
- `products.weight_brutto` — Вага брутто, кг. Використання: app/Models/Product.php
- `products.volume_m3` — Обʼєм, м³. Використання: app/Models/Product.php
- `products.depth_mm` — Глибина, мм. Використання: app/Models/Product.php
- `products.width_mm` — Ширина, мм. Використання: app/Models/Product.php
- `products.height_mm` — Висота, мм. Використання: app/Models/Product.php
- `products.description` — Опис товару. Використання: app/Models/Product.php
- `products.rozetka_category_id` — ID категорії Rozetka. Використання: app/Models/Product.php
- `products.meta_title` — SEO title. Використання: app/Models/Product.php
- `products.meta_description` — SEO description. Використання: app/Models/Product.php
- `products.synced_at` — Час останньої синхронізації з 1С. Використання: app/Models/Product.php, app/Models/ProductVariant.php
- `products.created_at` — Час створення запису. Використання: —
- `products.updated_at` — Час оновлення запису. Використання: app/Models/Price.php, app/Models/Stock.php
- `product_variants.onec_guid` — GUID варіанту в 1С. Використання: app/Models/Product.php, app/Models/ProductVariant.php
- `product_variants.synced_at` — Час останньої синхронізації. Використання: app/Models/Product.php, app/Models/ProductVariant.php
- `product_variants.created_at` — Час створення. Використання: —
- `product_variants.updated_at` — Час оновлення. Використання: app/Models/Price.php, app/Models/Stock.php
- `prices.vat_rate` — Ставка ПДВ, %. Використання: app/Models/Price.php
- `prices.min_quantity` — Мін. кількість для цієї ціни. Використання: app/Models/Price.php
- `prices.currency` — Валюта. Використання: app/Models/Price.php
- `prices.updated_at` — Час оновлення ціни. Використання: app/Models/Price.php
- `categories.onec_guid` — GUID категорії в 1С. Використання: app/Models/Category.php, app/Models/Product.php
- `categories.parent_id` — FK на батьківську категорію. Використання: app/Models/Category.php
- `categories.created_at` — Час створення. Використання: —
- `contractors.onec_guid` — GUID контрагента в 1С. Використання: app/Models/Contractor.php, app/Models/Order.php, app/Models/Product.php, app/Models/ProductVariant.php
- `contractors.synced_at` — Час останньої синхронізації. Використання: app/Models/Contractor.php, app/Models/Product.php, app/Models/ProductVariant.php
- `contractors.updated_at` — Час оновлення. Використання: app/Models/Price.php
- `orders.user_id` — FK на users.id (staff). Використання: app/Models/Order.php
- `orders.onec_guid` — GUID в 1С після передачі. Використання: app/Models/Contractor.php, app/Models/Order.php
- `orders.currency` — Валюта. Використання: app/Models/Order.php
- `orders.transmitted_at` — Час передачі в 1С. Використання: app/Models/Order.php
- `orders.updated_at` — Час оновлення. Використання: —
- `order_items.id` — Первинний ключ позиції. Використання: —
- `order_items.order_id` — FK на orders.id. Використання: app/Models/OrderItem.php
- `order_items.variant_id` — FK на product_variants.id. Використання: app/Models/OrderItem.php
- `order_items.attributes` — Снапшот атрибутів (JSON). Використання: app/Models/OrderItem.php
- `order_items.price` — Ціна без ПДВ. Використання: app/Models/OrderItem.php
- `order_items.created_at` — Час створення. Використання: —
- `order_items.updated_at` — Час оновлення. Використання: —
- `reservations.updated_at` — Час оновлення. Використання: —
