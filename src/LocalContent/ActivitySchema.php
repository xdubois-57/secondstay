<?php

declare(strict_types=1);

namespace SecondStay\LocalContent;

/**
 * Schéma imposé à la sortie du modèle (SPECIFICATIONS.md §56 et §58).
 *
 * Il est envoyé au fournisseur **et** revalidé au retour. Les deux ne font pas
 * double emploi : le premier guide la génération, le second protège des
 * réponses qui passeraient à côté — y compris d'un fournisseur qui
 * n'appliquerait pas la contrainte.
 */
final class ActivitySchema
{
    /** Catégories acceptées : au-delà, l'affichage ne saurait pas quoi en faire. */
    public const CATEGORIES = ['market', 'festival', 'museum', 'nature', 'sport', 'food', 'other'];

    /** Nombre maximal d'activités retenues par génération. */
    public const MAX_ITEMS = 40;

    /**
     * @return array<string, mixed>
     */
    public static function jsonSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'activities' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'title' => ['type' => 'string'],
                            'summary' => ['type' => 'string'],
                            'category' => ['type' => 'string', 'enum' => self::CATEGORIES],
                            'starts_on' => ['type' => 'string', 'format' => 'date'],
                            'ends_on' => ['type' => 'string', 'format' => 'date'],
                            'booking_required' => ['type' => 'boolean'],
                            'location' => ['type' => 'string'],
                            'source_url' => ['type' => 'string', 'format' => 'uri'],
                        ],
                        'required' => [
                            'title',
                            'summary',
                            'category',
                            'starts_on',
                            'ends_on',
                            'booking_required',
                            'location',
                            'source_url',
                        ],
                        'additionalProperties' => false,
                    ],
                ],
            ],
            'required' => ['activities'],
            'additionalProperties' => false,
        ];
    }
}
