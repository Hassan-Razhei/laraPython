<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Python Executable Paths
    |--------------------------------------------------------------------------
    |
    | These options allow you to specify custom paths for the Python executable
    | and virtual environment. When undefined, the resolver discovers system
    | defaults or utilizes the project's local virtual environment.
    |
    */

    'executable_paths' => [
        'python' => env('PYTHON_EXECUTABLE_PATH', 'python3'),
        'venv' => env('PYTHON_VENV_PATH', base_path('.venv')),
    ],

];

