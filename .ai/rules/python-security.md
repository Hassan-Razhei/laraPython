---
paths:
  - "python/**"
  - "app/Services/Python/**"
  - "app/Mcp/Tools/Python*"
---

# Python Security Guidelines

## 1. Code Execution Policy
- Prohibit arbitrary user code execution (`eval()`, `exec()`, or dynamic script generation from user input).
- Only execute pre-registered, allowlisted Python scripts stored within designated directories.

## 2. Input Validation and Sanitization
- Strictly validate and type-cast all inputs in Laravel FormRequests or Service layers before passing them to Python processes.
- Never pass sensitive credentials, API keys, or database passwords as command-line arguments (which are visible in `ps` process tables). Use environment variables or secure stdin pipelines.

## 3. Process Isolation and Error Handling
- Never expose raw Python tracebacks or system paths directly to end users.
- Capture `stderr` and non-zero exit codes, convert them into domain-specific Laravel exceptions, and log details through Laravel's secure logging channels.

