@echo off
setlocal

cd /d "%~dp0"

set "PHP_EXE=C:\xampp\php\php.exe"
if not exist "%PHP_EXE%" set "PHP_EXE=php"

set "PID_FILE=%~dp0logs\websocket.pid"
if not exist "%~dp0logs" mkdir "%~dp0logs" >nul 2>&1

if exist "%PID_FILE%" (
    set /p WS_PID=<"%PID_FILE%"
    if not "%WS_PID%"=="" (
        powershell -NoProfile -ExecutionPolicy Bypass -Command ^
          "$proc = Get-Process -Id %WS_PID% -ErrorAction SilentlyContinue; if ($proc) { exit 0 } else { exit 1 }"
        if not errorlevel 1 (
            echo WebSocket server is already running with PID %WS_PID%.
            pause
            exit /b 0
        )
    )
    del "%PID_FILE%" >nul 2>&1
)

for /f %%I in ('powershell -NoProfile -ExecutionPolicy Bypass -Command "(Get-Date).ToString(\"yyyyMMdd-HHmmss\")"') do set "WS_STAMP=%%I"
set "OUT_LOG=%~dp0logs\websocket-%WS_STAMP%.out.log"
set "ERR_LOG=%~dp0logs\websocket-%WS_STAMP%.err.log"

powershell -NoProfile -ExecutionPolicy Bypass -Command ^
  "$existing = Get-NetTCPConnection -LocalPort 8080 -State Listen -ErrorAction SilentlyContinue | Select-Object -First 1; if ($existing) { Write-Host 'Port 8080 is already in use by another process.'; exit 1 }; $outLog = '%OUT_LOG%'; $errLog = '%ERR_LOG%'; $proc = Start-Process -FilePath '%PHP_EXE%' -ArgumentList '%~dp0websocket-server.php' -WorkingDirectory '%~dp0' -WindowStyle Hidden -RedirectStandardOutput $outLog -RedirectStandardError $errLog -PassThru; Start-Sleep -Seconds 2; if ($proc.HasExited) { Write-Host 'WebSocket server failed to start.'; if (Test-Path $errLog) { Get-Content $errLog }; exit 1 }; Set-Content -Path '%PID_FILE%' -Value $proc.Id"

if errorlevel 1 (
    echo WebSocket server failed to start. Check:
    echo   %OUT_LOG%
    echo   %ERR_LOG%
    pause
    exit /b 1
)

echo WebSocket server started.
echo PID file: %PID_FILE%
echo Logs:
echo   %OUT_LOG%
echo   %ERR_LOG%
pause
