@echo off
setlocal

cd /d "%~dp0"

set "PHP_EXE=C:\xampp\php\php.exe"
if not exist "%PHP_EXE%" set "PHP_EXE=php"

set "PID_FILE=%~dp0logs\websocket.pid"
if not exist "%~dp0logs" mkdir "%~dp0logs" >nul 2>&1

powershell -NoProfile -ExecutionPolicy Bypass -Command ^
  "$proc = Start-Process -FilePath '%PHP_EXE%' -ArgumentList '%~dp0websocket-server.php' -WorkingDirectory '%~dp0' -PassThru; Set-Content -Path '%PID_FILE%' -Value $proc.Id"

echo WebSocket server started.
echo PID file: %PID_FILE%
pause
