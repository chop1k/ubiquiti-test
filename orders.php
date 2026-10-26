<?php

// Файл намеренно без синтаксиса PHP 8: на старой версии должна сработать проверка
// платформы Composer с понятным сообщением, а не ошибка разбора.
$autoload = __DIR__ . '/vendor/autoload.php';
if (!file_exists($autoload)) {
    fwrite(STDERR, "Не найден vendor/autoload.php. Выполните composer install.\n");
    exit(1);
}
require $autoload;

exit(main($argv));
