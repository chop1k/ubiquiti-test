<?php

declare(strict_types=1);

const EXIT_OK = 0;
const EXIT_FATAL = 1;
const EXIT_PARTIAL = 2;

const REQUIRED_FIELDS = ['id', 'customer', 'amount', 'status'];

const USAGE = <<<TXT
Использование: php orders.php [--strict] [--no-round] [--output=FILE] < orders.json

  --strict         остановиться на первом некорректном заказе
                   или при переполнении итоговой суммы
  --no-round       не округлять дробные суммы до 2 знаков при выводе
  --output=FILE    сохранить результат обработки в JSON-файл
  -h, --help       показать эту справку

TXT;

enum OrderStatus: string
{
    case Paid = 'paid';
    case Pending = 'pending';
    case Cancelled = 'cancelled';
    case Refunded = 'refunded';
}

final class Order
{
    public function __construct(
        public readonly int $id,
        public readonly string $customer,
        public readonly int|float $amount,
        public readonly OrderStatus $status,
    ) {
    }
}

final class FatalErrorException extends RuntimeException
{
}

final class InvalidOrderException extends RuntimeException
{
}

/**
 * @return list<mixed>
 */
function decodeOrders(string $json): array
{
    if (str_starts_with($json, "\xEF\xBB\xBF")) {
        $json = substr($json, 3);
    }
    if (trim($json) === '') {
        throw new FatalErrorException('Пустой ввод: ожидался JSON-массив заказов');
    }

    // Декодируем в объекты, а не в ассоциативные массивы: иначе {} и [] неразличимы.
    // BIGINT_AS_STRING: число вне диапазона int станет строкой и не пройдёт проверку типа,
    // вместо тихой потери точности во float.
    try {
        $data = json_decode($json, false, 512, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
    } catch (JsonException $e) {
        throw new FatalErrorException('Не удалось декодировать JSON: ' . $e->getMessage(), previous: $e);
    }

    if (!is_array($data)) {
        throw new FatalErrorException('Ожидался JSON-массив заказов, получено: ' . describeJsonType($data));
    }

    return $data;
}

function parseOrder(mixed $raw): Order
{
    if (!$raw instanceof stdClass) {
        throw new InvalidOrderException('заказ должен быть JSON-объектом, получено: ' . describeJsonType($raw));
    }

    $fields = get_object_vars($raw);
    foreach (REQUIRED_FIELDS as $field) {
        if (!array_key_exists($field, $fields)) {
            throw new InvalidOrderException(sprintf('отсутствует обязательное поле "%s"', $field));
        }
    }
    ['id' => $id, 'customer' => $customer, 'amount' => $amount, 'status' => $status] = $fields;

    if (!is_int($id) || $id <= 0) {
        throw new InvalidOrderException('поле "id" должно быть положительным целым числом');
    }
    if (!is_string($customer) || trim($customer) === '') {
        throw new InvalidOrderException('поле "customer" должно быть непустой строкой');
    }
    // Числа в строках ("1500") не принимаем: неявное приведение скрыло бы ошибку источника данных.
    if (!is_int($amount) && !is_float($amount)) {
        throw new InvalidOrderException('поле "amount" должно быть числом, получено: ' . describeJsonType($amount));
    }
    // Ноль допустим: по условию заказ 104 с суммой 0 считается оплаченным.
    // Сумма выводится без округления: иначе -0.004 показалось бы как 0.00 и скрыло бы ошибку.
    if ($amount < 0) {
        throw new InvalidOrderException(sprintf('поле "amount" не может быть отрицательным (%s)', formatAmount($amount, false)));
    }
    // json_decode превращает слишком большие числа (1e309) в INF; -INF уже отсеяна проверкой выше.
    if (is_float($amount) && !is_finite($amount)) {
        throw new InvalidOrderException('поле "amount" превышает максимально допустимое значение');
    }
    if (!is_string($status)) {
        throw new InvalidOrderException('поле "status" должно быть строкой, получено: ' . describeJsonType($status));
    }

    $orderStatus = OrderStatus::tryFrom($status)
        ?? throw new InvalidOrderException(sprintf('неизвестный статус "%s"', $status));

    return new Order($id, $customer, $amount, $orderStatus);
}

/**
 * @param list<mixed> $rawOrders
 * @return array{orders: list<Order>, errors: list<string>}
 */
function parseOrders(array $rawOrders, bool $strict): array
{
    $orders = [];
    $errors = [];
    $seenIds = [];

    foreach ($rawOrders as $index => $raw) {
        try {
            $order = parseOrder($raw);
            // Дубликат отбрасываем, чтобы не учесть одну оплату дважды.
            if (isset($seenIds[$order->id])) {
                throw new InvalidOrderException(sprintf('дубликат id %d', $order->id));
            }
            $seenIds[$order->id] = true;
            $orders[] = $order;
        } catch (InvalidOrderException $e) {
            $message = sprintf('заказ [%d]%s: %s', $index, describeOrderId($raw), $e->getMessage());
            if ($strict) {
                throw new FatalErrorException($message, previous: $e);
            }
            $errors[] = $message;
        }
    }

    return ['orders' => $orders, 'errors' => $errors];
}

/**
 * @param list<Order> $orders
 * @return list<Order>
 */
function filterByStatus(array $orders, OrderStatus $status): array
{
    return array_values(array_filter($orders, static fn (Order $order): bool => $order->status === $status));
}

/**
 * @param list<Order> $orders
 */
function totalAmount(array $orders, bool $strict = false): int|float
{
    $sum = 0;
    foreach ($orders as $order) {
        $next = $sum + $order->amount;
        // PHP при переполнении int молча переходит на float, заметить это можно только по смене типа.
        if ($strict && is_int($sum) && is_int($order->amount) && is_float($next)) {
            throw new FatalErrorException('Итоговая сумма оплаченных заказов превышает PHP_INT_MAX, результат был бы неточным');
        }
        $sum = $next;
    }

    // Проверяется и без --strict: бесконечность нельзя выдать за результат.
    if (is_float($sum) && !is_finite($sum)) {
        throw new FatalErrorException('Итоговая сумма оплаченных заказов превышает максимальное значение float');
    }

    return $sum;
}

/**
 * При равных суммах возвращается заказ, встретившийся первым.
 *
 * @param list<Order> $orders
 */
function mostExpensive(array $orders): ?Order
{
    $max = null;
    foreach ($orders as $order) {
        if ($max === null || $order->amount > $max->amount) {
            $max = $order;
        }
    }

    return $max;
}

/**
 * @param list<Order> $orders
 * @return array<string, int>
 */
function countByStatus(array $orders): array
{
    $counts = array_fill_keys(array_map(static fn (OrderStatus $s): string => $s->value, OrderStatus::cases()), 0);
    foreach ($orders as $order) {
        $counts[$order->status->value]++;
    }

    return $counts;
}

/**
 * @param list<Order> $orders
 * @param list<string> $errors
 * @return array<string, mixed>
 */
function buildReport(array $orders, array $errors, bool $strict, bool $round): array
{
    $paid = filterByStatus($orders, OrderStatus::Paid);
    $top = mostExpensive($paid);
    // Считаем без округления, округляем только выводимые суммы: так погрешность float (0.1 + 0.2) не попадает в отчёт.
    $present = static fn (int|float $amount): int|float => $round && is_float($amount) ? round($amount, 2) : $amount;

    return [
        'paid_count' => count($paid),
        'paid_total' => $present(totalAmount($paid, $strict)),
        'most_expensive_paid' => $top === null ? null : ['id' => $top->id, 'amount' => $present($top->amount)],
        'count_by_status' => countByStatus($orders),
        'valid_orders' => count($orders),
        'skipped_orders' => count($errors),
        'errors' => $errors,
    ];
}

/**
 * @param array<string, mixed> $report
 */
function renderReport(array $report, bool $round): string
{
    $top = $report['most_expensive_paid'];
    $lines = [
        'Количество оплаченных заказов: ' . $report['paid_count'],
        'Общая сумма оплаченных заказов: ' . formatAmount($report['paid_total'], $round),
        'Самый дорогой оплаченный заказ: '
            . ($top === null ? 'нет' : sprintf('№ %d на сумму %s', $top['id'], formatAmount($top['amount'], $round))),
        '',
        'Заказы по статусам:',
    ];
    foreach ($report['count_by_status'] as $status => $count) {
        $lines[] = sprintf('  %s: %d', $status, $count);
    }

    return implode(PHP_EOL, $lines) . PHP_EOL;
}

/**
 * @param array<string, mixed> $report
 */
function saveReport(array $report, string $path): void
{
    try {
        $json = json_encode(
            $report,
            JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION,
        ) . PHP_EOL;
    } catch (JsonException $e) {
        throw new FatalErrorException('Не удалось сформировать JSON результата: ' . $e->getMessage(), previous: $e);
    }

    $dir = dirname($path);
    if (!is_dir($dir) || !is_writable($dir)) {
        throw new FatalErrorException(sprintf('Каталог "%s" не существует или недоступен для записи', $dir));
    }

    // Пишем во временный файл и переименовываем: при сбое не останется наполовину записанного результата.
    $tmp = $path . '.tmp' . getmypid();
    if (@file_put_contents($tmp, $json) === false || !@rename($tmp, $path)) {
        @unlink($tmp);
        throw new FatalErrorException(sprintf('Не удалось записать файл "%s"', $path));
    }
}

function formatAmount(int|float $amount, bool $round = true): string
{
    return match (true) {
        is_int($amount) => (string) $amount,
        $round => number_format($amount, 2, '.', ''),
        default => var_export($amount, true),
    };
}

function describeJsonType(mixed $value): string
{
    return match (true) {
        $value === null => 'null',
        is_bool($value) => 'boolean',
        is_int($value), is_float($value) => 'число',
        is_string($value) => 'строка',
        is_array($value) => 'массив',
        default => 'объект',
    };
}

function describeOrderId(mixed $raw): string
{
    if ($raw instanceof stdClass && isset($raw->id) && (is_int($raw->id) || is_string($raw->id))) {
        return sprintf(' (id %s)', $raw->id);
    }

    return '';
}

/**
 * @param list<string> $args
 * @return array{strict: bool, round: bool, output: ?string, help: bool}
 */
function parseArgs(array $args): array
{
    $options = ['strict' => false, 'round' => true, 'output' => null, 'help' => false];

    foreach ($args as $arg) {
        if ($arg === '--strict') {
            $options['strict'] = true;
        } elseif ($arg === '--no-round') {
            $options['round'] = false;
        } elseif ($arg === '-h' || $arg === '--help') {
            $options['help'] = true;
        } elseif (str_starts_with($arg, '--output=')) {
            // Форма "--output FILE" не поддерживается: иначе "--output --strict" молча принял бы "--strict" за путь.
            $options['output'] = substr($arg, strlen('--output='));
        } elseif ($arg === '--output') {
            throw new FatalErrorException('Путь для --output указывается через "=": --output=FILE');
        } else {
            throw new FatalErrorException(sprintf('Неизвестный аргумент "%s"', $arg));
        }
    }

    if ($options['output'] === '') {
        throw new FatalErrorException('Не указан путь для --output');
    }

    return $options;
}

/**
 * @param list<string> $argv
 */
function main(array $argv): int
{
    try {
        $options = parseArgs(array_slice($argv, 1));
        if ($options['help']) {
            fwrite(STDOUT, USAGE);

            return EXIT_OK;
        }
        // Без перенаправления ввода скрипт молча ждал бы данных с клавиатуры.
        if (stream_isatty(STDIN)) {
            throw new FatalErrorException("Данные не переданы через стандартный ввод\n\n" . USAGE);
        }

        $input = stream_get_contents(STDIN);
        if ($input === false) {
            throw new FatalErrorException('Не удалось прочитать стандартный ввод');
        }

        ['orders' => $orders, 'errors' => $errors] = parseOrders(decodeOrders($input), $options['strict']);
        foreach ($errors as $error) {
            fwrite(STDERR, 'Предупреждение: ' . $error . PHP_EOL);
        }

        $report = buildReport($orders, $errors, $options['strict'], $options['round']);

        // Файл сохраняется до вывода: при ошибке записи результат не выводится вовсе, как и при других фатальных ошибках.
        if ($options['output'] !== null) {
            saveReport($report, $options['output']);
        }

        fwrite(STDOUT, renderReport($report, $options['round']));
        if ($options['output'] !== null) {
            fwrite(STDOUT, PHP_EOL . 'Результат сохранён в ' . $options['output'] . PHP_EOL);
        }

        if ($errors !== []) {
            fwrite(STDERR, sprintf('Пропущено некорректных заказов: %d%s', count($errors), PHP_EOL));

            return EXIT_PARTIAL;
        }

        return EXIT_OK;
    } catch (FatalErrorException $e) {
        fwrite(STDERR, 'Ошибка: ' . $e->getMessage() . PHP_EOL);

        return EXIT_FATAL;
    }
}
