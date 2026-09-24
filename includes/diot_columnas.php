<?php
/**
 * Catálogo visual de las 54 columnas de la DIOT 2025.
 * Fuente: archivo de ejemplo SAT incluido en el proyecto (diot Ejemplo 2025.xlsx).
 *
 * - corto: texto compacto para la pantalla.
 * - descripcion: descripción completa para tooltip y exportación Excel.
 * - grupo: bloque visual al que pertenece la columna.
 */
return [
    1  => ['corto' => 'Tipo tercero',          'descripcion' => "Tipo de tercero\n\n04 - Nacional\n05 - Extranjero\n15 - Global", 'grupo' => 'Datos del tercero declarado'],
    2  => ['corto' => 'Tipo operación',        'descripcion' => "Tipo de operación\n\n02 - Enaj. de Bienes\n03 - Prest. de Serv. Prof.\n06 - Uso o goce temp. de bienes\n85 - Otros\n87 - Ope. globales", 'grupo' => 'Datos del tercero declarado'],
    3  => ['corto' => 'RFC',                   'descripcion' => 'RFC', 'grupo' => 'Datos del tercero declarado'],
    4  => ['corto' => 'ID fiscal',             'descripcion' => 'Número de identificación fiscal', 'grupo' => 'Datos del tercero declarado'],
    5  => ['corto' => 'Nombre extranjero',     'descripcion' => 'Nombre del extranjero', 'grupo' => 'Datos del tercero declarado'],
    6  => ['corto' => 'País / jurisdicción',   'descripcion' => 'País o jurisdicción de residencia fiscal', 'grupo' => 'Datos del tercero declarado'],
    7  => ['corto' => 'Lugar jurisdicción',    'descripcion' => 'Especificar lugar de jurisdicción fiscal', 'grupo' => 'Datos del tercero declarado'],

    8  => ['corto' => 'Actos pagados RFN',     'descripcion' => 'Actos o actividades pagados en la (RFN)', 'grupo' => 'Valor de actos o actividades'],
    9  => ['corto' => 'Dev./desc. RFN',        'descripcion' => 'Devoluciones, descuentos y bonificaciones (RFN)', 'grupo' => 'Valor de actos o actividades'],
    10 => ['corto' => 'Actos pagados RFS',     'descripcion' => 'Actos o actividades pagados en la (RFS)', 'grupo' => 'Valor de actos o actividades'],
    11 => ['corto' => 'Dev./desc. RFS',        'descripcion' => 'Devoluciones, descuentos y bonificaciones (RFS)', 'grupo' => 'Valor de actos o actividades'],
    12 => ['corto' => 'Base 16%',              'descripcion' => 'Base 16%', 'grupo' => 'Valor de actos o actividades'],
    13 => ['corto' => 'Dev./desc. 16%',        'descripcion' => 'Devoluciones, descuentos y bonificaciones 16%', 'grupo' => 'Valor de actos o actividades'],
    14 => ['corto' => 'Import. tangible 16%',  'descripcion' => 'Importaciones tangibles 16%', 'grupo' => 'Valor de actos o actividades'],
    15 => ['corto' => 'Dev. import. tangible', 'descripcion' => 'Devoluciones, descuentos y bonificaciones Importaciones tangibles 16%', 'grupo' => 'Valor de actos o actividades'],
    16 => ['corto' => 'Import. intangible 16%','descripcion' => 'Importaciones Intangibles 16%', 'grupo' => 'Valor de actos o actividades'],
    17 => ['corto' => 'Dev. import. intangible','descripcion' => 'Devoluciones, descuentos y bonificaciones Importaciones Intangibles 16%', 'grupo' => 'Valor de actos o actividades'],

    18 => ['corto' => 'IVA acreditable RFN',   'descripcion' => 'Exclusivamente de actividades gravadas (Monto del IVA pagado acreditable en la RFN)', 'grupo' => 'IVA acreditable'],
    19 => ['corto' => 'IVA proporción RFN',    'descripcion' => 'Asociado actividades por las cuales se aplicó una proporción (RFN)', 'grupo' => 'IVA acreditable'],
    20 => ['corto' => 'IVA acreditable RFS',   'descripcion' => 'Exclusivamente de actividades gravadas (Monto del IVA pagado acreditable en la RFS)', 'grupo' => 'IVA acreditable'],
    21 => ['corto' => 'IVA proporción RFS',    'descripcion' => 'Asociado actividades por las cuales se aplicó una proporción (RFS)', 'grupo' => 'IVA acreditable'],
    22 => ['corto' => 'IVA acreditable 16%',   'descripcion' => 'Exclusivamente de actividades gravadas (Monto del IVA pagado acreditable a la tasa del 16%)', 'grupo' => 'IVA acreditable'],
    23 => ['corto' => 'IVA proporción 16%',    'descripcion' => 'Asociado actividades por las cuales se aplicó una proporción (base 16%)', 'grupo' => 'IVA acreditable'],
    24 => ['corto' => 'IVA import. tangible',  'descripcion' => 'Exclusivamente de actividades gravadas (Monto del IVA pagado acreditable en la importación por aduana de bienes tangibles a la tasa del 16%)', 'grupo' => 'IVA acreditable'],
    25 => ['corto' => 'IVA prop. imp. tangible','descripcion' => 'Asociado actividades por las cuales se aplicó una proporción (Monto del IVA pagado acreditable en la importación por aduana de bienes tangibles a la tasa del 16%)', 'grupo' => 'IVA acreditable'],
    26 => ['corto' => 'IVA import. intangible','descripcion' => 'Exclusivamente de actividades gravadas (Monto del IVA pagado acreditable en la importación de bienes intangibles y servicios a la tasa del 16%)', 'grupo' => 'IVA acreditable'],
    27 => ['corto' => 'IVA prop. imp. intangible','descripcion' => 'Asociado actividades por las cuales se aplicó una proporción (Monto del IVA pagado acreditable en la importación de bienes intangibles y servicios a la tasa del 16%)', 'grupo' => 'IVA acreditable'],

    28 => ['corto' => 'No acred. prop. RFN',   'descripcion' => 'Asociado a actividades por las cuales se aplicó una proporción (Monto del IVA pagado no acreditable en la RFN)', 'grupo' => 'IVA no acreditable'],
    29 => ['corto' => 'No acred. req. RFN',    'descripcion' => 'Asociado a actividades que no cumple con requisitos (Monto del IVA pagado no acreditable en la RFN)', 'grupo' => 'IVA no acreditable'],
    30 => ['corto' => 'No acred. exento RFN',  'descripcion' => 'Asociado a actividades exentas (Monto del IVA pagado no acreditable en la RFN)', 'grupo' => 'IVA no acreditable'],
    31 => ['corto' => 'No acred. no objeto RFN','descripcion' => 'Asociado a actividades no objeto (Monto del IVA pagado no acreditable en la RFN)', 'grupo' => 'IVA no acreditable'],
    32 => ['corto' => 'No acred. prop. RFS',   'descripcion' => 'Asociado a actividades por las cuales se aplicó una proporción (Monto del IVA pagado no acreditable en la RFS)', 'grupo' => 'IVA no acreditable'],
    33 => ['corto' => 'No acred. req. RFS',    'descripcion' => 'Asociado a que no cumple con requisitos (Monto del IVA pagado no acreditable en la RFS)', 'grupo' => 'IVA no acreditable'],
    34 => ['corto' => 'No acred. exento RFS',  'descripcion' => 'Asociado a actividades exentas (Monto del IVA pagado no acreditable en la RFS)', 'grupo' => 'IVA no acreditable'],
    35 => ['corto' => 'No acred. no objeto RFS','descripcion' => 'Asociado a actividades no objeto (Monto del IVA pagado no acreditable en la RFS)', 'grupo' => 'IVA no acreditable'],
    36 => ['corto' => 'No acred. prop. 16%',   'descripcion' => 'Asociado a actividades por las cuales se aplicó una proporción (Monto del IVA pagado no acreditable a la tasa del 16%)', 'grupo' => 'IVA no acreditable'],
    37 => ['corto' => 'No acred. req. 16%',    'descripcion' => 'Asociado a que no cumple con requisitos (Monto del IVA pagado no acreditable a la tasa del 16%)', 'grupo' => 'IVA no acreditable'],
    38 => ['corto' => 'No acred. exento 16%',  'descripcion' => 'Asociado a actividades exentas (Monto del IVA pagado no acreditable a la tasa del 16%)', 'grupo' => 'IVA no acreditable'],
    39 => ['corto' => 'No acred. no objeto 16%','descripcion' => 'Asociado a actividades no objeto (Monto del IVA pagado no acreditable a la tasa del 16%)', 'grupo' => 'IVA no acreditable'],
    40 => ['corto' => 'No acred. prop. imp. tang.','descripcion' => 'Asociado a actividades por las cuales se aplicó una proporción (Monto del IVA pagado no acreditable en la importación por aduana de bienes tangibles a la tasa del 16%)', 'grupo' => 'IVA no acreditable'],
    41 => ['corto' => 'No acred. req. imp. tang.','descripcion' => 'Asociado a que no cumple con requisitos (Monto del IVA pagado no acreditable en la importación por aduana de bienes tangibles a la tasa del 16%)', 'grupo' => 'IVA no acreditable'],
    42 => ['corto' => 'No acred. exento imp. tang.','descripcion' => 'Asociado a actividades exentas (Monto del IVA pagado no acreditable en la importación por aduana de bienes tangibles a la tasa del 16%)', 'grupo' => 'IVA no acreditable'],
    43 => ['corto' => 'No acred. no obj. imp. tang.','descripcion' => 'Asociado a actividades no objeto (Monto del IVA pagado no acreditable en la importación por aduana de bienes tangibles a la tasa del 16%)', 'grupo' => 'IVA no acreditable'],
    44 => ['corto' => 'No acred. prop. imp. intang.','descripcion' => 'Asociado a actividades por las cuales se aplicó una proporción (Monto del IVA pagado no acreditable en la importación de bienes intangibles y servicios a la tasa del 16%)', 'grupo' => 'IVA no acreditable'],
    45 => ['corto' => 'No acred. req. imp. intang.','descripcion' => 'Asociado a que no cumple con requisitos (Monto del IVA pagado no acreditable en la importación de bienes intangibles y servicios a la tasa del 16%)', 'grupo' => 'IVA no acreditable'],
    46 => ['corto' => 'No acred. exento imp. intang.','descripcion' => 'Asociado a actividades exentas (Monto del IVA pagado no acreditable en la importación de bienes intangibles y servicios a la tasa del 16%)', 'grupo' => 'IVA no acreditable'],
    47 => ['corto' => 'No acred. no obj. imp. intang.','descripcion' => 'Asociado a actividades no objeto (Monto del IVA pagado no acreditable en la importación de bienes intangibles y servicios a la tasa del 16%)', 'grupo' => 'IVA no acreditable'],

    48 => ['corto' => 'IVA retenido',          'descripcion' => 'IVA retenido por el contribuyente', 'grupo' => 'Datos adicionales'],
    49 => ['corto' => 'Importación exenta',    'descripcion' => 'Actos o actividades pagados en la importación de bienes y servicios por los que no se pagará el IVA (Exentos)', 'grupo' => 'Datos adicionales'],
    50 => ['corto' => 'Exentos',               'descripcion' => 'EXENTOS', 'grupo' => 'Datos adicionales'],
    51 => ['corto' => 'Base 0%',               'descripcion' => 'BASE 0%', 'grupo' => 'Datos adicionales'],
    52 => ['corto' => 'No objeto',             'descripcion' => 'No Objeto', 'grupo' => 'Datos adicionales'],
    53 => ['corto' => 'No objeto sin estab.',  'descripcion' => 'No objeto del IVA por no contar con establecimiento en territorio nacional', 'grupo' => 'Datos adicionales'],
    54 => ['corto' => 'Manifiesto',            'descripcion' => 'Manifiesto que se dio efectos fiscales a los comprobantes que amparan las operaciones realizadas', 'grupo' => 'Datos adicionales'],
];
