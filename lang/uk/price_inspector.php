<?php

return [
    'headline' => [
        'resolved' => 'Ціна знайдена',
        'unavailable' => 'Ціну не знайдено',
        'configuration_error' => 'Потрібно виправити налаштування цін',
    ],

    'outcome' => [
        'resolved' => 'Для вибраного клієнта знайдено чинну ціну.',
        'unavailable' => 'Жодне доступне джерело не повернуло ціну.',
        'configuration_error' => 'Перевірку неможливо завершити через помилку налаштування цін.',
    ],

    'step_outcome' => [
        'used' => 'Використано',
        'not_used' => 'Не використано',
        'not_checked_resolved' => 'Не перевірявся — ціну вже знайдено',
    ],

    'source' => [
        'customer_price_list' => 'Прайс-лист клієнта',
        'workspace_default_price_list' => 'Основний прайс-лист',
        'base_price_cache' => 'Базова ціна',
    ],

    'reason' => [
        'price_list_not_assigned' => 'Прайс-лист не призначено',
        'price_list_inactive' => 'Прайс-лист неактивний',
        'item_missing' => 'Позицію не знайдено',
        'item_inactive' => 'Позиція неактивна',
        'quantity_below_minimum' => 'Кількість нижче мінімуму',
        'not_yet_effective' => 'Ще не набула чинності',
        'expired' => 'Термін дії минув',
        'matched' => 'Збіг',
        'previous_source_resolved' => 'Попереднє джерело вже розв\'язало ціну',
        'all_sources_exhausted' => 'Усі джерела вичерпано',
        'default_price_list_misconfigured' => 'Дефолтний прайс-лист налаштовано некоректно',
    ],

    'explanation' => [
        'customer_price_list' => [
            'price_list_not_assigned' => 'Клієнту не призначено прайс-лист.',
            'price_list_inactive' => 'Призначений прайс-лист «:name» неактивний.',
            'item_missing' => 'Для варіанта :sku немає позиції в прайс-листі клієнта.',
            'item_inactive' => 'Позиція прайс-листа неактивна (статус: :status).',
            'quantity_below_minimum' => 'Мінімальна кількість для цієї позиції — :quantity шт.',
            'not_yet_effective' => 'Ціна набуде чинності з :date.',
            'expired' => 'Ціна діяла до :date.',
            'matched' => 'Знайдено ціну :amount.',
        ],
        'workspace_default_price_list' => [
            'item_missing' => 'Для варіанта :sku немає позиції в основному прайс-листі.',
            'item_inactive' => 'Позиція основного прайс-листа неактивна (статус: :status).',
            'quantity_below_minimum' => 'Мінімальна кількість для цієї позиції — :quantity шт.',
            'not_yet_effective' => 'Ціна набуде чинності з :date.',
            'expired' => 'Ціна діяла до :date.',
            'matched' => 'Знайдено ціну :amount.',
            'previous_source_resolved' => 'Ціну вже знайдено на попередньому етапі.',
            'default_price_list_misconfigured' => 'Основний прайс-лист workspace налаштовано некоректно.',
        ],
        'base_price_cache' => [
            'item_missing' => 'Базову ціну не задано.',
            'matched' => 'Використано базову ціну :amount.',
            'previous_source_resolved' => 'Ціну вже знайдено на попередньому етапі.',
        ],
    ],

    'action' => [
        'extend_validity' => 'Продовжити термін дії',
        'add_item_to_customer_price_list' => 'Додати товар у клієнтський прайс-лист',
        'add_item_to_default_price_list' => 'Додати товар в основний прайс-лист',
        'assign_price_list' => 'Призначити прайс-лист клієнту',
        'open_price_list' => 'Відкрити прайс-лист',
        'open_price_list_item' => 'Відкрити позицію прайс-листа',
        'edit_price_list_item' => 'Редагувати цю позицію прайс-листа',
        'open_product' => 'Відкрити товар',
        'check_quantity' => 'Перевірити ціну для :quantity шт.',
        'set_base_price' => 'Відкрити варіант і задати базову ціну',
        'open_price_list_settings' => 'Відкрити налаштування прайс-листів',
    ],

    'section' => [
        'what_to_fix' => 'Що потрібно виправити',
        'decision_path' => 'Як система перевірила ціну',
        'technical_details' => 'Технічні дані',
        'copy_diagnostics' => 'Копіювати діагностику',
        'copied' => 'Скопійовано',
    ],

    'form' => [
        'check_price' => 'Перевірити ціну',
        'parameters' => 'Параметри перевірки',
        'customer' => 'Клієнт',
        'product_filter' => 'Товар (фільтр)',
        'variant' => 'Варіант',
        'quantity' => 'Кількість',
        'effective_at' => 'Дата/час дії ціни',
        'timezone' => 'Часовий пояс',
        'timezone_hint' => 'Часовий пояс: :timezone',
        'price_checked' => 'Ціну перевірено',
    ],

    'page' => [
        'title' => 'Перевірка ціни для клієнта',
        'subheading' => 'Дізнайтеся, яку ціну отримає клієнт і чому.',
        'navigation' => 'Перевірка ціни',
    ],

    'technical' => [
        'status' => 'Статус',
        'reason_codes' => 'Коди причин',
        'failure' => 'Помилка',
        'price' => 'Ціна (технічно)',
        'context' => 'Контекст',
        'trace' => 'Trace',
        'trace_index' => '#',
        'trace_source' => 'Джерело',
        'trace_status' => 'Статус',
        'trace_reason' => 'Причина',
        'trace_price_list_id' => 'price_list_id',
        'trace_amount' => 'Сума',
        'trace_metadata' => 'Метадані',
    ],

    'opens_in_new_tab' => '(відкривається в новій вкладці)',
];
