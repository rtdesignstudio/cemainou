<?php

// Iconițele sunt din Carbon: se caută întâi în resources/svg/heroicons-carbon (scripts/carbon-icons.php),
// apoi în Heroicons, pentru un nume pe care nu l-am trecut încă în resources/icons/carbon.php.
return [
    'prefix' => 'heroicon',
    'fallback' => '',
    'class' => '',
    'attributes' => [],
    'paths' => [
        resource_path('svg/heroicons-carbon'),
        base_path('vendor/blade-ui-kit/blade-heroicons/resources/svg'),
    ],
];
