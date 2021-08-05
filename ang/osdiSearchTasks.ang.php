<?php
// Declare an Angular module
return [
  'js' => [
    'ang/osdiSearchTasks.js',
    'ang/osdiSearchTasks/*.js',
    'ang/osdiSearchTasks/*/*.js',
  ],
  'partials' => [
    'ang/osdiSearchTasks',
  ],
  'requires' => [
    //'crmUi',
    //'crmUtil',
    //'ngRoute',
    'api4',
  ],
];
