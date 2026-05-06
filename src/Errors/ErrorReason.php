<?php

declare(strict_types=1);

namespace X402\Errors;

/**
 * Canonical error reason strings from the x402 spec v2 §9.
 *
 * Both `VerifyResponse.invalidReason` and `SettleResponse.errorReason`
 * use this catalogue. Reference TS / Go / Python impls dispatch retry
 * logic on the exact string match — emitting non-canonical text breaks
 * client integrations.
 *
 * Free-form text is allowed (the spec doesn't enforce closed-set
 * validation) but pinning to these constants is a courtesy to clients.
 */
enum ErrorReason: string
{
    case InsufficientFunds = 'insufficient_funds';

    case InvalidExactEvmAuthValidAfter = 'invalid_exact_evm_payload_authorization_valid_after';

    case InvalidExactEvmAuthValidBefore = 'invalid_exact_evm_payload_authorization_valid_before';

    case InvalidExactEvmAuthValueMismatch = 'invalid_exact_evm_payload_authorization_value_mismatch';

    case InvalidExactEvmSignature = 'invalid_exact_evm_payload_signature';

    case InvalidExactEvmRecipientMismatch = 'invalid_exact_evm_payload_recipient_mismatch';

    case InvalidNetwork = 'invalid_network';

    case InvalidPayload = 'invalid_payload';

    case InvalidPaymentRequirements = 'invalid_payment_requirements';

    case InvalidScheme = 'invalid_scheme';

    case UnsupportedScheme = 'unsupported_scheme';

    case InvalidX402Version = 'invalid_x402_version';

    case InvalidTransactionState = 'invalid_transaction_state';

    case UnexpectedVerifyError = 'unexpected_verify_error';

    case UnexpectedSettleError = 'unexpected_settle_error';

    /** Permit2 path — HTTP 412 Precondition Failed. */
    case Permit2AllowanceRequired = 'PERMIT2_ALLOWANCE_REQUIRED';

    /** Replay attempt — non-spec; we emit this internally. */
    case ReplayAttempt = 'replay_attempt';
}
