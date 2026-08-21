<?php

declare(strict_types=1);

namespace SecondStay\Controller\Admin;

use SecondStay\Compliance\ComplianceService;
use SecondStay\Compliance\ComplianceTopic;
use SecondStay\Core\Exception\NotFoundException;
use SecondStay\Core\Http\Response;
use SecondStay\Core\RequestContext;
use SecondStay\Document\DocumentKind;
use SecondStay\Document\DocumentService;
use SecondStay\Document\DocumentSource;
use SecondStay\I18n\Locales;
use SecondStay\Legal\LegalDocumentType;
use SecondStay\Legal\LegalService;

/**
 * Assistant conformité et textes légaux (SPECIFICATIONS.md §60 à §62, §65).
 *
 * L'écran sépare volontairement ce que le produit **explique** — définition,
 * applicabilité, où chercher, impact — de ce que le propriétaire **constate**.
 * Le premier est du texte traduit ; le second est sa responsabilité, et le
 * produit se contente de la dater et d'en garder la source.
 */
final class AdminComplianceController extends AdminController
{
    protected function section(): string
    {
        return 'compliance';
    }

    /**
     * @param array<string, string> $params
     */
    public function index(RequestContext $context, array $params = []): Response
    {
        $this->requireOperational();

        $compliance = $this->container->get(ComplianceService::class);
        $legal = $this->container->get(LegalService::class);

        $legalStatus = [];
        foreach ([LegalDocumentType::Terms, LegalDocumentType::Privacy] as $type) {
            $current = [];
            foreach (Locales::ALL as $locale) {
                $document = $legal->current($type, $locale);
                $current[$locale] = $document !== null && $document->locale === $locale
                    ? $document->version
                    : '';
            }

            $legalStatus[] = [
                'type' => $type,
                'versions' => $legal->versions($type),
                'current' => $current,
            ];
        }

        return $this->renderAdmin('admin/compliance.html.twig', [
            'meta_title' => $this->trans('compliance.title'),
            'items' => $compliance->all(),
            'summary' => $compliance->summary(),
            'topics' => ComplianceTopic::cases(),
            'legal' => $legalStatus,
            'locales' => Locales::ALL,
            'today' => gmdate('Y-m-d'),
        ]);
    }

    /**
     * Enregistre le constat du propriétaire pour un sujet.
     *
     * @param array<string, string> $params
     */
    public function save(RequestContext $context, array $params = []): Response
    {
        $user = $this->requireOperational();

        $topic = ComplianceTopic::tryFrom((string) ($params['topic'] ?? ''));
        if ($topic === null) {
            throw new NotFoundException('Sujet de conformité inconnu.');
        }

        $result = $this->container->get(ComplianceService::class)->save($topic, [
            'status' => (string) $context->request->input('status', 'to_verify'),
            'value' => (string) $context->request->input('value', ''),
            'notes' => (string) $context->request->input('notes', ''),
            'source_url' => (string) $context->request->input('source_url', ''),
            'last_verified' => (string) $context->request->input('last_verified', ''),
            'next_review' => (string) $context->request->input('next_review', ''),
        ], $user);

        $result['ok'] ? $this->flashSuccess('compliance.saved') : $this->flashError($result['error']);

        return $this->redirectToRoute($context, 'admin.compliance');
    }

    /**
     * Attache une pièce justificative à un sujet.
     *
     * @param array<string, string> $params
     */
    public function uploadEvidence(RequestContext $context, array $params = []): Response
    {
        $user = $this->requireOperational();

        $topic = ComplianceTopic::tryFrom((string) ($params['topic'] ?? ''));
        if ($topic === null) {
            throw new NotFoundException('Sujet de conformité inconnu.');
        }

        /** @var array<string, mixed> $file */
        $file = $context->request->files['evidence'] ?? ['error' => UPLOAD_ERR_NO_FILE];
        $contents = ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK
            ? file_get_contents((string) $file['tmp_name'])
            : false;

        if ($contents === false || $contents === '') {
            $this->flashError('document.error.upload_failed');

            return $this->redirectToRoute($context, 'admin.compliance');
        }

        $result = $this->container->get(DocumentService::class)->store(
            $contents,
            (string) ($file['name'] ?? 'justificatif'),
            // Un justificatif de conformité décrit le logement, pas un séjour.
            DocumentKind::Proof,
            DocumentSource::Upload,
            null,
            null,
            $user->id,
            '',
            $context->locale,
            'compliance:' . $topic->value,
        );

        if ($result['ok'] === false || $result['document'] === null) {
            $this->flashError($result['error']);

            return $this->redirectToRoute($context, 'admin.compliance');
        }

        $this->container->get(ComplianceService::class)
            ->attachEvidence($topic, $result['document']->id, $user);

        $this->flashSuccess('compliance.evidence_added');

        return $this->redirectToRoute($context, 'admin.compliance');
    }

    /**
     * Publie une version d'un texte légal dans toutes les langues.
     *
     * @param array<string, string> $params
     */
    public function publishLegal(RequestContext $context, array $params = []): Response
    {
        $user = $this->requireAdministrator();

        $type = LegalDocumentType::tryFrom((string) $context->request->input('type', ''));
        if ($type === null) {
            throw new NotFoundException('Texte légal inconnu.');
        }

        $result = $this->container->get(LegalService::class)->publish(
            $type,
            (string) $context->request->input('version', ''),
            $user,
        );

        if ($result['ok'] === false) {
            $this->flashError($result['error']);

            return $this->redirectToRoute($context, 'admin.compliance');
        }

        // Publier une version incomplète est possible — mieux vaut un texte
        // opposable dans trois langues que dans aucune — mais cela se dit.
        $result['missing'] === []
            ? $this->flashSuccess('legal.published')
            : $this->flashWarning('legal.published_partial');

        return $this->redirectToRoute($context, 'admin.compliance');
    }
}
