<?php

declare(strict_types=1);

namespace Facta\Api;

use Facta\Api\Support\Warnings;

/**
 * The error the SDK throws, and the only thing a caller should switch on.
 *
 * Mirrors `FactaError` of the TypeScript SDK (`src/errors.ts`): the string
 * codes are identical. The message is prose for a human reading a log and may
 * be rewritten at any time; branch on {@see FactaError::getErrorCode()}, never
 * on the message.
 *
 * `Exception::getCode()` carries the HTTP status (0 when no answer arrived).
 */
class FactaError extends \Exception
{
    public const UNAUTHORIZED = 'unauthorized';
    public const INVALID_API_KEY = 'invalid_api_key';
    public const KEY_REVOKED = 'key_revoked';
    public const KEY_EXPIRED = 'key_expired';
    public const KEY_INACTIVE = 'key_inactive';
    public const FORBIDDEN_SCOPE = 'forbidden_scope';
    public const DTE_TYPE_NOT_ALLOWED = 'dte_type_not_allowed';
    public const IP_NOT_ALLOWED = 'ip_not_allowed';
    public const ENVIRONMENT_NOT_ALLOWED = 'environment_not_allowed';
    public const SIGN_KEY_REQUIRED = 'sign_key_required';
    public const SIGN_KEY_INVALID = 'sign_key_invalid';
    public const SIGN_VAULT_LOCKED = 'sign_vault_locked';
    public const SIGN_VAULT_MISSING = 'sign_vault_missing';
    public const INVALID_REQUEST = 'invalid_request';
    public const INCLUDE_LIMIT_EXCEEDED = 'include_limit_exceeded';
    public const VALIDATION_FAILED = 'validation_failed';
    public const RETENTION_MIXED_CLASS_UNSUPPORTED = 'retention_mixed_class_unsupported';
    public const NOT_FOUND = 'not_found';
    public const METHOD_NOT_ALLOWED = 'method_not_allowed';
    public const NOT_SEALED = 'not_sealed';
    public const CATALOG_WRITE_DISABLED = 'catalog_write_disabled';
    public const CATALOG_ENCRYPTED = 'catalog_encrypted';
    public const CATALOG_DUPLICATE = 'catalog_duplicate';
    public const CATALOG_REVISION_CONFLICT = 'catalog_revision_conflict';
    public const CATALOG_RECORD_INACTIVE = 'catalog_record_inactive';
    public const CATALOG_SEARCH_TOO_BROAD = 'catalog_search_too_broad';
    public const CATALOG_CURSOR_EXPIRED = 'catalog_cursor_expired';
    public const DATA_GRANT_UNAVAILABLE = 'data_grant_unavailable';
    public const DATA_GRANT_REVOKED = 'data_grant_revoked';
    public const DATA_EPOCH_STALE = 'data_epoch_stale';
    public const INBOX_RETIRED = 'inbox_retired';
    public const WEBHOOK_LIMIT_REACHED = 'webhook_limit_reached';
    public const WEBHOOK_DUPLICATE = 'webhook_duplicate';
    public const CATALOG_WRITE_TEST_KEY = 'catalog_write_test_key';
    public const PAID_PLAN_REQUIRED = 'paid_plan_required';
    public const MH_AUTH_FAILED = 'mh_auth_failed';
    public const DRIVE_NOT_CONFIGURED = 'drive_not_configured';
    public const DRIVE_REAUTHORIZATION_REQUIRED = 'drive_reauthorization_required';
    public const DRIVE_TOKEN_FAILED = 'drive_token_failed';
    public const DRIVE_NOT_AVAILABLE_FOR_API = 'drive_not_available_for_api';
    public const DRAFTS_NOT_READY = 'drafts_not_ready';
    public const CONTENT_HASH_MISMATCH = 'content_hash_mismatch';
    public const ID_CONFLICT = 'id_conflict';
    public const ITEM_FINAL = 'item_final';
    public const DRAFT_CLAIMED = 'draft_claimed';
    public const DRAFT_ISSUING = 'draft_issuing';
    public const DRAFT_ISSUED = 'draft_issued';
    public const DRAFT_NOT_ISSUABLE = 'draft_not_issuable';
    public const VERSION_MISMATCH = 'version_mismatch';
    public const INBOX_CAP_REACHED = 'inbox_cap_reached';
    public const IDEMPOTENCY_KEY_REQUIRED = 'idempotency_key_required';
    public const IDEMPOTENCY_KEY_REUSE = 'idempotency_key_reuse';
    public const IDEMPOTENCY_IN_FLIGHT = 'idempotency_in_flight';
    public const PREPARE_TOKEN_INVALID = 'prepare_token_invalid';
    public const RETURN_EXCEEDS_AVAILABLE = 'return_exceeds_available';
    public const RETURN_WINDOW_CLOSED = 'return_window_closed';
    public const RETURN_TYPE_NOT_ALLOWED = 'return_type_not_allowed';
    public const RETURN_PDF_UNAVAILABLE = 'return_pdf_unavailable';
    public const HAS_RETURN_EVENTS = 'has_return_events';
    public const RATE_LIMITED = 'rate_limited';
    public const AMOUNT_LIMIT = 'amount_limit';
    public const MH_REJECTED = 'mh_rejected';
    public const MH_UNREACHABLE = 'mh_unreachable';
    public const CORRELATIVE_UNAVAILABLE = 'correlative_unavailable';
    public const SERVICE_UNAVAILABLE = 'service_unavailable';
    public const NO_STORAGE_DESTINATION = 'no_storage_destination';
    public const STORAGE_UNSUPPORTED = 'storage_unsupported';
    /** HTTP 410: the temporary encrypted copy was deleted (2 days after the document was marked stored, or 30 days after sealing). Look in your own storage. */
    public const TEMPORARY_COPY_EXPIRED = 'temporary_copy_expired';
    /** HTTP 409 from `retryDocumentStorage()`: the temporary copy is gone, so Facta has nothing to rewrite from. */
    public const NOTHING_TO_REPAIR_FROM = 'nothing_to_repair_from';
    /** HTTP 422: the `sha256` of a storage confirmation is not the one of that document's JSON; nothing was recorded. */
    public const STORAGE_HASH_MISMATCH = 'storage_hash_mismatch';
    public const STORAGE_UNAVAILABLE = 'storage_unavailable';
    public const STORAGE_CONTRACT_INVALID = 'storage_contract_invalid';
    public const ENTREGA_VENCIDA = 'entrega_vencida';
    public const ENTREGA_TOKEN_INVALIDO = 'entrega_token_invalido';
    public const CANAL_NO_MARCADO = 'canal_no_marcado';
    public const INTERNAL_ERROR = 'internal_error';
    public const OPERATION_OUTCOME_UNKNOWN = 'operation_outcome_unknown';
    public const ARCHIVE_INTEGRITY_ERROR = 'archive_integrity_error';
    /** Not from the server: the request never got an answer. */
    public const NETWORK_ERROR = 'network_error';

    /** @var string */
    private $errorCode;

    /** @var mixed */
    private $details;

    /** @var int|null */
    private $retryAfterMs;

    /** @var string|null */
    private $factaWarning;

    /**
     * @param mixed $details Decoded `error.details` of the response, already redacted.
     */
    public function __construct(
        string $errorCode,
        string $message,
        int $status = 0,
        $details = null,
        ?int $retryAfterMs = null,
        ?string $factaWarning = null
    ) {
        parent::__construct($message, $status);
        $this->errorCode = $errorCode;
        $this->details = $details;
        $this->retryAfterMs = $retryAfterMs;
        $this->factaWarning = $factaWarning;
    }

    /** One of the string constants above (or a code a newer server added). */
    public function getErrorCode(): string
    {
        return $this->errorCode;
    }

    /** HTTP status, or 0 when the request never got an answer. */
    public function getStatus(): int
    {
        return (int) $this->getCode();
    }

    /** @return mixed */
    public function getDetails()
    {
        return $this->details;
    }

    /** What the server's `Retry-After` header asked for, in milliseconds, if it sent one. */
    public function getRetryAfterMs(): ?int
    {
        return $this->retryAfterMs;
    }

    /**
     * The `campo_desconocido` warnings the server attached to this failure (`details.advertencias`):
     * request fields Facta DTE ignored. Empty when there are none. Unknown fields are warnings, never errors.
     *
     * @return array<int, array{codigo: string, campo: string, mensaje: string, sugerencia?: string}>
     */
    public function getUnknownFields(): array
    {
        return Warnings::unknownFields($this->details);
    }

    /**
     * The `Facta-Warning` response header (`campo_desconocido; campos=2`), parsed; null when absent.
     *
     * @return array{code: string, count: int|null}|null
     */
    public function getFactaWarning(): ?array
    {
        return Warnings::parseHeader($this->factaWarning);
    }

    /**
     * True when Hacienda READ the document and refused it, as opposed to the
     * request never getting there. A rejection is fixed by changing the data,
     * an outage by waiting.
     */
    public function isRejection(): bool
    {
        return $this->errorCode === self::MH_REJECTED;
    }

    /**
     * The correlative a rejection already burned, or null. The corrected
     * document may reuse that number and generation code.
     *
     * @return array{codigoGeneracion: string, numeroControl: string}|null
     */
    public function spent(): ?array
    {
        $cg = $this->detail('codigoGeneracion');
        $nc = $this->detail('numeroControl');
        if (!is_string($cg) || !is_string($nc)) {
            return null;
        }
        return ['codigoGeneracion' => $cg, 'numeroControl' => $nc];
    }

    /**
     * What Hacienda said, word for word, when it refused.
     *
     * @return string[]
     */
    public function mhObservations(): array
    {
        $raw = $this->detail('observaciones');
        if (!is_array($raw)) {
            return [];
        }
        return array_map(static function ($item): string {
            return is_scalar($item) ? (string) $item : (string) json_encode($item);
        }, array_values($raw));
    }

    /** @return mixed */
    private function detail(string $name)
    {
        if (is_object($this->details) && isset($this->details->{$name})) {
            return $this->details->{$name};
        }
        if (is_array($this->details) && isset($this->details[$name])) {
            return $this->details[$name];
        }
        return null;
    }
}
