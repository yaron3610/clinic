@echo off
rem Starts the local copy of shovalclinic.co.il at http://localhost:8080
rem (MariaDB on port 3307 + PHP built-in server). Close this window to stop PHP.
setlocal
set "HERE=%~dp0"

tasklist /FI "IMAGENAME eq mariadbd.exe" | find /I "mariadbd.exe" >nul
if errorlevel 1 (
  echo Starting MariaDB...
  start "MariaDB (shovalclinic local)" /MIN "%HERE%mariadb\bin\mariadbd.exe" --defaults-file="%HERE%mariadb-data\my.ini" --console
  timeout /t 4 /nobreak >nul
)

echo Site: http://localhost:8080
"%HERE%php\php.exe" -S localhost:8080 -t "%HERE%..\site" "%HERE%router.php"
