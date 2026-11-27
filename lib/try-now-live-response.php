<?php
declare(strict_types=1);

/**
 * Public responses for the controlled Try Now SMS workflow.
 * Provider acceptance is NOT handset delivery confirmation.
 * Pure PHP; no database, Redis, network or SMS operations.
 */
function stTryNowLiveResponse(string $status, array $errors = []): array
{
    switch ($status) {
        case 'accepted':
            return [
                'http_status' => 200,
                'message' => 'Your sample SMS has been accepted for delivery.',
                'extras' => [
                    'mode' => 'live',
                    'status' => 'accepted',
                    'accepted' => true,
                    'delivered' => false,
                ],
            ];
        case 'already_requested':
            return [
                'http_status' => 409,
                'message' => 'This mobile number has already requested this sample. Please contact us if you have not received it.',
                'extras' => [
                    'mode' => 'live',
                    'status' => 'already_requested',
                    'accepted' => false,
                ],
            ];
        case 'invalid':
            return [
                'http_status' => 422,
                'message' => 'Please check your details.',
                'extras' => [
                    'mode' => 'live',
                    'status' => 'invalid',
                    'accepted' => false,
                    'errors' => $errors,
                ],
            ];
        case 'provider_rejected':
            return [
                'http_status' => 502,
                'message' => 'Our SMS provider could not accept your request. Please contact us for assistance.',
                'extras' => [
                    'mode' => 'live',
                    'status' => 'provider_rejected',
                    'accepted' => false,
                ],
            ];
        case 'provider_unknown':
            return [
                'http_status' => 503,
                'message' => 'We could not confirm whether the SMS was accepted. Please do not submit again; contact us for assistance.',
                'extras' => [
                    'mode' => 'live',
                    'status' => 'provider_unknown',
                    'accepted' => false,
                ],
            ];
        case 'unavailable':
            return [
                'http_status' => 503,
                'message' => 'Try Now is temporarily unavailable. Please contact us for assistance.',
                'extras' => [
                    'mode' => 'unavailable',
                    'status' => 'unavailable',
                    'accepted' => false,
                ],
            ];
    }

    throw new InvalidArgumentException('Unsupported Try Now SMS result.');
}
