@echo off
set XAMPP_DIR=C:\xampp

:: Apache check aur background mein start
tasklist /FI "IMAGENAME eq httpd.exe" 2>NUL | find /I /N "httpd.exe">NUL
if "%ERRORLEVEL%"=="1" (
    start /B "" "%XAMPP_DIR%\apache\bin\httpd.exe"
)

:: MariaDB check aur background mein start
tasklist /FI "IMAGENAME eq mysqld.exe" 2>NUL | find /I /N "mysqld.exe">NUL
if "%ERRORLEVEL%"=="1" (
    start /B "" "%XAMPP_DIR%\mysql\bin\mysqld.exe" --defaults-file="%XAMPP_DIR%\mysql\bin\my.ini" --standalone
)

:: 1 second ka pause taake database connect ho jaye
timeout /t 1 /nobreak >nul

:: Direct Chrome app window mein kholne ke liye (Professional App Look)
start chrome.exe --app="http://localhost/pharmacy_software/auth/login.php"

exit