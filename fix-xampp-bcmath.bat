@echo off
echo ============================================
echo  Fixing bcmath extension for XAMPP on Windows
echo ============================================

:: Find php.ini
set PHP_INI=C:\xampp\php\php.ini

if not exist "%PHP_INI%" (
    echo ERROR: php.ini not found at %PHP_INI%
    echo Please edit php.ini manually and uncomment: extension=php_bcmath.dll
    pause
    exit /b 1
)

:: Uncomment extension=php_bcmath.dll using PowerShell
powershell -Command "(Get-Content '%PHP_INI%') -replace ';extension=php_bcmath.dll', 'extension=php_bcmath.dll' | Set-Content '%PHP_INI%'"
powershell -Command "(Get-Content '%PHP_INI%') -replace ';extension=bcmath', 'extension=bcmath' | Set-Content '%PHP_INI%'"

echo.
echo Done! Now:
echo  1. Open XAMPP Control Panel
echo  2. Stop Apache and Start Apache again
echo  3. Run: php -m | findstr bcmath
echo     (you should see "bcmath" in the output)
echo.
pause
