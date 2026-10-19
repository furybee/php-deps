<?php

return [
    'paths' => ['src'],
    'layers' => [
        'Domain' => ['Shop\*\Domain\**'],
        'Application' => ['Shop\*\Application\**'],
        'Infrastructure' => ['Shop\*\Infrastructure\**'],
    ],
    'rules' => [
        'Domain' => [],
        'Application' => ['Domain'],
        'Infrastructure' => ['Domain', 'Application'],
    ],
    'baseline' => 'baseline.json',
];
