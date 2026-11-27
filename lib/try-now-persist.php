<?php
declare(strict_types=1);

/**
 * Insert a new Try Now customer and a durable PREPARED SMS attempt.
 *
 * IMPORTANT: this function NEVER commits, never sends SMS and never calls
 * Sendmode. The caller MUST start a transaction and either commit or roll back.
 * Its prepared attempt MUST NOT be treated as evidence that SMS was sent.
 *
 * Required existing files in this lib/ directory:
 *   try-now-support.php
 *   try-now-registration.php
 *   try-now-duplicates.php
 *
 * PHP 7.4+ compatible.
 */
require_once __DIR__ . '/try-now-registration.php';
require_once __DIR__ . '/try-now-duplicates.php';

/** Use exactly the recipient format that the Sendmode preparer expects. */
function stTryNowCanonicalRecipient(string $mobile): string
{
    $normalised = stTryNowNormalizeMobile($mobile);
    if ($normalised === null) {
        throw new InvalidArgumentException('Invalid mobile number.');
    }

    $digits = preg_replace('/\D/', '', $normalised);
    if (!is_string($digits)) {
        throw new InvalidArgumentException('Invalid mobile number.');
    }

    if (preg_match('/^08[0-9]{8}$/D', $digits)) {
        return '353' . substr($digits, 1);
    }
    if (preg_match('/^07[0-9]{9}$/D', $digits)) {
        return '44' . substr($digits, 1);
    }

    throw new InvalidArgumentException('Unsupported mobile number.');
}

/**
 * Caller owns the transaction; never commits inside this function.
 *
 * On success, returns a customer ID/code and attempt ID which exist only
 * inside the caller's transaction until the caller explicitly commits.
 * On validation failure or existing registration, does not insert anything.
 * On any database exception, caller MUST roll back the transaction.
 *
 * Do not expose the returned code/mobile to logs or public diagnostics.
 */
function stTryNowInsertPreparedAttempt(PDO $pdo, array $post): array
{
    if (!$pdo->inTransaction()) {
        throw new RuntimeException('A database transaction is required.');
    }

    $registration = stTryNowPrepareRegistration($post);
    if (($registration['ok'] ?? false) !== true) {
        return [
            'ok' => false,
            'status' => 'invalid',
            'errors' => $registration['errors'] ?? [],
        ];
    }

    $customer = $registration['customer'];
    $industry = $registration['industry'];
    $sample = stTryNowSampleForIndustry($industry);

    if ($sample === null ||
        $customer['campaign_id'] !== (int) $sample['campaign_id']) {
        throw new RuntimeException('Unexpected Try Now campaign selection.');
    }

    $campaignId = (int) $sample['campaign_id'];
    $mobile = $customer['mobile'];
    $canonical = stTryNowCanonicalRecipient($mobile);

    // Reconfirm the selected campaign and short-link mapping on production.
    $stmt = $pdo->prepare(
        'SELECT campaign_link FROM campaign WHERE id = ? LIMIT 1'
    );
    $stmt->execute([$campaignId]);
    $campaign = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!is_array($campaign) ||
        rtrim((string) ($campaign['campaign_link'] ?? ''), '/') !==
            'https://stxt.me/' . $sample['short_id']) {
        throw new RuntimeException('Try Now campaign configuration mismatch.');
    }

    // Existing sample registration may predate the new attempt ledger.
    if (stTryNowDuplicateExists($pdo, $campaignId, $mobile)) {
        return ['ok' => false, 'status' => 'already_registered'];
    }

    $stmt = $pdo->prepare(
        'SELECT 1 FROM try_now_sms_attempt_ledger
         WHERE campaign_id = ? AND mobile_normalized = ? LIMIT 1'
    );
    $stmt->execute([$campaignId, $canonical]);
    if ($stmt->fetchColumn() !== false) {
        return ['ok' => false, 'status' => 'already_registered'];
    }

    // The short URL contains campaign ID, so check code collision in that
    // campaign. The existing campaign_id index supports this query.
    $codeCheck = $pdo->prepare(
        'SELECT 1 FROM customer WHERE campaign_id = ? AND code = ? LIMIT 1'
    );
    $code = null;

    for ($attempt = 0; $attempt < 20; $attempt++) {
        $candidate = stTryNowGenerateCustomerCode();
        $codeCheck->execute([$campaignId, $candidate]);
        if ($codeCheck->fetchColumn() === false) {
            $code = $candidate;
            break;
        }
    }
    if ($code === null) {
        throw new RuntimeException('Unable to allocate a Try Now customer code.');
    }

    // Required columns only; existing DB defaults fill click/booking/call
    // flags and date_added. No changes to the customer table schema.
    $stmt = $pdo->prepare(
        'INSERT INTO customer
            (code, first_name, last_name, mobile, campaign_id)
         VALUES (?, ?, ?, ?, ?)'
    );
    $stmt->execute([
        $code,
        $customer['first_name'],
        $customer['last_name'],
        $mobile,
        $campaignId,
    ]);
    $customerId = (int) $pdo->lastInsertId();
    if ($customerId < 1) {
        throw new RuntimeException('Unable to identify new Try Now customer.');
    }

    // The UNIQUE(campaign_id, mobile_normalized) index is the final
    // concurrency guard. If it rejects an insert, the caller MUST roll back
    // the customer insert as well. Never retry automatically.
    $stmt = $pdo->prepare(
        "INSERT INTO try_now_sms_attempt_ledger
            (campaign_id, customer_id, mobile_normalized, state)
         VALUES (?, ?, ?, 'prepared')"
    );
    $stmt->execute([$campaignId, $customerId, $canonical]);
    $attemptId = (int) $pdo->lastInsertId();
    if ($attemptId < 1) {
        throw new RuntimeException('Unable to identify new SMS attempt.');
    }

    $stmt = $pdo->prepare(
        "INSERT INTO try_now_sms_attempt_events (attempt_id, event_name)
         VALUES (?, 'prepared')"
    );
    $stmt->execute([$attemptId]);

    return [
        'ok' => true,
        'status' => 'prepared',
        'sent' => false,
        'campaign_id' => $campaignId,
        'customer_id' => $customerId,
        'attempt_id' => $attemptId,
        'customer_code' => $code,
        'sample_link' => stTryNowBuildCustomerLink($industry, $code),
        'mobile_normalized' => $canonical,
    ];
}
