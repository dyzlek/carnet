@echo off
rem Lance l'app de suivi muscu sur http://localhost:8000
rem (accessible aussi depuis ton telephone sur le meme Wi-Fi : http://IP-DU-PC:8000)
cd /d "%~dp0"
echo.
echo   Muscu  ^>  http://localhost:8000
echo   Ctrl+C pour arreter.
echo.
start "" http://localhost:8000
php -d extension=pdo_sqlite -S 0.0.0.0:8000 router.php
