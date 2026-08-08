@echo off
setlocal
set SCRIPT_DIR=%~dp0
"C:\laragon\bin\php\php-8.3.26-Win32-vs16-x64\php.exe" "%SCRIPT_DIR%backend\install.php"
pause
