---
paths:
  - "python/**"
  - "app/Services/Python/**"
  - "config/python.php"
  - "app/Mcp/Tools/Python*"
---

# Python Runtime Guidelines

## 1. Interpreter and Binary Resolution
- Never hardcode `python` or `/usr/bin/python3` directly in application logic.
- Always use `App\Services\Python\PythonPathResolver` to resolve the Python executable and pip binaries.
- The project's local virtual environment (`.venv`) takes precedence over system-wide Python.

## 2. Process Execution Standards
- Always execute Python processes using array arguments with `Illuminate\Support\Facades\Process` (e.g. `Process::run([$python, $script, ...])`).
- Never concatenate raw strings to construct shell commands.
- Always specify an explicit timeout on long-running processes to prevent thread starvation.

## 3. Data Bridge and Encoding
- JSON is the mandatory format for exchanging structured data between PHP and Python.
- All strings, inputs, and outputs must be encoded in UTF-8 with full support for Arabic Unicode characters.
- Large datasets should be passed via temporary file streams or shared memory, not through process argument lists.

