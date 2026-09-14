<?php

declare(strict_types=1);

final class WhatsAppApiException extends RuntimeException
{
    private int $httpStatus;
    private array $details;

    public function __construct(string $message, int $httpStatus = 0, array $details = [])
    {
        parent::__construct($message);
        $this->httpStatus = $httpStatus;
        $this->details = $details;
    }

    public function httpStatus(): int
    {
        return $this->httpStatus;
    }

    public function details(): array
    {
        return $this->details;
    }

    public function isRetryable(): bool
    {
        if (!empty($this->details['is_transient'])) {
            return true;
        }
        $code = (int) ($this->details['code'] ?? 0);
        if (in_array($code, [1, 2, 4, 17, 32, 613, 130429, 131000, 131016, 131048, 131056], true)) {
            return true;
        }
        return $this->httpStatus === 429 || $this->httpStatus >= 500 || $this->httpStatus === 0;
    }
}
