<?php

return [
    'single' => [
        'label' => 'Șterge',
        'modal' => [
            'heading' => 'Ștergi :label?',
            'actions' => ['delete' => ['label' => 'Șterge']],
        ],
        'notifications' => ['deleted' => ['title' => 'Șters']],
    ],
    'multiple' => [
        'label' => 'Șterge ce e selectat',
        'modal' => [
            'heading' => 'Ștergi :label selectate?',
            'actions' => ['delete' => ['label' => 'Șterge']],
        ],
        'notifications' => ['deleted' => ['title' => 'Șterse']],
    ],
];
