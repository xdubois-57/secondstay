<?php

declare(strict_types=1);

namespace SecondStay\Controller;

use SecondStay\Booking\QuoteService;
use SecondStay\Core\Http\Response;
use SecondStay\Core\RequestContext;

/**
 * Devis en direct pour le calendrier public.
 *
 * L'endpoint renvoie exactement ce que le serveur facturerait : le total
 * affiché pendant la sélection et le total de la réservation proviennent du
 * même calcul.
 */
final class QuoteController extends AbstractController
{
    /**
     * @param array<string, string> $params
     */
    public function quote(RequestContext $context, array $params = []): Response
    {
        $request = $context->request;

        $input = [
            'arrival' => (string) ($request->query('arrival') ?? ''),
            'departure' => (string) ($request->query('departure') ?? ''),
            'adults' => (int) ($request->query('adults') ?? 2),
            'children' => (int) ($request->query('children') ?? 0),
            'infants' => (int) ($request->query('infants') ?? 0),
        ];

        // Le ménage n'est transmis que si le visiteur a fait un choix : sinon
        // c'est le mode configuré qui décide.
        if ($request->query('cleaning') !== null) {
            $input['cleaning'] = $request->query('cleaning') === '1';
        }

        $result = $this->container->get(QuoteService::class)->evaluate($input);

        // Les erreurs sont traduites ici : le navigateur n'a jamais à
        // connaître les clés de traduction.
        $messages = array_map(fn (string $key): string => $this->trans($key), $result['errors']);

        return Response::json([
            'ok' => $result['ok'],
            'errors' => $messages,
            'conflicts' => $result['conflicts'],
            'quote' => $result['quote'],
            'rules' => $result['rules'],
        ]);
    }
}
