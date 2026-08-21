<?php

declare(strict_types=1);

namespace SecondStay\Llm;

/**
 * Frontière vers un modèle de langage (ARCHITECTURE.md §3).
 *
 * Comme les autres fournisseurs externes du produit — paiement, push, IMAP —
 * celui-ci est une interface : le pipeline de contenu local se teste alors
 * entièrement sans réseau, sans clé, et sans dépendre de ce qu'un modèle
 * voudra bien répondre ce jour-là.
 */
interface LlmProvider
{
    public function name(): string;

    /**
     * Le fournisseur peut-il réellement répondre ?
     *
     * Sans clé, la réponse est non : le produit n'affiche alors aucune
     * activité plutôt que d'en inventer.
     */
    public function isConfigured(): bool;

    public function complete(LlmPrompt $prompt): LlmResult;
}
