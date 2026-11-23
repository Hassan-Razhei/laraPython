<?php

use App\Providers\AppServiceProvider;
use App\Providers\PythonServiceProvider;

return [
    AppServiceProvider::class,
    PythonServiceProvider::class,
    LaraPython\LaraPythonServiceProvider::class,
];
