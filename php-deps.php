<?php

// php-deps checks itself: php analyze.php --check

return [
    'paths' => ['src'],
    'layers' => [
        'Console' => ['DepsGraph\Console\**'],
        'Architecture' => ['DepsGraph\Architecture\**'],
        'Metrics' => ['DepsGraph\Metrics\**'],
        'Analysis' => ['DepsGraph\*'],
    ],
    'rules' => [
        'Analysis' => [],
        'Metrics' => ['Analysis'],
        'Architecture' => ['Metrics', 'Analysis'],
    ],
    'cycles' => ['level' => 'class'],
];
