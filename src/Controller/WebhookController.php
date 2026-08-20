<?php

declare(strict_types=1);

namespace SecondStay\Controller;

use SecondStay\Core\Http\Response;
use SecondStay\Core\RequestContext;
use SecondStay\Payment\PaymentService;

/**
 * Réception des notifications de paiement (SPECIFICATIONS.md §34).
 *
 * Trois règles gouvernent ce point d'entrée :
 *
 * 1. il ne fait jamais confiance au corps reçu — il n'y lit qu'un
 *    identifiant, puis relit l'état chez le fournisseur ;
 * 2. il est idempotent — un même événement rejoué ne produit rien de plus ;
 * 3. il répond 200 dès que l'événement est pris en compte, y compris pour un
 *    doublon ou un identifiant inconnu, afin que le fournisseur cesse de
 *    réessayer ; il ne renvoie une erreur que si un nouvel essai a du sens.
 */
final class WebhookController extends AbstractController
{
    /**
     * @param array<string, string> $params
     */
    public function payment(RequestContext $context, array $params = []): Response
    {
        $request = $context->request;
        $raw = $request->body;

        // Mollie notifie en `application/x-www-form-urlencoded`, d'autres en
        // JSON : les deux formes sont acceptées, aucune n'est crue sur parole.
        $payload = $request->post !== [] ? $request->post : $request->json();

        $result = $this->container->get(PaymentService::class)->handleWebhook($payload, $raw);

        if ($result['ok'] === false) {
            // Le fournisseur doit réessayer : c'est une erreur de notre côté.
            $status = $result['status'] === 'invalid' ? 400 : 503;

            return Response::json(['status' => $result['status']], $status);
        }

        return Response::json(['status' => $result['status']]);
    }
}
