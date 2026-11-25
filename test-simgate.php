<?php
try {
    $response = file_get_contents("https://api.simgate.app");
    echo "HTTPS SUCCESS";
} catch (Throwable $e) {
    echo get_class($e) . PHP_EOL;
    echo $e->getMessage();
}
