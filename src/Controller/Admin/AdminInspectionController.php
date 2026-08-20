<?php

declare(strict_types=1);

namespace SecondStay\Controller\Admin;

use SecondStay\Booking\BookingRepository;
use SecondStay\Core\Exception\NotFoundException;
use SecondStay\Core\Http\Response;
use SecondStay\Core\RequestContext;
use SecondStay\Document\DocumentKind;
use SecondStay\Document\DocumentService;
use SecondStay\Document\DocumentSource;
use SecondStay\I18n\Locales;
use SecondStay\Inspection\InspectionKind;
use SecondStay\Inspection\InspectionService;
use SecondStay\Inspection\ZoneRepository;

/**
 * Zones du logement et photos de référence (SPECIFICATIONS.md §53).
 *
 * Le propriétaire décide de l'ordre du parcours, des zones actives et de
 * celles qui exigent une photo au départ. Rien de tout cela n'est figé dans le
 * code : ce qui décrit **ce** logement vit en base.
 */
final class AdminInspectionController extends AdminController
{
    protected function section(): string
    {
        return 'inspections';
    }

    /**
     * @param array<string, string> $params
     */
    public function index(RequestContext $context, array $params = []): Response
    {
        $this->requireOperational();

        $locale = $this->currentLocale($context);
        $zones = $this->container->get(ZoneRepository::class);

        $rows = [];
        foreach ($zones->all($locale) as $zone) {
            $rows[] = [
                'zone' => $zone,
                'references' => $zones->referenceDocuments($zone->id),
            ];
        }

        return $this->renderAdmin('admin/inspections.html.twig', [
            'meta_title' => $this->trans('inspection.admin.title'),
            'locales' => Locales::ALL,
            'current_locale' => $locale,
            'zones' => $rows,
            'completeness' => $zones->completeness(),
            'defaults' => array_keys(ZoneRepository::DEFAULTS),
        ]);
    }

    /**
     * Crée ou met à jour une zone et son libellé dans la langue courante.
     *
     * @param array<string, string> $params
     */
    public function saveZone(RequestContext $context, array $params = []): Response
    {
        $user = $this->requireOperational();

        $locale = $this->currentLocale($context);
        $zones = $this->container->get(ZoneRepository::class);

        $code = $this->normaliseCode((string) $context->request->input('code', ''));
        if ($code === '') {
            $this->flashError('inspection.error.code');

            return $this->backToZones($context, $locale);
        }

        $zoneId = $zones->save($code, [
            'position' => max(0, min(9999, (int) $context->request->input('position', '0'))),
            'photo_required' => $context->request->input('photo_required') !== null ? 1 : 0,
            'active' => $context->request->input('active') !== null ? 1 : 0,
            'reference_note' => mb_substr((string) $context->request->input('reference_note', ''), 0, 255),
        ]);

        $zones->saveTranslation(
            $zoneId,
            $locale,
            (string) $context->request->input('name', ''),
            (string) $context->request->input('instructions', ''),
        );

        $this->audit()->record('inspection.zone_saved', 'inspection_zone', $code, null, [
            'locale' => $locale,
        ], $user->id, $user->email);

        $this->flashSuccess('inspection.admin.saved');

        return $this->backToZones($context, $locale);
    }

    /**
     * Rétablit les zones proposées, si aucune n'existe encore.
     *
     * @param array<string, string> $params
     */
    public function seed(RequestContext $context, array $params = []): Response
    {
        $this->requireAdministrator();

        $created = $this->container->get(ZoneRepository::class)->seedDefaults();

        $created > 0
            ? $this->flashSuccess('inspection.admin.seeded')
            : $this->flashWarning('inspection.admin.already_seeded');

        return $this->backToZones($context, $this->currentLocale($context));
    }

    /**
     * Ajoute une photo de référence à une zone.
     *
     * @param array<string, string> $params
     */
    public function uploadReference(RequestContext $context, array $params = []): Response
    {
        $user = $this->requireOperational();

        $locale = $this->currentLocale($context);
        $zones = $this->container->get(ZoneRepository::class);
        $zone = $zones->find((int) ($params['id'] ?? 0), $locale);

        if ($zone === null) {
            throw new NotFoundException('Zone introuvable.');
        }

        /** @var array<string, mixed> $file */
        $file = $context->request->files['photo'] ?? ['error' => UPLOAD_ERR_NO_FILE];
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            $this->flashError('document.error.upload_failed');

            return $this->backToZones($context, $locale);
        }

        $contents = file_get_contents((string) $file['tmp_name']);
        if ($contents === false) {
            $this->flashError('document.error.upload_failed');

            return $this->backToZones($context, $locale);
        }

        $documents = $this->container->get(DocumentService::class);
        $mime = $documents->detectMime($contents);

        if ($mime === null || !str_starts_with($mime, 'image/')) {
            // Une photo de référence est une photo : un PDF ne montrerait pas
            // l'état attendu d'une pièce.
            $this->flashError('inspection.error.not_a_photo');

            return $this->backToZones($context, $locale);
        }

        $result = $documents->store(
            $contents,
            (string) ($file['name'] ?? 'reference.jpg'),
            // La photo de référence n'appartient à aucun séjour : elle décrit
            // le logement, pas un passage.
            DocumentKind::Inventory,
            DocumentSource::Upload,
            null,
            null,
            $user->id,
            '',
            $locale,
            'reference',
        );

        if ($result['ok'] === false || $result['document'] === null) {
            $this->flashError($result['error']);

            return $this->backToZones($context, $locale);
        }

        $zones->addReference($zone->id, $result['document']->id);
        $this->flashSuccess('inspection.admin.reference_added');

        return $this->backToZones($context, $locale);
    }

    /**
     * États des lieux d'un séjour, côté exploitation.
     *
     * @param array<string, string> $params
     */
    public function forBooking(RequestContext $context, array $params = []): Response
    {
        $this->requireOperational();

        $booking = $this->container->get(BookingRepository::class)->find((int) ($params['id'] ?? 0));
        if ($booking === null) {
            throw new NotFoundException('Réservation introuvable.');
        }

        $inspections = $this->container->get(InspectionService::class);

        $views = [];
        foreach (InspectionKind::cases() as $kind) {
            $views[] = ['kind' => $kind, 'inspection' => $inspections->find($booking, $kind, $context->locale)];
        }

        return $this->renderAdmin('admin/booking-inspections.html.twig', [
            'meta_title' => $this->trans('inspection.title'),
            'booking' => $booking,
            'inspections' => $views,
        ]);
    }

    // --- Interne ------------------------------------------------------------------

    /**
     * Un code de zone est un identifiant technique : il reste stable quand le
     * libellé change de langue, il ne peut donc pas être du texte libre.
     */
    private function normaliseCode(string $code): string
    {
        $code = strtolower(trim($code));
        $code = (string) preg_replace('/[^a-z0-9_]+/', '_', $code);
        $code = trim($code, '_');

        return mb_substr($code, 0, 32);
    }

    private function currentLocale(RequestContext $context): string
    {
        $requested = $context->request->query('locale') ?? $context->request->input('locale');

        return is_string($requested) && Locales::isSupported($requested)
            ? $requested
            : $this->settings()->string('site.default_locale');
    }

    private function backToZones(RequestContext $context, string $locale): Response
    {
        return $this->redirect(
            $context->request->basePath
            . $this->router()->path('admin.inspections', ['locale' => $locale], $context->locale)
        );
    }
}
