<?php

return [
    'consent_version' => '2026-10-11.1',
    'privacy_version' => '2026-10-11.1',
    'operator_name' => env('SERVICE_OPERATOR_NAME', 'Кочергин Владимир Михайлович'),
    'operator_inn' => env('SERVICE_OPERATOR_INN', '242022368804'),
    'operator_address' => env('SERVICE_OPERATOR_ADDRESS', ''),
    'privacy_email' => env('SERVICE_PRIVACY_EMAIL', 'kochergin.vladim@mail.ru'),
    'receipt_mode' => env('YOOKASSA_RECEIPT_MODE', 'npd_manual'),
    'templates' => [
        'ppd-lkr' => [
            'name' => 'Личная карточка работника', 'manufacturer' => 'ООО «Первый печатный двор»', 'preview_image' => 'templates/ppd-lkr.png', 'series' => 'ЛКР', 'version' => '3.0',
            'width' => 210, 'height' => 148, 'crop' => [44, 75, 120, 73],
            // Coordinates measured from the upper left of the untrimmed A5 sheet. Verify on physical stock.
            'fields' => [
                'surname' => [92.2, 96, 61.5], 'given_names' => [92.2, 101, 61.5],
                'organization_type' => [92.2, 108.2, 61.5], 'organization_name' => [92.2, 115.2, 61.5],
                'director' => [92.2, 122.2, 61.5], 'issue_date' => [126.2, 135.5, 24],
            ],
        ],
        'ppd-lko' => [
            'name' => 'Личная карточка работника', 'manufacturer' => 'ООО «Первый печатный двор»', 'preview_image' => 'templates/ppd-lko.png', 'series' => 'ЛКО', 'version' => '2.0',
            'width' => 210, 'height' => 148, 'crop' => [44, 75, 120, 73],
            // Coordinates measured from the upper left of the untrimmed A5 sheet. Verify on physical stock.
            'fields' => [
                'surname' => [92.2, 97, 61.5], 'given_names' => [92.2, 102, 61.5],
                'organization_type' => [92.2, 108.2, 61.5], 'organization_name' => [92.2, 115.2, 61.5],
                'director' => [92.2, 122.2, 61.5], 'issue_date' => [126.2, 136.2, 24],
            ],
        ],
    ],
    'packages' => [
        'start' => ['name' => 'Стартовый', 'credits' => 25, 'amount' => 49000],
        'standard' => ['name' => 'Рабочий', 'credits' => 50, 'amount' => (int) env('PACKAGE_50_AMOUNT', 89000)],
        'business' => ['name' => 'Расширенный', 'credits' => 100, 'amount' => 149000],
    ],
    'payments_enabled' => (bool) env('PAYMENTS_ENABLED', false),
    'yookassa' => ['shop_id' => env('YOOKASSA_SHOP_ID'), 'secret' => env('YOOKASSA_SECRET_KEY') ?: env('YOOKASSA_SECRET'), 'test' => (bool) env('YOOKASSA_TEST', true), 'vat_code' => (int) env('YOOKASSA_VAT_CODE', 1)],
];
