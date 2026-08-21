<?php

declare(strict_types=1);

namespace SecondStay\Llm;

/**
 * Une demande faite au modèle, avec le schéma que la réponse doit respecter.
 *
 * Le schéma n'est pas un souhait : il est envoyé au fournisseur **et**
 * revalidé au retour. Un modèle qui répondrait à côté ne produirait alors
 * aucune activité, plutôt que des lignes à moitié fausses.
 */
final class LlmPrompt
{
    /**
     * @param array<string, mixed> $schema JSON Schema attendu en sortie
     */
    public function __construct(
        public readonly string $system,
        public readonly string $user,
        public readonly array $schema,
        public readonly int $maxTokens = 8000,
        public readonly string $effort = 'medium',
    ) {
    }

    /**
     * Empreinte de la demande, pour journaliser sans stocker le prompt.
     */
    public function fingerprint(): string
    {
        return substr(hash('sha256', $this->system . "\n" . $this->user), 0, 16);
    }
}
