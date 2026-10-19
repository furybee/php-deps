<?php

// Copy to the project root as php-deps.php, then: php analyze.php --check

return [
    'paths' => ['src'],

    // FQCN globs: `*` stays within one namespace segment, `**` crosses segments.
    // A class belongs to the first layer it matches, classes in no layer are not checked.
    'layers' => [
        // Before Infrastructure: providers are composition roots and wire every layer together
        'Provider' => ['**\Infrastructure\Providers\**'],
        'Domain' => ['**\Domain\**'],
        'Application' => ['**\Application\**'],
        'Infrastructure' => ['**\Infrastructure\**'],
        'Presentation' => ['**\Presentation\**'],
    ],

    // Layers each layer may depend on, besides itself. A layer without an entry is unrestricted.
    'rules' => [
        'Domain' => [],
        'Application' => ['Domain'],
        'Infrastructure' => ['Domain', 'Application'],
        'Presentation' => ['Domain', 'Application'],
    ],

    // Fail on dependency cycles. Remove to only check layers.
    'cycles' => [
        'level' => 'namespace',            // or 'class'
        'depth' => 0,                      // namespace level: group on the first N segments, 0 = full namespace
        'scope' => ['**'],                 // classes checked
        'ignore' => ['**\Providers\**'],   // composition roots wire everything together
    ],

    'baseline' => 'php-deps-baseline.json',
];
