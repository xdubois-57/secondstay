<?php

declare(strict_types=1);

namespace SecondStay\Mail;

use InvalidArgumentException;

final class MailAddress
{
    public function __construct(
        public readonly string $address,
        public readonly string $name = '',
    ) {
        if (filter_var($address, FILTER_VALIDATE_EMAIL) === false) {
            throw new InvalidArgumentException('Adresse e-mail invalide.');
        }
    }

    /**
     * En-tête RFC 5322. Le nom est encodé si nécessaire et ne peut jamais
     * injecter d'en-tête supplémentaire.
     */
    public function toHeader(): string
    {
        $name = str_replace(["\r", "\n", "\0"], '', $this->name);
        if (trim($name) === '') {
            return $this->address;
        }

        if (preg_match('/^[\x20-\x7E]*$/', $name) === 1) {
            return '"' . str_replace('"', '', $name) . '" <' . $this->address . '>';
        }

        return '=?UTF-8?B?' . base64_encode($name) . '?= <' . $this->address . '>';
    }

    public function __toString(): string
    {
        return $this->toHeader();
    }
}
