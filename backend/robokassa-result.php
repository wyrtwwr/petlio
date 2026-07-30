<?php

declare(strict_types=1);

require_once __DIR__ . '/security.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/order-data.php';
require_once __DIR__ . '/robokassa.php';
require_once __DIR__ . '/send-order-email.php';

function robokassa_result_response(string $body, int $status): void
{
    send_security_headers();
    http_response_code($status);
    header('Content-Type: text/plain; charset=utf-8');
    echo $body;
    exit;
}

function send_admin_order_notification_once(PDO $pdo, int $invId): void
{
    $pdo->beginTransaction();

    try {
        $order = find_order_by_robokassa_inv_id($pdo, $invId, true);

        if ($order === null) {
            throw new RuntimeException('Order not found for admin notification.');
        }

        $emailWasSent = !empty($order['email_sent_at']) || (int) ($order['email_sent'] ?? 0) === 1;

        if (!$emailWasSent) {
            send_order_email($order);
            mark_order_email_sent($pdo, (int) $order['id']);
        }

        $pdo->commit();
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        throw $error;
    }
}

function send_customer_payment_notification_once(PDO $pdo, int $invId): void
{
    $pdo->beginTransaction();

    try {
        $order = find_order_by_robokassa_inv_id($pdo, $invId, true);

        if ($order === null) {
            throw new RuntimeException('Order not found for customer notification.');
        }

        $customerEmail = trim((string) ($order['customer_email'] ?? ''));

        if (
            empty($order['customer_email_sent_at'])
            && filter_var($customerEmail, FILTER_VALIDATE_EMAIL) !== false
        ) {
            send_customer_payment_email($order);
            mark_customer_payment_email_sent($pdo, (int) $order['id']);
        }

        $pdo->commit();
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        throw $error;
    }
}

try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        header('Allow: POST');
        robokassa_result_response('Method not allowed', 405);
    }

    $config = require __DIR__ . '/config.php';

    try {
        $resultParams = robokassa_validate_result_params($_POST, $config);
    } catch (UnexpectedValueException $error) {
        robokassa_result_response('Invalid signature', 403);
    } catch (InvalidArgumentException $error) {
        robokassa_result_response($error->getMessage(), 400);
    }

    $outSumInput = $resultParams['out_sum'];
    $invIdInput = $resultParams['inv_id_input'];
    $invId = $resultParams['inv_id'];

    $pdo = getDatabaseConnection();
    $order = find_order_by_robokassa_inv_id($pdo, $invId);

    if ($order === null) {
        robokassa_result_response('Order not found', 404);
    }

    if (($order['payment_provider'] ?? '') !== 'robokassa') {
        robokassa_result_response('Order payment provider mismatch', 400);
    }

    if (robokassa_amount_to_kopecks($outSumInput) !== robokassa_amount_to_kopecks((string) $order['amount'])) {
        robokassa_result_response('Amount mismatch', 400);
    }

    $pdo->beginTransaction();

    try {
        $order = find_order_by_robokassa_inv_id($pdo, $invId, true);

        if ($order === null) {
            $pdo->rollBack();
            robokassa_result_response('Order not found', 404);
        }

        if (($order['payment_provider'] ?? '') !== 'robokassa') {
            $pdo->rollBack();
            robokassa_result_response('Order payment provider mismatch', 400);
        }

        if (robokassa_amount_to_kopecks($outSumInput) !== robokassa_amount_to_kopecks((string) $order['amount'])) {
            $pdo->rollBack();
            robokassa_result_response('Amount mismatch', 400);
        }

        $addressTagErrors = address_tag_validation_errors(
            address_tag_data_from_order_row($order),
            'stored'
        );

        if ($addressTagErrors !== []) {
            $pdo->rollBack();
            error_log(sprintf(
                'Robokassa payment rejected for incomplete order %d: %s',
                $invId,
                implode(', ', array_keys($addressTagErrors))
            ));
            robokassa_result_response('Order data incomplete', 422);
        }

        $status = (string) ($order['payment_status'] ?? '');

        if (order_status_accepts_payment($status)) {
            mark_robokassa_order_paid($pdo, $invId);
        } elseif (
            !order_status_has_confirmed_payment($status)
            && empty($order['paid_at'])
        ) {
            $pdo->rollBack();
            robokassa_result_response('Order cannot be marked as paid', 400);
        }

        $pdo->commit();
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        throw $error;
    }

    try {
        // Each notification commits independently. If the second email fails,
        // a Robokassa retry does not send the first one again.
        send_admin_order_notification_once($pdo, $invId);
        send_customer_payment_notification_once($pdo, $invId);
    } catch (Throwable $error) {
        error_log(sprintf('Robokassa notification failed for InvId %d (%s).', $invId, get_class($error)));
        robokassa_result_response('Internal server error', 500);
    }

    robokassa_result_response('OK' . $invIdInput, 200);
} catch (Throwable $error) {
    error_log('Robokassa Result URL failed: ' . get_class($error));
    robokassa_result_response('Internal server error', 500);
}
