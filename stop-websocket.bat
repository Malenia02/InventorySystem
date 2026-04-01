@echo off
setlocal

set "PID_FILE=%~dp0logs\websocket.pid"

if exist "%PID_FILE%" (
    set /p WS_PID=<"%PID_FILE%"
    if not "%WS_PID%"=="" (
        taskkill /F /T /PID %WS_PID% >nul 2>&1
        if not errorlevel 1 (
            del "%PID_FILE%" >nul 2>&1
            echo WebSocket server stopped.
            pause
            exit /b 0
        )
    )
)

taskkill /F /T /IM php.exe >nul 2>&1

if errorlevel 1 (
    echo No running WebSocket server was found.
) else (
    echo WebSocket server stopped.
)

if exist "%PID_FILE%" del "%PID_FILE%" >nul 2>&1

pause
