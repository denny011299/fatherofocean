<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Commercial invoice / packing list Excel (container export)
    |--------------------------------------------------------------------------
    */

    'template' => env('PACKING_LIST_TEMPLATE_PATH')
        ?: resource_path('excel/packing-list-commercial-invoice-template.xls'),

    'exporter_company_name' => env('PACKING_LIST_EXPORTER_NAME', 'WENZHOU PALI IMPORT AND EXPORT CO.,LTD'),

    'exporter_address' => env(
        'PACKING_LIST_EXPORTER_ADDRESS',
        'ZONE A25 RM2001, DEVELOPMENT BLDG, XIN CHENG AVENUE, LUCHENG, WENZHOU',
    ),

    'default_consignee' => env(
        'PACKING_LIST_DEFAULT_CONSIGNEE',
        'PT. GADING DELTA INDONESIA JALAN KALIBARU TIMUR NO. 08 B KEL. BANDARHARJO, SEMARANG',
    ),

    'term' => env('PACKING_LIST_TERM', 'CNF'),

    'from_port' => env('PACKING_LIST_FROM_PORT', 'NANSHA, CHINA'),

    'default_to_port' => env('PACKING_LIST_TO_PORT', 'SEMARANG, INDONESIA'),

    'unit_price_column_header' => "Unit Price\n(RMB)",

    'amount_column_header' => "Amount\n(RMB)",

    'photo_column_header' => "Photo\n品图",

    'photo_column_width' => 14,

    'photo_row_height_pt' => 72,

    'photo_image_height_px' => 88,

];
