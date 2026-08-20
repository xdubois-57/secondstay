<?php

declare(strict_types=1);

namespace SecondStay\Contract;

use SecondStay\Booking\Booking;
use SecondStay\I18n\Formatter;
use SecondStay\I18n\Translator;
use SecondStay\Payment\Payment;
use SecondStay\Pdf\PdfDocument;
use SecondStay\Settings\SettingsService;

/**
 * Rend le contrat de location en PDF, dans la langue du séjour
 * (SPECIFICATIONS.md §39).
 *
 * Tout le texte vient des catalogues de traduction : le contrat existe donc
 * en FR/EN/NL/DE sans qu'aucune phrase soit écrite en dur ici. Les montants
 * proviennent du séjour et de son échéancier, jamais d'un recalcul : un
 * contrat doit refléter ce qui a été engagé, pas ce que le tarif du jour
 * donnerait.
 */
final class ContractBuilder
{
    /**
     * Version du modèle de contrat.
     *
     * Elle est incrémentée dès que le contenu du contrat change de fond : un
     * séjour conserve la version qu'il a acceptée, et l'instantané reste
     * relisible (SPECIFICATIONS.md §39).
     */
    public const VERSION = '1';

    public function __construct(
        private readonly Translator $translator,
        private readonly Formatter $formatter,
        private readonly SettingsService $settings,
    ) {
    }

    /**
     * @param list<Payment> $payments
     */
    public function build(Booking $booking, array $payments = [], string $generatedAt = ''): string
    {
        $locale = $booking->locale;
        $formatter = $this->formatter->withLocale($locale);

        $document = new PdfDocument([
            'title' => $this->trans('contract.pdf.title', $locale) . ' ' . $booking->reference,
            'author' => $this->propertyName(),
            'subject' => $this->trans('contract.pdf.subject', $locale),
            'date' => $generatedAt === '' ? gmdate('YmdHis') : $generatedAt,
        ]);

        $document->addPage();
        $document->title($this->trans('contract.pdf.title', $locale));
        $document->small($this->trans('contract.pdf.reference', $locale) . ' : ' . $booking->reference);
        $document->small($this->trans('contract.pdf.version', $locale, [
            'version' => self::VERSION,
            'locale' => strtoupper($locale),
        ]));
        $document->rule();

        $this->parties($document, $booking, $locale);
        $this->property($document, $locale);
        $this->stay($document, $booking, $locale, $formatter);
        $this->amounts($document, $booking, $payments, $locale, $formatter);
        $this->clauses($document, $locale);
        $this->acceptance($document, $locale);

        return $document->output();
    }

    // --- Sections ---------------------------------------------------------------

    private function parties(PdfDocument $document, Booking $booking, string $locale): void
    {
        $document->heading($this->trans('contract.section.parties', $locale));

        $document->keyValue($this->trans('contract.field.owner', $locale), $this->propertyName());

        $ownerAddress = $this->address();
        if ($ownerAddress !== '') {
            $document->keyValue($this->trans('contract.field.owner_address', $locale), $ownerAddress);
        }

        $siret = $this->settings->string('property.siret');
        if ($siret !== '') {
            $document->keyValue($this->trans('contract.field.siret', $locale), $siret);
        }

        $document->keyValue($this->trans('contract.field.guest', $locale), $booking->guestName);
        $document->keyValue($this->trans('contract.field.guest_email', $locale), $booking->guestEmail);

        if ($booking->guestPhone !== '') {
            $document->keyValue($this->trans('contract.field.guest_phone', $locale), $booking->guestPhone);
        }
    }

    private function property(PdfDocument $document, string $locale): void
    {
        $document->heading($this->trans('contract.section.property', $locale));

        $address = $this->address();
        if ($address !== '') {
            $document->keyValue($this->trans('contract.field.address', $locale), $address);
        }

        $capacity = $this->settings->int('booking.max_guests');
        $document->keyValue(
            $this->trans('contract.field.capacity', $locale),
            $this->transChoice('contract.value.guests', $capacity, $locale, ['count' => (string) $capacity])
        );
    }

    private function stay(PdfDocument $document, Booking $booking, string $locale, Formatter $formatter): void
    {
        $document->heading($this->trans('contract.section.stay', $locale));

        $document->keyValue(
            $this->trans('contract.field.arrival', $locale),
            $formatter->date($booking->range->arrival) . ' — ' . $this->settings->string('booking.checkin_time')
        );
        $document->keyValue(
            $this->trans('contract.field.departure', $locale),
            $formatter->date($booking->range->departure) . ' — ' . $this->settings->string('booking.checkout_time')
        );
        $document->keyValue(
            $this->trans('contract.field.nights', $locale),
            $this->transChoice('contract.value.nights', $booking->nights(), $locale, [
                'count' => (string) $booking->nights(),
            ])
        );
        $document->keyValue(
            $this->trans('contract.field.occupants', $locale),
            $this->trans('contract.value.occupants', $locale, [
                'adults' => (string) $booking->adults,
                'children' => (string) $booking->children,
                'infants' => (string) $booking->infants,
            ])
        );
    }

    /**
     * @param list<Payment> $payments
     */
    private function amounts(
        PdfDocument $document,
        Booking $booking,
        array $payments,
        string $locale,
        Formatter $formatter,
    ): void {
        $document->heading($this->trans('contract.section.amounts', $locale));

        $document->keyValue(
            $this->trans('contract.field.accommodation', $locale),
            $formatter->money($booking->accommodationCents)
        );

        if ($booking->cleaningCents > 0) {
            $document->keyValue(
                $this->trans('contract.field.cleaning', $locale),
                $formatter->money($booking->cleaningCents)
            );
        }

        if ($booking->discountCents > 0) {
            $document->keyValue(
                $this->trans('contract.field.discount', $locale),
                '-' . $formatter->money($booking->discountCents)
            );
        }

        $document->keyValue(
            $this->trans('contract.field.total', $locale),
            $formatter->money($booking->totalCents),
            true
        );

        if ($booking->securityDepositCents > 0) {
            $document->keyValue(
                $this->trans('contract.field.security_deposit', $locale),
                $formatter->money($booking->securityDepositCents)
            );
        }

        if ($payments !== []) {
            $document->spacer(6.0);
            $document->table(
                [
                    $this->trans('contract.table.component', $locale),
                    $this->trans('contract.table.due_on', $locale),
                    $this->trans('contract.table.amount', $locale),
                ],
                array_map(
                    function (Payment $payment) use ($locale, $formatter): array {
                        $due = $payment->dueDate();

                        return [
                            $this->trans($payment->kind->labelKey(), $locale),
                            $due === null ? '—' : $formatter->date($due, 'short'),
                            $formatter->money($payment->amountCents),
                        ];
                    },
                    $payments
                ),
                [2.0, 1.0, 1.0]
            );
        }
    }

    private function clauses(PdfDocument $document, string $locale): void
    {
        foreach (['cancellation', 'inventory', 'rules', 'liability', 'data'] as $clause) {
            $document->heading($this->trans('contract.clause.' . $clause . '.title', $locale));
            $document->paragraph($this->trans('contract.clause.' . $clause . '.body', $locale));
        }

        $terms = $this->settings->string('legal.terms_version');
        if ($terms !== '') {
            $document->small($this->trans('contract.field.terms_version', $locale, ['version' => $terms]));
        }
    }

    private function acceptance(PdfDocument $document, string $locale): void
    {
        $document->spacer(10.0);
        $document->rule();
        $document->small($this->trans('contract.pdf.acceptance_notice', $locale));
    }

    // --- Outils -------------------------------------------------------------------

    private function propertyName(): string
    {
        $name = $this->settings->string('property.name');

        return $name === '' ? 'SecondStay' : $name;
    }

    private function address(): string
    {
        $parts = array_filter([
            $this->settings->string('property.address_line1'),
            $this->settings->string('property.address_line2'),
            trim($this->settings->string('property.postal_code') . ' ' . $this->settings->string('property.city')),
            $this->settings->string('property.country'),
        ], static fn (string $part): bool => trim($part) !== '');

        return implode(', ', $parts);
    }

    /**
     * @param array<string, string> $parameters
     */
    private function trans(string $key, string $locale, array $parameters = []): string
    {
        return $this->translator->trans($key, $parameters, $locale);
    }

    /**
     * @param array<string, string> $parameters
     */
    private function transChoice(string $key, int $count, string $locale, array $parameters = []): string
    {
        return $this->translator->transChoice($key, $count, $parameters, $locale);
    }
}
