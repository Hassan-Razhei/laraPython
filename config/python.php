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

    /*
    |--------------------------------------------------------------------------
    | Allowed Python Scripts Directory
    |--------------------------------------------------------------------------
    |
    | The base directory where executable Python scripts reside. The security
    | validator enforces that all relative script requests resolve within
    | this directory, preventing path traversal attacks.
    |
    */

    'scripts_path' => env('PYTHON_SCRIPTS_PATH', base_path('python/scripts')),

    /*
    |--------------------------------------------------------------------------
    | Python Libraries / Modules Directory
    |--------------------------------------------------------------------------
    |
    | Directory containing shared Python libraries (e.g. larabridge).
    | Automatically added to PYTHONPATH during execution.
    |
    */

    'lib_path' => env('PYTHON_LIB_PATH', base_path('python/lib')),

    /*
    |--------------------------------------------------------------------------
    | Default Execution Timeout
    |--------------------------------------------------------------------------
    |
    | Default timeout in seconds for executing Python processes.
    |
    */

    'timeout' => (int) env('PYTHON_TIMEOUT', 60),

];
