<?php

return [
    'headline' => [
        'resolved' => 'Цена найдена',
        'unavailable' => 'Цену не найдено',
        'configuration_error' => 'Нужно исправить настройки цен',
    ],

    'outcome' => [
        'resolved' => 'Для выбранного клиента найдена действующая цена.',
        'unavailable' => 'Ни один доступный источник не вернул цену.',
        'configuration_error' => 'Проверку невозможно завершить из-за ошибки настройки цен.',
    ],

    'step_outcome' => [
        'used' => 'Использовано',
        'not_used' => 'Не использовано',
        'not_checked_resolved' => 'Не проверялся — цена уже найдена',
    ],

    'source' => [
        'customer_price_list' => 'Прайс-лист клиента',
        'workspace_default_price_list' => 'Основной прайс-лист',
        'base_price_cache' => 'Базовая цена',
    ],

    'reason' => [
        'price_list_not_assigned' => 'Прайс-лист не назначен',
        'price_list_inactive' => 'Прайс-лист неактивен',
        'item_missing' => 'Позицию не найдено',
        'item_inactive' => 'Позиция неактивна',
        'quantity_below_minimum' => 'Количество ниже минимума',
        'not_yet_effective' => 'Ещё не вступила в силу',
        'expired' => 'Срок действия истёк',
        'matched' => 'Совпадение',
        'previous_source_resolved' => 'Предыдущий источник уже разрешил цену',
        'all_sources_exhausted' => 'Все источники исчерпаны',
        'default_price_list_misconfigured' => 'Дефолтный прайс-лист настроен некорректно',
    ],

    'explanation' => [
        'customer_price_list' => [
            'price_list_not_assigned' => 'Клиенту не назначен прайс-лист.',
            'price_list_inactive' => 'Назначенный прайс-лист «:name» неактивен.',
            'item_missing' => 'Для варианта :sku нет позиции в прайс-листе клиента.',
            'item_inactive' => 'Позиция прайс-листа неактивна (статус: :status).',
            'quantity_below_minimum' => 'Минимальное количество для этой позиции — :quantity шт.',
            'not_yet_effective' => 'Цена вступит в силу с :date.',
            'expired' => 'Цена действовала до :date.',
            'matched' => 'Найдена цена :amount.',
        ],
        'workspace_default_price_list' => [
            'item_missing' => 'Для варианта :sku нет позиции в основном прайс-листе.',
            'item_inactive' => 'Позиция основного прайс-листа неактивна (статус: :status).',
            'quantity_below_minimum' => 'Минимальное количество для этой позиции — :quantity шт.',
            'not_yet_effective' => 'Цена вступит в силу с :date.',
            'expired' => 'Цена действовала до :date.',
            'matched' => 'Найдена цена :amount.',
            'previous_source_resolved' => 'Цену уже найдено на предыдущем этапе.',
            'default_price_list_misconfigured' => 'Основной прайс-лист workspace настроен некорректно.',
        ],
        'base_price_cache' => [
            'item_missing' => 'Базовая цена не задана.',
            'matched' => 'Использована базовая цена :amount.',
            'previous_source_resolved' => 'Цену уже найдено на предыдущем этапе.',
        ],
    ],

    'action' => [
        'extend_validity' => 'Продлить срок действия',
        'add_item_to_customer_price_list' => 'Добавить товар в клиентский прайс-лист',
        'add_item_to_default_price_list' => 'Добавить товар в основной прайс-лист',
        'assign_price_list' => 'Назначить прайс-лист клиенту',
        'open_price_list' => 'Открыть прайс-лист',
        'open_price_list_item' => 'Открыть позицию прайс-листа',
        'edit_price_list_item' => 'Редактировать эту позицию прайс-листа',
        'open_product' => 'Открыть товар',
        'check_quantity' => 'Проверить цену для :quantity шт.',
        'set_base_price' => 'Открыть вариант и задать базовую цену',
        'open_price_list_settings' => 'Открыть настройки прайс-листов',
    ],

    'section' => [
        'what_to_fix' => 'Что нужно исправить',
        'decision_path' => 'Как система проверила цену',
        'technical_details' => 'Технические данные',
        'copy_diagnostics' => 'Копировать диагностику',
        'copied' => 'Скопировано',
    ],

    'form' => [
        'check_price' => 'Проверить цену',
        'parameters' => 'Параметры проверки',
        'customer' => 'Клиент',
        'product_filter' => 'Товар (фильтр)',
        'variant' => 'Вариант',
        'quantity' => 'Количество',
        'effective_at' => 'Дата/время действия цены',
        'timezone' => 'Часовой пояс',
        'timezone_hint' => 'Часовой пояс: :timezone',
        'price_checked' => 'Цену проверено',
    ],

    'page' => [
        'title' => 'Проверка цены для клиента',
        'subheading' => 'Узнайте, какую цену получит клиент и почему.',
        'navigation' => 'Проверка цены',
    ],

    'technical' => [
        'status' => 'Статус',
        'reason_codes' => 'Коды причин',
        'failure' => 'Ошибка',
        'price' => 'Цена (технически)',
        'context' => 'Контекст',
        'trace' => 'Trace',
        'trace_index' => '#',
        'trace_source' => 'Источник',
        'trace_status' => 'Статус',
        'trace_reason' => 'Причина',
        'trace_price_list_id' => 'price_list_id',
        'trace_amount' => 'Сумма',
        'trace_metadata' => 'Метаданные',
    ],

    'opens_in_new_tab' => '(открывается в новой вкладке)',
];
